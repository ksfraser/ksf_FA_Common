<?php
/**
 * FlashMessage
 *
 * Session-based flash messages for FA modules. Replaces the fragile
 * meta-refresh + $_GET['message'] pattern with proper redirects that
 * survive F5 reload.
 *
 * Usage (standalone pages):
 *   FlashMessage::set('Budget saved successfully.');
 *   header('Location: quickbudget.php');
 *   exit;
 *
 *   // In your view:
 *   FlashMessage::display();
 *
 * Usage (class-based hooks via trait):
 *   class hooks_my_module extends hooks {
 *       use \ksfraser\FrontAccounting\Common\Traits\FlashMessageTrait;
 *   }
 *
 * @since 1.1.0
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Utils;

class FlashMessage
{
    private static string $sessionKey = 'ksf_flash_message';

    /**
     * Set a flash message to be displayed on the next page load.
     */
    public static function set(string $message, string $type = 'info'): void
    {
        $_SESSION[self::$sessionKey] = [
            'text' => $message,
            'type' => $type,
        ];
    }

    /**
     * Get and clear the current flash message.
     *
     * @return array{text: string, type: string}|null
     */
    public static function get(): ?array
    {
        if (isset($_SESSION[self::$sessionKey])) {
            $msg = $_SESSION[self::$sessionKey];
            unset($_SESSION[self::$sessionKey]);
            return $msg;
        }
        return null;
    }

    /**
     * Display the flash message as an FA alert div, if one exists.
     */
    public static function display(): void
    {
        $msg = self::get();
        if ($msg === null) {
            return;
        }

        $typeClass = 'alert-info';
        switch ($msg['type']) {
            case 'success':  $typeClass = 'alert-success';  break;
            case 'error':
            case 'danger':   $typeClass = 'alert-danger';   break;
            case 'warning':  $typeClass = 'alert-warning';  break;
        }

        echo '<div class="alert ' . $typeClass . '">'
            . htmlspecialchars($msg['text']) . '</div>';
    }

    /**
     * Set flash message and redirect. Convenience one-liner.
     */
    public static function redirect(string $message, string $url, string $type = 'info'): void
    {
        self::set($message, $type);
        header('Location: ' . $url);
        exit;
    }
}
