<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Plugin;

use SamJUK\MediaProxy\Api\ConfigInterface;
use SamJUK\MediaProxy\Api\RequestedMediaInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\HTTP\PhpEnvironment\Response;
use Magento\MediaStorage\App\Media as BaseMedia;
use Psr\Log\LoggerInterface;

class Media
{
    /**
     * Every miss that reaches here costs a full Magento bootstrap. Letting the
     * browser hold on to the answer keeps a page of missing images from paying
     * that on each reload. Short, because the answer changes if media is synced
     * or the mode is switched.
     */
    private const RESPONSE_TTL = 300;

    /** @readonly */
    private ConfigInterface $config;
    /** @readonly */
    private RequestedMediaInterface $requestedMedia;
    /** @readonly */
    private Response $response;
    /** @readonly */
    private LoggerInterface $logger;

    public function __construct(
        ConfigInterface $config,
        RequestedMediaInterface $requestedMedia,
        Response $response,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->requestedMedia = $requestedMedia;
        $this->response = $response;
        $this->logger = $logger;
    }

    /**
     * @param BaseMedia $subject
     * @param callable $proceed
     * @return ResponseInterface
     */
    public function aroundLaunch(BaseMedia $subject, callable $proceed)
    {
        try {
            if ($this->config->isProxyMode() && !$this->requestedMedia->exists()) {
                return $this->createRedirect($this->requestedMedia->getUpstreamRedirectUrl());
            }

            if ($this->config->isStreamMode() && !$this->requestedMedia->exists()) {
                return $this->createFileResponse($this->requestedMedia->download());
            }

            if ($this->config->isCacheMode() && !$this->requestedMedia->exists()) {
                $this->requestedMedia->sync();
            }
        } catch (\Throwable $e) {
            // Never break the media request over this, let Magento serve its placeholder.
            $this->logger->warning('SamJUK_MediaProxy: ' . $e->getMessage(), ['exception' => $e]);
        }

        return $proceed();
    }

    private function createRedirect(string $url, int $code = 302): Response
    {
        $this->response->setRedirect($url, $code);

        // An upstream host carrying credentials puts them in the Location header.
        // That must not come to rest in a browser history or a shared Varnish.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $carriesCredentials = parse_url($url, PHP_URL_USER) !== null;
        $this->response->setHeader(
            'Cache-Control',
            $carriesCredentials ? 'no-store' : 'max-age=' . self::RESPONSE_TTL,
            true
        );

        return $this->response;
    }

    /** @param array{body: string, contentType: string} $download */
    private function createFileResponse(array $download): Response
    {
        $this->response->setHttpResponseCode(200);
        if ($download['contentType'] !== '') {
            $this->response->setHeader('Content-Type', $download['contentType'], true);
        }
        $this->response->setHeader('Content-Length', (string)strlen($download['body']), true);
        $this->response->setHeader('Cache-Control', 'max-age=' . self::RESPONSE_TTL, true);
        $this->response->setBody($download['body']);

        return $this->response;
    }
}
