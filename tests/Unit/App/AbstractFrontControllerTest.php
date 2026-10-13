<?php
/**
 * AbstractFrontController unit tests — the front-controller preamble.
 *
 * Focus is the preamble every module was hand-rolling: tab resolution, the
 * `tab` / `page` separation, action resolution, the register-hook merge, and
 * above all that the security check cannot be skipped or routed around.
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit
 * @since   1.1.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit;

use InvalidArgumentException;
use ksfraser\FrontAccounting\Common\App\AbstractAppShell;
use ksfraser\FrontAccounting\Common\App\AbstractFrontController;
use ksfraser\FrontAccounting\Common\App\AbstractTabController;
use ksfraser\FrontAccounting\Common\App\FrontControllerRoutingException;
use ksfraser\FrontAccounting\Common\App\FrontControllerSecurityException;
use ksfraser\FrontAccounting\Common\App\TabRegistration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FaHookStub.php';

/**
 * Concrete shell with the tabs a test wants, no FA required.
 */
final class FrontControllerTestShell extends AbstractAppShell
{
    /**
     * @param TabRegistration[] $tabs
     * @param string            $defaultView
     * @param string            $appId
     * @param string            $viewParam
     */
    public function __construct(
        array $tabs,
        string $defaultView = 'employees',
        string $appId = 'hrm',
        string $viewParam = 'tab'
    ) {
        parent::__construct($appId, 'hrm.php', $viewParam, $defaultView);
        foreach ($tabs as $tab) {
            $this->registerTab($tab);
        }
    }
}

/**
 * Tab controller whose output is a marker, so a test can prove which tab ran
 * and which verb reached it.
 */
class FrontControllerTestTab extends AbstractTabController
{
    /** @var string|null Verb passed to runAction(), or null when none ran. */
    public static $actionSeen = null;

    /** @var bool Whether runAction() claims the verb. */
    public static $handlesAction = true;

    /** @var string Tab key the controller was constructed for. */
    public static $view = '';

    public function __construct($context = null, array $options = [])
    {
        self::$view = (string) ($options['view'] ?? '');
        parent::__construct($context, $options);
    }

    public function run(): void
    {
        // AbstractAppShell::createController() news the controller up with no
        // arguments, so identify the tab by the normalised $_GET['tab'] — which
        // is itself part of what run() is expected to guarantee.
        $key = self::$view !== '' ? self::$view : (string) ($_GET['tab'] ?? '');
        echo '[run:' . $key . ']';
    }

    /**
     * @param string $action
     * @return bool
     */
    public function runAction(string $action): bool
    {
        self::$actionSeen = $action;
        echo '[action:' . $action . ']';

        return self::$handlesAction;
    }

    protected function getPkField(): string
    {
        return 'widget_id';
    }

    protected function getFieldMetadata(): array
    {
        return [
            'entity'      => 'widget',
            'table'       => 'test_widgets',
            'label'       => 'Widget',
            'labelPlural' => 'Widgets',
            'hookPrefix'  => 'widget',
            'pk'          => 'widget_id',
            'fields'      => [
                'widget_id' => ['label' => 'ID', 'type' => 'text', 'showInTable' => true],
                'name'      => ['label' => 'Name', 'type' => 'text', 'showInTable' => true, 'showInForm' => true],
            ],
        ];
    }

    protected function listRows(int $page, int $perPage): array
    {
        return [];
    }

    protected function countRows(): int
    {
        return 0;
    }

    protected function findRecord(string $pk): ?array
    {
        return null;
    }

    protected function createRecord(array $data)
    {
        return '1';
    }

    protected function updateRecord(string $pk, array $data): void
    {
    }

    protected function deleteRecord(string $pk): void
    {
    }
}

/**
 * Front controller double — only the documented overridable seams.
 */
class FrontControllerTestHarness extends AbstractFrontController
{
    /** @var string[] Security areas passed to the checker, in order. */
    public static $checked = [];

    /**
     * @param string $area
     * @return void
     */
    public static function recordCheck(string $area): void
    {
        self::$checked[] = $area;
    }
}

class AbstractFrontControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private $getBackup = [];

    /** @var array<string, mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        $this->getBackup    = $_GET;
        $this->requestBackup = $_REQUEST;
        $_GET     = [];
        $_REQUEST = [];

        FaHookStub::reset();

        FrontControllerTestTab::$actionSeen    = null;
        FrontControllerTestTab::$handlesAction = true;
        FrontControllerTestTab::$view          = '';
        FrontControllerTestHarness::$checked   = [];
    }

    protected function tearDown(): void
    {
        $_GET     = $this->getBackup;
        $_REQUEST = $this->requestBackup;
        FaHookStub::reset();
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function tab(string $key, string $security = '', ?string $controller = null): TabRegistration
    {
        return new TabRegistration(
            $key,
            ucfirst($key),
            $security,
            $controller ?? FrontControllerTestTab::class
        );
    }

    /**
     * @return TabRegistration[]
     */
    private function coreTabs(): array
    {
        return [
            $this->tab('employees', 'SA_HRM_EMPLOYEE'),
            $this->tab('leave', ''),
            $this->tab('payroll', 'SA_HRM_PAYROLL'),
            // A numeric tab key, to prove `page` is never read as a selector.
            $this->tab('3'),
        ];
    }

    private function frontController(
        ?AbstractAppShell $shell = null,
        string $fallback = 'SA_ksf_FA_HRMVIEW',
        array $options = []
    ): AbstractFrontController {
        return new FrontControllerTestHarness(
            $shell ?? new FrontControllerTestShell($this->coreTabs()),
            $fallback,
            '',
            array_merge(['security_checker' => function (string $area): void {
                FrontControllerTestHarness::recordCheck($area);
            }], $options)
        );
    }

    // ── tab resolution ─────────────────────────────────────────────────

    public function testResolvesTheRequestedTab(): void
    {
        $_GET['tab'] = 'payroll';

        $fc = $this->frontController();

        $this->assertSame('payroll', $fc->resolveTabKey());
        $this->assertSame('payroll', $fc->getResolvedTab());
    }

    public function testFallsBackToTheDefaultViewWhenNoTabRequested(): void
    {
        $fc = $this->frontController();

        $this->assertSame('employees', $fc->resolveTabKey());
    }

    public function testFallsBackToTheDefaultViewForAnUnknownTab(): void
    {
        $_GET['tab'] = 'no-such-tab';

        $fc = $this->frontController();

        $this->assertSame('employees', $fc->resolveTabKey());
    }

    public function testAcceptsTheDeprecatedViewAlias(): void
    {
        $_GET['view'] = 'leave';

        $fc = $this->frontController();

        $this->assertSame('leave', $fc->resolveTabKey());
    }

    public function testCanonicalTabParamWinsOverTheAlias(): void
    {
        $_GET['tab']  = 'payroll';
        $_GET['view'] = 'leave';

        $fc = $this->frontController();

        $this->assertSame('payroll', $fc->resolveTabKey());
    }

    public function testAliasCanBeDisabled(): void
    {
        $_GET['view'] = 'leave';

        $fc = $this->frontController(null, 'SA_ksf_FA_HRMVIEW', ['alias_param' => '']);

        $this->assertSame('employees', $fc->resolveTabKey());
    }

    /**
     * The collision the `tab` rename removes: `page` is pagination. A tab whose
     * key happens to be numeric must not be reachable via `?page=`.
     */
    public function testPagerPositionIsNeverUsedAsATabSelector(): void
    {
        $_GET['page'] = '3';

        $fc = $this->frontController();

        $this->assertSame('employees', $fc->resolveTabKey());
    }

    public function testAnEmptyShellRaisesARoutingException(): void
    {
        $fc = $this->frontController(new FrontControllerTestShell([]));

        $this->expectException(FrontControllerRoutingException::class);
        $this->runRefusing($fc);
    }

    // ── pager passthrough ──────────────────────────────────────────────

    public function testPagerPositionIsReadSeparatelyFromTheTab(): void
    {
        $_GET['tab']  = 'leave';
        $_GET['page'] = '3';

        $fc = $this->frontController();

        $this->assertSame('leave', $fc->resolveTabKey());
        $this->assertSame(3, $fc->resolvePagePosition());
    }

    public function testPagerPositionSurvivesRequestNormalisation(): void
    {
        $_GET['tab']  = 'leave';
        $_GET['page'] = '3';

        $this->captureRun();

        // MasterSummaryTable::pagerUrl() copies names in preserve_params
        // verbatim out of $_GET, so `page` must still be 3 and `tab` must now be
        // the resolved key — that is what makes `?page=3&tab=leave` come out.
        $this->assertSame('3', $_GET['page']);
        $this->assertSame('leave', $_GET['tab']);
    }

    public function testNormalisingRewritesTheDeprecatedAliasOntoTheCanonicalParam(): void
    {
        $_GET['view'] = 'leave';

        $this->captureRun();

        $this->assertSame('leave', $_GET['tab']);
        $this->assertArrayNotHasKey('view', $_GET);
    }

    /**
     * @dataProvider pagerPositionProvider
     */
    public function testPagerPositionIsClampedToAtLeastOne($raw, int $expected): void
    {
        $_GET['page'] = $raw;

        $this->assertSame($expected, $this->frontController()->resolvePagePosition());
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function pagerPositionProvider(): array
    {
        return [
            'absent'    => [null, 1],
            'zero'      => ['0', 1],
            'negative'  => ['-5', 1],
            'not a num' => ['abc', 1],
            'valid'     => ['7', 7],
        ];
    }

    // ── action resolution ──────────────────────────────────────────────

    public function testResolvesTheActionVerb(): void
    {
        $_REQUEST['action'] = 'save';

        $this->assertSame('save', $this->frontController()->resolveAction());
    }

    public function testAnAbsentActionIsTheEmptyString(): void
    {
        $this->assertSame('', $this->frontController()->resolveAction());
    }

    public function testAnArrayActionIsIgnored(): void
    {
        $_REQUEST['action'] = ['save'];

        $this->assertSame('', $this->frontController()->resolveAction());
    }

    public function testAnActionRequestReachesTheTabsRunAction(): void
    {
        $_GET['tab']        = 'payroll';
        $_REQUEST['action'] = 'save';

        $output = $this->captureRun();

        $this->assertSame('save', FrontControllerTestTab::$actionSeen);
        $this->assertStringContainsString('[action:save]', $output);
        $this->assertStringNotContainsString('[run:', $output);
    }

    public function testAnUnhandledActionFallsThroughToRenderingTheTab(): void
    {
        FrontControllerTestTab::$handlesAction = false;
        $_GET['tab']        = 'payroll';
        $_REQUEST['action'] = 'nonsense';

        $output = $this->captureRun();

        $this->assertStringContainsString('[action:nonsense]', $output);
        $this->assertStringContainsString('[run:payroll]', $output);
    }

    public function testAnActionOnATabWithNoControllerRendersTheUnavailableMessage(): void
    {
        $shell = new FrontControllerTestShell([
            'legacy' => new TabRegistration('legacy', 'Legacy', '', null, 50, 0, null),
        ]);
        $_GET['tab']        = 'legacy';
        $_REQUEST['action'] = 'save';

        $output = $this->captureRun($shell);

        $this->assertStringContainsString('This action is not available', $output);
        $this->assertNull(FrontControllerTestTab::$actionSeen);
    }

    // ── security ───────────────────────────────────────────────────────

    public function testConstructorRejectsAnEmptyFallbackSecurityArea(): void
    {
        $this->expectException(FrontControllerSecurityException::class);

        $this->frontController(null, '');
    }

    public function testConstructorRejectsAWhitespaceOnlyFallbackSecurityArea(): void
    {
        $this->expectException(FrontControllerSecurityException::class);

        $this->frontController(null, '   ');
    }

    public function testRejectsANonCallableSecurityChecker(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FrontControllerTestHarness(
            new FrontControllerTestShell($this->coreTabs()),
            'SA_ksf_FA_HRMVIEW',
            '',
            ['security_checker' => 'no_such_function_at_all']
        );
    }

    public function testSecurityAreaPrefersTheTabsOwnArea(): void
    {
        $_GET['tab'] = 'payroll';

        $this->assertSame('SA_HRM_PAYROLL', $this->frontController()->getSecurityArea());
    }

    public function testSecurityAreaFallsBackWhenTheTabDeclaresNone(): void
    {
        $_GET['tab'] = 'leave';

        $this->assertSame('SA_ksf_FA_HRMVIEW', $this->frontController()->getSecurityArea());
    }

    /**
     * Before session.inc the register hook has not fired, so a tab contributed
     * by another module does not exist yet and the request resolves onto the
     * default view — whose own area is what session.inc is opened with.
     * Enforcement against the contributed tab's own area happens later; see
     * testAHookRegisteredThirdPartyTabIsCheckedAgainstItsOwnAreaNotTheFallback.
     */
    public function testAnUnknownPreSessionTabResolvesOntoTheDefaultView(): void
    {
        $_GET['tab'] = 'recruit';

        $fc = $this->frontController();

        $this->assertSame('employees', $fc->resolveTabKey());
        $this->assertSame('SA_HRM_EMPLOYEE', $fc->getSecurityArea());
    }

    public function testTheFallbackAreaCoversAnEmptyDefaultView(): void
    {
        $shell = new FrontControllerTestShell(
            ['leave' => $this->tab('leave', '')],
            'leave'
        );

        $_GET['tab'] = 'unknown';

        $this->assertSame('SA_ksf_FA_HRMVIEW', $this->frontController($shell)->getSecurityArea());
    }

    public function testTheEnforcedAreaIsTheTabsOwnArea(): void
    {
        $_GET['tab'] = 'payroll';

        FrontControllerTestHarness::$checked = [];
        $this->captureRun();

        $this->assertSame(['SA_HRM_PAYROLL'], FrontControllerTestHarness::$checked);
    }

    public function testTheFallbackAreaIsEnforcedForATabThatDeclaresNone(): void
    {
        $_GET['tab'] = 'leave';

        FrontControllerTestHarness::$checked = [];
        $this->captureRun();

        $this->assertSame(['SA_ksf_FA_HRMVIEW'], FrontControllerTestHarness::$checked);
    }

    public function testSecurityDenialStopsTheReadPath(): void
    {
        $_GET['tab'] = 'payroll';

        $fc = $this->denyingController(['SA_HRM_PAYROLL']);

        try {
            $this->captureRun(null, $fc);
            $this->fail('Expected the security denial to propagate.');
        } catch (FrontControllerSecurityException $e) {
            $this->assertSame('denied: SA_HRM_PAYROLL', $e->getMessage());
        }

        $this->assertSame('', FrontControllerTestTab::$view, 'The tab controller must never be constructed.');
    }

    public function testSecurityDenialStopsTheActionPathToo(): void
    {
        $_GET['tab']        = 'payroll';
        $_REQUEST['action'] = 'save';

        $fc = $this->denyingController(['SA_HRM_PAYROLL']);

        try {
            $this->captureRun(null, $fc);
            $this->fail('Expected the security denial to propagate.');
        } catch (FrontControllerSecurityException $e) {
            $this->assertSame('denied: SA_HRM_PAYROLL', $e->getMessage());
        }

        $this->assertNull(FrontControllerTestTab::$actionSeen, 'No action may reach a denied tab.');
    }

    public function testARefusedRequestStillChecksTheTabTheUserActuallyAskedFor(): void
    {
        // 'employees' is granted; 'payroll' is not. Asking for payroll must be
        // refused on payroll's own area, not waved through on the fallback.
        $_GET['tab'] = 'payroll';

        FrontControllerTestHarness::$checked = [];
        $this->runRefusing($this->denyingController(['SA_HRM_EMPLOYEE']));

        $this->assertSame(['SA_HRM_PAYROLL'], FrontControllerTestHarness::$checked);
    }

    /**
     * Fail closed: with no FA session there is no check_page_security(), and
     * refusing beats rendering an unchecked tab.
     */
    public function testRefusesToDispatchWhenNoAccessCheckerExists(): void
    {
        $_GET['tab'] = 'payroll';

        $fc = new FrontControllerTestHarness(
            new FrontControllerTestShell($this->coreTabs()),
            'SA_ksf_FA_HRMVIEW'
        );

        $this->expectException(FrontControllerSecurityException::class);
        $this->runRefusing($fc);
    }

    public function testRunAndSecurityEnforcementCannotBeOverridden(): void
    {
        $this->assertTrue(
            (new \ReflectionMethod(AbstractFrontController::class, 'run'))->isFinal(),
            'run() must be final so the dispatch sequence cannot be replaced.'
        );
        $this->assertTrue(
            (new \ReflectionMethod(AbstractFrontController::class, 'enforceSecurity'))->isFinal(),
            'enforceSecurity() must be final so the check cannot be weakened.'
        );
    }

    public function testASubclassCannotBypassSecurityThroughAnOverridableSeam(): void
    {
        // dispatchAction() is deliberately overridable (a module may pre-handle
        // a verb), but it runs after enforcement. Prove the ordering.
        $_GET['tab']        = 'payroll';
        $_REQUEST['action'] = 'save';

        $fc = new class (
            new FrontControllerTestShell($this->coreTabs()),
            'SA_ksf_FA_HRMVIEW',
            '',
            ['security_checker' => function (string $area): void {
                FrontControllerTestHarness::$checked[] = $area;
            }]
        ) extends FrontControllerTestHarness {
            public function dispatchAction(TabRegistration $tab, string $action): void
            {
                echo '[overridden-dispatchAction]';
            }
        };

        $output = $this->captureRun(null, $fc);

        $this->assertSame(['SA_HRM_PAYROLL'], FrontControllerTestHarness::$checked);
        $this->assertStringContainsString('[overridden-dispatchAction]', $output);
    }

    // ── register hook / third-party tabs ───────────────────────────────

    public function testAHookRegisteredThirdPartyTabIsDispatched(): void
    {
        FaHookStub::respondTo('hrm_register_tabs', function (array &$data): void {
            $data['tabs'][] = new TabRegistration(
                'recruit',
                'Recruitment',
                'SA_ksf_FA_RECRUITVIEW',
                FrontControllerTestTab::class
            );
        });

        $_GET['tab'] = 'recruit';

        $output = $this->captureRun();

        $this->assertStringContainsString('[run:recruit]', $output);
        $this->assertStringContainsString('Recruitment', $output, 'the tab must reach the menu.');
    }

    public function testAHookRegisteredThirdPartyTabIsCheckedAgainstItsOwnAreaNotTheFallback(): void
    {
        FaHookStub::respondTo('hrm_register_tabs', function (array &$data): void {
            $data['tabs'][] = new TabRegistration(
                'recruit',
                'Recruitment',
                'SA_ksf_FA_RECRUITVIEW',
                FrontControllerTestTab::class
            );
        });

        $_GET['tab'] = 'recruit';

        FrontControllerTestHarness::$checked = [];
        $this->captureRun();

        // This is the gap boot() opens: getSecurityArea() could only see the
        // constructor-time tabs, so session.inc was opened on the fallback.
        // Enforcement must still run against the contributed tab's own area.
        $this->assertSame(['SA_ksf_FA_RECRUITVIEW'], FrontControllerTestHarness::$checked);
    }

    public function testAHookRegisteredThirdPartyTabIsRefusedWhenItsAreaIsNotGranted(): void
    {
        FaHookStub::respondTo('hrm_register_tabs', function (array &$data): void {
            $data['tabs'][] = new TabRegistration(
                'recruit',
                'Recruitment',
                'SA_ksf_FA_RECRUITVIEW',
                FrontControllerTestTab::class
            );
        });

        $_GET['tab'] = 'recruit';

        $fc = $this->denyingController(['SA_ksf_FA_RECRUITVIEW']);

        $this->expectException(FrontControllerSecurityException::class);
        $this->runRefusing($fc);
    }

    public function testTheRegisterHookNameIsDerivedFromTheAppId(): void
    {
        $fc = $this->frontController(new FrontControllerTestShell($this->coreTabs(), 'employees', 'crm'));

        $this->assertSame('crm_register_tabs', $fc->getAppShell()->getRegisterHook());
    }

    // ── output ─────────────────────────────────────────────────────────

    public function testTheMenuIsRenderedBeforeDispatch(): void
    {
        $_GET['tab'] = 'leave';

        $output = $this->captureRun();

        $this->assertStringContainsString('ksf-module-menu', $output);
        $this->assertLessThan(
            strpos($output, '[run:leave]'),
            strpos($output, 'ksf-module-menu'),
            'the menu must precede the tab body.'
        );
    }

    public function testMenuLinksCarryTheTabSelector(): void
    {
        $_GET['tab'] = 'leave';

        $output = $this->captureRun();

        $this->assertStringContainsString('hrm.php?tab=leave', $output);
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function denyingController(array $denied): AbstractFrontController
    {
        return new FrontControllerTestHarness(
            new FrontControllerTestShell($this->coreTabs()),
            'SA_ksf_FA_HRMVIEW',
            '',
            ['security_checker' => function (string $area) use ($denied): void {
                FrontControllerTestHarness::$checked[] = $area;
                if (in_array($area, $denied, true)) {
                    throw new FrontControllerSecurityException('denied: ' . $area);
                }
            }]
        );
    }

    private function captureRun(?AbstractAppShell $shell = null, ?AbstractFrontController $fc = null): string
    {
        ob_start();
        try {
            ($fc ?? $this->frontController($shell))->run();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * run() while swallowing the output a refusing request has already emitted
     * before the check fires, so the suite log stays readable.
     */
    private function runRefusing(AbstractFrontController $fc): void
    {
        ob_start();
        try {
            $fc->run();
        } finally {
            ob_get_clean();
        }
    }
}