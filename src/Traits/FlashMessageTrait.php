<?php
/**
 * FlashMessageTrait
 *
 * Convenience trait for class-based FA hooks. Delegates to FlashMessage static class.
 *
 * Usage:
 *   class hooks_my_module extends hooks {
 *       use \ksfraser\FrontAccounting\Common\Traits\FlashMessageTrait;
 *
 *       function some_action() {
 *           $this->flashRedirect('Budget saved.', 'quickbudget.php');
 *       }
 *   }
 *
 *   // In your page view:
 *   $this->flashDisplay();
 *
 * @since 1.1.0
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

use ksfraser\FrontAccounting\Common\Utils\FlashMessage;

trait FlashMessageTrait
{
    protected function flashSet(string $message, string $type = 'info'): void
    {
        FlashMessage::set($message, $type);
    }

    protected function flashGet(): ?array
    {
        return FlashMessage::get();
    }

    protected function flashDisplay(): void
    {
        FlashMessage::display();
    }

    protected function flashRedirect(string $message, string $url, string $type = 'info'): void
    {
        FlashMessage::redirect($message, $url, $type);
    }
}
