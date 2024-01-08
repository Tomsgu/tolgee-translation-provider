<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;

return static function (ECSConfig $config): void {
    $config->paths([__DIR__.'/src']);
    $config->parallel();

    $config->import(SetList::CLEAN_CODE);
    $config->import(SetList::PSR_12);
};
