<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\App;

use RuntimeException;

/**
 * FrontControllerRoutingException — raised when AbstractFrontController cannot
 * resolve the request to a tab.
 *
 * Raised when the requested tab (and the shell's default view, and the first
 * registered tab) all fail to resolve, i.e. the application shell has no tabs
 * at all. The alternative — rendering an empty shell — is a silent
 * fail-open, so this is an exception instead.
 *
 * PHP 7.3 compatible.
 *
 * @package KsfCommon\App
 * @since   1.1.0
 *
 * @UML Note: APP_TAB_ARCHITECTURE.md §11 (host page = thin router)
 * @BABOK Related: FR-HRM-001 (app shell front controller)
 */
final class FrontControllerRoutingException extends RuntimeException
{
}