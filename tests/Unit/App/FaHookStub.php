<?php
/**
 * FA hook dispatcher stub for the AbstractFrontController unit tests.
 *
 * AbstractAppShell::boot() fires hook_invoke_all('<appId>_register_tabs', $data)
 * but only when the function exists, and no other test in this suite boots an
 * app shell — so declaring it here does not alter any existing test's behaviour.
 * It is a strict no-op unless a test registers a responder for that exact hook
 * name, which is how the third-party tab path is exercised.
 *
 * Not named *Test.php, so PHPUnit does not collect it as a test case.
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit
 * @since   1.1.0
 */

declare(strict_types=1);

namespace {
    if (!function_exists('hook_invoke_all')) {
        /**
         * @param string $method Hook name
         * @param array  $data  Hook payload, passed by reference
         * @return void
         */
        function hook_invoke_all($method, &$data, $opts = null)
        {
            \Ksfraser\FrontAccounting\Common\Tests\Unit\FaHookStub::invoke((string) $method, $data);
        }
    }
}

namespace ksfraser\FrontAccounting\Common\Tests\Unit {
    /**
     * Responder registry behind the hook_invoke_all() stub above.
     */
    final class FaHookStub
    {
        /** @var array<string, array<int, callable>> */
        private static $responders = [];

        /**
         * Register a responder for a hook name.
         *
         * @param string   $hook      Hook name (e.g. 'hrm_register_tabs')
         * @param callable $responder function (array $data): void
         * @return void
         */
        public static function respondTo(string $hook, callable $responder): void
        {
            self::$responders[$hook][] = $responder;
        }

        /**
         * Drop every responder (called from each test's setUp).
         *
         * @return void
         */
        public static function reset(): void
        {
            self::$responders = [];
        }

        /**
         * Invoke every responder for a hook.
         *
         * @param string $hook
         * @param array  $data By reference, as FA's dispatcher does
         * @return void
         */
        public static function invoke(string $hook, &$data): void
        {
            foreach (self::$responders[$hook] ?? [] as $responder) {
                $responder($data);
            }
        }
    }
}