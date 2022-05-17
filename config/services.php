<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tomsgu\TolgeeTranslationProvider\TolgeeProvider;
use Tomsgu\TolgeeTranslationProvider\TolgeeProviderFactory;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set('tomsgu.translation.provider_factory.tolgee', TolgeeProviderFactory::class)
        ->args([
            service(Symfony\Contracts\HttpClient\HttpClientInterface::class),
            service(Psr\Log\LoggerInterface::class),
            param('kernel.default_locale'),
        ])
        ->tag('translation.provider_factory');

    $services->set('tomsgu.translation.provider.tolgee', TolgeeProvider::class);
};
