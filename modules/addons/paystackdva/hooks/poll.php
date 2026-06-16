<?php
/**
 * Paystack Dedicated Accounts — Deposit Poller (cron)
 *
 * Reliable fallback to the webhook. On every WHMCS cron run it pulls each
 * dedicated-account customer's successful transactions from Paystack and credits
 * any new deposits. Idempotent, so it never double-credits even alongside the
 * webhook.
 *
 * WHMCS's system cron is intended to run every 5 minutes; AfterCronJob fires on
 * each run, so deposits are picked up within minutes even if the webhook fails.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/../lib.php';

add_hook('AfterCronJob', 1, function ($vars) {
    if (!function_exists('paystackdva_poll')) {
        return;
    }
    try {
        $credited = paystackdva_poll();
        if ($credited > 0) {
            logActivity('Paystack poller: processed ' . $credited . ' new payment(s)/deposit(s).');
        }
    } catch (\Exception $e) {
        logActivity('Paystack DVA poller error: ' . $e->getMessage());
    }
});
