<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @author Tomas Jakl <tomasjakl@tomsgu.com>
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        return new TreeBuilder('tomsgu_tolgee_translation_provider');
    }
}
