<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider\Api;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tomsgu\TolgeeTranslationProvider\Model\Key;
use Tomsgu\TolgeeTranslationProvider\Model\Tag;
use Tomsgu\TolgeeTranslationProvider\Model\TolgeeCatalogue;
use Tomsgu\TolgeeTranslationProvider\Model\Translation;

/**
 * @author Tomas Jakl <tomasjakl@tomsgu.com>
 */
class TranslationsApi
{
    private const PAGE_SIZE = 2000;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetchAllLanguages(): array
    {
        $url = sprintf('/v2/projects/languages?size=%d&page=0', self::PAGE_SIZE);
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            $error = sprintf(
                'Unable to fetch translations from Tolgee: (status code: "%s") "%s".',
                $response->getStatusCode(),
                $response->getContent(false)
            );
            $this->logger->error($error);

            return [];
        }

        $data = json_decode($response->getContent(), true);
        if (array_key_exists('_embedded', $data) === false || array_key_exists(
            'languages',
            $data['_embedded']
        ) === false) {
            return [];
        }

        return array_map(fn (array $language) => $language['tag'], $data['_embedded']['languages']);
    }

    public function fetchAllKeys(): TolgeeCatalogue
    {
        $catalogue = new TolgeeCatalogue();
        $page = 0;
        $languages = $this->fetchAllLanguages();
        $baseUrl = array_reduce(
            $languages,
            fn (string $carryUrl, string $lang) => sprintf('%s&languages=%s', $carryUrl, $lang),
            sprintf('/v2/projects/translations?size=%d', self::PAGE_SIZE)
        );
        $url = sprintf('%s&page=', $baseUrl);
        while (1) {
            $response = $this->client->request('GET', $url . $page++);

            if ($response->getStatusCode() !== Response::HTTP_OK) {
                $error = sprintf(
                    'Unable to fetch translations from Tolgee: (status code: "%s") "%s".',
                    $response->getStatusCode(),
                    $response->getContent(false)
                );
                $this->logger->error($error);

                return $catalogue;
            }

            $data = json_decode($response->getContent(), true);
            if (array_key_exists('_embedded', $data) === false || array_key_exists(
                'keys',
                $data['_embedded']
            ) === false) {
                return $catalogue;
            }


            $keys = array_map(function (array $key) {
                $translations = [];
                foreach (($key['translations'] ?? []) as $langKey => $translation) {
                    // Some Tolgee versions return an object keyed by the language tag,
                    // others return a list with 'languageTag' inside each item.
                    $tag = is_string($langKey) ? $langKey : ($translation['languageTag'] ?? null);
                    if ($tag === null) {
                        continue;
                    }

                    $translations[$tag] = new Translation(
                        $translation['id'] ?? null,
                        $translation['text'] ?? '',
                        $translation['state'] ?? Translation::UNTRANSLATED_STATE
                    );
                }

                $tags = array_map(
                    fn (array $tag) => new Tag($tag['id'], $tag['name']),
                    $key['keyTags'] ?? []
                );

                return new Key($key['keyId'], $key['keyName'], $tags, $translations);
            }, $data['_embedded']['keys']);

            $catalogue->addKeys($keys);


            if ($data['page']['number'] + 1 === $data['page']['totalPages']) {
                break;
            }
        }

        return $catalogue;
    }

    /**
     * @param array<int> $ids
     */
    public function deleteKeys(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $url = sprintf('/v2/projects/keys/%s', implode('|', $ids));

        $response = $this->client->request('DELETE', $url);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            $error = sprintf(
                'Unable to delete translations from Tolgee: (status code: "%s") "%s".',
                $response->getStatusCode(),
                $response->getContent(false)
            );
            $this->logger->error($error);
        }
    }

    public function createKey(string $key, string $domain): void
    {
        $response = $this->client->request('POST', '/v2/projects/keys', [
            'json' => [
                'name' => $key,
                'tags' => [$domain]
            ]
        ]);

        if (!in_array($response->getStatusCode(), [Response::HTTP_CREATED, Response::HTTP_OK])) {
            $this->logger->error(sprintf(
                'Unable to add new translation key "%s" to Tolgee: (status code: "%s") "%s".',
                $key,
                $response->getStatusCode(),
                $response->getContent(false)
            ));
        }
    }

    public function tagKey(string $domain, Key $key): void
    {
        $response = $this->client->request('PUT', '/v2/projects/keys/' . $key->id . '/tags', [
            'json' => [
                'name' => $domain
            ]
        ]);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            $this->logger->error(sprintf(
                'Unable to tag an existing key "%s" with a tag "%s" to Tolgee: (status code: "%s") "%s".',
                $key->name,
                $domain,
                $response->getStatusCode(),
                $response->getContent(false)
            ));
        }
    }

    /**
     * @param array<string, string> $translations
     */
    public function createOrUpdateTranslations(string $key, array $translations): void
    {
        $response = $this->client->request('POST', '/v2/projects/translations', [
            'json' => [
                'key' => $key,
                'translations' => $translations
            ]
        ]);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            $this->logger->error(sprintf(
                'Unable to add new translations "%s" for key "%s" to Tolgee: (status code: "%s") "%s".',
                json_encode($translations),
                $key,
                $response->getStatusCode(),
                $response->getContent(false)
            ));
        }


        // Symfony by default prefixes untranslated messages with "__".
        $updated = json_decode($response->getContent(), true);
        foreach ($updated['translations'] as $translation) {
            $txt = $translation['text'] ?? null;
            if ($txt !== null && str_starts_with($txt, '__') && isset($translation['id'])) {
                $this->setTranslationState((int) $translation['id'], Translation::UNTRANSLATED_STATE);
            }
        }
    }

    private function setTranslationState(int $id, string $state): void
    {
        $response = $this->client->request(
            'PUT',
            sprintf('/v2/projects/translations/%d/set-state/%s', $id, $state)
        );

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            $this->logger->error(sprintf(
                'Unable to set state "%s" to translation "%d" to Tolgee: (status code: "%s") "%s".',
                $state,
                $id,
                $response->getStatusCode(),
                $response->getContent(false)
            ));
        }
    }
}
