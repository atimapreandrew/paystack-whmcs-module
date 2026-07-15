<?php
/**
 * Paystack Payment Gateway — Callback Handler
 *
 * Handles three entry paths:
 * 1. Inline JS redirect (GET with ?trxref=) — verify and apply the payment
 * 2. Standard/fallback flow (GET with ?go=standard) — server-side init
 * 3. Webhook events (POST with X-Paystack-Signature header)
 *
 * Website: https://www.paystack.com
 * Email: support@paystack.com
 */

/**
 * Detect the request scheme (https/http), honouring a forwarding proxy.
 *
 * @return string
 */
function paystack_request_scheme()
{
    $isSsl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    return $isSsl ? 'https' : 'http';
}

// Track whether this is a webhook request (no browser redirect needed).
$isWebhookRequest = false;

// Browser-initiated requests fall through to a redirect back to the invoice.
register_shutdown_function(function () use (&$isWebhookRequest) {
    // Skip when output was already sent (e.g. a die() error page) — a header
    // can't be issued any more — or when a flow already issued its own
    // redirect, which must not be clobbered.
    if ($isWebhookRequest || headers_sent()) {
        return;
    }

    foreach (headers_list() as $header) {
        if (stripos($header, 'Location:') === 0) {
            return;
        }
    }

    $invoiceId = filter_input(INPUT_GET, 'invoiceid', FILTER_SANITIZE_NUMBER_INT);
    if (!$invoiceId) {
        return;
    }

    header('Location: ' . getInvoiceUrl($invoiceId));
});


/**
 * Plugin tracker for Paystack analytics.
 */
class PaystackPluginTracker
{
    private $publicKey;
    private $pluginName;

    public function __construct($pluginName, $publicKey)
    {
        $this->pluginName = $pluginName;
        $this->publicKey = $publicKey;
    }

    public function logSuccess($transactionRef)
    {
        $url = 'https://plugin-tracker.paystackintegrations.com/log/charge_success';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'plugin_name'         => $this->pluginName,
            'transaction_reference' => $transactionRef,
            'public_key'          => $this->publicKey,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }
}


// ============================================================
// Bootstrap WHMCS
// ============================================================

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Dedicated Virtual Account helpers (only defines functions; safe to include).
require_once __DIR__ . '/../../addons/paystackdva/lib.php';

use WHMCS\Database\Capsule;

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Resolve keys
$secretKey = ($gatewayParams['testMode'] === 'on')
    ? trim($gatewayParams['testSecretKey'])
    : trim($gatewayParams['liveSecretKey']);

$publicKey = ($gatewayParams['testMode'] === 'on')
    ? trim($gatewayParams['testPublicKey'])
    : trim($gatewayParams['livePublicKey']);

$enableLogs = ($gatewayParams['gatewayLogs'] ?? '') === 'on';


// ============================================================
// Helper: Verify a transaction with Paystack API
// ============================================================

function verifyTransaction($trxref, $secretKey)
{
    $ch = curl_init();
    $txStatus = new stdClass();

    curl_setopt($ch, CURLOPT_URL, 'https://api.paystack.co/transaction/verify/' . rawurlencode($trxref));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $secretKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $txStatus->error = 'cURL error: ' . curl_error($ch);
        curl_close($ch);
        return $txStatus;
    }

    curl_close($ch);

    $body = json_decode($response);
    if (!$body || !$body->status) {
        $txStatus->error = 'Paystack API: ' . ($body->message ?? 'Unknown error');
        return $txStatus;
    }

    return $body->data;
}


// ============================================================
// Helper: Build invoice view URL for redirects
// ============================================================

/**
 * The configured WHMCS System URL (no trailing slash), or '' if unavailable.
 * Preferred over $_SERVER['HTTP_HOST'] so a spoofed Host header can't steer
 * redirects or generated callback URLs.
 */
function paystack_system_base_url()
{
    try {
        if (class_exists('\WHMCS\Config\Setting')) {
            $configured = trim((string) \WHMCS\Config\Setting::getValue('SystemURL'));
            if ($configured !== '') {
                return rtrim($configured, '/');
            }
        }
    } catch (\Exception $e) {
        // fall through
    }

    return '';
}

function getInvoiceUrl($invoiceId)
{
    $base = paystack_system_base_url();
    if ($base !== '') {
        return $base . '/viewinvoice.php?id=' . rawurlencode($invoiceId);
    }

    return paystack_request_scheme() . '://' . $_SERVER['HTTP_HOST']
        . substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/'))
        . '/../../../viewinvoice.php?id=' . rawurlencode($invoiceId);
}


// ============================================================
// Helper: Validate HMAC signature on fallback URL params
// ============================================================

function validateFallbackSignature($secretKey)
{
    $sig = filter_input(INPUT_GET, 'sig');
    if (!$sig) {
        return false;
    }

    // Rebuild the data that was signed (everything except 'sig'). Only the
    // invoice ID travels in the URL — everything else is looked up server-side.
    $data = [
        'invoiceid' => filter_input(INPUT_GET, 'invoiceid'),
        'go'        => filter_input(INPUT_GET, 'go'),
    ];

    ksort($data);
    $expected = hash_hmac('sha256', http_build_query($data), $secretKey);

    return hash_equals($expected, $sig);
}


// ============================================================
// FLOW 1: Standard/Fallback — Server-side transaction init
// ============================================================

if (strtolower(filter_input(INPUT_GET, 'go') ?? '') === 'standard') {

    $invoiceId = filter_input(INPUT_GET, 'invoiceid', FILTER_SANITIZE_NUMBER_INT);

    // Validate HMAC signature to prevent URL tampering
    if (!validateFallbackSignature($secretKey)) {
        if ($enableLogs) {
            logTransaction($gatewayModuleName, 'Fallback URL signature validation failed', 'Unsuccessful');
        }
        die('Invalid request signature. Please try again from the invoice page.');
    }

    // Look everything up server-side from the (signed) invoice ID so the
    // email, phone and amount can neither be tampered with nor leak via the URL.
    $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    if (($invoiceData['result'] ?? '') !== 'success' || ($invoiceData['status'] ?? '') !== 'Unpaid') {
        header('Location: ' . getInvoiceUrl($invoiceId));
        exit;
    }

    $client = Capsule::table('tblclients')
        ->where('id', (int) $invoiceData['userid'])
        ->first(['email', 'phonenumber', 'currency']);

    if (!$client) {
        header('Location: ' . getInvoiceUrl($invoiceId));
        exit;
    }

    // Amount due in the gateway's charge currency (mirror the link flow's
    // conversion when "Convert To For Processing" is configured).
    $amountDue = (float) $invoiceData['balance'];
    if ($gatewayParams['convertto']) {
        $amountDue = convertCurrency($amountDue, $client->currency, $gatewayParams['convertto']);
    }
    $amountInKobo = (int) round($amountDue * 100);

    if ($amountInKobo <= 0) {
        header('Location: ' . getInvoiceUrl($invoiceId));
        exit;
    }

    $email = $client->email;
    $phone = $client->phonenumber;
    $txnref = $invoiceId . '_' . time();

    $systemBase = paystack_system_base_url();
    $callbackUrl = ($systemBase !== ''
            ? $systemBase . '/modules/gateways/callback/paystack.php'
            : paystack_request_scheme() . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'])
        . '?invoiceid=' . rawurlencode($invoiceId);

    // Initialize transaction with Paystack
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.paystack.co/transaction/initialize');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'amount'       => $amountInKobo,
        'email'        => $email,
        'phone'        => $phone,
        'reference'    => $txnref,
        'callback_url' => $callbackUrl,
        'metadata'     => [
            'custom_fields' => [
                ['display_name' => 'Plugin', 'variable_name' => 'plugin', 'value' => 'whmcs'],
                ['display_name' => 'Invoice ID', 'variable_name' => 'invoice_id', 'value' => (string) $invoiceId],
            ],
        ],
    ]));
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $curlError = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    if ($curlError) {
        if ($enableLogs) {
            logTransaction($gatewayModuleName, "Transaction init failed: cURL error: $curlError", 'Unsuccessful');
        }
        die('Payment initialization failed. Please try again.');
    }

    $body = json_decode($response);
    if (!$body || !$body->status) {
        $errorMsg = $body->message ?? 'Unknown error';
        if ($enableLogs) {
            logTransaction($gatewayModuleName, "Transaction init failed: $errorMsg", 'Unsuccessful');
        }
        die('Payment initialization failed: ' . htmlspecialchars($errorMsg));
    }

    // Redirect to Paystack checkout page
    $authUrl = $body->data->authorization_url;
    header('Location: ' . $authUrl);
    exit;
}


// ============================================================
// FLOW 2: Webhook — Paystack server-to-server notification
// ============================================================

$input = @file_get_contents('php://input');
$event = json_decode($input);

if (isset($event->event) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
    $isWebhookRequest = true;

    $mode = ($gatewayParams['testMode'] === 'on') ? 'test' : 'live';

    // Validate webhook signature BEFORE logging anything derived from the
    // payload — otherwise anyone could flood the Activity Log with arbitrary
    // event names via unauthenticated POSTs.
    $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    $expectedSignature = hash_hmac('sha512', $input, $secretKey);

    if (!$signature || !hash_equals($expectedSignature, $signature)) {
        // Most often this means the webhook is configured for the OTHER mode:
        // a Test-mode webhook is signed with the test secret key and vice versa.
        // (Fixed message only — no attacker-controlled content is logged.)
        logActivity('Paystack webhook signature mismatch — check that the webhook in your '
            . 'Paystack dashboard is on the same mode (' . $mode . ') as the gateway, and that '
            . 'the matching secret key is saved in WHMCS.');
        if ($enableLogs) {
            logTransaction($gatewayModuleName, 'Webhook signature validation failed', 'Unsuccessful');
        }
        http_response_code(401);
        exit;
    }

    // Signature is genuine — record that the webhook reached the endpoint
    // (diagnostic, not gated on the Gateway Logs setting) so a missing or
    // misconfigured webhook is obvious.
    logActivity('Paystack webhook received: ' . $event->event . ' (' . $mode . ' mode)');

    // Log all webhook events if logging is enabled
    if ($enableLogs) {
        logTransaction($gatewayModuleName, [
            'event'     => $event->event,
            'reference' => $event->data->reference ?? 'N/A',
            'amount'    => ($event->data->amount ?? 0) / 100,
            'currency'  => $event->data->currency ?? 'N/A',
            'channel'   => $event->data->channel ?? 'N/A',
        ], 'Webhook: ' . $event->event);
    }

    switch ($event->event) {
        case 'charge.success':
            $trxref = $event->data->reference;

            // ---- Dedicated Virtual Account (DVA) top-up ----
            // Transfers into a client's dedicated account arrive with the
            // "dedicated_nuban" channel and a Paystack-generated reference that
            // is NOT in our "invoiceid_timestamp" format. These credit the
            // client's WHMCS funds balance instead of paying an invoice.
            //
            // IMPORTANT: the routing decision is made on the CHANNEL ONLY. A
            // client who owns a dedicated account carries the same Paystack
            // customer_code on their ordinary card/checkout invoice payments,
            // so matching on customer_code here would wrongly send invoice
            // payments to the wallet (invoice paid AND balance credited).
            $isDva = (($event->data->channel ?? '') === 'dedicated_nuban');

            if ($isDva) {
                // Verify server-side before crediting (don't trust webhook alone).
                $txStatus = verifyTransaction($trxref, $secretKey);

                if (isset($txStatus->error) || ($txStatus->status ?? '') !== 'success') {
                    logActivity('Paystack DVA: could not verify funding ' . $trxref . ' — '
                        . ($txStatus->error ?? ('status ' . ($txStatus->status ?? 'unknown'))));
                    if ($enableLogs) {
                        logTransaction($gatewayModuleName, [
                            'reference' => $trxref,
                            'error'     => $txStatus->error ?? ('status: ' . ($txStatus->status ?? 'unknown')),
                        ], 'DVA Funding Unverified');
                    }
                    http_response_code(200);
                    exit;
                }

                // The VERIFIED transaction must also be on the dedicated_nuban
                // channel — the webhook body is not trusted for routing money.
                if (($txStatus->channel ?? '') !== 'dedicated_nuban') {
                    logActivity('Paystack DVA: webhook for ' . $trxref . ' claimed dedicated_nuban '
                        . 'but the verified channel is "' . ($txStatus->channel ?? 'unknown')
                        . '" — not credited.');
                    http_response_code(200);
                    exit;
                }

                // Resolve the WHMCS client from the dedicated-account record,
                // preferring the verified customer_code over the webhook body's.
                $customerCode = $txStatus->customer->customer_code
                    ?? ($event->data->customer->customer_code ?? null);
                $receiver = $txStatus->metadata->receiver_account_number
                    ?? ($event->data->metadata->receiver_account_number ?? null);

                $dvaRow = null;
                try {
                    $query = Capsule::table('mod_paystack_dva');
                    if ($customerCode) {
                        $query->where('customer_code', $customerCode);
                    } elseif ($receiver) {
                        $query->where('account_number', $receiver);
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                    $dvaRow = $query->first();
                } catch (\Exception $e) {
                    $dvaRow = null;
                }

                if (!$dvaRow) {
                    logActivity('Paystack DVA: funding ' . $trxref . ' had no matching client '
                        . '(customer_code ' . ($customerCode ?: 'n/a') . '). Was the account generated in WHMCS?');
                    if ($enableLogs) {
                        logTransaction($gatewayModuleName, [
                            'reference'     => $trxref,
                            'customer_code' => $customerCode,
                        ], 'DVA Funding — No Matching Client');
                    }
                    http_response_code(200);
                    exit;
                }

                $fundAmount = floatval($txStatus->amount) / 100;
                $fundCurrency = $txStatus->currency ?? ($dvaRow->currency ?? 'NGN');

                // Dedupe (unique reference), the NGN-only guards, AddCredit with
                // rollback-on-failure, activity logging and the PaystackDvaFunded
                // notification hook all live in paystackdva_credit_deposit(),
                // shared with the cron poller so the two paths can never
                // double-credit the same deposit.
                $credited = function_exists('paystackdva_credit_deposit')
                    && paystackdva_credit_deposit((int) $dvaRow->userid, $trxref, $fundAmount, $fundCurrency);

                if ($credited) {
                    if ($enableLogs) {
                        logTransaction($gatewayModuleName, [
                            'reference' => $trxref,
                            'userid'    => $dvaRow->userid,
                            'amount'    => $fundAmount,
                            'currency'  => $fundCurrency,
                            'account'   => $dvaRow->account_number,
                        ], 'DVA Funding Credited');
                    }

                    $tracker = new PaystackPluginTracker('whmcs', $publicKey);
                    $tracker->logSuccess($trxref);
                }

                http_response_code(200);
                exit;
            }

            // ---- Standard invoice payment ----
            $orderDetails = explode('_', $trxref);
            $invoiceId = (int) $orderDetails[0];

            if ($invoiceId > 0) {
                // Verify the transaction server-side (don't trust webhook data alone)
                $txStatus = verifyTransaction($trxref, $secretKey);

                if (!isset($txStatus->error) && $txStatus->status === 'success') {
                    // Amount validation: compare paid amount to invoice balance
                    $paidAmount = floatval($txStatus->amount) / 100;
                    $requestedAmount = floatval($txStatus->requested_amount ?? 0) / 100;
                    if ($requestedAmount > 0) {
                        $paidAmount = $requestedAmount;
                    }

                    // Get invoice balance from WHMCS
                    try {
                        $invoice = Capsule::table('tblinvoices')
                            ->where('id', $invoiceId)
                            ->first(['total', 'status']);

                        if ($invoice && $invoice->status === 'Unpaid') {
                            // Check for existing payments to avoid duplicates
                            $existingPayment = Capsule::table('tblaccounts')
                                ->where('invoiceid', $invoiceId)
                                ->where('transid', $trxref)
                                ->first();

                            if (!$existingPayment) {
                                $fees = floatval($txStatus->fees ?? 0) / 100;

                                // Handle currency conversion if configured
                                if ($gatewayParams['convertto']) {
                                    $clientData = Capsule::table('tblclients')
                                        ->join('tblinvoices', 'tblinvoices.userid', '=', 'tblclients.id')
                                        ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
                                        ->where('tblinvoices.id', $invoiceId)
                                        ->first(['tblclients.currency as currency_id', 'tblcurrencies.code']);

                                    if ($clientData) {
                                        $paidAmount = convertCurrency($paidAmount, $gatewayParams['convertto'], $clientData->currency_id);
                                        $fees = convertCurrency($fees, $gatewayParams['convertto'], $clientData->currency_id);
                                    }
                                }

                                // Validate invoice and transaction
                                $invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
                                checkCbTransID($trxref);

                                // Apply payment
                                addInvoicePayment($invoiceId, $trxref, $paidAmount, $fees, $gatewayModuleName);

                                // Log to Paystack tracker
                                $tracker = new PaystackPluginTracker('whmcs', $publicKey);
                                $tracker->logSuccess($trxref);

                                if ($enableLogs) {
                                    logTransaction($gatewayModuleName, [
                                        'reference' => $trxref,
                                        'invoice'   => $invoiceId,
                                        'amount'    => $paidAmount,
                                        'fees'      => $fees,
                                        'channel'   => $txStatus->channel ?? 'unknown',
                                        'card_type' => $txStatus->authorization->card_type ?? 'N/A',
                                        'last4'     => $txStatus->authorization->last4 ?? 'N/A',
                                        'bank'      => $txStatus->authorization->bank ?? 'N/A',
                                    ], 'Successful');
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        if ($enableLogs) {
                            logTransaction($gatewayModuleName, 'Webhook processing error: ' . $e->getMessage(), 'Error');
                        }
                    }
                }
            }
            break;

        case 'dedicatedaccount.assign.success':
            // A dedicated account finished provisioning (async assignment).
            // Complete the client's pending record with the real account details.
            $customerCode = $event->data->customer->customer_code ?? null;
            $account = $event->data->dedicated_account ?? $event->data;

            if ($customerCode && isset($account->account_number)) {
                try {
                    $update = [
                        'account_number' => $account->account_number,
                        'account_name'   => $account->account_name ?? '',
                        'bank_name'      => $account->bank->name ?? '',
                        'bank_slug'      => $account->bank->slug ?? '',
                        'currency'       => $account->currency ?? 'NGN',
                        'status'         => 'active',
                    ];
                    if (isset($account->id)) {
                        $update['dedicated_account_id'] = (string) $account->id;
                    }
                    // Only email on the pending → active transition, so a sync
                    // generation (already emailed) isn't emailed twice.
                    $wasPending = Capsule::table('mod_paystack_dva')
                        ->where('customer_code', $customerCode)
                        ->where('status', 'pending')
                        ->value('userid');

                    Capsule::table('mod_paystack_dva')
                        ->where('customer_code', $customerCode)
                        ->update($update);

                    if ($wasPending && function_exists('paystackdva_send_account_email')) {
                        paystackdva_send_account_email((int) $wasPending);
                    }

                    if ($enableLogs) {
                        logTransaction($gatewayModuleName, [
                            'customer_code'  => $customerCode,
                            'account_number' => $account->account_number,
                        ], 'DVA Account Assigned');
                    }
                } catch (\Exception $e) {
                    if ($enableLogs) {
                        logTransaction($gatewayModuleName, 'DVA assign update error: ' . $e->getMessage(), 'Error');
                    }
                }
            }
            break;

        case 'charge.failed':
            if ($enableLogs) {
                logTransaction($gatewayModuleName, [
                    'reference'    => $event->data->reference ?? 'N/A',
                    'gateway_resp' => $event->data->gateway_response ?? 'N/A',
                    'channel'      => $event->data->channel ?? 'N/A',
                ], 'Payment Failed');
            }
            break;

        case 'refund.processed':
            if ($enableLogs) {
                logTransaction($gatewayModuleName, [
                    'reference'      => $event->data->transaction_reference ?? 'N/A',
                    'refund_amount'  => ($event->data->amount ?? 0) / 100,
                    'refund_status'  => $event->data->status ?? 'N/A',
                ], 'Refund Processed');
            }
            break;

        case 'subscription.create':
        case 'subscription.disable':
        case 'invoice.create':
        case 'invoice.update':
            // Placeholder for recurring billing integration
            break;
    }

    http_response_code(200);
    exit; // CRITICAL: Exit here to prevent fall-through to browser verification
}

// A POST carrying a Paystack signature header that did NOT parse as an event:
// log it so a malformed/blocked webhook doesn't silently fall through to the
// browser-redirect flow below.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && !empty($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'])) {
    $isWebhookRequest = true;

    // Only log when the signature is genuine — otherwise this too would be an
    // unauthenticated Activity Log flood vector.
    $expectedSignature = hash_hmac('sha512', $input, $secretKey);
    if (hash_equals($expectedSignature, (string) $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'])) {
        logActivity('Paystack webhook POST received but body could not be parsed as an event. '
            . 'Raw length: ' . strlen($input) . ' bytes.');
        http_response_code(200);
    } else {
        http_response_code(401);
    }
    exit;
}


// ============================================================
// FLOW 3: Browser redirect — User returning from Paystack checkout
// ============================================================

$invoiceIdParam = (int) filter_input(INPUT_GET, 'invoiceid', FILTER_SANITIZE_NUMBER_INT);
$trxref = filter_input(INPUT_GET, 'trxref');

if (!$trxref || !$invoiceIdParam) {
    if ($invoiceIdParam) {
        header('Location: ' . getInvoiceUrl($invoiceIdParam));
    }
    exit;
}

// The invoice a payment belongs to is encoded in the reference itself
// ("invoiceid_timestamp"). Derive it from there instead of trusting the
// separate invoiceid parameter, so a valid payment for one invoice can never
// be applied to a different one by tampering with the URL.
if (!preg_match('/^\d+_\d+$/', $trxref)) {
    header('Location: ' . getInvoiceUrl($invoiceIdParam));
    exit;
}

$invoiceId = (int) explode('_', $trxref)[0];
if ($invoiceId !== $invoiceIdParam) {
    logActivity('Paystack: invoiceid parameter (' . $invoiceIdParam . ') does not match transaction '
        . 'reference ' . $trxref . ' — using the invoice from the reference.');
}

$txStatus = verifyTransaction($trxref, $secretKey);

if (isset($txStatus->error)) {
    if ($enableLogs) {
        logTransaction($gatewayModuleName, [
            'reference' => $trxref,
            'invoice'   => $invoiceId,
            'error'     => $txStatus->error,
        ], 'Unsuccessful');
    }

    header('Location: ' . getInvoiceUrl($invoiceId));
    exit;
}

if ($txStatus->status !== 'success') {
    if ($enableLogs) {
        logTransaction($gatewayModuleName, [
            'reference' => $trxref,
            'invoice'   => $invoiceId,
            'status'    => $txStatus->status,
        ], 'Unsuccessful');
    }

    header('Location: ' . getInvoiceUrl($invoiceId));
    exit;
}

// ---- Transaction is successful ----

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
checkCbTransID($trxref);

$paidAmount = floatval($txStatus->amount) / 100;
$requestedAmount = floatval($txStatus->requested_amount ?? 0) / 100;
if ($requestedAmount > 0) {
    $paidAmount = $requestedAmount;
}
$fees = floatval($txStatus->fees ?? 0) / 100;

// Amount validation: compare what was paid vs what the invoice expects
try {
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first(['total']);

    if ($invoice) {
        $invoiceTotal = floatval($invoice->total);

        // Allow a small tolerance (0.01) for rounding differences
        // but flag significant underpayments
        if ($paidAmount < ($invoiceTotal - 0.01)) {
            // Always record underpayments in the Activity Log — this is a
            // possible tampering signal and must not depend on the Gateway
            // Logs setting being enabled.
            logActivity('Paystack: payment ' . $trxref . ' underpaid invoice ' . $invoiceId
                . ' (paid ' . $paidAmount . ' of ' . $invoiceTotal . ') — applied as a partial payment.');
            if ($enableLogs) {
                logTransaction($gatewayModuleName, [
                    'reference'      => $trxref,
                    'invoice'        => $invoiceId,
                    'paid_amount'    => $paidAmount,
                    'invoice_total'  => $invoiceTotal,
                    'warning'        => 'Amount mismatch — paid less than invoice total',
                ], 'Amount Mismatch');
            }
            // Still apply it; the invoice stays partially paid in WHMCS, which
            // is the correct behaviour for an underpayment.
        }
    }
} catch (\Exception $e) {
    // Non-fatal: if the check fails, proceed with payment anyway
    if ($enableLogs) {
        logTransaction($gatewayModuleName, 'Amount validation error: ' . $e->getMessage(), 'Warning');
    }
}

// Handle currency conversion if the gateway is configured to convert.
if ($gatewayParams['convertto']) {
    try {
        $clientData = Capsule::table('tblclients')
            ->join('tblinvoices', 'tblinvoices.userid', '=', 'tblclients.id')
            ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblinvoices.id', $invoiceId)
            ->first(['tblclients.currency as currency_id', 'tblcurrencies.code']);

        if ($clientData) {
            $paidAmount = convertCurrency($paidAmount, $gatewayParams['convertto'], $clientData->currency_id);
            $fees = convertCurrency($fees, $gatewayParams['convertto'], $clientData->currency_id);
            $paidAmount = format_as_currency($paidAmount);
            $fees = format_as_currency($fees);
        }
    } catch (\Exception $e) {
        if ($enableLogs) {
            logTransaction($gatewayModuleName, 'Currency conversion error: ' . $e->getMessage(), 'Warning');
        }
    }
}

// Apply payment to invoice
addInvoicePayment($invoiceId, $trxref, $paidAmount, $fees, $gatewayModuleName);

// Log success with payment method details
if ($enableLogs) {
    logTransaction($gatewayModuleName, [
        'reference'  => $trxref,
        'invoice'    => $invoiceId,
        'amount'     => $paidAmount,
        'fees'       => $fees,
        'channel'    => $txStatus->channel ?? 'unknown',
        'card_type'  => $txStatus->authorization->card_type ?? 'N/A',
        'last4'      => $txStatus->authorization->last4 ?? 'N/A',
        'bank'       => $txStatus->authorization->bank ?? 'N/A',
    ], 'Successful');
}

$tracker = new PaystackPluginTracker('whmcs', $publicKey);
$tracker->logSuccess($trxref);

header('Location: ' . getInvoiceUrl($invoiceId));
exit;
