<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

use SamJUK\MediaProxy\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class ConfigTest extends TestCase
{
    /** @var ScopeConfigInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;
    /** @var EncryptorInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $encryptor;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
    }

    public function testProxyModeRespectsGlobalFeatureFlag(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->scopeConfig->method('getValue')->willReturn('proxy');

        $this->assertFalse($this->createConfig()->isProxyMode());
    }

    public function testCacheModeRespectsGlobalFeatureFlag(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->scopeConfig->method('getValue')->willReturn('cache');

        $this->assertFalse($this->createConfig()->isCacheMode());
    }

    public function testModesAreMutuallyExclusiveWhenEnabled(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturn('cache');

        $config = $this->createConfig();
        $this->assertTrue($config->isCacheMode());
        $this->assertFalse($config->isProxyMode());
    }

    public function testProxyModeIsActiveWhenEnabled(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturn('proxy');

        $config = $this->createConfig();
        $this->assertTrue($config->isProxyMode());
        $this->assertFalse($config->isCacheMode());
    }

    public function testStreamModeIsActiveWhenEnabled(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturn('stream');

        $config = $this->createConfig();
        $this->assertTrue($config->isStreamMode());
        $this->assertFalse($config->isProxyMode());
        $this->assertFalse($config->isCacheMode());
    }

    public function testStreamModeRespectsGlobalFeatureFlag(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->scopeConfig->method('getValue')->willReturn('stream');

        $this->assertFalse($this->createConfig()->isStreamMode());
    }

    public function testAnUnknownModeActivatesNothing(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturn('nonsense');

        $config = $this->createConfig();
        $this->assertFalse($config->isProxyMode());
        $this->assertFalse($config->isCacheMode());
        $this->assertFalse($config->isStreamMode());
    }

    public function testTheFeatureFlagIsReadAtStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('samjuk_media_proxy/general/enabled', 'store')
            ->willReturn(true);

        $this->assertTrue($this->createConfig()->isEnabled());
    }

    public function testConfigIsReadAtStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('samjuk_media_proxy/general/mode', 'store')
            ->willReturn('proxy');

        $this->assertSame('proxy', $this->createConfig()->getMode());
    }

    public function testGetUpstreamHostTrimsTrailingSlash(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('https://example.com/');

        $this->assertSame('https://example.com', $this->createConfig()->getUpstreamHost());
    }

    public function testGetUpstreamHostLeavesAnAlreadyTrimmedHostAlone(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('https://example.com');

        $this->assertSame('https://example.com', $this->createConfig()->getUpstreamHost());
    }

    public function testGetUpstreamHostHandlesUnsetValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame('', $this->createConfig()->getUpstreamHost());
    }

    public function testTimeoutFallsBackWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame(10, $this->createConfig()->getTimeout());
    }

    public function testTimeoutFallsBackWhenNotPositive(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        $this->assertSame(10, $this->createConfig()->getTimeout());
    }

    public function testTimeoutFallsBackWhenNegative(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('-5');

        $this->assertSame(10, $this->createConfig()->getTimeout());
    }

    public function testTimeoutUsesConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('25');

        $this->assertSame(25, $this->createConfig()->getTimeout());
    }

    public function testUpstreamUsernameIsReadVerbatim(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('staging');

        $this->assertSame('staging', $this->createConfig()->getUpstreamUsername());
    }

    public function testUnsetUpstreamUsernameIsAnEmptyString(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame('', $this->createConfig()->getUpstreamUsername());
    }

    public function testUpstreamPasswordIsDecrypted(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0:3:encrypted');
        $this->encryptor->expects($this->once())
            ->method('decrypt')
            ->with('0:3:encrypted')
            ->willReturn('hunter2');

        $this->assertSame('hunter2', $this->createConfig()->getUpstreamPassword());
    }

    public function testUnsetUpstreamPasswordIsNotDecrypted(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->encryptor->expects($this->never())->method('decrypt');

        $this->assertSame('', $this->createConfig()->getUpstreamPassword());
    }

    private function createConfig(): Config
    {
        return new Config($this->scopeConfig, $this->encryptor);
    }
}
