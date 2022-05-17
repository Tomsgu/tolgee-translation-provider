<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider\Model;

/**
 * @author Tomas Jakl <tomasjakl@tomsgu.com>
 */
class Key
{
    /**
     * @param array<Tag>  $tags
     * @param array<string, Translation>  $translations
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $tags,
        public readonly array $translations
    ) {
    }

    public function hasTranslation(string $locale): bool
    {
        return array_key_exists($locale, $this->translations);
    }

    public function getTranslation(string $locale): Translation
    {
        return $this->translations[$locale];
    }
}
