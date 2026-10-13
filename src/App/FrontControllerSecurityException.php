<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\App;

use RuntimeException;

/**
 * FrontControllerSecurityException — raised when AbstractFrontController cannot
 * establish or enforce a security area for the tab it is about to render.
 *
 * AbstractFrontController fails closed: a controller instance cannot be built
 * without a fallback security area, and run() always enforces one before any
 * output is dispatched. This exception is what surfaces when that guarantee
 * cannot be met — no tab resolves, or the runtime offers no way to check access
 * (FA's check_page_security() is absent, e.g. a CLI/test host with no session).
 *
 * PHP 7.3 compatible.
 *
 * @package KsfCommon\App
 * @since   1.1.0
 *
 * @UML Note: APP_TAB_ARCHITECTURE.md §11 (host page = thin router)
 * @BABOK Related: FR-HRM-001 (app shell front controller)
 */
final class FrontControllerSecurityException extends RuntimeException
{
}