<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class ProviderTestCase extends TestCase
{
    protected HttpClientInterface $client;
    protected LoggerInterface $logger;
    protected string $defaultLocale;

    abstract public function createProvider(
        HttpClientInterface $client,
        LoggerInterface $logger,
        string $defaultLocale,
        string $endpoint
    ): ProviderInterface;

    /**
     * @return iterable<array{0: string, 1: string, 2: string}>
     */
    abstract public static function toStringProvider(): iterable;

    #[DataProvider('toStringProvider')]
    public function testToString(string $baseUri, string $endpoint, string $expected): void
    {
        $provider = $this->createProvider(
            $this->getClient()->withOptions(['base_uri' => $baseUri]),
            $this->getLogger(),
            $this->getDefaultLocale(),
            $endpoint
        );

        $this->assertSame($expected, (string) $provider);
    }

    protected function getClient(): MockHttpClient
    {
        return $this->client ??= new MockHttpClient();
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger ??= $this->createStub(LoggerInterface::class);
    }

    protected function getDefaultLocale(): string
    {
        return $this->defaultLocale ??= 'en';
    }
}
