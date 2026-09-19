<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Api;

interface ConfigInterface
{
    /**
     * Feature flag to determine if the module functionality is enabled
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Get the upstream host for the current store
     * @return string
     */
    public function getUpstreamHost(): string;

    /**
     * Get the current mode the module is operating in.
     * @return string
     */
    public function getMode(): string;

    /**
     * Is the module enabled & operating in proxy mode for the current store.
     * @return bool
     */
    public function isProxyMode(): bool;

    /**
     * Is the module enabled & operating in cache mode for the current store.
     * @return bool
     */
    public function isCacheMode(): bool;

    /**
     * Is the module enabled & operating in stream mode for the current store.
     * @return bool
     */
    public function isStreamMode(): bool;

    /**
     * Seconds to wait on the upstream before giving up.
     * @return int
     */
    public function getTimeout(): int;

    /**
     * HTTP basic auth username for the upstream, empty when unauthenticated.
     * @return string
     */
    public function getUpstreamUsername(): string;

    /**
     * HTTP basic auth password for the upstream, empty when unauthenticated.
     * @return string
     */
    public function getUpstreamPassword(): string;
}
