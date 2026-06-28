<?php
/**
 * ksf_FA_Common — Logging Functions
 *
 * Procedural logging API that fires FA hooks when available, with
 * a direct error_log() fallback for CLI scripts and tests.
 *
 * Calling convention:
 *   ksf_log('calendar', 'info', 'Event created', ['event_id' => 42]);
 *   ksf_log_critical('payroll', 'Payrun failed', ['run_id' => 7]);
 *
 * @package KsfCommon
 */

if (!function_exists('ksf_log')) {

    /**
     * Core logging function.
     *
     * Builds a standardised payload and dispatches via FA hook_invoke_all
     * ('ksf_log', ...) if the FA hook system is available.  Falls back to
     * PHP error_log() when hooks are absent (e.g. isolated unit tests).
     *
     * The 'ksf_log' hook is handled by ksf_FA_Logging module's hooks class
     * which performs level-threshold checks and persists the entry.
     *
     * @param string $module  Module identifier  (e.g. 'calendar', 'attachments')
     * @param string $level   Severity level     (debug, info, warning, error, critical)
     * @param string $message Human-readable log message
     * @param array  $context Structured data payload (MUST be serialisable)
     *
     * @return void
     */
    function ksf_log($module, $level, $message, array $context = [])
    {
        $payload = [
            'module'    => $module,
            'level'     => strtolower($level),
            'message'   => $message,
            'context'   => $context,
            'timestamp' => date('Y-m-d\TH:i:s'),
            'hostname'  => '',
            'pid'       => 0,
            'user_id'   => '',
        ];

        if (function_exists('php_uname')) {
            $payload['hostname'] = php_uname('n');
        }
        if (function_exists('getmypid')) {
            $payload['pid'] = getmypid();
        }
        if (isset($_SESSION['wa_current_user']->id)) {
            $payload['user_id'] = $_SESSION['wa_current_user']->id;
        }

        if (function_exists('hook_invoke_all')) {
            hook_invoke_all('ksf_log', $payload);
        } else {
            $line = sprintf(
                "[%s] %-9s %-16s %s %s",
                $payload['timestamp'],
                '[' . $payload['level'] . ']',
                '[' . $payload['module'] . ']',
                $payload['message'],
                !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
            );
            error_log(trim($line));
        }
    }
}

// Convenience helpers ---------------------------------------------------------

if (!function_exists('ksf_log_debug')) {
    function ksf_log_debug($module, $message, array $context = [])
    {
        ksf_log($module, 'debug', $message, $context);
    }
}
if (!function_exists('ksf_log_info')) {
    function ksf_log_info($module, $message, array $context = [])
    {
        ksf_log($module, 'info', $message, $context);
    }
}
if (!function_exists('ksf_log_warning')) {
    function ksf_log_warning($module, $message, array $context = [])
    {
        ksf_log($module, 'warning', $message, $context);
    }
}
if (!function_exists('ksf_log_error')) {
    function ksf_log_error($module, $message, array $context = [])
    {
        ksf_log($module, 'error', $message, $context);
    }
}
if (!function_exists('ksf_log_critical')) {
    function ksf_log_critical($module, $message, array $context = [])
    {
        ksf_log($module, 'critical', $message, $context);
    }
}
