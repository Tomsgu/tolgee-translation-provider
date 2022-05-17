<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tomsgu\TolgeeTranslationProvider\Api\TranslationsApi;

/**
 * @author Tomas Jakl <tomasjakl@tomsgu.com>
 *
 * In Tolgee:
 *    - Tags refers to Symfony's translation domains
 */
class TolgeeProvider implements ProviderInterface
{
    private TranslationsApi $translationsApi;

    public function __construct(
        HttpClientInterface $client,
        LoggerInterface $logger,
        private string $defaultLocale,
        private string $endpoint
    ) {
        $this->translationsApi = new TranslationsApi($client, $logger);
    }

    public function __toString(): string
    {
        return sprintf('tolgee://%s', $this->endpoint);
    }

    public function write(TranslatorBagInterface $translatorBag): void
    {
        $catalogue = $translatorBag->getCatalogue($this->defaultLocale);

        if (!$catalogue) {
            $catalogue = $translatorBag->getCatalogues()[0];
        }

        $tolgeeCatalogue = $this->translationsApi->fetchAllKeys();
        foreach ($catalogue->all() as $domain => $messages) {
            foreach ($messages as $key => $translation) {
                if ($tolgeeCatalogue->hasKey($domain, $key) === false) {
                    $this->translationsApi->createKey($key, $domain);
                }
            }
        }

        $localMessages = [];
        foreach ($translatorBag->getCatalogues() as $catalogue) {
            $locale = $catalogue->getLocale();

            foreach ($catalogue->all() as $domain => $messages) {
                foreach ($messages as $key => $message) {
                    $localMessages[$domain][$key][$locale] = $message;
                }
            }
        }
        foreach ($localMessages as $domain => $keys) {
            foreach ($keys as $key => $translations) {
                $updatedTranslations = [];
                foreach ($translations as $locale => $translation) {
                    if ($tolgeeCatalogue->hasTranslation($domain, $locale, $key, $translation) === false) {
                        $updatedTranslations[$locale] = $translation;
                    }
                }

                if (count($updatedTranslations) > 0) {
                    $this->translationsApi->createOrUpdateTranslations($key, $updatedTranslations);
                }
            }
        }
    }

    public function read(array $domains, array $locales): TranslatorBag
    {
        $translatorBag = new TranslatorBag();
        $tolgeeCatalogue = $this->translationsApi->fetchAllKeys();

        foreach ($locales as $locale) {
            foreach ($domains as $domain) {
                if ($tolgeeCatalogue->hasDomain($domain) === false) {
                    continue;
                }
                $catalogue = new MessageCatalogue($locale);
                foreach ($tolgeeCatalogue->getKeysByDomainAndLocale($domain, $locale) as $key) {
                    $catalogue->set($key->name, $key->getTranslation($locale)->text, $domain);
                }

                $translatorBag->addCatalogue($catalogue);
            }
        }


        return $translatorBag;
    }

    public function delete(TranslatorBagInterface $translatorBag): void
    {
        $catalogue = $translatorBag->getCatalogue($this->defaultLocale);

        if (!$catalogue) {
            $catalogue = $translatorBag->getCatalogues()[0];
        }

        $tolgeeCatalogue = $this->translationsApi->fetchAllKeys();
        $ids = [];
        foreach ($catalogue->all() as $domain => $messages) {
            foreach ($messages as $id => $message) {
                if ($tolgeeCatalogue->hasKey($domain, $id) === true) {
                    $ids[] = $tolgeeCatalogue->getKeyById($domain, $id)->id;
                }
            }
        }

        $this->translationsApi->deleteKeys($ids);
    }
}
