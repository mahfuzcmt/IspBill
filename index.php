<?php
/**
 * NetPulse entry point.
 *
 * Bare URL (no _route query) → admin login.
 * Everything else falls through to the standard PHPNuxBill router.
 */

if (empty($_GET['_route']) && empty($_POST['_route'])) {
    // Bare URL → admin login. Path-only Location header so the browser
    // keeps the current scheme + host (works for any IP / domain / proxy).
    header('Location: index.php?_route=admin', true, 302);
    exit;
}

require('system/boot.php');
App::_run();
