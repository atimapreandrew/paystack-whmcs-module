<?php
/**
 * Paystack Dedicated Accounts — Hooks entry point.
 *
 * WHMCS automatically loads this file for ACTIVE addon modules, in both the admin
 * and client areas and during cron. Keeping all hooks here (instead of loose files
 * in /includes/hooks) means the whole feature ships as one folder and its hooks
 * enable/disable together with the addon.
 *
 * The registrations are split into focused partials for readability.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib.php';

require_once __DIR__ . '/hooks/sidebar.php';        // client-area panel + generate action
require_once __DIR__ . '/hooks/admin.php';          // admin client-summary panel + disable
require_once __DIR__ . '/hooks/notifications.php';  // "funds added" client emails
require_once __DIR__ . '/hooks/poll.php';           // cron deposit/invoice reconciliation
