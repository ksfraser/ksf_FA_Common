<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Utils;

use ksfraser\FrontAccounting\Common\Utils\ModuleDependencies;
use PHPUnit\Framework\TestCase;

/**
 * @BABOK Related: UT-MODDEP-001
 */
final class ModuleDependenciesTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['Hooks']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['Hooks']);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function registry(bool $neededActive): array
    {
        return [
            ['package' => 'modules_dep_needed', 'active' => $neededActive, 'version' => '2.4.3-1'],
            ['package' => 'modules_dep_needs', 'active' => false, 'version' => '2.4.3-1'],
        ];
    }

    public function testMissingReportsInactiveDependency(): void
    {
        $missing = ModuleDependencies::missingFromRegistry(
            ['modules_dep_needed'],
            $this->registry(false)
        );
        $this->assertSame(['modules_dep_needed'], $missing);
    }

    public function testMissingIsEmptyWhenDependencyActive(): void
    {
        $missing = ModuleDependencies::missingFromRegistry(
            ['modules_dep_needed'],
            $this->registry(true)
        );
        $this->assertSame([], $missing);
    }

    public function testMissingPreservesRequestedOrder(): void
    {
        $missing = ModuleDependencies::missingFromRegistry(
            ['zeta', 'alpha', 'middle'],
            []
        );
        $this->assertSame(['zeta', 'alpha', 'middle'], $missing);
    }

    public function testIsActiveMatchesOnPackageThenName(): void
    {
        $this->assertTrue(ModuleDependencies::isActive(['package' => 'a', 'active' => 1], 'a'));
        $this->assertTrue(ModuleDependencies::isActive(['name' => 'a', 'active' => true], 'a'));
        $this->assertFalse(ModuleDependencies::isActive(['package' => 'a', 'active' => 0], 'a'));
        $this->assertFalse(ModuleDependencies::isActive(['package' => 'b', 'active' => 1], 'a'));
        $this->assertFalse(ModuleDependencies::isActive([], 'a'));
    }

    public function testRequireHooksTreatsFlaggedButUnloadedAsMissing(): void
    {
        $registry = $this->registry(true);

        $withHooks = ModuleDependencies::missingFromRegistry(['modules_dep_needed'], $registry, false);
        $this->assertSame([], $withHooks, 'flag-only check should pass');

        $GLOBALS['Hooks'] = ['modules_dep_needs' => new \stdClass()];
        $withoutHooks = ModuleDependencies::missingFromRegistry(['modules_dep_needed'], $registry, true);
        $this->assertSame(
            ['modules_dep_needed'],
            $withoutHooks,
            'required hooks object absent => treat as missing'
        );
    }

    public function testRequireHooksSatisfiedWhenHooksRegistered(): void
    {
        $GLOBALS['Hooks'] = ['modules_dep_needed' => new \stdClass()];
        $missing = ModuleDependencies::missingFromRegistry(
            ['modules_dep_needed'],
            $this->registry(true),
            true
        );
        $this->assertSame([], $missing);
    }

    public function testMessageSingularWording(): void
    {
        $message = ModuleDependencies::message(['modules_dep_needed'], 'modules_dep_needs');
        $this->assertStringContainsString('we need module modules_dep_needed', $message);
        $this->assertStringContainsString('modules_dep_needs cannot be activated', $message);
        $this->assertStringNotContainsString('modules we need', $message);
    }

    public function testMessagePluralWording(): void
    {
        $message = ModuleDependencies::message(['a', 'b'], 'self');
        $this->assertStringContainsString('we need modules a, b', $message);
    }

    public function testMessageEmptyWhenNothingMissing(): void
    {
        $this->assertSame('', ModuleDependencies::message([], 'self'));
    }

    public function testBlankModuleNamesAreIgnored(): void
    {
        $missing = ModuleDependencies::missingFromRegistry(['', 'real'], []);
        $this->assertSame(['real'], $missing);
    }

    public function testRegistryIsEmptyWhenNoFaRootAvailable(): void
    {
        $saved = $GLOBALS['path_to_root'] ?? null;
        unset($GLOBALS['path_to_root']);
        try {
            $this->assertSame([], ModuleDependencies::registry(-1));
        } finally {
            if ($saved !== null) {
                $GLOBALS['path_to_root'] = $saved;
            }
        }
    }
}
