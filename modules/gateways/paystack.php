<?php
/**
 * Paystack Payment Gateway for WHMCS
 *
 * Website: https://www.paystack.com
 * Email: support@paystack.com
 */

if (!defined("WHMCS")) {
    die("<!-- Silence. -->");
}

use WHMCS\Database\Capsule;

/**
 * Helper: Detect if current request is over SSL.
 *
 * @return bool
 */
function paystack_is_ssl()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/**
 * Helper: Build base URL for callbacks.
 *
 * @return string
 */
function paystack_base_url()
{
    $scheme = paystack_is_ssl() ? 'https' : 'http';
    $uri = str_replace('/admin/', '/', $_SERVER['REQUEST_URI']);
    $basePath = substr($uri, 0, strrpos($uri, '/'));

    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath;
}

/**
 * Helper: Get active keys based on test/live mode.
 *
 * @param array $params Gateway params
 * @return array ['secret' => string, 'public' => string]
 */
function paystack_get_keys($params)
{
    if ($params['testMode'] === 'on') {
        return [
            'secret' => trim($params['testSecretKey']),
            'public' => trim($params['testPublicKey']),
        ];
    }

    return [
        'secret' => trim($params['liveSecretKey']),
        'public' => trim($params['livePublicKey']),
    ];
}

/**
 * Helper: Generate HMAC signature for fallback URL params.
 *
 * @param array  $data      Data to sign
 * @param string $secretKey Secret key
 * @return string
 */
function paystack_sign_params($data, $secretKey)
{
    ksort($data);
    return hash_hmac('sha256', http_build_query($data), $secretKey);
}

/**
 * Helper: Make a cURL request to Paystack API.
 *
 * @param string $url       Full API URL
 * @param string $secretKey Secret key for auth
 * @param string $method    HTTP method (GET or POST)
 * @param array  $postData  POST body (optional)
 * @return object           Response object with ->error or API data
 */
function paystack_api_request($url, $secretKey, $method = 'GET', $postData = [])
{
    $ch = curl_init();
    $result = new stdClass();

    $headers = ['Authorization: Bearer ' . $secretKey];

    if ($method === 'POST' && !empty($postData)) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $result->error = "cURL error: " . curl_error($ch);
        curl_close($ch);
        return $result;
    }

    curl_close($ch);

    $body = json_decode($response);
    if (!$body || !$body->status) {
        $result->error = "Paystack API: " . ($body->message ?? 'Unknown error');
        return $result;
    }

    return $body->data;
}

/**
 * Supported currencies.
 *
 * @return array
 */
function paystack_supported_currencies()
{
    return ['NGN', 'USD', 'GHS', 'ZAR', 'EGP', 'XOF', 'KES', 'RWF'];
}

/**
 * Define Paystack gateway configuration options.
 *
 * @return array
 */
function paystack_config()
{
    $callbackUrl = paystack_base_url() . '/modules/gateways/callback/paystack.php';

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Paystack',
        ],
        'webhook' => [
            'FriendlyName' => 'Webhook URL',
            'Type' => 'yesno',
            'Description' => 'Copy and paste this URL into your Paystack Dashboard webhook settings: '
                . '<br><code>' . htmlspecialchars($callbackUrl) . '</code>',
            'Default' => '',
        ],
        'gatewayLogs' => [
            'FriendlyName' => 'Gateway Logs',
            'Type' => 'yesno',
            'Description' => 'Enable detailed gateway transaction logging',
            'Default' => '0',
        ],
        'testMode' => [
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Enable test mode (uses test API keys)',
            'Default' => '0',
        ],
        'hideNonNgn' => [
            'FriendlyName' => 'Hide for Non-NGN Currencies',
            'Type' => 'yesno',
            'Description' => 'Hide Paystack from the payment options whenever the order or invoice '
                . 'currency is not Nigerian Naira (NGN). Applies to the order forms (including One Step '
                . 'Checkout), the invoice payment selector and Add Funds. Requires the bundled '
                . 'includes/hooks/paystack_hide_non_ngn.php file to be installed.',
            'Default' => '',
        ],
        'liveSecretKey' => [
            'FriendlyName' => 'Live Secret Key',
            'Type' => 'password',
            'Size' => '40',
            'Default' => '',
        ],
        'livePublicKey' => [
            'FriendlyName' => 'Live Public Key',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
        ],
        'testSecretKey' => [
            'FriendlyName' => 'Test Secret Key',
            'Type' => 'password',
            'Size' => '40',
            'Default' => '',
        ],
        'testPublicKey' => [
            'FriendlyName' => 'Test Public Key',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
        ],
        // ---- Multi-channel payment options ----
        'enableCard' => [
            'FriendlyName' => 'Enable Card Payments',
            'Type' => 'yesno',
            'Description' => 'Accept debit/credit card payments (Visa, Mastercard, Verve)',
            'Default' => 'on',
        ],
        'enableBankTransfer' => [
            'FriendlyName' => 'Enable Bank Transfer',
            'Type' => 'yesno',
            'Description' => 'Accept payments via bank transfer',
            'Default' => '',
        ],
        'enableUSSD' => [
            'FriendlyName' => 'Enable USSD',
            'Type' => 'yesno',
            'Description' => 'Accept payments via USSD (NGN only)',
            'Default' => '',
        ],
        'enableBankAccount' => [
            'FriendlyName' => 'Enable Pay with Bank',
            'Type' => 'yesno',
            'Description' => 'Accept payments directly from bank accounts',
            'Default' => '',
        ],
        'enableMobileMoney' => [
            'FriendlyName' => 'Enable Mobile Money',
            'Type' => 'yesno',
            'Description' => 'Accept mobile money payments (GHS, KES, RWF, XOF)',
            'Default' => '',
        ],
        'enableQR' => [
            'FriendlyName' => 'Enable QR Code',
            'Type' => 'yesno',
            'Description' => 'Accept payments via QR code scan',
            'Default' => '',
        ],
    ];
}

/**
 * Build the list of enabled Paystack payment channels.
 *
 * @param array $params Gateway params
 * @return array
 */
function paystack_get_channels($params)
{
    $channelMap = [
        'enableCard'         => 'card',
        'enableBankTransfer' => 'bank_transfer',
        'enableUSSD'         => 'ussd',
        'enableBankAccount'  => 'bank',
        'enableMobileMoney'  => 'mobile_money',
        'enableQR'           => 'qr',
    ];

    $channels = [];
    foreach ($channelMap as $param => $channel) {
        if (($params[$param] ?? '') === 'on') {
            $channels[] = $channel;
        }
    }

    // Default to card if nothing is enabled
    return !empty($channels) ? $channels : ['card'];
}

/**
 * Payment link — renders the Paystack checkout button on invoices.
 *
 * @param array $params Payment Gateway Module Parameters
 * @return string
 */
function paystack_link($params)
{
    $email = $params['clientdetails']['email'];
    $phone = $params['clientdetails']['phonenumber'];
    $firstName = $params['clientdetails']['firstname'];
    $lastName = $params['clientdetails']['lastname'];

    $payNowLabel = $params['langpaynow'] ?? 'Pay Now';

    $keys = paystack_get_keys($params);
    $publicKey = $keys['public'];
    $secretKey = $keys['secret'];

    // Auto-trigger popup if invoice loaded directly (no gateway selector visible)
    $paynowload = !array_key_exists('id', $_GET);

    $invoiceId = $params['invoiceid'];
    $amountInKobo = intval(round(floatval($params['amount']) * 100));
    $currency = strtoupper($params['currency']);
    $txnref = $invoiceId . '_' . time();

    if (!in_array($currency, paystack_supported_currencies())) {
        $supported = implode(', ', paystack_supported_currencies());
        return '<div style="color:#dc3545;padding:10px;border:1px solid #dc3545;border-radius:6px;'
            . 'font-size:13px;margin:8px 0;">'
            . 'Paystack does not support <strong>' . htmlspecialchars($currency) . '</strong> payments. '
            . 'Supported currencies: ' . $supported . '.</div>';
    }

    $channels = paystack_get_channels($params);
    $channelsJs = json_encode($channels);

    // On success the popup redirects here with the trxref to verify against.
    $callbackUrl = paystack_base_url() . '/modules/gateways/callback/paystack.php?'
        . http_build_query(['invoiceid' => $invoiceId]);

    // Fallback URL: server-side initialization if the inline popup fails to load.
    $fallbackData = [
        'invoiceid'    => $invoiceId,
        'email'        => $email,
        'phone'        => $phone,
        'amountinkobo' => $amountInKobo,
        'go'           => 'standard',
    ];
    $fallbackData['sig'] = paystack_sign_params($fallbackData, $secretKey);
    $fallbackUrl = paystack_base_url() . '/modules/gateways/callback/paystack.php?'
        . http_build_query($fallbackData);

    $metadata = json_encode([
        'custom_fields' => [
            ['display_name' => 'Plugin', 'variable_name' => 'plugin', 'value' => 'whmcs'],
            ['display_name' => 'Invoice ID', 'variable_name' => 'invoice_id', 'value' => (string) $invoiceId],
            ['display_name' => 'Customer', 'variable_name' => 'customer_name', 'value' => $firstName . ' ' . $lastName],
        ],
    ]);

    $code = '
    <script src="https://js.paystack.co/v2/inline.js"></script>
    <div id="paystack-btn-area"></div>
    <script>
    (function() {
        var $ = window.jQuery;
        if (!$) {
            var s = document.createElement("script");
            s.src = "https://code.jquery.com/jquery-3.7.1.min.js";
            s.onload = function() { initPaystack(window.jQuery); };
            document.head.appendChild(s);
        } else {
            initPaystack($);
        }

        function initPaystack($) {
            $(function() {
                var paymentMethod = $(\'select[name="gateway"]\').val();
                if (paymentMethod === "paystack" || !$(\'select[name="gateway"]\').length) {
                    var btnHtml = \'<button type="button" id="paystack-pay-btn"\'
                        + \' style="padding:12px 30px;margin:2px 0;border-radius:2px;background:#065144;\'
                        + \'color:#ffffff;border:none;cursor:pointer;font-size:15px;font-weight:500;\'
                        + \'transition:opacity 0.2s;"\'
                        + \' onclick="payWithPaystack()">\'
                        + \'' . addslashes(htmlspecialchars($payNowLabel)) . '\'
                        + \'</button>\';

                    if ($(".payment-btn-container").length) {
                        $(".payment-btn-container").append(btnHtml);
                    } else {
                        $("#paystack-btn-area").append(btnHtml);
                    }
                }
            });
        }
    })();

    var paystackPop = new PaystackPop();
    var paystack_paying = false;

    function payWithPaystack() {
        if (paystack_paying) return;
        paystack_paying = true;

        var btn = document.getElementById("paystack-pay-btn");
        if (btn) { btn.disabled = true; btn.textContent = "Processing..."; }

        paystackPop.checkout({
            key: ' . json_encode($publicKey) . ',
            email: ' . json_encode($email) . ',
            phone: ' . json_encode($phone) . ',
            amount: ' . $amountInKobo . ',
            currency: ' . json_encode($currency) . ',
            ref: ' . json_encode($txnref) . ',
            channels: ' . $channelsJs . ',
            metadata: ' . $metadata . ',
            onSuccess: function(response) {
                if (btn) { btn.textContent = "Payment successful! Redirecting..."; }
                window.location.href = ' . json_encode($callbackUrl) . '
                    + "&trxref=" + encodeURIComponent(response.trxref);
            },
            onCancel: function() {
                paystack_paying = false;
                if (btn) { btn.disabled = false; btn.textContent = ' . json_encode($payNowLabel) . '; }
            }
        });
    }

    ' . ($paynowload ? 'setTimeout(payWithPaystack, 4000);' : '') . '
    </script>';

    return $code;
}

/**
 * Refund a Paystack transaction from WHMCS admin.
 *
 * Supports both full and partial refunds. WHMCS calls this
 * when an admin initiates a refund from the transaction list.
 *
 * @param array $params Payment Gateway Module Parameters
 * @return array
 */
function paystack_refund($params)
{
    $keys = paystack_get_keys($params);
    $transactionId = $params['transid'];
    $refundAmount = $params['amount'];
    $currency = $params['currency'];

    // Paystack expects amount in kobo/pesewas
    $amountInKobo = intval(round(floatval($refundAmount) * 100));

    // Look up the Paystack transaction to get the internal Paystack ID
    $txVerify = paystack_api_request(
        'https://api.paystack.co/transaction/verify/' . rawurlencode($transactionId),
        $keys['secret']
    );

    if (isset($txVerify->error)) {
        return [
            'status'  => 'error',
            'rawdata' => ['error' => $txVerify->error],
            'transid' => $transactionId,
        ];
    }

    // Paystack refund endpoint needs the integer transaction ID, not the reference
    $paystackTxnId = $txVerify->id ?? null;
    if (!$paystackTxnId) {
        return [
            'status'  => 'error',
            'rawdata' => ['error' => 'Could not resolve Paystack transaction ID from reference.'],
            'transid' => $transactionId,
        ];
    }

    // Build refund request
    $postData = [
        'transaction' => $paystackTxnId,
    ];

    // Partial refund: only include amount if it's less than the original
    $originalAmountInKobo = intval($txVerify->amount ?? 0);
    if ($amountInKobo > 0 && $amountInKobo < $originalAmountInKobo) {
        $postData['amount'] = $amountInKobo;
    }

    $refundResult = paystack_api_request(
        'https://api.paystack.co/refund',
        $keys['secret'],
        'POST',
        $postData
    );

    if (isset($refundResult->error)) {
        return [
            'status'  => 'error',
            'rawdata' => ['error' => $refundResult->error],
            'transid' => $transactionId,
        ];
    }

    // Log the refund
    if (($params['gatewayLogs'] ?? '') === 'on') {
        logTransaction(
            $params['paymentmethod'] ?? 'paystack',
            [
                'action'       => 'refund',
                'refund_id'    => $refundResult->id ?? 'N/A',
                'transaction'  => $transactionId,
                'amount'       => $refundAmount,
                'currency'     => $currency,
                'status'       => $refundResult->status ?? 'processed',
            ],
            'Refund Successful'
        );
    }

    return [
        'status'  => 'success',
        'rawdata' => [
            'refund_id'       => $refundResult->id ?? 'N/A',
            'refund_status'   => $refundResult->status ?? 'processed',
            'amount_refunded' => $refundAmount,
        ],
        'transid' => $transactionId,
        'fees'    => 0,
    ];
}
