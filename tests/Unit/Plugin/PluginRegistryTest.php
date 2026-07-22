<?php

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Plugin;

use ksfraser\FrontAccounting\Common\Plugin\AbstractPlugin;
use ksfraser\FrontAccounting\Common\Plugin\PluginInterface;
use ksfraser\FrontAccounting\Common\Plugin\PluginRegistry;
use PHPUnit\Framework\TestCase;

class SamplePlugin extends AbstractPlugin
{
    private string $name;
    private bool $active;

    public function __construct(string $name = 'sample', bool $active = true)
    {
        $this->name = $name;
        $this->active = $active;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}

class PluginRegistryTest extends TestCase
{
    public function testRegisterAndGetPlugin(): void
    {
        $registry = new PluginRegistry();
        $plugin = new SamplePlugin();

        $registry->register($plugin);

        $this->assertTrue($registry->has('sample'));
        $this->assertSame($plugin, $registry->get('sample'));
    }

    public function testGetUnknownPluginReturnsNull(): void
    {
        $registry = new PluginRegistry();

        $this->assertNull($registry->get('nonexistent'));
        $this->assertFalse($registry->has('nonexistent'));
    }

    public function testGetAllReturnsAllPlugins(): void
    {
        $registry = new PluginRegistry();
        $registry->register(new SamplePlugin('a'));
        $registry->register(new SamplePlugin('b'));

        $this->assertCount(2, $registry->getAll());
    }

    public function testGetActiveFiltersInactivePlugins(): void
    {
        $registry = new PluginRegistry();
        $registry->register(new SamplePlugin('active', true));
        $registry->register(new SamplePlugin('inactive', false));

        $active = $registry->getActive();
        $this->assertCount(1, $active);
        $this->assertArrayHasKey('active', $active);
    }

    public function testRegisterOverridesSameName(): void
    {
        $registry = new PluginRegistry();
        $first = new SamplePlugin('dup', true);
        $second = new SamplePlugin('dup', false);

        $registry->register($first);
        $registry->register($second);

        $this->assertCount(1, $registry->getAll());
        $this->assertFalse($registry->get('dup')->isActive());
    }

    public function testClearRemovesAllPlugins(): void
    {
        $registry = new PluginRegistry();
        $registry->register(new SamplePlugin('a'));
        $registry->register(new SamplePlugin('b'));

        $registry->clear();

        $this->assertCount(0, $registry->getAll());
        $this->assertFalse($registry->has('a'));
    }

    public function testDiscoverLoadsPluginsFromDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/plugin_test_' . uniqid();
        mkdir($tmpDir);

        $code = '<?php
namespace ksfraser\FrontAccounting\Common\Tests\Unit\Plugin\Discovery;
use ksfraser\FrontAccounting\Common\Plugin\AbstractPlugin;
class DiscoveredPlugin extends AbstractPlugin {
    public function getName(): string { return "discovered"; }
}';
        file_put_contents($tmpDir . '/DiscoveredPlugin.php', $code);

        $registry = new PluginRegistry();
        $registry->discover($tmpDir);

        $this->assertTrue($registry->has('discovered'));

        @unlink($tmpDir . '/DiscoveredPlugin.php');
        @rmdir($tmpDir);
    }

    public function testDiscoverSkipsNonExistentDirectory(): void
    {
        $registry = new PluginRegistry();
        $registry->discover('/nonexistent/path');

        $this->assertCount(0, $registry->getAll());
    }

    public function testDiscoverSkipsAbstractClasses(): void
    {
        $tmpDir = sys_get_temp_dir() . '/plugin_test_' . uniqid();
        mkdir($tmpDir);

        $code = '<?php
namespace ksfraser\FrontAccounting\Common\Tests\Unit\Plugin\Discovery2;
use ksfraser\FrontAccounting\Common\Plugin\AbstractPlugin;
abstract class AbstractOnly extends AbstractPlugin {
    public function getName(): string { return "abstract"; }
}';
        file_put_contents($tmpDir . '/AbstractOnly.php', $code);

        $registry = new PluginRegistry();
        $registry->discover($tmpDir);

        $this->assertFalse($registry->has('abstract'));

        @unlink($tmpDir . '/AbstractOnly.php');
        @rmdir($tmpDir);
    }

    public function testDiscoverSkipsInterfaces(): void
    {
        $tmpDir = sys_get_temp_dir() . '/plugin_test_' . uniqid();
        mkdir($tmpDir);

        $code = '<?php
namespace ksfraser\FrontAccounting\Common\Tests\Unit\Plugin\Discovery3;
interface FakeInterface extends \ksfraser\FrontAccounting\Common\Plugin\PluginInterface {}
';
        file_put_contents($tmpDir . '/FakeInterface.php', $code);

        $registry = new PluginRegistry();
        $registry->discover($tmpDir);

        $this->assertCount(0, $registry->getAll());

        @unlink($tmpDir . '/FakeInterface.php');
        @rmdir($tmpDir);
    }
}
