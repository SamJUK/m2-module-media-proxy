<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use SamJUK\MediaProxy\Api\ConfigInterface;
use SamJUK\MediaProxy\Model\RequestedMedia;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request;

class RequestedMediaTest extends TestCase
{
    private const PUB_PATH = '/var/www/html/pub/';
    private const CACHE_SEGMENT = 'cache/0e6bd0f8f1e6cbc0a5a2b3e2c3d4e5f6';
    private const MISS_KEY_PREFIX = 'samjuk_media_proxy_miss_';
    private const PLACEHOLDER_KEY_PREFIX = 'samjuk_media_proxy_placeholder_';

    /** @var ConfigInterface&MockObject */
    private $config;
    /** @var ClientInterface&MockObject */
    private $client;
    /** @var WriteInterface&MockObject */
    private $pubDirectory;
    /** @var CacheInterface&MockObject */
    private $cache;
    /** @var array<string, string> */
    private $cacheEntries = [];

    protected function setUp(): void
    {
        $this->config = $this->createConfig();

        $this->client = $this->createMock(ClientInterface::class);

        $this->pubDirectory = $this->createMock(WriteInterface::class);
        $this->pubDirectory->method('getDriver')->willReturn(new File());
        $this->pubDirectory->method('getAbsolutePath')->willReturnCallback(
            static fn ($path = null): string => self::PUB_PATH . ltrim((string)$path, '/')
        );
        $this->pubDirectory->method('getRelativePath')->willReturnCallback(
            static fn ($path = null): string => substr((string)$path, strlen(self::PUB_PATH))
        );

        // By default: nothing is a known miss, and the upstream has already been
        // probed and found not to serve a placeholder.
        $this->cacheEntries = [self::PLACEHOLDER_KEY_PREFIX => '-'];
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturnCallback(
            function ($key) {
                foreach ($this->cacheEntries as $prefix => $value) {
                    if (strncmp((string)$key, (string)$prefix, strlen((string)$prefix)) === 0) {
                        return $value;
                    }
                }
                return false;
            }
        );
    }

    public function testCacheSegmentIsStrippedFromTheResolvedPath(): void
    {
        $subject = $this->createSubject('media/catalog/product/' . self::CACHE_SEGMENT . '/a/a/aaa.jpg');

        $this->assertSame(
            self::PUB_PATH . 'media/catalog/product/a/a/aaa.jpg',
            $subject->getAbsolutePath()
        );
    }

    public function testAnUppercaseCacheHashIsStripped(): void
    {
        $subject = $this->createSubject('media/catalog/product/cache/0E6BD0F8F1E6CBC0A5A2B3E2C3D4E5F6/a/a/aaa.jpg');

        $this->assertSame(self::PUB_PATH . 'media/catalog/product/a/a/aaa.jpg', $subject->getAbsolutePath());
    }

    public function testATruncatedCacheHashIsNotStripped(): void
    {
        $subject = $this->createSubject('media/catalog/product/cache/0e6bd0f8/a/a/aaa.jpg');

        $this->assertSame(
            self::PUB_PATH . 'media/catalog/product/cache/0e6bd0f8/a/a/aaa.jpg',
            $subject->getAbsolutePath()
        );
    }

    public function testFileNamedCacheIsNotStripped(): void
    {
        $subject = $this->createSubject('media/c/a/cache.jpg');

        $this->assertSame(self::PUB_PATH . 'media/c/a/cache.jpg', $subject->getAbsolutePath());
    }

    public function testNonHashCacheDirectoryIsNotStripped(): void
    {
        $subject = $this->createSubject('media/wysiwyg/cache/banners/hero.jpg');

        $this->assertSame(self::PUB_PATH . 'media/wysiwyg/cache/banners/hero.jpg', $subject->getAbsolutePath());
    }

    public function testUpstreamUrlUsesTheStrippedPath(): void
    {
        $subject = $this->createSubject('media/catalog/product/' . self::CACHE_SEGMENT . '/a/a/aaa.jpg');

        $this->assertSame(
            'https://upstream.example.com/media/catalog/product/a/a/aaa.jpg',
            $subject->getUpstreamUrl()
        );
    }

    public function testTraversalCannotEscapeTheMediaDirectory(): void
    {
        // The '..' segments are stripped, then what is left is bounds checked.
        $subject = $this->createSubject('media/../../app/etc/env.php');

        $this->assertStringStartsWith(self::PUB_PATH . 'media/', $subject->getAbsolutePath());
    }

    public function testPathsOutsideTheMediaDirectoryAreRejected(): void
    {
        $subject = $this->createSubject('/etc/passwd');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('outside of the media directory');
        $subject->getAbsolutePath();
    }

    public function testPathsInPubButOutsideMediaAreRejected(): void
    {
        $subject = $this->createSubject('static/frontend/app.js');

        $this->expectException(LocalizedException::class);
        $subject->getAbsolutePath();
    }

    public function testMediaDirectoryPrefixIsNotMatchedPartially(): void
    {
        $subject = $this->createSubject('media-backup/secret.jpg');

        $this->expectException(LocalizedException::class);
        $subject->getAbsolutePath();
    }

    public function testEmptyRequestIsRejected(): void
    {
        $subject = $this->createSubject('');

        $this->expectException(LocalizedException::class);
        $subject->getUpstreamUrl();
    }

    /**
     * The annotation is for PHPUnit 9, which Magento 2.4.3 to 2.4.6 pin. The
     * attribute is for PHPUnit 12, which dropped the annotation, and is a plain
     * comment to the PHP 7.4 that 2.4.3 runs on.
     *
     * @dataProvider invalidUpstreamHostProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUpstreamHostProvider')]
    public function testUpstreamHostMustBeAnAbsoluteHttpUrl(string $host): void
    {
        $subject = $this->createSubject('media/a/b/c.jpg', $this->createConfig(['host' => $host]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('absolute http(s) URL');
        $subject->getUpstreamUrl();
    }

    /** @return array<string, array{0: string}> */
    public static function invalidUpstreamHostProvider(): array
    {
        return [
            'unset' => [''],
            'no scheme' => ['example.com'],
            'file scheme' => ['file:///etc'],
            'ftp scheme' => ['ftp://example.com'],
        ];
    }

    public function testTheResolvedPathIsComputedOnce(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())->method('getPathInfo')->willReturn('media/a/b/c.jpg');
        $request->method('getRequestUri')->willReturn('/media/a/b/c.jpg');

        $subject = $this->createSubject('unused', null, $request);

        $subject->getAbsolutePath();
        $subject->getAbsolutePath();
        $subject->getUpstreamUrl();
    }

    public function testExistsIsTrueWhenTheFileIsOnDisk(): void
    {
        $this->pubDirectory->method('isFile')->willReturn(true);

        $this->assertTrue($this->createSubject('media/a/b/c.jpg')->exists());
    }

    public function testExistsChecksForAFileRatherThanAnyNode(): void
    {
        $this->pubDirectory->expects($this->once())
            ->method('isFile')
            ->with('media/a/b/c.jpg')
            ->willReturn(false);

        $this->assertFalse($this->createSubject('media/a/b/c.jpg')->exists());
    }

    public function testSyncWritesToATemporaryPathThenMovesItIntoPlace(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');

        $temporaryPath = null;
        $this->pubDirectory->expects($this->once())
            ->method('writeFile')
            ->willReturnCallback(function ($path, $content) use (&$temporaryPath) {
                $temporaryPath = $path;
                $this->assertSame('BINARY', $content);
                $this->assertStringEndsWith('.mediaproxy', (string)$path);
                return strlen((string)$content);
            });

        $this->pubDirectory->expects($this->once())
            ->method('renameFile')
            ->willReturnCallback(function ($from, $to) use (&$temporaryPath) {
                $this->assertSame($temporaryPath, $from);
                $this->assertSame('media/a/b/c.jpg', $to);
                return true;
            });

        $this->assertTrue($this->createSubject('media/a/b/c.jpg')->sync());
    }

    public function testSyncRemovesTheTemporaryFileWhenTheMoveFails(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');

        $this->pubDirectory->method('writeFile')->willReturn(6);
        $this->pubDirectory->method('renameFile')->willThrowException(new \RuntimeException('disk full'));
        $this->pubDirectory->expects($this->once())->method('delete');

        $this->expectException(\RuntimeException::class);
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testSyncRemovesTheTemporaryFileWhenTheWriteFails(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');

        $this->pubDirectory->method('writeFile')->willThrowException(new \RuntimeException('disk full'));
        $this->pubDirectory->expects($this->never())->method('renameFile');
        $this->pubDirectory->expects($this->once())->method('delete');

        $this->expectException(\RuntimeException::class);
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testTheTimeoutAndRedirectPolicyAreAppliedToTheClient(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');
        $this->pubDirectory->method('writeFile')->willReturn(6);
        $this->pubDirectory->method('renameFile')->willReturn(true);

        $this->client->expects($this->once())->method('setTimeout')->with(10);
        $this->client->expects($this->once())
            ->method('setOptions')
            ->with([
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE => 33554432
            ]);

        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testAnOversizedDownloadIsRefusedBeforeItCanExhaustMemory(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');
        $this->pubDirectory->method('writeFile')->willReturn(6);
        $this->pubDirectory->method('renameFile')->willReturn(true);

        $this->client->expects($this->once())
            ->method('setOptions')
            ->willReturnCallback(function (array $options) {
                $this->assertSame(33554432, $options[CURLOPT_MAXFILESIZE]);
                return null;
            });

        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testTheContentTypeHeaderIsMatchedCaseInsensitively(): void
    {
        $this->givenUpstreamResponds(200, 'text/html', '<html></html>', 'content-type');
        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testSetCookieHeadersDoNotBreakContentTypeDetection(): void
    {
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([
            'Set-Cookie' => ['a=1', 'b=2'],
            'Content-Type' => 'image/webp'
        ]);
        $this->client->method('getBody')->willReturn('BINARY');
        $this->pubDirectory->expects($this->once())->method('writeFile')->willReturn(6);
        $this->pubDirectory->method('renameFile')->willReturn(true);

        $this->assertTrue($this->createSubject('media/a/b/c.jpg')->sync());
    }

    public function testAResponseWithoutAContentTypeIsAccepted(): void
    {
        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn([]);
        $this->client->method('getBody')->willReturn('BINARY');
        $this->pubDirectory->expects($this->once())->method('writeFile')->willReturn(6);
        $this->pubDirectory->method('renameFile')->willReturn(true);

        $this->assertTrue($this->createSubject('media/a/b/c.jpg')->sync());
    }

    public function testACurlLevelFailureIsRejected(): void
    {
        $this->givenUpstreamResponds(0, '', '');
        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('HTTP 0');
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testAnUpstreamRedirectResponseIsNotTreatedAsTheImage(): void
    {
        $this->givenUpstreamResponds(302, 'text/html', '');
        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('HTTP 302');
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testSyncRejectsANonOkResponse(): void
    {
        $this->givenUpstreamResponds(404, 'text/html', 'Not Found');
        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('HTTP 404');
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testSyncRejectsAnHtmlBodyServedWithATwoHundred(): void
    {
        $this->givenUpstreamResponds(200, 'text/html; charset=UTF-8', '<html>Please log in</html>');
        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('rather than a media file');
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testSyncRejectsAnEmptyBody(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', '');
        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('empty body');
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testCredentialsAreSentAsACurlOptionRatherThanAHeader(): void
    {
        $config = $this->createConfig(['username' => 'dev', 'password' => 'hunter2']);

        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');
        $this->pubDirectory->method('writeFile')->willReturn(6);
        $this->pubDirectory->method('renameFile')->willReturn(true);

        // setCredentials() adds a literal Authorization header, which curl replays
        // across a cross host redirect. CURLOPT_USERPWD is scoped to the first host.
        $this->client->expects($this->never())->method('setCredentials');
        $this->client->expects($this->once())
            ->method('setOptions')
            ->willReturnCallback(function (array $options) {
                $this->assertSame('dev:hunter2', $options[CURLOPT_USERPWD]);
                $this->assertFalse($options[CURLOPT_UNRESTRICTED_AUTH]);
                return null;
            });

        $this->createSubject('media/a/b/c.jpg', $config)->sync();
    }

    public function testNoCredentialsAreSentWhenNoneAreConfigured(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');
        $this->pubDirectory->method('writeFile')->willReturn(6);
        $this->pubDirectory->method('renameFile')->willReturn(true);

        $this->client->expects($this->never())->method('setCredentials');
        $this->client->expects($this->once())
            ->method('setOptions')
            ->willReturnCallback(function (array $options) {
                $this->assertArrayNotHasKey(CURLOPT_USERPWD, $options);
                return null;
            });

        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testAnUpstreamResolvingBackToThisInstallationIsRefused(): void
    {
        $subject = $this->createSubject(
            'unused',
            null,
            $this->createRequest('media/a/b/c.jpg', '', true)
        );

        $this->client->expects($this->never())->method('get');
        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('resolves back to this installation');
        $subject->download();
    }

    public function testEveryUpstreamRequestIsMarkedSoTheLoopCanBeDetected(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');
        $this->pubDirectory->method('writeFile')->willReturn(6);
        $this->pubDirectory->method('renameFile')->willReturn(true);

        $this->client->expects($this->once())
            ->method('addHeader')
            ->with('X-SamJUK-Media-Proxy', '1');

        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testUpstreamCredentialsNeverReachTheLogMessage(): void
    {
        $config = $this->createConfig(['host' => 'https://dev:hunter2@upstream.example.com']);
        $this->givenUpstreamResponds(404, 'text/html', 'Not Found');

        try {
            $this->createSubject('media/a/b/c.jpg', $config)->sync();
            $this->fail('Expected the 404 to be rejected.');
        } catch (LocalizedException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
            $this->assertStringNotContainsString('dev:', $e->getMessage());
            $this->assertStringContainsString('https://upstream.example.com/media/a/b/c.jpg', $e->getMessage());
        }
    }

    public function testAnInvalidHostIsReportedWithoutItsCredentials(): void
    {
        $subject = $this->createSubject('media/a/b/c.jpg', $this->createConfig(['host' => 'dev:hunter2@example.com']));

        try {
            $subject->getUpstreamUrl();
            $this->fail('Expected the host to be rejected.');
        } catch (LocalizedException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function testATidyUpFailureDoesNotMaskTheWriteFailure(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');
        $this->pubDirectory->method('writeFile')->willThrowException(new \RuntimeException('disk full'));
        $this->pubDirectory->method('delete')->willThrowException(new \RuntimeException('cannot delete'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disk full');
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testAKnownMissIsNotRefetched(): void
    {
        $this->givenCache([self::PLACEHOLDER_KEY_PREFIX => '-', self::MISS_KEY_PREFIX => '1']);

        $this->client->expects($this->never())->method('get');
        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('known not to hold');
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testAFailedFetchIsRemembered(): void
    {
        $this->givenUpstreamResponds(404, 'text/html', 'Not Found');

        $this->cache->expects($this->once())
            ->method('save')
            ->with('1', $this->stringStartsWith(self::MISS_KEY_PREFIX), [], 300);

        $this->expectException(LocalizedException::class);
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testAWriteFailureIsNotRememberedAsAnUpstreamMiss(): void
    {
        $this->givenUpstreamResponds(200, 'image/jpeg', 'BINARY');
        $this->pubDirectory->method('writeFile')->willThrowException(new \RuntimeException('disk full'));

        $this->cache->expects($this->never())->method('save');

        $this->expectException(\RuntimeException::class);
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testTheUpstreamPlaceholderIsNotCachedAsTheImage(): void
    {
        $this->givenCache([self::PLACEHOLDER_KEY_PREFIX => hash('sha256', 'PLACEHOLDER')]);
        $this->givenUpstreamResponds(200, 'image/jpeg', 'PLACEHOLDER');

        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('served its placeholder');
        $this->createSubject('media/a/b/c.jpg')->sync();
    }

    public function testARealImageIsStillCachedWhilePlaceholderDetectionIsActive(): void
    {
        $this->givenCache([self::PLACEHOLDER_KEY_PREFIX => hash('sha256', 'PLACEHOLDER')]);
        $this->givenUpstreamResponds(200, 'image/jpeg', 'REAL IMAGE BYTES');

        $this->pubDirectory->expects($this->once())->method('writeFile')->willReturn(16);
        $this->pubDirectory->method('renameFile')->willReturn(true);

        $this->assertTrue($this->createSubject('media/a/b/c.jpg')->sync());
    }

    public function testTheUpstreamIsProbedOnceAndTheResultRemembered(): void
    {
        $this->givenCache([]);

        $this->client->method('getStatus')->willReturn(200);
        $this->client->method('getHeaders')->willReturn(['Content-Type' => 'image/jpeg']);
        // First call is the probe for a path that cannot exist, second the real image.
        $this->client->method('getBody')->willReturnOnConsecutiveCalls('PLACEHOLDER', 'PLACEHOLDER');

        $probed = [];
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($url) use (&$probed) {
                $probed[] = $url;
                return null;
            });

        $saved = [];
        $this->cache->method('save')->willReturnCallback(
            function ($data, $key, $tags, $lifetime) use (&$saved) {
                $saved[] = [$data, $key, $lifetime];
                return true;
            }
        );

        try {
            $this->createSubject('media/a/b/c.jpg')->sync();
            $this->fail('Expected the placeholder response to be rejected.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('served its placeholder', $e->getMessage());
        }

        $this->assertSame(
            'https://upstream.example.com/media/catalog/product/samjuk-media-proxy/probe.jpg',
            $probed[0]
        );
        $this->assertSame('https://upstream.example.com/media/a/b/c.jpg', $probed[1]);

        // The signature is remembered for a day, and the file itself as a miss.
        $this->assertSame([hash('sha256', 'PLACEHOLDER'), 86400], [$saved[0][0], $saved[0][2]]);
        $this->assertStringStartsWith(self::PLACEHOLDER_KEY_PREFIX, $saved[0][1]);
        $this->assertSame(['1', 300], [$saved[1][0], $saved[1][2]]);
        $this->assertStringStartsWith(self::MISS_KEY_PREFIX, $saved[1][1]);
    }

    public function testAnUpstreamThatAnswersMissingFilesHonestlyNeedsNoDetection(): void
    {
        $this->givenCache([]);

        // The probe 404s, so there is no placeholder signature to learn.
        $this->client->method('getStatus')->willReturnOnConsecutiveCalls(404, 200);
        $this->client->method('getHeaders')->willReturn(['Content-Type' => 'image/jpeg']);
        $this->client->method('getBody')->willReturn('REAL IMAGE BYTES');

        $this->cache->expects($this->once())
            ->method('save')
            ->with('-', $this->stringStartsWith(self::PLACEHOLDER_KEY_PREFIX), [], 86400);

        $this->pubDirectory->expects($this->once())->method('writeFile')->willReturn(16);
        $this->pubDirectory->method('renameFile')->willReturn(true);

        $this->assertTrue($this->createSubject('media/a/b/c.jpg')->sync());
    }

    public function testARedirectUrlCarriesTheQueryStringForTheUpstreamImageService(): void
    {
        $subject = $this->createSubject(
            'unused',
            null,
            $this->createRequest(
                'media/catalog/product/a/b/c.jpg',
                '/media/catalog/product/a/b/c.jpg?width=700&height=700'
            )
        );

        $this->assertSame(
            'https://upstream.example.com/media/catalog/product/a/b/c.jpg?width=700&height=700',
            $subject->getUpstreamRedirectUrl()
        );
    }

    public function testTheDownloadUrlNeverCarriesTheQueryString(): void
    {
        // Appending it would store a resized variant at the original's path.
        $subject = $this->createSubject(
            'unused',
            null,
            $this->createRequest('media/catalog/product/a/b/c.jpg', '/media/catalog/product/a/b/c.jpg?width=700')
        );

        $this->assertSame(
            'https://upstream.example.com/media/catalog/product/a/b/c.jpg',
            $subject->getUpstreamUrl()
        );
    }

    public function testARedirectUrlWithoutAQueryIsUnchanged(): void
    {
        $subject = $this->createSubject('media/a/b/c.jpg');

        $this->assertSame(
            'https://upstream.example.com/media/a/b/c.jpg',
            $subject->getUpstreamRedirectUrl()
        );
    }

    public function testDownloadReturnsTheBodyAndContentTypeWithoutWritingAnything(): void
    {
        $this->givenUpstreamResponds(200, 'image/webp', 'BINARY');
        $this->pubDirectory->expects($this->never())->method('writeFile');

        $this->assertSame(
            ['body' => 'BINARY', 'contentType' => 'image/webp'],
            $this->createSubject('media/a/b/c.jpg')->download()
        );
    }

    public function testDownloadHonoursTheNegativeCache(): void
    {
        $this->givenCache([self::PLACEHOLDER_KEY_PREFIX => '-', self::MISS_KEY_PREFIX => '1']);
        $this->client->expects($this->never())->method('get');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('known not to hold');
        $this->createSubject('media/a/b/c.jpg')->download();
    }

    public function testDownloadRejectsTheUpstreamPlaceholder(): void
    {
        $this->givenCache([self::PLACEHOLDER_KEY_PREFIX => hash('sha256', 'PLACEHOLDER')]);
        $this->givenUpstreamResponds(200, 'image/jpeg', 'PLACEHOLDER');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('served its placeholder');
        $this->createSubject('media/a/b/c.jpg')->download();
    }

    public function testDoubleDotsAreStrippedFromTheRequestedPath(): void
    {
        $subject = $this->createSubject('media/a/../b/c.jpg');

        $this->assertSame(self::PUB_PATH . 'media/a/b/c.jpg', $subject->getAbsolutePath());
    }

    /** @param array<string, string> $entries keyed by cache key prefix */
    private function givenCache(array $entries): void
    {
        $this->cacheEntries = $entries;
    }

    private function givenUpstreamResponds(
        int $status,
        string $contentType,
        string $body,
        string $headerName = 'Content-Type'
    ): void {
        $this->client->method('getStatus')->willReturn($status);
        $this->client->method('getHeaders')->willReturn([$headerName => $contentType]);
        $this->client->method('getBody')->willReturn($body);
    }

    private function createRequest(string $pathInfo, string $requestUri = '', bool $loopHeader = false): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getPathInfo')->willReturn($pathInfo);
        $request->method('getRequestUri')->willReturn($requestUri !== '' ? $requestUri : '/' . $pathInfo);
        $request->method('getHeader')->willReturn($loopHeader ? '1' : false);

        return $request;
    }

    /** @param array{host?: string, username?: string, password?: string} $overrides */
    private function createConfig(array $overrides = []): ConfigInterface
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getUpstreamHost')->willReturn($overrides['host'] ?? 'https://upstream.example.com');
        $config->method('getTimeout')->willReturn(10);
        $config->method('getUpstreamUsername')->willReturn($overrides['username'] ?? '');
        $config->method('getUpstreamPassword')->willReturn($overrides['password'] ?? '');

        return $config;
    }

    private function createSubject(
        string $pathInfo,
        ?ConfigInterface $config = null,
        ?Request $request = null
    ): RequestedMedia {
        if ($request === null) {
            $request = $this->createRequest($pathInfo);
        }

        $mediaDirectory = $this->createMock(ReadInterface::class);
        $mediaDirectory->method('getAbsolutePath')->willReturn(self::PUB_PATH . 'media');

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::PUB)->willReturn($this->pubDirectory);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::MEDIA)->willReturn($mediaDirectory);

        return new RequestedMedia(
            $config ?? $this->config,
            $filesystem,
            $this->client,
            $request,
            $this->cache
        );
    }
}
