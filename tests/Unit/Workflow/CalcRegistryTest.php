<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\CalcRegistry;
use PHPUnit\Framework\TestCase;

class CalcRegistryTest extends TestCase
{
    public function testRegisterAndResolve(): void
    {
        $registry = new CalcRegistry();
        $registry->register('double', function (array $dto, array $ctx) {
            return $dto['amount'] * 2;
        });

        $this->assertTrue($registry->has('double'));
        $result = $registry->call('double', ['amount' => 5]);
        $this->assertSame(10, $result);
    }

    public function testFactoryIsLazyAndCached(): void
    {
        $registry = new CalcRegistry();
        $calls    = 0;
        $registry->registerFactory('lazy', function () use (&$calls) {
            $calls++;
            return function (array $dto, array $ctx) {
                return 'resolved';
            };
        });

        $this->assertTrue($registry->has('lazy'));
        $this->assertSame(0, $calls);

        $this->assertSame('resolved', $registry->call('lazy', []));
        $this->assertSame('resolved', $registry->call('lazy', []));
        $this->assertSame(1, $calls);
    }

    public function testUnknownResolverThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CalcRegistry())->call('nonexistent', []);
    }

    public function testHasReturnsFalseForUnknown(): void
    {
        $this->assertFalse((new CalcRegistry())->has('nonexistent'));
    }

    public function testResolveWithMethodNameOnArray(): void
    {
        $registry = new CalcRegistry();
        $obj      = new class {
            public function multiply(array $dto, array $ctx)
            {
                return $dto['n'] * 3;
            }
        };
        $registry->register('svc', [$obj, 'multiply']);

        $this->assertSame(6, $registry->call('svc', ['n' => 2]));
    }

    public function testContextPassthrough(): void
    {
        $registry = new CalcRegistry();
        $registry->register('ctx_read', function (array $dto, array $ctx) {
            return $ctx['extra'] ?? null;
        });

        $this->assertSame('value', $registry->call('ctx_read', [], ['extra' => 'value']));
    }
}