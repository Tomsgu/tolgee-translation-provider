<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;

return static function (ECSConfig $conf): void {
    $conf->paths([
        __DIR__.'/src',
    ]);
    $conf->parallel();

    $conf->sets([
        SetList::CLEAN_CODE,
        SetList::PSR_12
    ]);
};
