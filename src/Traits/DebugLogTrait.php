<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

/**
 * DebugLogTrait
 *
 * Writes structured debug log entries directly to a file (no hook dependency,
 * no ksf_FA_Logging required). Ideal for debugging module hooks and views
 * where the standard logging chain may not be available.
 *
 * Usage:
 *   class hooks_my_module extends hooks {
 *       use \ksfraser\FrontAccounting\Common\Traits\DebugLogTrait;
 *
 *       protected $logFile = '/tmp/my_module_debug.log';
 *       protected $logPrefix = 'MyModule';
 *
 *       public function someHook() {
 *           $this->log('info', 'hook called', array('foo' => 'bar'));
 *       }
 *   }
 *
 * @since 1.2.0
 */
trait DebugLogTrait
{
    /** @var string Absolute path to the debug log file */
    protected $logFile = '/tmp/fa_debug.log';

    /** @var string Prefix prepended to each log line */
    protected $logPrefix = 'DEBUG';

    /**
     * Write a structured debug log entry directly to $this->logFile.
     *
     * @param string $level   Log level (debug, info, warning, error, critical)
     * @param string $message Human-readable message
     * @param array  $context Additional context data (will be JSON-encoded)
     * @return void
     */
    protected function log(string $level, string $message, array $context = array()): void
    {
        $entry = array(
            'ts' => date('Y-m-d\TH:i:s'),
            'level' => $level,
            'module' => $this->logPrefix,
            'message' => $message,
            'context' => $context,
            'pid' => getmypid(),
        );

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = date('Y-m-d\TH:i:s') . ' ' . $level . ' ' . $this->logPrefix . ' ' . $message;
        }

        @file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Convenience: log at debug level.
     */
    protected function logDebug(string $message, array $context = array()): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Convenience: log at info level.
     */
    protected function logInfo(string $message, array $context = array()): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Convenience: log at warning level.
     */
    protected function logWarning(string $message, array $context = array()): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Convenience: log at error level.
     */
    protected function logError(string $message, array $context = array()): void
    {
        $this->log('error', $message, $context);
    }
}
