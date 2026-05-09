<?php
declare(strict_types=1);

/**
 * Ensures HTTP requests are authenticated before privileged actions (e.g. service restarts).
 * Safe to require_once; PHP deduplicates by file path.
 */
require_once dirname(__DIR__) . '/security.php';
dc_security_require_authenticated();
