<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Model;

use SamJUK\MediaProxy\Api\ConfigInterface;
use SamJUK\MediaProxy\Model\Config\Source\Mode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config implements ConfigInterface
{
    private const XML_PATH_ENABLED = 'samjuk_media_proxy/general/enabled';
    private const XML_PATH_MODE = 'samjuk_media_proxy/general/mode';
    private const XML_PATH_UPSTREAM_HOST = 'samjuk_media_proxy/general/upstream_host';
    private const XML_PATH_TIMEOUT = 'samjuk_media_proxy/general/timeout';
    private const XML_PATH_USERNAME = 'samjuk_media_proxy/general/upstream_username';
    private const XML_PATH_PASSWORD = 'samjuk_media_proxy/general/upstream_password';

    private const FALLBACK_TIMEOUT = 10;

    /** @readonly */
    private ScopeConfigInterface $scopeConfig;
    /** @readonly */
    private EncryptorInterface $encryptor;

    public function __construct(
        ScopeConfigInterface $scopeConfigInterface,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfigInterface;
        $this->encryptor = $encryptor;
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getUpstreamHost(): string
    {
        return rtrim($this->getValue(self::XML_PATH_UPSTREAM_HOST), '/');
    }

    public function getMode(): string
    {
        return $this->getValue(self::XML_PATH_MODE);
    }

    public function isProxyMode(): bool
    {
        return $this->isEnabled() && $this->getMode() === Mode::PROXY;
    }

    public function isCacheMode(): bool
    {
        return $this->isEnabled() && $this->getMode() === Mode::CACHE;
    }

    public function isStreamMode(): bool
    {
        return $this->isEnabled() && $this->getMode() === Mode::STREAM;
    }

    public function getTimeout(): int
    {
        $timeout = (int)$this->getValue(self::XML_PATH_TIMEOUT);

        return $timeout > 0 ? $timeout : self::FALLBACK_TIMEOUT;
    }

    public function getUpstreamUsername(): string
    {
        return $this->getValue(self::XML_PATH_USERNAME);
    }

    public function getUpstreamPassword(): string
    {
        $password = $this->getValue(self::XML_PATH_PASSWORD);

        return $password === '' ? '' : (string)$this->encryptor->decrypt($password);
    }

    private function getValue(string $path): string
    {
        return (string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
    }
}
