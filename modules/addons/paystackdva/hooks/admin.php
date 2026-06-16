<?php
/**
 * Paystack Dedicated Accounts — Admin Client Summary
 *
 * Shows the client's dedicated account (and total funded through it) on the
 * client's Summary page in the WHMCS admin area, via the AdminAreaClientSummaryPage
 * hook. Loaded by the addon's hooks.php when the addon is active.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../lib.php';

add_hook('AdminAreaClientSummaryPage', 1, function ($vars) {
    // $vars['userid'] is the client being viewed; fall back to the admin URL
    // (clientssummary.php?userid=X) just in case.
    $userid = (int) ($vars['userid'] ?? $_REQUEST['userid'] ?? 0);
    if ($userid <= 0) {
        return '';
    }

    // Handle the disable request here (on the summary page itself) so it works
    // without the admin needing addon-module access, and uses the core page's
    // own CSRF protection.
    $flash = '';
    if (!empty($_POST['paystackdva_disable_user'])
        && (int) $_POST['paystackdva_disable_user'] === $userid) {
        $result = paystackdva_delete_account($userid);
        if (isset($result['error'])) {
            $flash = '<div class="alert alert-danger" style="font-size:12px;">'
                . 'Could not disable: ' . htmlspecialchars($result['error']) . '</div>';
        } else {
            logActivity('Paystack DVA: admin disabled the dedicated account for client #' . $userid);
            return '<div class="successbox" style="font-size:12px;">'
                . 'Paystack dedicated account disabled. The client can now generate a new one.</div>';
        }
    }

    $account = paystackdva_get_account($userid);
    if (!$account) {
        return $flash;
    }

    $funded = 0;
    try {
        $funded = Capsule::table('mod_paystack_dva_tx')
            ->where('userid', $userid)
            ->sum('amount');
    } catch (\Exception $e) {
        // ignore
    }

    $statusLabel = ($account->status === 'active' && !empty($account->account_number))
        ? '<span class="label label-success">Active</span>'
        : '<span class="label label-warning">Pending</span>';

    $acctNo = htmlspecialchars($account->account_number ?: '—');
    $bank = htmlspecialchars($account->bank_name ?: '—');
    $currency = htmlspecialchars($account->currency ?: 'NGN');
    $fundedText = number_format((float) $funded, 2) . ' ' . $currency;

    // WHMCS rejects admin POSTs without its CSRF token, so include it.
    $token = function_exists('generate_token') ? generate_token('form') : '';

    return $flash . '
    <div class="clients-summary-panel" style="margin-top:12px;font-size:12px;line-height:1.7;">
        <strong>Paystack Virtual Account</strong> ' . $statusLabel . '
        <br><span class="text-muted">Account</span> ' . $acctNo
        . ' &middot; ' . $bank . '
        <br><span class="text-muted">Funded</span> ' . $fundedText . '
        <form method="post" action="" '
        . 'onsubmit="return confirm(\'Disable and delete this dedicated account? '
        . 'The NUBAN will be closed on Paystack and the client can generate a new one.\');"'
        . ' style="display:inline;margin-left:6px;">
            ' . $token . '
            <input type="hidden" name="paystackdva_disable_user" value="' . $userid . '">
            <button type="submit" class="btn btn-xs btn-default" style="color:#c0392b;">Disable</button>
        </form>
    </div>';
});
