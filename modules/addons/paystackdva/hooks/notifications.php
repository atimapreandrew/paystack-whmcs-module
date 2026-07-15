<?php
/**
 * Add Funds — Client Email Notification
 *
 * A standalone, self-contained feature: emails the client whenever money is
 * successfully credited to their WHMCS funds/credit balance, regardless of how
 * it was paid.
 *
 * Covers two paths:
 *  1. Standard "Add Funds" deposits through ANY payment gateway — caught via the
 *     core `AddInvoicePayment` hook by detecting Add Funds invoices.
 *  2. Paystack dedicated-account top-ups — caught via the custom `PaystackDvaFunded`
 *     hook fired by modules/gateways/callback/paystack.php after a successful credit.
 *
 * The email is sent only once funds are actually credited. No pre-made WHMCS email
 * template is required — a custom email is sent via the local API.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../lib.php';

/**
 * Standard Add Funds deposits (any gateway).
 *
 * AddInvoicePayment fires for every invoice payment — including PARTIAL
 * payments — and per the WHMCS hook docs it receives only {invoiceid}. So we
 * act only when the paid invoice is an "Add Funds" invoice (identified by an
 * AddFunds line item) AND it is now fully Paid — WHMCS only credits the funds
 * balance once the Add Funds invoice is paid in full, so emailing on a partial
 * payment would announce credit the client hasn't received yet.
 */
add_hook('AddInvoicePayment', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    try {
        $isAddFunds = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'AddFunds')
            ->exists();

        if (!$isAddFunds) {
            return; // ordinary invoice payment — not a funds deposit
        }

        $invoice = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first(['userid', 'total', 'status']);

        if (!$invoice || $invoice->status !== 'Paid') {
            return; // partially paid — funds not credited yet
        }

        // The hook only provides the invoice ID; resolve the reference from
        // the most recent transaction recorded against the invoice.
        $transid = '';
        try {
            $transid = (string) Capsule::table('tblaccounts')
                ->where('invoiceid', $invoiceId)
                ->orderBy('id', 'desc')
                ->value('transid');
        } catch (\Exception $e) {
            // optional detail only
        }

        paystack_send_funds_email(
            (int) $invoice->userid,
            (float) $invoice->total,
            'your selected payment method',
            $transid
        );
    } catch (\Exception $e) {
        logActivity('Add Funds notification error: ' . $e->getMessage());
    }
});

/**
 * Paystack dedicated-account top-ups.
 *
 * Fired by the gateway callback after AddCredit succeeds.
 */
add_hook('PaystackDvaFunded', 1, function ($vars) {
    $userid = (int) ($vars['userid'] ?? 0);
    $amount = (float) ($vars['amount'] ?? 0);
    if ($userid <= 0 || $amount <= 0) {
        return;
    }

    paystack_send_funds_email(
        $userid,
        $amount,
        'your Paystack dedicated account',
        $vars['transid'] ?? ''
    );
});

/**
 * Send the "funds added" confirmation email to a client.
 *
 * @param int    $clientId
 * @param float  $amount
 * @param string $method   Human description of how the funds were paid
 * @param string $transid  Optional transaction reference
 */
function paystack_send_funds_email($clientId, $amount, $method, $transid = '')
{
    if ($clientId <= 0 || $amount <= 0) {
        return;
    }

    try {
        // Resolve the client's currency + their new credit balance for the message.
        $client = Capsule::table('tblclients')
            ->leftJoin('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblclients.id', $clientId)
            ->first([
                'tblclients.firstname',
                'tblclients.credit',
                'tblcurrencies.code as currency_code',
                'tblcurrencies.prefix as currency_prefix',
            ]);

        if (!$client) {
            return;
        }

        $currency = $client->currency_code ?: '';
        $prefix = $client->currency_prefix ?: '';
        $amountText = trim($prefix . number_format($amount, 2) . ' ' . $currency);
        $balanceText = trim($prefix . number_format((float) $client->credit, 2) . ' ' . $currency);

        $templateName = defined('PAYSTACKDVA_EMAIL_TEMPLATE')
            ? PAYSTACKDVA_EMAIL_TEMPLATE
            : 'Funds Added Confirmation';

        $templateExists = Capsule::table('tblemailtemplates')
            ->where('name', $templateName)
            ->exists();

        if ($templateExists) {
            // Preferred: use the WHMCS email template so admins can edit it.
            sendMessage($templateName, $clientId, [
                'funds_amount'    => $amountText,
                'funds_balance'   => $balanceText,
                'funds_method'    => $method,
                'funds_reference' => $transid,
            ]);
            return;
        }

        // Fallback (template not created yet) — send a custom email.
        $refLine = $transid
            ? '<p style="color:#777;font-size:13px;">Reference: ' . htmlspecialchars($transid) . '</p>'
            : '';
        $message = '<p>Hi ' . htmlspecialchars($client->firstname) . ',</p>'
            . '<p>We have received your payment and <strong>' . htmlspecialchars($amountText)
            . '</strong> has been added to your account balance via '
            . htmlspecialchars($method) . '.</p>'
            . '<p>Your available credit balance is now <strong>' . htmlspecialchars($balanceText)
            . '</strong>.</p>'
            . $refLine
            . '<p>You can apply this balance to any outstanding or future invoices from your client area.</p>'
            . '<p>Thank you.</p>';

        localAPI('SendEmail', [
            'id'            => $clientId,
            'customtype'    => 'general',
            'customsubject' => 'Funds Added to Your Account',
            'custommessage' => $message,
        ]);
    } catch (\Exception $e) {
        logActivity('Add Funds notification email error: ' . $e->getMessage());
    }
}
