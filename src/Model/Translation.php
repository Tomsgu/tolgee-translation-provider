<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider\Model;

/**
 * @author Tomas Jakl <tomasjakl@tomsgu.com>
 */
class Translation
{
    public const UNTRANSLATED_STATE = 'UNTRANSLATED';
    public const TRANSLATED_STATE = 'TRANSLATED';
    public const REVIEWED_STATE = 'REVIEWED';

    public function __construct(
        public readonly int $id,
        public readonly string $text,
        public readonly string $state
    ) {
    }
}
