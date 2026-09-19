<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Api;

interface RequestedMediaInterface
{
    /**
     * Get the absolute path for the requested media on the local file system
     * @return string
     */
    public function getAbsolutePath(): string;

    /**
     * Get the full url of the requested media on the upstream host
     * @return string
     */
    public function getUpstreamUrl(): string;

    /**
     * Get the url a proxied request should be redirected to. Unlike
     * getUpstreamUrl() this carries the original query string, which the
     * image_optimization_based_on_query_parameters url format uses to ask the
     * upstream for a particular size.
     * @return string
     */
    public function getUpstreamRedirectUrl(): string;

    /**
     * Check if the requested media exists on the local file system
     * @return bool
     */
    public function exists(): bool;

    /**
     * Fetch the media from the upstream host without writing it anywhere.
     * @return array{body: string, contentType: string}
     */
    public function download(): array;

    /**
     * Sync the image from the upstream host, to the local file system
     * @return bool
     */
    public function sync(): bool;
}
