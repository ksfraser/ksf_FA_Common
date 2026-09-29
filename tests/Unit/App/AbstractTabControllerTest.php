<?php
/**
 * AbstractTabController unit tests — the overridable rendering seams.
 *
 * Focus is the seams a concrete tab subclass uses to vary the summary table's
 * row actions without re-implementing renderSummaryTable(): the action labels
 * and the delete confirmation. These matter where the row action is a domain
 * verb rather than a literal delete (e.g. the HRM employees tab maps delete to
 * EmployeeService::terminate()).
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit
 * @since 1.0.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit;

use ksfraser\FrontAccounting\Common\App\AbstractTabController;
use PHPUnit\Framework\TestCase;

class AbstractTabControllerTest extends TestCase
{
    /**
     * Minimal concrete controller exercising the data-access contract.
     */
    private function makeController(): AbstractTabController
    {
        return new class extends AbstractTabController {
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
                return [
                    ['widget_id' => '1', 'name' => 'Alpha'],
                    ['widget_id' => '2', 'name' => 'Beta'],
                ];
            }

            protected function countRows(): int
            {
                return 2;
            }

            protected function findRecord(string $pk): ?array
            {
                return $pk === '1' ? ['widget_id' => '1', 'name' => 'Alpha'] : null;
            }

            protected function createRecord(array $data)
            {
                return 3;
            }

            protected function updateRecord(string $pk, array $data): void
            {
            }

            protected function deleteRecord(string $pk): void
            {
            }
        };
    }

    /**
     * Invoke the protected renderSummaryTable() and capture its output.
     */
    private function renderSummary(AbstractTabController $controller): string
    {
        $method = new \ReflectionMethod(AbstractTabController::class, 'renderSummaryTable');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($controller);

        return (string) ob_get_clean();
    }

    public function testDefaultActionLabelsAreEditAndDelete(): void
    {
        $html = $this->renderSummary($this->makeController());

        $this->assertStringContainsString('>Edit<', $html);
        $this->assertStringContainsString('>Delete<', $html);
    }

    public function testDefaultDeleteConfirmMessage(): void
    {
        $html = $this->renderSummary($this->makeController());

        $this->assertStringContainsString("return confirm('Delete this record?');", $html);
    }

    public function testActionLabelsAreWiredThroughToRenderedButtons(): void
    {
        // A subclass overriding only the label seams must not need to
        // re-implement renderSummaryTable().
        $controller = new class extends AbstractTabController {
            protected function getPkField(): string
            {
                return 'employee_id';
            }

            protected function getFieldMetadata(): array
            {
                return [
                    'labelPlural' => 'Employees',
                    'pk'          => 'employee_id',
                    'fields'      => [
                        'employee_id' => ['label' => 'ID', 'type' => 'text', 'showInTable' => true],
                    ],
                ];
            }

            protected function listRows(int $page, int $perPage): array
            {
                return [['employee_id' => '7']];
            }

            protected function countRows(): int
            {
                return 1;
            }

            protected function findRecord(string $pk): ?array
            {
                return null;
            }

            protected function createRecord(array $data)
            {
                return 1;
            }

            protected function updateRecord(string $pk, array $data): void
            {
            }

            protected function deleteRecord(string $pk): void
            {
            }

            protected function getActionLabels(): array
            {
                return ['edit' => 'Edit', 'delete' => 'Terminate'];
            }

            protected function getDeleteConfirmMessage(): string
            {
                return 'Terminate this employment record?';
            }
        };

        $html = $this->renderSummary($controller);

        $this->assertStringContainsString('>Terminate<', $html);
        $this->assertStringContainsString('>Edit<', $html);
        $this->assertStringNotContainsString('>Delete<', $html);
        $this->assertStringContainsString("return confirm('Terminate this employment record?');", $html);
        // The POST name must stay `delete_7` so handlePost() still routes to deleteRecord().
        $this->assertStringContainsString('name="delete_7"', $html);
    }

    /**
     * Build a controller whose metadata carries a checkbox field, so
     * collectFormValues() can be exercised against a simulated POST.
     */
    private function makeCheckboxController(): AbstractTabController
    {
        return new class extends AbstractTabController {
            public $captured = [];

            protected function getPkField(): string
            {
                return 'grade_id';
            }

            protected function getFieldMetadata(): array
            {
                return [
                    'labelPlural' => 'Grades',
                    'pk'          => 'grade_id',
                    'fields'      => [
                        'grade_id'    => ['label' => 'ID', 'type' => 'text', 'showInTable' => true],
                        'grade_name'  => ['label' => 'Name', 'type' => 'text', 'showInForm' => true],
                        'is_active'   => [
                            'label'    => 'Active',
                            'type'     => 'checkbox',
                            'default'  => 1,
                            'showInForm' => true,
                        ],
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
                $this->captured = $data;
                return 1;
            }

            protected function updateRecord(string $pk, array $data): void
            {
                $this->captured = $data;
            }

            protected function deleteRecord(string $pk): void
            {
            }
        };
    }

    private function collect(AbstractTabController $controller): array
    {
        $method = new \ReflectionMethod(AbstractTabController::class, 'collectFormValues');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    public function testUncheckedCheckboxCollectsAsZeroNotTheDefault(): void
    {
        // Regression: an unchecked HTML checkbox is absent from $_POST. Falling
        // back to the field default (1) meant is_active could never be turned
        // off — unchecking "Active" still saved the row as active.
        $_POST = ['grade_name' => 'Senior'];
        $data  = $this->collect($this->makeCheckboxController());
        $_POST = [];

        $this->assertSame(0, $data['is_active']);
        $this->assertSame('Senior', $data['grade_name']);
    }

    public function testCheckedCheckboxCollectsAsOne(): void
    {
        $_POST = ['grade_name' => 'Senior', 'is_active' => 'on'];
        $data  = $this->collect($this->makeCheckboxController());
        $_POST = [];

        $this->assertSame(1, $data['is_active']);
    }

    public function testNonCheckboxFieldStillFallsBackToItsDefault(): void
    {
        $_POST = ['is_active' => 'on'];
        $data  = $this->collect($this->makeCheckboxController());
        $_POST = [];

        // grade_name has no default and no POST value -> '' as before.
        $this->assertSame('', $data['grade_name']);
    }

    public function testPrimaryKeyIsNeverCollected(): void
    {
        $_POST = ['grade_id' => '99', 'is_active' => 'on'];
        $data  = $this->collect($this->makeCheckboxController());
        $_POST = [];

        $this->assertArrayNotHasKey('grade_id', $data);
    }
}
