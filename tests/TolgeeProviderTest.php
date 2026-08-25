<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider\Tests;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tomsgu\TolgeeTranslationProvider\Exception\TolgeeApiException;
use Tomsgu\TolgeeTranslationProvider\TolgeeProvider;

class TolgeeProviderTest extends ProviderTestCase
{
    public function createProvider(
        HttpClientInterface $client,
        LoggerInterface $logger,
        string $defaultLocale,
        string $endpoint
    ): ProviderInterface {
        return new TolgeeProvider($client, $logger, $defaultLocale, $endpoint);
    }

    public static function toStringProvider(): iterable
    {
        yield ['https://app.tolgee.com', 'app.tolgee.com', 'tolgee://app.tolgee.com'];
        yield ['https://domain.tolgee.com', 'domain.tolgee.com', 'tolgee://domain.tolgee.com'];
        yield ['https://app.tolgee.com:99', 'app.tolgee.com:99', 'tolgee://app.tolgee.com:99'];
    }

    /**
     * @param array<int, string> $locales
     */
    private function fetchAllKeysResponses(array $locales, array ...$pages): array
    {
        $responses = [
            function (string $method, string $url) use ($locales): ResponseInterface {
                $this->assertSame('GET', $method);
                $this->assertSame('https://app.tolgee.com/v2/projects/languages?size=2000&page=0', $url);

                return new MockResponse(json_encode([
                    '_embedded' => ['languages' => array_map(
                        static fn (string $tag) => ['tag' => $tag],
                        $locales
                    )],
                ]));
            },
        ];

        $query = implode('', array_map(static fn (string $l) => '&languages=' . $l, $locales));
        foreach ($pages as $number => $keys) {
            $totalPages = count($pages);
            $responses[] = function (string $method, string $url) use (
                $query,
                $number,
                $totalPages,
                $keys
            ): ResponseInterface {
                $this->assertSame('GET', $method);
                $this->assertSame(
                    sprintf(
                        'https://app.tolgee.com/v2/projects/translations?size=2000%s&page=%d',
                        $query,
                        $number
                    ),
                    $url
                );

                return new MockResponse(json_encode([
                    '_embedded' => ['keys' => $keys],
                    'page' => ['number' => $number, 'totalPages' => $totalPages],
                ]));
            };
        }

        return $responses;
    }

    /**
     * @param array<string, string> $translations locale => text
     */
    private static function tolgeeKey(int $id, string $name, string $domain, array $translations): array
    {
        $payload = [];
        $translationId = $id * 10;
        foreach ($translations as $locale => $text) {
            $payload[$locale] = ['id' => $translationId++, 'text' => $text, 'state' => 'TRANSLATED'];
        }

        return [
            'keyId' => $id,
            'keyName' => $name,
            'keyTags' => [['id' => 1, 'name' => $domain]],
            'translations' => $payload,
        ];
    }

    private function createTolgeeProvider(array $responses, string $defaultLocale = 'en'): ProviderInterface
    {
        return $this->createProvider(
            (new MockHttpClient($responses))->withOptions(['base_uri' => 'https://app.tolgee.com']),
            $this->getLogger(),
            $defaultLocale,
            'app.tolgee.com'
        );
    }

    public function testWriteCreatesUnknownKeysThenUploadsTranslations()
    {
        $createdKeys = [];
        $uploaded = [];

        $responses = array_merge(
            $this->fetchAllKeysResponses(['en', 'fr'], []),
            [
                function (string $method, string $url, array $options) use (&$createdKeys): ResponseInterface {
                    $this->assertSame('POST', $method);
                    $this->assertSame('https://app.tolgee.com/v2/projects/keys', $url);
                    $createdKeys[] = json_decode($options['body'], true);

                    return new MockResponse(json_encode(['id' => 11]), ['http_code' => 201]);
                },
            ],
            // write() refetches once it has created a key, so later domains see it.
            $this->fetchAllKeysResponses(['en', 'fr'], [
                self::tolgeeKey(11, 'a', 'messages', []),
            ]),
            [
                function (string $method, string $url, array $options) use (&$uploaded): ResponseInterface {
                    $this->assertSame('POST', $method);
                    $this->assertSame('https://app.tolgee.com/v2/projects/translations', $url);
                    $uploaded[] = json_decode($options['body'], true);

                    return new MockResponse(json_encode(['translations' => []]));
                },
            ]
        );

        $translatorBag = new TranslatorBag();
        $translatorBag->addCatalogue(new MessageCatalogue('en', ['messages' => ['a' => 'trans_en_a']]));
        $translatorBag->addCatalogue(new MessageCatalogue('fr', ['messages' => ['a' => 'trans_fr_a']]));

        $this->createTolgeeProvider($responses)->write($translatorBag);

        $this->assertSame([['name' => 'a', 'tags' => ['messages']]], $createdKeys);
        $this->assertSame(
            [['key' => 'a', 'translations' => ['en' => 'trans_en_a', 'fr' => 'trans_fr_a']]],
            $uploaded
        );
    }

    public function testWriteTagsAnExistingKeyInsteadOfRecreatingIt()
    {
        $tagged = [];

        $responses = array_merge(
            // 'a' already exists, but tagged with 'messages' only.
            $this->fetchAllKeysResponses(['en'], [
                self::tolgeeKey(11, 'a', 'messages', ['en' => 'trans_en_a']),
            ]),
            [
                function (string $method, string $url, array $options) use (&$tagged): ResponseInterface {
                    $this->assertSame('PUT', $method);
                    $this->assertSame('https://app.tolgee.com/v2/projects/keys/11/tags', $url);
                    $tagged[] = json_decode($options['body'], true);

                    return new MockResponse(json_encode([]));
                },
            ],
            $this->fetchAllKeysResponses(['en'], [
                self::tolgeeKey(11, 'a', 'validators', ['en' => 'trans_en_a']),
            ])
        );

        $translatorBag = new TranslatorBag();
        $translatorBag->addCatalogue(new MessageCatalogue('en', ['validators' => ['a' => 'trans_en_a']]));

        $this->createTolgeeProvider($responses)->write($translatorBag);

        $this->assertSame([['name' => 'validators']], $tagged);
    }

    public function testWriteSkipsTranslationsTolgeeAlreadyHas()
    {
        $client = (new MockHttpClient($this->fetchAllKeysResponses(['en'], [
            self::tolgeeKey(11, 'a', 'messages', ['en' => 'trans_en_a']),
        ])))->withOptions(['base_uri' => 'https://app.tolgee.com']);

        $translatorBag = new TranslatorBag();
        $translatorBag->addCatalogue(new MessageCatalogue('en', ['messages' => ['a' => 'trans_en_a']]));

        $this->createProvider(
            $client,
            $this->getLogger(),
            $this->getDefaultLocale(),
            'app.tolgee.com'
        )->write($translatorBag);

        // Nothing changed, so only the initial languages + translations reads happen:
        // no key creation, no refetch, no translation upload.
        $this->assertSame(2, $client->getRequestsCount());
    }

    public function testWriteMarksSymfonyPlaceholderTranslationsAsUntranslated()
    {
        $states = [];

        $responses = array_merge(
            $this->fetchAllKeysResponses(['en'], [
                self::tolgeeKey(11, 'a', 'messages', ['en' => 'something else']),
            ]),
            [
                fn (): ResponseInterface => new MockResponse(json_encode([
                    'translations' => [['id' => 501, 'text' => '__a']],
                ])),
                function (string $method, string $url) use (&$states): ResponseInterface {
                    $this->assertSame('PUT', $method);
                    $states[] = $url;

                    return new MockResponse(json_encode([]));
                },
            ]
        );

        $translatorBag = new TranslatorBag();
        $translatorBag->addCatalogue(new MessageCatalogue('en', ['messages' => ['a' => '__a']]));

        $this->createTolgeeProvider($responses)->write($translatorBag);

        $this->assertSame(
            ['https://app.tolgee.com/v2/projects/translations/501/set-state/UNTRANSLATED'],
            $states
        );
    }

    public function testReadForOneLocaleAndOneDomain()
    {
        $responses = $this->fetchAllKeysResponses(['en', 'fr'], [
            self::tolgeeKey(11, 'a', 'messages', ['en' => 'trans_en_a', 'fr' => 'trans_fr_a']),
            self::tolgeeKey(22, 'b', 'messages', ['en' => 'trans_en_b']),
        ]);

        $bag = $this->createTolgeeProvider($responses)->read(['messages'], ['fr']);

        $expected = new TranslatorBag();
        $expected->addCatalogue(new MessageCatalogue('fr', ['messages' => ['a' => 'trans_fr_a']]));

        $this->assertEquals($expected->getCatalogues(), $bag->getCatalogues());
    }

    public function testReadForSeveralLocalesAndDomains()
    {
        $responses = $this->fetchAllKeysResponses(['en'], [
            self::tolgeeKey(11, 'a', 'messages', ['en' => 'trans_en_a']),
            self::tolgeeKey(22, 'post.num_comments', 'validators', ['en' => '{count, plural, other {# comments}}']),
        ]);

        $bag = $this->createTolgeeProvider($responses)->read(['messages', 'validators'], ['en']);

        $catalogue = $bag->getCatalogue('en');
        $this->assertSame(['a' => 'trans_en_a'], $catalogue->all('messages'));
        $this->assertSame(
            ['post.num_comments' => '{count, plural, other {# comments}}'],
            $catalogue->all('validators')
        );
    }

    public function testReadSkipsDomainsTolgeeDoesNotKnow()
    {
        $responses = $this->fetchAllKeysResponses(['en'], [
            self::tolgeeKey(11, 'a', 'messages', ['en' => 'trans_en_a']),
        ]);

        $bag = $this->createTolgeeProvider($responses)->read(['does_not_exist'], ['en']);

        $this->assertSame([], $bag->getCatalogues());
    }

    public function testReadWalksEveryTranslationPage()
    {
        $responses = $this->fetchAllKeysResponses(
            ['en'],
            [self::tolgeeKey(11, 'a', 'messages', ['en' => 'trans_en_a'])],
            [self::tolgeeKey(22, 'b', 'messages', ['en' => 'trans_en_b'])]
        );

        $bag = $this->createTolgeeProvider($responses)->read(['messages'], ['en']);

        $this->assertSame(
            ['a' => 'trans_en_a', 'b' => 'trans_en_b'],
            $bag->getCatalogue('en')->all('messages')
        );
    }

    public function testDelete()
    {
        $deleteBody = null;

        $responses = [
            'listLanguages' => function (string $method, string $url): ResponseInterface {
                $this->assertSame('GET', $method);
                $this->assertSame('https://app.tolgee.com/v2/projects/languages?size=2000&page=0', $url);

                return new MockResponse(json_encode([
                    '_embedded' => ['languages' => [['tag' => 'en']]],
                ]));
            },
            'listTranslations' => function (string $method, string $url): ResponseInterface {
                $this->assertSame('GET', $method);
                $this->assertSame(
                    'https://app.tolgee.com/v2/projects/translations?size=2000&languages=en&page=0',
                    $url
                );

                return new MockResponse(json_encode([
                    '_embedded' => [
                        'keys' => [
                            [
                                'keyId' => 11,
                                'keyName' => 'en a',
                                'keyTags' => [['id' => 1, 'name' => 'messages']],
                                'translations' => ['en' => ['id' => 101, 'text' => 'en a']],
                            ],
                            [
                                'keyId' => 22,
                                'keyName' => 'en b',
                                'keyTags' => [['id' => 1, 'name' => 'messages']],
                                'translations' => ['en' => ['id' => 102, 'text' => 'en b']],
                            ],
                            [
                                'keyId' => 33,
                                'keyName' => 'keep me',
                                'keyTags' => [['id' => 1, 'name' => 'messages']],
                                'translations' => ['en' => ['id' => 103, 'text' => 'keep me']],
                            ],
                        ],
                    ],
                    'page' => ['number' => 0, 'totalPages' => 1],
                ]));
            },
            'deleteKeys' => function (string $method, string $url, array $options) use (&$deleteBody): ResponseInterface {
                $this->assertSame('DELETE', $method);
                $this->assertSame('https://app.tolgee.com/v2/projects/keys', $url);
                $deleteBody = json_decode($options['body'], true);

                return new MockResponse('', ['http_code' => 200]);
            },
        ];

        $translatorBag = new TranslatorBag();
        $translatorBag->addCatalogue(new MessageCatalogue('en', [
            'messages' => [
                'en a' => 'en a',
                'en b' => 'en b',
            ],
        ]));

        $provider = $this->createProvider((new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://app.tolgee.com',
        ]), $this->getLogger(), $this->getDefaultLocale(), 'app.tolgee.com');

        $provider->delete($translatorBag);

        $this->assertSame(['ids' => [11, 22]], $deleteBody);
    }

    public function testDeleteThrowsWhenTolgeeRejectsTheCall()
    {
        $responses = [
            'listLanguages' => fn (): ResponseInterface => new MockResponse(json_encode([
                '_embedded' => ['languages' => [['tag' => 'en']]],
            ])),
            'listTranslations' => fn (): ResponseInterface => new MockResponse(json_encode([
                '_embedded' => [
                    'keys' => [
                        [
                            'keyId' => 11,
                            'keyName' => 'en a',
                            'keyTags' => [['id' => 1, 'name' => 'messages']],
                            'translations' => ['en' => ['id' => 101, 'text' => 'en a']],
                        ],
                    ],
                ],
                'page' => ['number' => 0, 'totalPages' => 1],
            ])),
            'deleteKeys' => fn (): ResponseInterface => new MockResponse('', ['http_code' => 405]),
        ];

        $translatorBag = new TranslatorBag();
        $translatorBag->addCatalogue(new MessageCatalogue('en', [
            'messages' => ['en a' => 'en a'],
        ]));

        $provider = $this->createProvider((new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://app.tolgee.com',
        ]), $this->getLogger(), $this->getDefaultLocale(), 'app.tolgee.com');

        $this->expectException(TolgeeApiException::class);
        $this->expectExceptionMessageMatches('/status code: "405"/');

        $provider->delete($translatorBag);
    }

    public function testDeleteWithNothingToRemoveMakesNoDeleteCall()
    {
        $responses = [
            'listLanguages' => fn (): ResponseInterface => new MockResponse(json_encode([
                '_embedded' => ['languages' => [['tag' => 'en']]],
            ])),
            'listTranslations' => fn (): ResponseInterface => new MockResponse(json_encode([
                '_embedded' => ['keys' => []],
                'page' => ['number' => 0, 'totalPages' => 1],
            ])),
        ];

        $client = (new MockHttpClient($responses))->withOptions(['base_uri' => 'https://app.tolgee.com']);

        $translatorBag = new TranslatorBag();
        $translatorBag->addCatalogue(new MessageCatalogue('en', [
            'messages' => ['en a' => 'en a'],
        ]));

        $provider = $this->createProvider(
            $client,
            $this->getLogger(),
            $this->getDefaultLocale(),
            'app.tolgee.com'
        );

        $provider->delete($translatorBag);

        $this->assertSame(2, $client->getRequestsCount());
    }
}
