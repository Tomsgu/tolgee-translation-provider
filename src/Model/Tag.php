<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider\Model;

/**
 * @author Tomas Jakl <tomasjakl@tomsgu.com>
 */
class Tag
{
    public function __construct(
        public readonly int $id,
        public readonly string $name
    ) {
    }
}
