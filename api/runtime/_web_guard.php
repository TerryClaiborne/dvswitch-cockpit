<?php
declare(strict_types=1);

/**
 * Ensures DVSwitch Cockpit HTTP requests are authenticated before any runtime
 * code (system commands, log scraping, etc.) executes. Safe to require_once
 * from multiple includes; PHP deduplicates by file path.
 */
require_once dirname(__DIR__) . '/security.php';
dc_security_require_authenticated();
