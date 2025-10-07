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
 *    - Tag refers to Symfony translation domain
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
        $modified = false;

        foreach ($catalogue->all() as $domain => $messages) {
            foreach ($messages as $key => $translation) {
                if ($tolgeeCatalogue->hasKeyInAnyDomain($key) === true) {
                    if ($tolgeeCatalogue->hasKey($domain, $key) === false) {
                        $tolgeeKey = $tolgeeCatalogue->getKeyFromAnyDomain($key);
                        $this->translationsApi->tagKey($domain, $tolgeeKey);
                        $modified = true;
                    }
                    continue;
                }
                if ($tolgeeCatalogue->hasKey($domain, $key) === false) {
                    $this->translationsApi->createKey($key, $domain);
                    $modified = true;
                }
            }
        }

        if ($modified) {
            // Another fetch is needed, otherwise it will try to add the same key
            // for a different domains, if we add the key for the first domain.
            $tolgeeCatalogue = $this->translationsApi->fetchAllKeys();
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
                $added = false;
                foreach ($tolgeeCatalogue->getKeysByDomainAndLocale($domain, $locale) as $key) {
                    $catalogue->set($key->name, $key->getTranslation($locale)->text, $domain);
                    $added = true;
                }

                if ($added) {
                    $translatorBag->addCatalogue($catalogue);
                }
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
                    $ids[] = $tolgeeCatalogue->getKeyByName($domain, $id)->id;
                }
            }
        }

        $this->translationsApi->deleteKeys($ids);
    }
}
