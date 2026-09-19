<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Test\Integration;

use PHPUnit\Framework\TestCase;

use SamJUK\MediaProxy\Api\ConfigInterface;
use SamJUK\MediaProxy\Api\RequestedMediaInterface;
use SamJUK\MediaProxy\Model\Config;
use SamJUK\MediaProxy\Model\RequestedMedia;
use SamJUK\MediaProxy\Plugin\Media as MediaPlugin;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\Module\ModuleListInterface;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * @magentoAppArea global
 */
class ModuleTest extends TestCase
{
    public function testTheModuleIsRegistered(): void
    {
        $modules = Bootstrap::getObjectManager()->get(ModuleListInterface::class);

        $this->assertNotNull($modules->getOne('SamJUK_MediaProxy'));
    }

    /**
     * Every collaborator has to be resolvable by the object manager. An interface
     * without a preference only fails at the point a media request is served.
     *
     * @dataProvider buildableTypeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('buildableTypeProvider')]
    public function testTypesAreBuildableByTheObjectManager(string $requested, string $expected): void
    {
        $this->assertInstanceOf($expected, Bootstrap::getObjectManager()->create($requested));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function buildableTypeProvider(): array
    {
        return [
            'config' => [ConfigInterface::class, Config::class],
            'requested media' => [RequestedMediaInterface::class, RequestedMedia::class],
            'media plugin' => [MediaPlugin::class, MediaPlugin::class]
        ];
    }

    public function testTheModuleIsInertOutOfTheBox(): void
    {
        $config = Bootstrap::getObjectManager()->create(ConfigInterface::class);

        $this->assertFalse($config->isEnabled());
        $this->assertFalse($config->isProxyMode());
        $this->assertFalse($config->isCacheMode());
        $this->assertSame(10, $config->getTimeout());
        $this->assertSame('', $config->getUpstreamHost());
    }

    public function testTheAdminSectionCanBeGrantedToARole(): void
    {
        $acl = Bootstrap::getObjectManager()->create(AclBuilder::class)->getAcl();

        $this->assertTrue($acl->hasResource('SamJUK_MediaProxy::config'));
    }
}
