<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;

/**
 * Tests for ComposerDependencies bootstrap guard.
 *
 * Covers the ensure() branches and the namespace-scoped redeclaration guard
 * semantics shared by the canonical class and the per-module copy template.
 *
 * @covers \ksfraser\FrontAccounting\Common\Utils\ComposerDependencies
 * @BABOK Related: infra (ComposerDependencies bootstrap)
 */
class ComposerDependenciesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ksf_common_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    public function testEnsureReturnsTrueWhenAutoloadExists(): void
    {
        $autoload = $this->tmpDir . '/vendor/autoload.php';
        mkdir(dirname($autoload), 0777, true);
        file_put_contents($autoload, '<?php');

        $result = \ksfraser\FrontAccounting\Common\Utils\ComposerDependencies::ensure($this->tmpDir);

        $this->assertTrue($result);
    }

    public function testEnsureReturnsFalseWithoutComposerJson(): void
    {
        $result = \ksfraser\FrontAccounting\Common\Utils\ComposerDependencies::ensure($this->tmpDir);

        $this->assertFalse($result);
    }

    /**
     * An unrenamed template copy (all landing on the placeholder
     * MODULENAME namespace) must declare the class exactly once across
     * multiple includes — no redeclaration fatal, no clobbering.
     */
    public function testUnrenamedTemplateGuardPreventsRedeclaration(): void
    {
        $template = dirname(__DIR__, 3) . '/src/Utils/ComposerDependencies.template.php';
        $this->assertFileExists($template, 'template file must exist');

        $script = $this->tmpDir . '/run.php';
        $code = <<<'PHP'
<?php
require $argv[1];
require $argv[1];
echo class_exists('ksfraser\FrontAccounting\MODULENAME\Utils\ComposerDependencies', false) ? 'OK' : 'MISSING';
PHP;
        file_put_contents($script, $code);

        exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($template) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    /**
     * Properly-renamed copies (HRM vs CRM namespaces) must coexist: each
     * declares its own class and neither is suppressed by the other.
     */
    public function testRenamedCopiesCoexistInOwnNamespaces(): void
    {
        $template = dirname(__DIR__, 3) . '/src/Utils/ComposerDependencies.template.php';
        $source = file_get_contents($template);
        $hrmCopy = $this->tmpDir . '/ComposerDependencies-HRM.php';
        $crmCopy = $this->tmpDir . '/ComposerDependencies-CRM.php';
        file_put_contents($hrmCopy, str_replace('MODULENAME', 'HRM', $source));
        file_put_contents($crmCopy, str_replace('MODULENAME', 'CRM', $source));

        $script = $this->tmpDir . '/run.php';
        $code = <<<'PHP'
<?php
require $argv[1];
require $argv[2];
$hrm = class_exists('ksfraser\FrontAccounting\HRM\Utils\ComposerDependencies', false);
$crm = class_exists('ksfraser\FrontAccounting\CRM\Utils\ComposerDependencies', false);
echo ($hrm && $crm) ? 'OK' : 'MISSING';
PHP;
        file_put_contents($script, $code);

        exec(
            PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($hrmCopy) . ' ' . escapeshellarg($crmCopy) . ' 2>&1',
            $output,
            $exitCode
        );

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    /**
     * The cloned unrenamed template in the legacy Common namespace must still
     * set the old global constant so older copies short-circuit.
     */
    public function testCommonNamespaceCopySetsLegacyConstant(): void
    {
        $template = dirname(__DIR__, 3) . '/src/Utils/ComposerDependencies.template.php';
        $source = file_get_contents($template);
        $commonCopy = $this->tmpDir . '/ComposerDependencies-Common.php';
        file_put_contents($commonCopy, str_replace('MODULENAME', 'Common', $source));

        $script = $this->tmpDir . '/run.php';
        $code = <<<'PHP'
<?php
require $argv[1];
echo (class_exists('ksfraser\FrontAccounting\Common\Utils\ComposerDependencies', false)
        && defined('KSF_FA_COMMON_COMPOSER_DEPENDENCIES_DECLARED'))
    ? 'OK' : 'MISSING';
PHP;
        file_put_contents($script, $code);

        exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($commonCopy) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }
}