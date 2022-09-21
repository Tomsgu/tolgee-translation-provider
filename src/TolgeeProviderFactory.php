<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\Exception\UnsupportedSchemeException;
use Symfony\Component\Translation\Provider\AbstractProviderFactory;
use Symfony\Component\Translation\Provider\Dsn;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Tomas Jakl <tomasjakl@tomsgu.com>
 */
final class TolgeeProviderFactory extends AbstractProviderFactory
{
    private const HOST = 'app.tolgee.io';

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private string $defaultLocale
    ) {
    }

    /**
     * @return TolgeeProvider
     */
    public function create(Dsn $dsn): ProviderInterface
    {
        if ('tolgee' !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, 'tolgee', $this->getSupportedSchemes());
        }

        $endpoint = 'default' === $dsn->getHost() ? self::HOST : $dsn->getHost();
        $endpoint .= $dsn->getPort() ? ':' . $dsn->getPort() : '';

        $client = $this->client->withOptions([
            'base_uri' => sprintf('https://%s', $endpoint),
            'headers' => [
                'X-API-Key' => $this->getUser($dsn)
            ]
        ]);

        return new TolgeeProvider($client, $this->logger, $this->defaultLocale, $endpoint);
    }

    protected function getSupportedSchemes(): array
    {
        return ['tolgee'];
    }
}
