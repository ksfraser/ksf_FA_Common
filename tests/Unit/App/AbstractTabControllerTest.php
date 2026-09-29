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
}
