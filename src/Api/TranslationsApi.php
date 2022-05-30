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

    public function fetchAllKeys(): TolgeeCatalogue
    {
        $catalogue = new TolgeeCatalogue();
        $page = 0;
        $url = sprintf('/v2/projects/translations?size=%d&page=', self::PAGE_SIZE);
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
            if (array_key_exists('_embedded', $data) === false || array_key_exists('keys', $data['_embedded'])){
                return $catalogue;
            }

            $keys = array_map(function (array $key) {
                $translations = array_map(fn (
                    array $translation
                ) => new Translation(
                    $translation['id'],
                    $translation['text'],
                    $translation['state']
                ), $key['translations']);

                $tags = array_map(
                    fn (array $tag) => new Tag($tag['id'], $tag['name']),
                    $key['keyTags']
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

    public function deleteKeys(array $ids): void
    {
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

        if ($response->getStatusCode() !== Response::HTTP_CREATED) {
            $this->logger->error(sprintf(
                'Unable to add new translation key "%s" to Tolgee: (status code: "%s") "%s".',
                $key,
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
        $response = $this->client->request('PUT', '/v2/projects/translations', [
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
        $updatedTranslations = json_decode($response->getContent(), true);
        foreach ($updatedTranslations['translations'] as $translation) {
            if ($translation['text'] !== null && str_starts_with($translation['text'],
                    '__') === true) {
                if (str_starts_with($translation['text'], '__') === true) {
                    $this->setTranslationState($translation['id'], Translation::UNTRANSLATED_STATE);
                }
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
