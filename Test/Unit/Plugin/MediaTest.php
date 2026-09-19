<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Test\Unit\Plugin;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use SamJUK\MediaProxy\Api\ConfigInterface;
use SamJUK\MediaProxy\Api\RequestedMediaInterface;
use SamJUK\MediaProxy\Plugin\Media as MediaPlugin;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\Response;
use Magento\MediaStorage\App\Media as BaseMedia;
use Psr\Log\LoggerInterface;

class MediaTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;
    /** @var RequestedMediaInterface&MockObject */
    private $requestedMedia;
    /** @var BaseMedia&MockObject */
    private $baseMedia;
    /** @var LoggerInterface&MockObject */
    private $logger;
    private MediaPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->requestedMedia = $this->createMock(RequestedMediaInterface::class);
        $this->baseMedia = $this->createMock(BaseMedia::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->plugin = new MediaPlugin(
            $this->config,
            $this->requestedMedia,
            new Response(),
            $this->logger
        );
    }

    public function testPluginReturnsRedirectInProxyMode(): void
    {
        $this->config->method('isProxyMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('getUpstreamRedirectUrl')
            ->willReturn('https://upstream.example.com/media/a.jpg');
        $this->baseMedia->expects($this->never())->method('launch');

        $response = $this->launch();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->isRedirect());
        $this->assertSame(
            'https://upstream.example.com/media/a.jpg',
            $response->getHeader('Location')->getFieldValue()
        );
    }

    public function testARedirectCarryingCredentialsIsNeverCached(): void
    {
        $this->config->method('isProxyMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('getUpstreamRedirectUrl')
            ->willReturn('https://dev:hunter2@upstream.example.com/media/a.jpg');

        $response = $this->launch();

        $this->assertSame('no-store', $response->getHeader('Cache-Control')->getFieldValue());
    }

    public function testStreamModeServesTheUpstreamBodyWithoutWritingAnything(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isStreamMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('download')
            ->willReturn(['body' => 'BINARY', 'contentType' => 'image/jpeg']);
        $this->requestedMedia->expects($this->never())->method('sync');
        $this->baseMedia->expects($this->never())->method('launch');

        $response = $this->launch();

        $this->assertSame(200, $response->getHttpResponseCode());
        $this->assertSame('BINARY', $response->getBody());
        $this->assertSame('image/jpeg', $response->getHeader('Content-Type')->getFieldValue());
        $this->assertSame('6', $response->getHeader('Content-Length')->getFieldValue());
        $this->assertSame('max-age=300', $response->getHeader('Cache-Control')->getFieldValue());
    }

    public function testStreamModeOmitsAnUnknownContentType(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isStreamMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('download')
            ->willReturn(['body' => 'BINARY', 'contentType' => '']);

        $response = $this->launch();

        $this->assertFalse($response->getHeader('Content-Type'));
    }

    public function testStreamModeDoesNotRefetchAFileWeAlreadyHave(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isStreamMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(true);
        $this->requestedMedia->expects($this->never())->method('download');
        $this->baseMedia->expects($this->once())->method('launch');

        $this->launch();
    }

    public function testAFailedStreamFallsThroughToMagento(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isStreamMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('download')
            ->willThrowException(new LocalizedException(__('upstream down')));

        $this->logger->expects($this->once())->method('warning');
        $this->baseMedia->expects($this->once())->method('launch');

        $this->launch();
    }

    public function testTheRedirectIsCacheableSoAReloadDoesNotBootstrapMagentoAgain(): void
    {
        $this->config->method('isProxyMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('getUpstreamRedirectUrl')
            ->willReturn('https://upstream.example.com/media/a.jpg');

        $response = $this->launch();

        $this->assertSame('max-age=300', $response->getHeader('Cache-Control')->getFieldValue());
    }

    public function testPluginDoesNotRedirectAwayFromAFileWeAlreadyHave(): void
    {
        $this->config->method('isProxyMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(true);
        $this->baseMedia->expects($this->once())->method('launch');

        $this->launch();
    }

    public function testPluginSyncsMediaInCacheModeWhenMissing(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isCacheMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->expects($this->once())->method('sync');
        $this->baseMedia->expects($this->once())->method('launch');

        $this->launch();
    }

    public function testPluginDoesNotSyncMediaInCacheModeWhenNotMissing(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isCacheMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(true);
        $this->requestedMedia->expects($this->never())->method('sync');

        $this->launch();
    }

    public function testPluginDoesNothingWhenDisabled(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isCacheMode')->willReturn(false);
        $this->config->method('isStreamMode')->willReturn(false);
        $this->requestedMedia->expects($this->never())->method('sync');
        $this->requestedMedia->expects($this->never())->method('exists');
        $this->baseMedia->expects($this->once())->method('launch');

        $this->launch();
    }

    public function testAFailedSyncIsLoggedAndFallsThroughToMagento(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isCacheMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('sync')->willThrowException(new LocalizedException(__('upstream down')));

        $this->logger->expects($this->once())->method('warning');
        $this->baseMedia->expects($this->once())->method('launch');

        $this->launch();
    }

    public function testAnUnresolvableUpstreamUrlFallsThroughToMagento(): void
    {
        $this->config->method('isProxyMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('getUpstreamRedirectUrl')
            ->willThrowException(new LocalizedException(__('no upstream host')));

        $this->logger->expects($this->once())->method('warning');
        $this->baseMedia->expects($this->once())->method('launch');

        $this->launch();
    }

    public function testAnUnresolvablePathFallsThroughToMagento(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isCacheMode')->willReturn(true);
        $this->requestedMedia->method('exists')
            ->willThrowException(new LocalizedException(__('outside of the media directory')));

        $this->logger->expects($this->once())->method('warning');
        $this->baseMedia->expects($this->once())->method('launch');

        $this->launch();
    }

    public function testTheFailureReasonIsLogged(): void
    {
        $exception = new LocalizedException(__('upstream down'));

        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isCacheMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('sync')->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('upstream down'),
                ['exception' => $exception]
            );

        $this->launch();
    }

    public function testErrorsAreCaughtAsWellAsExceptions(): void
    {
        $this->config->method('isProxyMode')->willReturn(false);
        $this->config->method('isCacheMode')->willReturn(true);
        $this->requestedMedia->method('exists')->willReturn(false);
        $this->requestedMedia->method('sync')->willThrowException(new \Error('boom'));

        $this->logger->expects($this->once())->method('warning');
        $this->baseMedia->expects($this->once())->method('launch');

        $this->launch();
    }

    /** @return mixed */
    private function launch()
    {
        return $this->plugin->aroundLaunch($this->baseMedia, [$this->baseMedia, 'launch']);
    }
}
