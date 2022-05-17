<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider\Model;

/**
 * @author Tomas Jakl <tomasjakl@tomsgu.com>
 */
class TolgeeCatalogue
{
    /**
     * @param array<string, array<string, Key>> $keys
     */
    public function __construct(private array $keys = [])
    {
    }

    /**
     * @param array<Key> $keys
     */
    public function addKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $this->addKey($key);
        }
    }

    public function addKey(Key $key): void
    {
        foreach ($key->tags as $tag) {
            $this->keys[$tag->name][$key->name] = $key;
        }
    }

    public function hasKey(string $domain, string $key): bool
    {
        if ($this->hasDomain($domain) === false) {
            return false;
        }
        if (array_key_exists($key, $this->keys[$domain]) === false) {
            return false;
        }

        return true;
    }

    public function hasDomain(string $domain): bool
    {
        return array_key_exists($domain, $this->keys);
    }

    public function hasTranslation(
        string $domain,
        string $locale,
        string $key,
        string $translation
    ): bool {
        if (array_key_exists($domain, $this->keys) === false) {
            return false;
        }

        if (array_key_exists($key, $this->keys[$domain]) === false) {
            return false;
        }

        $keyObj = $this->keys[$domain][$key];
        if ($keyObj->hasTranslation($locale) === false) {
            return false;
        }

        return $keyObj->getTranslation($locale)->text === $translation;
    }

    /**
     * @return array<string, Key>
     */
    public function getKeysByDomainAndLocale(string $domain, string $locale): array
    {
        return array_filter($this->keys[$domain], fn (Key $key) => $key->hasTranslation($locale));
    }

    public function getKeyById(string $domain, string $id): Key
    {
        return $this->keys[$domain][$id];
    }
}
