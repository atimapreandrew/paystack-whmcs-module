<?php
/**
 * Paystack Dedicated Accounts — WHMCS Addon Module (entry point)
 *
 * This is the file WHMCS loads for the addon. It deliberately defines NOTHING
 * itself — all functions (the WHMCS entry points paystackdva_config/_activate/
 * _deactivate/_output AND the shared helpers) live in lib.php, which is included
 * here and by the addon's hooks/callback/poller via require_once.
 *
 * Why: WHMCS auto-loads the addon's hooks.php on every page, and SEPARATELY does
 * a plain include() of this file when its admin page is opened. If the functions
 * lived here, that second plain include would redeclare them (fatal). Keeping the
 * definitions in a require_once'd lib.php means they load exactly once no matter
 * how many times this entry file is included.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib.php';
