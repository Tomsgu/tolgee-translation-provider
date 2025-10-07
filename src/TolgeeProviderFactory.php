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
        if (!in_array($dsn->getScheme(), $this->getSupportedSchemes(), true)) {
            throw new UnsupportedSchemeException($dsn, $dsn->getScheme(), $this->getSupportedSchemes());
        }

        $endpoint = 'default' === $dsn->getHost() ? self::HOST : $dsn->getHost();
        $endpoint .= $dsn->getPort() ? ':' . $dsn->getPort() : '';

        $scheme = $dsn->getScheme() === 'tolgees' ? 'https://' : 'http://';
        $client = $this->client->withOptions([
            'base_uri' => sprintf('%s%s', $scheme, $endpoint),
            'headers' => [
                'X-API-Key' => $this->getUser($dsn)
            ]
        ]);

        return new TolgeeProvider($client, $this->logger, $this->defaultLocale, $endpoint);
    }

    protected function getSupportedSchemes(): array
    {
        return ['tolgee', 'tolgees'];
    }
}
