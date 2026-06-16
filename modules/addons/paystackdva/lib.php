<?php
/**
 * Paystack Dedicated Accounts — Library (all function definitions)
 *
 * Companion to the Paystack gateway (modules/gateways/paystack.php).
 *
 * Lets each client generate their own permanent Paystack Dedicated Virtual
 * Account (Dedicated NUBAN). Money transferred into that account is credited
 * to the client's WHMCS funds/credit balance.
 *
 * This file holds EVERY function — the WHMCS addon entry points
 * (paystackdva_config/_activate/_deactivate/_output) and the shared helpers. It is
 * pulled in via require_once by the addon entry file (paystackdva.php), the hooks,
 * the gateway callback and the poller, so the definitions load exactly once. The
 * thin paystackdva.php entry file defines nothing itself to avoid redeclaration
 * when WHMCS plain-includes it for the admin page.
 *
 * NOTES:
 *  - Dedicated NUBAN must be enabled on the merchant's Paystack account. Live mode
 *    requires KYC/business approval; test mode uses preferred_bank "test-bank".
 *  - Dedicated accounts are NGN-only, so credited clients should use NGN in WHMCS.
 *  - API keys and test/live mode are read from the existing "paystack" gateway
 *    config — there is no separate key storage here.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Addon configuration.
 *
 * @return array
 */
function paystackdva_config()
{
    return [
        'name'        => 'Paystack Dedicated Accounts',
        'description' => 'Clients generate a personal Paystack dedicated bank account (NGN) and fund '
            . 'their WHMCS balance by transfer. Also reconciles "stuck" invoice payments. '
            . 'Uses the API keys from your Paystack gateway — no keys are entered here.',
        'version'     => '1.1.0',
        'author'      => 'Paystack',
        'language'    => 'english',
        'fields'      => [
            // ---- Feature ----
            'enableFeature' => [
                'FriendlyName' => 'Enable Dedicated Accounts',
                'Type'         => 'dropdown',
                'Options'      => 'on,off',
                'Default'      => 'on',
                'Description'  => 'Master switch. When On, clients can generate and view their funding '
                    . 'account in the client area. When Off, the client-area panel is hidden and no new '
                    . 'accounts can be generated (existing balances are unaffected).',
            ],
            'liveBank' => [
                'FriendlyName' => 'Live Provider Bank',
                'Type'         => 'dropdown',
                'Options'      => 'titan-paystack,wema-bank',
                'Default'      => 'titan-paystack',
                'Description'  => 'Bank used to provision dedicated accounts in live mode. '
                    . 'Test mode always uses "test-bank". (Requires Dedicated NUBAN enabled on your '
                    . 'Paystack account.)',
            ],
            'requireActiveService' => [
                'FriendlyName' => 'Require an Active Service',
                'Type'         => 'dropdown',
                'Options'      => 'on,off',
                'Default'      => 'on',
                'Description'  => 'Only let clients who have at least one active product/service (or '
                    . 'active domain) generate a virtual account — the same kind of restriction WHMCS '
                    . 'applies to Add Funds. When On, the generate button is disabled (server-side) with '
                    . 'a notice until the client has an active service.',
            ],
            // ---- Reconciliation / polling ----
            'reconcileInvoices' => [
                'FriendlyName' => 'Reconcile Invoice Payments',
                'Type'         => 'dropdown',
                'Options'      => 'on,off',
                'Default'      => 'on',
                'Description'  => 'During polling, also catch successful Paystack invoice payments that '
                    . 'never completed in the browser (customer closed the page, lost connection, etc.) '
                    . 'and mark the matching WHMCS invoice paid. Uses the same API calls — no extra cost.',
            ],
            'pollToken' => [
                'FriendlyName' => 'Poll Secret Token (web cron only)',
                'Type'         => 'text',
                'Size'         => '40',
                'Description'  => 'Only needed if you trigger the poller over the web '
                    . '(poll.php?token=THIS) from an external uptime/cron service. Leave blank when '
                    . 'using the WHMCS cron or a server (command-line) cron.',
            ],
        ],
    ];
}

/**
 * Activation hook — create the database tables.
 *
 * @return array
 */
function paystackdva_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_paystack_dva')) {
            Capsule::schema()->create('mod_paystack_dva', function ($table) {
                $table->increments('id');
                $table->integer('userid')->unsigned()->unique();
                $table->string('customer_code')->nullable();
                $table->string('customer_id')->nullable();
                $table->string('dedicated_account_id')->nullable();
                $table->string('account_number');
                $table->string('account_name');
                $table->string('bank_name')->nullable();
                $table->string('bank_slug')->nullable();
                $table->string('currency', 8)->default('NGN');
                $table->string('status', 32)->default('active');
                $table->dateTime('created_at');
            });
        } else {
            // Migrations for installs created before later features.
            if (!Capsule::schema()->hasColumn('mod_paystack_dva', 'dedicated_account_id')) {
                Capsule::schema()->table('mod_paystack_dva', function ($table) {
                    $table->string('dedicated_account_id')->nullable()->after('customer_code');
                });
            }
            if (!Capsule::schema()->hasColumn('mod_paystack_dva', 'customer_id')) {
                Capsule::schema()->table('mod_paystack_dva', function ($table) {
                    $table->string('customer_id')->nullable()->after('customer_code');
                });
            }
        }

        if (!Capsule::schema()->hasTable('mod_paystack_dva_tx')) {
            Capsule::schema()->create('mod_paystack_dva_tx', function ($table) {
                $table->increments('id');
                $table->string('reference')->unique();
                $table->integer('userid')->unsigned()->index();
                $table->decimal('amount', 16, 2)->default(0);
                $table->string('currency', 8)->default('NGN');
                $table->dateTime('created_at');
            });
        }

        // Key/value store for poller state (e.g. the last-polled timestamp).
        if (!Capsule::schema()->hasTable('mod_paystack_dva_state')) {
            Capsule::schema()->create('mod_paystack_dva_state', function ($table) {
                $table->string('name')->unique();
                $table->text('value')->nullable();
            });
        }

        // Create the client email templates (idempotent).
        paystackdva_create_email_template();
        paystackdva_create_account_email_template();

        return [
            'status'      => 'success',
            'description' => 'Paystack Dedicated Accounts tables and email template created. Make sure '
                . 'the Paystack gateway is active and Dedicated NUBAN is enabled on your Paystack account.',
        ];
    } catch (\Exception $e) {
        return [
            'status'      => 'error',
            'description' => 'Unable to create tables: ' . $e->getMessage(),
        ];
    }
}

/**
 * Deactivation hook — keep the tables (they hold funding records & dedupe data).
 *
 * @return array
 */
function paystackdva_deactivate()
{
    return [
        'status'      => 'success',
        'description' => 'Paystack Dedicated Accounts deactivated. Account records were preserved.',
    ];
}

/**
 * The name of the client email template used for funds-added notifications.
 */
if (!defined('PAYSTACKDVA_EMAIL_TEMPLATE')) {
    define('PAYSTACKDVA_EMAIL_TEMPLATE', 'Funds Added Confirmation');
}

/**
 * Create the "Funds Added" general email template if it doesn't already exist.
 * The template is editable afterwards under Setup -> Email Templates.
 *
 * @return void
 */
function paystackdva_create_email_template()
{
    try {
        $exists = Capsule::table('tblemailtemplates')
            ->where('name', PAYSTACKDVA_EMAIL_TEMPLATE)
            ->exists();

        if ($exists) {
            return;
        }

        $message = "<p>Hi {\$client_first_name},</p>\n"
            . "<p>We have received your payment and <strong>{\$funds_amount}</strong> has been "
            . "added to your account balance via {\$funds_method}.</p>\n"
            . "<p>Your available credit balance is now <strong>{\$funds_balance}</strong>.</p>\n"
            . "{if \$funds_reference}<p style=\"color:#777;\">Reference: {\$funds_reference}</p>{/if}\n"
            . "<p>You can apply this balance to any outstanding or future invoices from your "
            . "client area at any time.</p>\n"
            . "<p>Thank you.</p>";

        Capsule::table('tblemailtemplates')->insert([
            'type'      => 'general',
            'name'      => PAYSTACKDVA_EMAIL_TEMPLATE,
            'subject'   => 'Funds Added to Your Account',
            'message'   => $message,
            'fromname'  => '',
            'fromemail' => '',
            'disabled'  => 0,
            'custom'    => 1,
            'language'  => '',
            'copyto'    => '',
            'plaintext' => 0,
        ]);
    } catch (\Exception $e) {
        // Non-fatal — notifications fall back to a custom email if the template is missing.
    }
}

/**
 * The name of the client email template sent when an account is provisioned.
 */
if (!defined('PAYSTACKDVA_ACCOUNT_TEMPLATE')) {
    define('PAYSTACKDVA_ACCOUNT_TEMPLATE', 'Dedicated Account Created');
}

/**
 * Create the "Dedicated Account Created" email template if it doesn't exist.
 *
 * @return void
 */
function paystackdva_create_account_email_template()
{
    try {
        $exists = Capsule::table('tblemailtemplates')
            ->where('name', PAYSTACKDVA_ACCOUNT_TEMPLATE)
            ->exists();

        if ($exists) {
            return;
        }

        $message = "<p>Hi {\$client_first_name},</p>\n"
            . "<p>Your dedicated virtual account is ready. Transfer to it from any bank to top up your "
            . "account balance (NGN only). Funds are credited to your balance automatically after each "
            . "transfer.</p>\n"
            . "<table cellpadding=\"6\" style=\"border-collapse:collapse;\">\n"
            . "<tr><td><strong>Account Number</strong></td><td>{\$dva_account_number}</td></tr>\n"
            . "<tr><td><strong>Bank</strong></td><td>{\$dva_bank}</td></tr>\n"
            . "<tr><td><strong>Account Name</strong></td><td>{\$dva_account_name}</td></tr>\n"
            . "</table>\n"
            . "<p>You can view this account any time from your client area.</p>\n"
            . "<p>Thank you.</p>";

        Capsule::table('tblemailtemplates')->insert([
            'type'      => 'general',
            'name'      => PAYSTACKDVA_ACCOUNT_TEMPLATE,
            'subject'   => 'Your Dedicated Virtual Account',
            'message'   => $message,
            'fromname'  => '',
            'fromemail' => '',
            'disabled'  => 0,
            'custom'    => 1,
            'language'  => '',
            'copyto'    => '',
            'plaintext' => 0,
        ]);
    } catch (\Exception $e) {
        // Non-fatal — generation still works without the email.
    }
}

/**
 * Email a client their dedicated account details. No-op if the account isn't
 * active yet (no account number) or the client can't be found.
 *
 * @param int $userid
 * @return void
 */
function paystackdva_send_account_email($userid)
{
    $account = paystackdva_get_account($userid);
    if (!$account || empty($account->account_number)) {
        return;
    }

    try {
        $client = Capsule::table('tblclients')->where('id', (int) $userid)->first(['firstname']);
        if (!$client) {
            return;
        }

        $vars = [
            'dva_account_number' => $account->account_number,
            'dva_bank'           => $account->bank_name,
            'dva_account_name'   => $account->account_name,
            'dva_currency'       => $account->currency ?: 'NGN',
        ];

        $templateName = PAYSTACKDVA_ACCOUNT_TEMPLATE;
        $templateExists = Capsule::table('tblemailtemplates')->where('name', $templateName)->exists();

        if ($templateExists) {
            sendMessage($templateName, (int) $userid, $vars);
            return;
        }

        // Fallback custom email.
        $message = '<p>Hi ' . htmlspecialchars($client->firstname) . ',</p>'
            . '<p>Your dedicated virtual account is ready (NGN only). Transfer to it to top up your '
            . 'balance — funds are credited automatically.</p>'
            . '<p><strong>Account Number:</strong> ' . htmlspecialchars($account->account_number) . '<br>'
            . '<strong>Bank:</strong> ' . htmlspecialchars($account->bank_name) . '<br>'
            . '<strong>Account Name:</strong> ' . htmlspecialchars($account->account_name) . '</p>'
            . '<p>Thank you.</p>';

        localAPI('SendEmail', [
            'id'            => (int) $userid,
            'customtype'    => 'general',
            'customsubject' => 'Your Dedicated Virtual Account',
            'custommessage' => $message,
        ]);
    } catch (\Exception $e) {
        logActivity('Paystack DVA account email error: ' . $e->getMessage());
    }
}

/**
 * Whether the "require an active service" restriction is enabled.
 *
 * @return bool
 */
function paystackdva_require_active_service()
{
    return paystackdva_setting('requireActiveService', 'on') !== 'off';
}

/**
 * Whether a client has at least one active service or domain.
 *
 * @param int $userid
 * @return bool
 */
function paystackdva_client_eligible($userid)
{
    $userid = (int) $userid;
    if ($userid <= 0) {
        return false;
    }
    try {
        $hasService = Capsule::table('tblhosting')
            ->where('userid', $userid)
            ->where('domainstatus', 'Active')
            ->exists();
        if ($hasService) {
            return true;
        }
        return Capsule::table('tbldomains')
            ->where('userid', $userid)
            ->where('status', 'Active')
            ->exists();
    } catch (\Exception $e) {
        // On error, fail open so we never wrongly block a paying client.
        return true;
    }
}

/**
 * Whether the given client may generate a dedicated account right now.
 *
 * @param int $userid
 * @return bool
 */
function paystackdva_can_generate($userid)
{
    if (!paystackdva_require_active_service()) {
        return true;
    }
    return paystackdva_client_eligible($userid);
}

/**
 * Disable and remove ALL dedicated accounts (global kill switch action).
 *
 * @return array ['removed' => int, 'failed' => int]
 */
function paystackdva_disable_all_accounts()
{
    $removed = 0;
    $failed = 0;
    try {
        $ids = Capsule::table('mod_paystack_dva')->pluck('userid');
    } catch (\Exception $e) {
        return ['removed' => 0, 'failed' => 0];
    }

    foreach ($ids as $uid) {
        $res = paystackdva_delete_account((int) $uid);
        if (isset($res['error'])) {
            $failed++;
        } else {
            $removed++;
        }
    }

    if ($removed > 0 || $failed > 0) {
        logActivity('Paystack DVA: admin disabled ALL dedicated accounts ('
            . $removed . ' removed, ' . $failed . ' failed).');
    }

    return ['removed' => $removed, 'failed' => $failed];
}

/**
 * Admin area output. NOTE: WHMCS captures echoed output here — it must be
 * echo'd, not returned.
 *
 * @param array $vars
 * @return void
 */
function paystackdva_output($vars)
{
    $modulelink = $vars['modulelink'] ?? 'addonmodules.php?module=paystackdva';
    $token = function_exists('generate_token') ? generate_token('form') : '';

    // ---- Actions ----
    if (!empty($_POST['paystackdva_sync'])) {
        $credited = paystackdva_poll();
        echo '<div class="alert alert-' . ($credited > 0 ? 'success' : 'info') . '">'
            . 'Sync complete. ' . (int) $credited . ' new payment(s)/deposit(s) processed.</div>';
    }

    if (!empty($_POST['paystackdva_disable_user'])) {
        $uid = (int) $_POST['paystackdva_disable_user'];
        $result = paystackdva_delete_account($uid);
        if (isset($result['error'])) {
            echo '<div class="alert alert-danger">Could not disable the account for client #'
                . $uid . ': ' . htmlspecialchars($result['error']) . '</div>';
        } else {
            logActivity('Paystack DVA: admin disabled the dedicated account for client #' . $uid);
            echo '<div class="alert alert-success">The dedicated account for client #' . $uid
                . ' has been disabled on Paystack and removed.</div>';
        }
    }

    if (!empty($_POST['paystackdva_disable_all'])) {
        $r = paystackdva_disable_all_accounts();
        echo '<div class="alert alert-warning">Disabled all dedicated accounts: '
            . (int) $r['removed'] . ' removed'
            . ($r['failed'] > 0 ? ', ' . (int) $r['failed'] . ' failed (see Activity Log)' : '')
            . '.</div>';
    }

    // ---- Status panel ----
    $keys = paystackdva_keys();
    $featureOn = paystackdva_enabled();
    $reconcileOn = (paystackdva_setting('reconcileInvoices', 'on') !== 'off');
    $mode = !empty($keys['secret']) ? ($keys['testMode'] ? 'Test' : 'Live') : 'Not configured';

    $count = 0;
    $funded = 0;
    try {
        $count = Capsule::table('mod_paystack_dva')->count();
        $funded = Capsule::table('mod_paystack_dva_tx')->sum('amount');
    } catch (\Exception $e) {
        // ignore
    }

    $lastPollTs = (int) paystackdva_get_state('last_poll_ts', 0);
    $lastPoll = $lastPollTs > 0 ? date('Y-m-d H:i:s', $lastPollTs) . ' (server time)' : 'never';

    $onLabel = '<span class="label label-success">Enabled</span>';
    $offLabel = '<span class="label label-default">Disabled</span>';

    echo '<div class="row"><div class="col-sm-6">'
        . '<h3>Status</h3>'
        . '<table class="table table-condensed">'
        . '<tr><td><strong>Client feature</strong></td><td>' . ($featureOn ? $onLabel : $offLabel)
        . ' <span class="text-muted">(set "Enable Dedicated Accounts" under this addon\'s '
        . 'Configure screen)</span></td></tr>'
        . '<tr><td><strong>Paystack mode</strong></td><td>' . $mode . '</td></tr>'
        . '<tr><td><strong>Invoice reconciliation</strong></td><td>' . ($reconcileOn ? $onLabel : $offLabel)
        . '</td></tr>'
        . '<tr><td><strong>Accounts provisioned</strong></td><td>' . (int) $count . '</td></tr>'
        . '<tr><td><strong>Total funded</strong></td><td>' . number_format((float) $funded, 2) . '</td></tr>'
        . '<tr><td><strong>Last poll</strong></td><td>' . htmlspecialchars($lastPoll) . '</td></tr>'
        . '</table>'
        . '</div><div class="col-sm-6">'
        . '<h3>Controls</h3>'
        . '<form method="post" action="' . htmlspecialchars($modulelink) . '" style="margin-bottom:10px;">'
        . $token
        . '<input type="hidden" name="paystackdva_sync" value="1">'
        . '<button type="submit" class="btn btn-primary">Sync now</button>'
        . ' <span class="text-muted">Pull recent deposits &amp; stuck invoice payments from Paystack.</span>'
        . '</form>'
        . '<form method="post" action="' . htmlspecialchars($modulelink) . '" '
        . 'onsubmit="return confirm(\'Disable and remove ALL dedicated accounts? Every NUBAN will be '
        . 'closed on Paystack. Clients can generate new ones unless you also untick the gateway toggle.\');">'
        . $token
        . '<input type="hidden" name="paystackdva_disable_all" value="1">'
        . '<button type="submit" class="btn btn-danger">Disable all accounts</button>'
        . ' <span class="text-muted">Closes every dedicated NUBAN and clears the records.</span>'
        . '</form>'
        . '<p class="text-muted" style="margin-top:10px;">To fully switch the feature off for everyone, '
        . 'set <strong>Enable Dedicated Accounts</strong> to Off on this addon\'s <strong>Configure</strong> '
        . 'screen (this hides the client-area panel and blocks new account generation).</p>'
        . '</div></div>';

    // ---- Accounts table ----
    try {
        $rows = Capsule::table('mod_paystack_dva')
            ->leftJoin('tblclients', 'tblclients.id', '=', 'mod_paystack_dva.userid')
            ->orderBy('mod_paystack_dva.id', 'desc')
            ->get([
                'mod_paystack_dva.userid',
                'mod_paystack_dva.account_number',
                'mod_paystack_dva.bank_name',
                'mod_paystack_dva.account_name',
                'mod_paystack_dva.status',
                'mod_paystack_dva.currency',
                'mod_paystack_dva.created_at',
                'tblclients.firstname',
                'tblclients.lastname',
            ]);
    } catch (\Exception $e) {
        $rows = [];
    }

    echo '<h3>Dedicated Accounts</h3>'
        . '<table class="datatable" width="100%"><thead><tr>'
        . '<th>Client</th><th>Account Number</th><th>Bank</th><th>Account Name</th>'
        . '<th>Status</th><th>Funded</th><th>Created</th><th>Action</th></tr></thead><tbody>';

    if (count($rows) === 0) {
        echo '<tr><td colspan="8" class="text-center">No dedicated accounts yet.</td></tr>';
    }

    foreach ($rows as $row) {
        $userFunded = 0;
        try {
            $userFunded = Capsule::table('mod_paystack_dva_tx')
                ->where('userid', $row->userid)->sum('amount');
        } catch (\Exception $e) {
            // ignore
        }

        $clientName = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? '')) ?: '—';
        $clientLink = '<a href="clientssummary.php?userid=' . (int) $row->userid . '">'
            . htmlspecialchars($clientName) . ' (#' . (int) $row->userid . ')</a>';
        $statusBadge = ($row->status === 'active' && !empty($row->account_number))
            ? '<span class="label label-success">Active</span>'
            : '<span class="label label-warning">Pending</span>';

        echo '<tr>'
            . '<td>' . $clientLink . '</td>'
            . '<td>' . htmlspecialchars($row->account_number ?: '—') . '</td>'
            . '<td>' . htmlspecialchars($row->bank_name ?: '—') . '</td>'
            . '<td>' . htmlspecialchars($row->account_name ?: '—') . '</td>'
            . '<td>' . $statusBadge . '</td>'
            . '<td>' . number_format((float) $userFunded, 2) . ' ' . htmlspecialchars($row->currency ?: 'NGN') . '</td>'
            . '<td>' . htmlspecialchars($row->created_at ?: '—') . '</td>'
            . '<td><form method="post" action="' . htmlspecialchars($modulelink) . '" style="margin:0;" '
            . 'onsubmit="return confirm(\'Disable and delete this dedicated account?\');">'
            . $token
            . '<input type="hidden" name="paystackdva_disable_user" value="' . (int) $row->userid . '">'
            . '<button type="submit" class="btn btn-danger btn-sm">Disable</button>'
            . '</form></td>'
            . '</tr>';
    }

    echo '</tbody></table>';
}


// ============================================================
// Shared helpers (reused by the sidebar hook and the callback)
// ============================================================

/**
 * Resolve the active Paystack secret key + mode from the gateway config.
 * Single source of truth — no separate key storage in this addon.
 *
 * Only the API keys/mode come from the gateway (shared with checkout); all
 * dedicated-account settings live in this addon's own configuration.
 *
 * @return array ['secret' => string, 'testMode' => bool, 'liveBank' => string]
 */
function paystackdva_keys()
{
    if (!function_exists('getGatewayVariables')) {
        require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
    }

    $gw = getGatewayVariables('paystack');
    $testMode = (($gw['testMode'] ?? '') === 'on');

    $secret = $testMode
        ? trim($gw['testSecretKey'] ?? '')
        : trim($gw['liveSecretKey'] ?? '');

    return [
        'secret'   => $secret,
        'testMode' => $testMode,
        'liveBank' => paystackdva_setting('liveBank', 'titan-paystack'),
    ];
}

/**
 * Whether the dedicated-accounts feature is enabled.
 *
 * Requires the Paystack gateway to have a usable secret key AND the addon's
 * "Enable Dedicated Accounts" setting to be On.
 *
 * @return bool
 */
function paystackdva_enabled()
{
    $keys = paystackdva_keys();
    if (empty($keys['secret'])) {
        return false;
    }
    return paystackdva_setting('enableFeature', 'on') !== 'off';
}

/**
 * Small cURL wrapper for the Paystack API.
 *
 * Mirrors paystack_api_request() in the gateway file but is kept local so this
 * addon (and the callback) don't have to cross-include the gateway module.
 *
 * @param string $url
 * @param string $secretKey
 * @param string $method
 * @param array  $postData
 * @return object Response data object, or an object with ->error set.
 */
function paystackdva_api($url, $secretKey, $method = 'GET', $postData = [])
{
    $ch = curl_init();
    $result = new stdClass();

    $headers = ['Authorization: Bearer ' . $secretKey];

    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    } elseif ($method !== 'GET') {
        // DELETE and other verbs.
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($postData)) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        }
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $result->error = 'cURL error: ' . curl_error($ch);
        curl_close($ch);
        return $result;
    }

    curl_close($ch);

    $body = json_decode($response);
    if (!$body || !$body->status) {
        $result->error = 'Paystack API: ' . ($body->message ?? 'Unknown error');
        return $result;
    }

    return $body->data;
}

/**
 * Fetch a client's stored dedicated account row.
 *
 * @param int $userid
 * @return object|null
 */
function paystackdva_get_account($userid)
{
    try {
        return Capsule::table('mod_paystack_dva')
            ->where('userid', (int) $userid)
            ->first();
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Provision a dedicated account for a client.
 *
 * Ensures a Paystack customer exists, then assigns a dedicated account and
 * persists it. Idempotent: if the client already has an account, returns it.
 *
 * @param array $client ['id','email','firstname','lastname','phonenumber']
 * @return array ['account' => object] on success, ['error' => string] on failure.
 */
function paystackdva_create_account($client)
{
    $userid = (int) $client['id'];

    // Idempotency — never provision twice for the same client.
    $existing = paystackdva_get_account($userid);
    if ($existing) {
        return ['account' => $existing, 'existing' => true];
    }

    $keys = paystackdva_keys();
    if (empty($keys['secret'])) {
        return ['error' => 'Paystack gateway is not configured. Please contact support.'];
    }

    // 1) Create (or fetch existing) Paystack customer for this client.
    //    Creating with an existing email returns the existing customer record.
    $customer = paystackdva_api(
        'https://api.paystack.co/customer',
        $keys['secret'],
        'POST',
        [
            'email'      => $client['email'],
            'first_name' => $client['firstname'] ?? '',
            'last_name'  => $client['lastname'] ?? '',
            'phone'      => $client['phonenumber'] ?? '',
        ]
    );

    if (isset($customer->error)) {
        return ['error' => 'Could not create customer: ' . $customer->error];
    }

    $customerCode = $customer->customer_code ?? null;
    if (!$customerCode) {
        return ['error' => 'Paystack did not return a customer code.'];
    }

    // 2) Assign a dedicated account.
    $preferredBank = $keys['testMode'] ? 'test-bank' : $keys['liveBank'];

    $dva = paystackdva_api(
        'https://api.paystack.co/dedicated_account',
        $keys['secret'],
        'POST',
        [
            'customer'       => $customerCode,
            'preferred_bank' => $preferredBank,
        ]
    );

    if (isset($dva->error)) {
        return ['error' => 'Could not assign a dedicated account: ' . $dva->error];
    }

    // Paystack may return the account synchronously (account_number present) or
    // queue it for async provisioning (no account_number yet — completed later
    // via the dedicatedaccount.assign.success webhook). Either way the customer
    // already exists on Paystack, so we ALWAYS persist the customer_code so an
    // incoming transfer can be matched and credited. We never error out here once
    // the customer/account request succeeded.
    $accountNumber = $dva->account_number ?? null;
    $isPending = empty($accountNumber);

    // 3) Persist (idempotent insert keyed on userid).
    try {
        Capsule::table('mod_paystack_dva')->insert([
            'userid'               => $userid,
            'customer_code'        => $customerCode,
            'customer_id'          => isset($customer->id) ? (string) $customer->id : null,
            'dedicated_account_id' => isset($dva->id) ? (string) $dva->id : null,
            'account_number'       => $accountNumber ?: '',
            'account_name'         => $dva->account_name ?? '',
            'bank_name'            => $dva->bank->name ?? '',
            'bank_slug'            => $dva->bank->slug ?? '',
            'currency'             => $dva->currency ?? 'NGN',
            'status'               => $isPending ? 'pending' : 'active',
            'created_at'           => date('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {
        // Possible race: another request inserted first. Return whatever exists.
        $row = paystackdva_get_account($userid);
        if ($row) {
            return ['account' => $row, 'pending' => ($row->status === 'pending')];
        }
        return ['error' => 'Could not save the dedicated account: ' . $e->getMessage()];
    }

    return [
        'account' => paystackdva_get_account($userid),
        'pending' => $isPending,
    ];
}

/**
 * Admin action: disable a client's dedicated account.
 *
 * Closes (deactivates) the dedicated NUBAN on Paystack and removes the local
 * record so the client can generate a fresh one. Funding history in
 * mod_paystack_dva_tx is preserved.
 *
 * @param int $userid
 * @return array ['ok' => true] on success, ['error' => string] on failure.
 */
function paystackdva_delete_account($userid)
{
    $account = paystackdva_get_account($userid);
    if (!$account) {
        return ['error' => 'No dedicated account found for this client.'];
    }

    $keys = paystackdva_keys();

    // Resolve the dedicated account id (needed by Paystack's deactivate endpoint).
    $dedicatedId = $account->dedicated_account_id ?? null;

    if (!$dedicatedId && !empty($keys['secret']) && !empty($account->customer_code)) {
        $customer = paystackdva_api(
            'https://api.paystack.co/customer/' . rawurlencode($account->customer_code),
            $keys['secret']
        );
        if (!isset($customer->error)) {
            if (isset($customer->dedicated_account->id)) {
                $dedicatedId = $customer->dedicated_account->id;
            } elseif (!empty($customer->dedicated_accounts[0]->id)) {
                $dedicatedId = $customer->dedicated_accounts[0]->id;
            }
        }
    }

    // Deactivate on Paystack (releases the NUBAN). DELETE /dedicated_account/:id
    if ($dedicatedId && !empty($keys['secret'])) {
        $res = paystackdva_api(
            'https://api.paystack.co/dedicated_account/' . rawurlencode($dedicatedId),
            $keys['secret'],
            'DELETE'
        );
        if (isset($res->error)) {
            return ['error' => 'Paystack could not deactivate the account: ' . $res->error];
        }
    }

    try {
        Capsule::table('mod_paystack_dva')->where('userid', (int) $userid)->delete();
    } catch (\Exception $e) {
        return ['error' => 'Could not remove the local record: ' . $e->getMessage()];
    }

    return ['ok' => true];
}

/**
 * Credit a single dedicated-account deposit to a client's WHMCS balance.
 *
 * Idempotent: the unique reference in mod_paystack_dva_tx prevents double credits,
 * so the webhook and the poller can both call this safely for the same deposit.
 *
 * @param int    $userid
 * @param string $reference Paystack transaction reference
 * @param float  $amount    Amount in major units (e.g. naira, not kobo)
 * @param string $currency
 * @return bool True if newly credited, false if duplicate or failed.
 */
function paystackdva_credit_deposit($userid, $reference, $amount, $currency = 'NGN')
{
    $userid = (int) $userid;
    if ($userid <= 0 || empty($reference) || $amount <= 0) {
        return false;
    }

    // Dedupe — claim the reference first. A duplicate key means it's already done.
    try {
        Capsule::table('mod_paystack_dva_tx')->insert([
            'reference'  => $reference,
            'userid'     => $userid,
            'amount'     => $amount,
            'currency'   => $currency,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {
        return false; // already processed
    }

    $api = localAPI('AddCredit', [
        'clientid'    => $userid,
        'description' => 'Paystack dedicated account funding (' . $reference . ')',
        'amount'      => $amount,
    ]);

    if (($api['result'] ?? '') !== 'success') {
        // Roll back the claim so a later run can retry.
        try {
            Capsule::table('mod_paystack_dva_tx')->where('reference', $reference)->delete();
        } catch (\Exception $e) {
            // ignore
        }
        logActivity('Paystack DVA: AddCredit failed for client ' . $userid
            . ' (' . $reference . ') — ' . ($api['message'] ?? 'unknown error'));
        return false;
    }

    logActivity('Paystack DVA: credited ' . $amount . ' ' . $currency
        . ' to client ' . $userid . ' (' . $reference . ')');

    if (function_exists('run_hook')) {
        run_hook('PaystackDvaFunded', [
            'userid'   => $userid,
            'amount'   => $amount,
            'currency' => $currency,
            'transid'  => $reference,
        ]);
    }

    return true;
}

/**
 * Read one of this addon's configuration settings.
 *
 * @param string $name
 * @param string $default
 * @return string
 */
function paystackdva_setting($name, $default = '')
{
    try {
        $value = Capsule::table('tbladdonmodules')
            ->where('module', 'paystackdva')
            ->where('setting', $name)
            ->value('value');
        return ($value === null || $value === '') ? $default : $value;
    } catch (\Exception $e) {
        return $default;
    }
}

/**
 * Apply a "stuck" Paystack invoice payment to its WHMCS invoice.
 *
 * Catches the case where a customer paid through the normal Paystack checkout but
 * never returned to the callback (closed the page, lost connection, …) and the
 * webhook didn't fire, leaving the invoice unpaid despite a successful charge.
 *
 * Only acts when: the reference is our "invoiceid_timestamp" format, the invoice
 * exists and is still Unpaid, and the transaction hasn't already been recorded.
 *
 * @param object $tx            Transaction object from the Paystack list
 * @param array  $gatewayParams getGatewayVariables('paystack')
 * @return bool True if a payment was applied.
 */
function paystackdva_apply_invoice_payment($tx, $gatewayParams)
{
    $reference = $tx->reference ?? '';

    // Must match the reference format the gateway generates: digits_digits.
    if (!preg_match('/^\d+_\d+$/', $reference)) {
        return false;
    }
    $invoiceId = (int) explode('_', $reference)[0];
    if ($invoiceId <= 0) {
        return false;
    }

    // Invoice must exist and still be unpaid.
    try {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['id', 'status']);
    } catch (\Exception $e) {
        return false;
    }
    if (!$invoice || $invoice->status !== 'Unpaid') {
        return false;
    }

    // Dedupe: skip if this transaction was already recorded against the invoice.
    try {
        if (Capsule::table('tblaccounts')->where('transid', $reference)->exists()) {
            return false;
        }
    } catch (\Exception $e) {
        return false;
    }

    // Ensure WHMCS payment helpers are available in cron/CLI context.
    if (!function_exists('addInvoicePayment')) {
        require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
        require_once __DIR__ . '/../../../includes/invoicefunctions.php';
    }

    $paidAmount = floatval($tx->amount ?? 0) / 100;
    $fees = floatval($tx->fees ?? 0) / 100;
    if ($paidAmount <= 0) {
        return false;
    }

    // Mirror the callback's currency conversion when the gateway converts.
    if (!empty($gatewayParams['convertto']) && function_exists('convertCurrency')) {
        try {
            $clientData = Capsule::table('tblclients')
                ->join('tblinvoices', 'tblinvoices.userid', '=', 'tblclients.id')
                ->where('tblinvoices.id', $invoiceId)
                ->first(['tblclients.currency as currency_id']);
            if ($clientData) {
                $paidAmount = convertCurrency($paidAmount, $gatewayParams['convertto'], $clientData->currency_id);
                $fees = convertCurrency($fees, $gatewayParams['convertto'], $clientData->currency_id);
            }
        } catch (\Exception $e) {
            // fall back to raw amounts
        }
    }

    $module = $gatewayParams['paymentmethod'] ?? 'paystack';

    // addInvoicePayment marks the invoice paid (if covered) and runs all the
    // normal post-payment automation (receipt email, service activation, hooks).
    addInvoicePayment($invoiceId, $reference, $paidAmount, $fees, $module);

    logActivity('Paystack reconcile: applied payment ' . $reference . ' to invoice '
        . $invoiceId . ' (' . $paidAmount . ').');

    return true;
}

/**
 * Read a value from the poller state store.
 *
 * @param string $name
 * @param mixed  $default
 * @return mixed
 */
function paystackdva_get_state($name, $default = null)
{
    try {
        $value = Capsule::table('mod_paystack_dva_state')->where('name', $name)->value('value');
        return ($value === null) ? $default : $value;
    } catch (\Exception $e) {
        return $default;
    }
}

/**
 * Write a value to the poller state store.
 *
 * @param string $name
 * @param string $value
 * @return void
 */
function paystackdva_set_state($name, $value)
{
    try {
        $exists = Capsule::table('mod_paystack_dva_state')->where('name', $name)->exists();
        if ($exists) {
            Capsule::table('mod_paystack_dva_state')->where('name', $name)->update(['value' => $value]);
        } else {
            Capsule::table('mod_paystack_dva_state')->insert(['name' => $name, 'value' => $value]);
        }
    } catch (\Exception $e) {
        // ignore
    }
}

/**
 * Poll Paystack for new activity and apply it: dedicated-account deposits (credit
 * the client's funds) and "stuck" invoice payments (mark the invoice paid). Both
 * are handled from the same single set of API calls.
 *
 * Reliable fallback to webhooks. Uses a moving time window so nothing is ever
 * missed, no matter how many deposits/payments occur between runs:
 *  - fetches successful transactions in [last_poll - buffer, now + buffer],
 *  - pages through ALL of them (not just the latest 100),
 *  - filters to "dedicated_nuban" deposits and matches each to a client,
 *  - advances the stored cursor only after a successful fetch.
 *
 * The buffer absorbs clock/timezone skew and indexing lag; the unique-reference
 * dedupe in paystackdva_credit_deposit() makes the overlap harmless. So it
 * catches everything while still being light (typically one page per run).
 *
 * @return int Number of deposits newly credited this run.
 */
function paystackdva_poll()
{
    $keys = paystackdva_keys();
    if (empty($keys['secret'])) {
        return 0;
    }

    // Map of Paystack customer_code => account row, for our clients only.
    try {
        $rows = Capsule::table('mod_paystack_dva')
            ->whereNotNull('customer_code')
            ->get(['userid', 'customer_code', 'currency']);
    } catch (\Exception $e) {
        return 0;
    }

    $byCode = [];
    foreach ($rows as $r) {
        if (!empty($r->customer_code)) {
            $byCode[$r->customer_code] = $r;
        }
    }

    // Invoice reconciliation is independent of dedicated accounts.
    $reconcileInvoices = (paystackdva_setting('reconcileInvoices', 'on') !== 'off');
    $gatewayParams = $reconcileInvoices ? getGatewayVariables('paystack') : [];

    // If there are no dedicated accounts AND invoice reconciliation is off, there
    // is nothing to poll — don't waste an API call.
    if (empty($byCode) && !$reconcileInvoices) {
        return 0;
    }

    // Build the start of the time window. First run looks back 24h; afterwards
    // from the last cursor minus a buffer that absorbs timezone/clock skew and
    // indexing lag. We leave the end open (no "to") so the very newest deposits
    // are always included — anything not seen this run is re-covered next run.
    $pollStart = time();
    $buffer = 3600; // 1 hour
    $cursor = (int) paystackdva_get_state('last_poll_ts', 0);
    $fromTs = ($cursor > 0) ? ($cursor - $buffer) : ($pollStart - 86400);

    $from = rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $fromTs));

    $credited = 0;
    $page = 1;
    $maxPages = 50; // safety cap (5,000 transactions)
    $got = 0;
    $success = false;

    do {
        $url = 'https://api.paystack.co/transaction?status=success&perPage=100'
            . '&from=' . $from . '&page=' . $page;
        $txns = paystackdva_api($url, $keys['secret']);

        if (isset($txns->error) || !is_array($txns)) {
            break;
        }
        $success = true;
        $got = count($txns);

        foreach ($txns as $tx) {
            if (($tx->status ?? '') !== 'success') {
                continue;
            }

            if (($tx->channel ?? '') === 'dedicated_nuban') {
                // Dedicated-account deposit → credit the client's funds balance.
                $code = $tx->customer->customer_code ?? null;
                if (!$code || !isset($byCode[$code])) {
                    continue; // not one of our dedicated-account clients
                }

                $acct = $byCode[$code];
                $reference = $tx->reference ?? null;
                $amount = floatval($tx->amount ?? 0) / 100;
                $currency = $tx->currency ?? ($acct->currency ?? 'NGN');

                if (paystackdva_credit_deposit((int) $acct->userid, $reference, $amount, $currency)) {
                    $credited++;
                }
            } elseif ($reconcileInvoices) {
                // Any other channel → reconcile a "stuck" invoice payment.
                if (paystackdva_apply_invoice_payment($tx, $gatewayParams)) {
                    $credited++;
                }
            }
        }

        $page++;
    } while ($got === 100 && $page <= $maxPages);

    // Advance the cursor only if at least one page fetched successfully, so a
    // network error doesn't skip the window — the next run retries it.
    if ($success) {
        paystackdva_set_state('last_poll_ts', (string) $pollStart);
    }

    return $credited;
}

