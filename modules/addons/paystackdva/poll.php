<?php
/**
 * Paystack Dedicated Accounts — Standalone Deposit Poller
 *
 * Runs paystackdva_poll() independently of the WHMCS cron, so you can schedule it
 * at any interval you like (e.g. every 30 seconds) instead of being tied to the
 * 5-minute WHMCS cron.
 *
 * Usage
 *   CLI (recommended):   php /path/to/whmcs/modules/addons/paystackdva/poll.php
 *   Web (with a pinger): https://yourdomain.com/modules/addons/paystackdva/poll.php?token=YOUR_TOKEN
 *
 * Web access requires the "Poll Secret Token" set in the addon configuration
 * (Setup -> Addon Modules -> Paystack Dedicated Accounts -> Configure). CLI runs
 * need no token.
 *
 * Schedule every minute with one OS crontab line:
 *   * * * * * /usr/bin/php /path/to/whmcs/modules/addons/paystackdva/poll.php >/dev/null 2>&1
 *
 * Each run makes a single Paystack API call (the latest successful transactions),
 * so once-a-minute polling is ~1,440 calls/day total regardless of account count.
 */

require __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // Web request — require the configured secret token.
    $provided = isset($_GET['token']) ? (string) $_GET['token'] : '';
    $stored = '';
    try {
        $stored = (string) Capsule::table('tbladdonmodules')
            ->where('module', 'paystackdva')
            ->where('setting', 'pollToken')
            ->value('value');
    } catch (\Exception $e) {
        $stored = '';
    }

    if ($stored === '' || !hash_equals($stored, $provided)) {
        http_response_code(403);
        die('Forbidden');
    }
}

$credited = paystackdva_poll();

header('Content-Type: text/plain');
echo 'Paystack DVA poll complete. Credited ' . (int) $credited . " new deposit(s).\n";
