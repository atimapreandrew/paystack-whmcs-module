<?php
/**
 * Paystack — hide the gateway for non-NGN currencies.
 *
 * When the Paystack gateway's "Hide for Non-NGN Currencies" option is On, this
 * removes Paystack from the payment-method list on any client-area page whose
 * operative currency is not Nigerian Naira: the order forms (including One Step
 * Checkout), the invoice payment selector and Add Funds. It runs server-side, so
 * the option can't be re-shown by tampering with the page in the browser.
 *
 * Why this lives in includes/hooks (and not in the gateway file): WHMCS only
 * loads modules/gateways/paystack.php when Paystack itself is invoked, but these
 * gateway lists are built on ordinary client-area pages, so the filter has to be
 * registered as a global hook that loads on every page.
 *
 * Install: copy this file to your WHMCS installation's /includes/hooks/ directory.
 * It is inert until you switch the gateway option On, so it is safe to leave in place.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Whether the "Hide for Non-NGN Currencies" feature is On AND the gateway is active.
 *
 * Result is memoised for the request — these hooks fire on every cart/invoice/
 * add-funds page and the answer can't change mid-request.
 *
 * @return bool
 */
function paystack_hide_non_ngn_enabled()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('getGatewayVariables')) {
        require_once __DIR__ . '/../gatewayfunctions.php';
    }

    $gw = getGatewayVariables('paystack');

    // getGatewayVariables() returns an empty 'type' when the gateway is not active.
    if (empty($gw) || empty($gw['type'])) {
        return $cached = false;
    }

    return $cached = (($gw['hideNonNgn'] ?? '') === 'on');
}

/**
 * Resolve an upper-case ISO currency code for the page being rendered, or '' if
 * it can't be determined (in which case we leave the gateway list untouched).
 *
 * @param array $vars Hook variables for the current page.
 * @return string
 */
function paystack_page_currency_code($vars)
{
    // 1) Cart / order form / add funds expose the active currency directly.
    if (!empty($vars['currency'])) {
        $c = $vars['currency'];
        if (is_array($c) && !empty($c['code'])) {
            return strtoupper($c['code']);
        }
        if (is_object($c) && !empty($c->code)) {
            return strtoupper($c->code);
        }
    }

    // 2) Invoice page — the currency follows the invoice's client.
    $invoiceId = $vars['invoiceid'] ?? (isset($_GET['id']) ? (int) $_GET['id'] : 0);
    if ($invoiceId) {
        try {
            $code = Capsule::table('tblinvoices')
                ->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
                ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
                ->where('tblinvoices.id', (int) $invoiceId)
                ->value('tblcurrencies.code');
            if (!empty($code)) {
                return strtoupper($code);
            }
        } catch (\Exception $e) {
            // fall through to the next strategy
        }
    }

    // 3) Cart session currency (guest ordering, before login).
    if (!empty($_SESSION['currency'])) {
        try {
            $code = Capsule::table('tblcurrencies')
                ->where('id', (int) $_SESSION['currency'])
                ->value('code');
            if (!empty($code)) {
                return strtoupper($code);
            }
        } catch (\Exception $e) {
            // fall through
        }
    }

    // 4) Logged-in client's own currency.
    if (!empty($_SESSION['uid'])) {
        try {
            $code = Capsule::table('tblcurrencies')
                ->join('tblclients', 'tblclients.currency', '=', 'tblcurrencies.id')
                ->where('tblclients.id', (int) $_SESSION['uid'])
                ->value('tblcurrencies.code');
            if (!empty($code)) {
                return strtoupper($code);
            }
        } catch (\Exception $e) {
            // fall through
        }
    }

    return '';
}

/**
 * Remove the Paystack entry from a WHMCS gateways list, handling the two shapes
 * WHMCS uses: an associative map (sysname => display name) and a numerically
 * indexed list of gateway arrays/objects (each carrying a 'sysname').
 *
 * @param mixed $gateways
 * @return mixed The list with Paystack removed.
 */
function paystack_strip_gateway($gateways)
{
    if (!is_array($gateways)) {
        return $gateways;
    }

    // Shape A: associative, keyed by gateway sysname (e.g. ['paystack' => 'Paystack']).
    if (array_key_exists('paystack', $gateways)) {
        unset($gateways['paystack']);
        return $gateways;
    }

    // Shape B: numerically indexed list of gateway arrays/objects.
    $filtered = [];
    foreach ($gateways as $gw) {
        $sysname = null;
        if (is_array($gw)) {
            $sysname = $gw['sysname'] ?? $gw['module'] ?? $gw['gateway'] ?? null;
        } elseif (is_object($gw)) {
            $sysname = $gw->sysname ?? $gw->module ?? $gw->gateway ?? null;
        }
        if ($sysname === 'paystack') {
            continue;
        }
        $filtered[] = $gw;
    }

    return $filtered;
}

/**
 * Shared hook body: when enabled and the page currency isn't NGN, drop Paystack
 * from the page's `gateways` variable. Returning [] leaves the page unchanged.
 *
 * @param array $vars
 * @return array
 */
function paystack_hide_non_ngn_filter($vars)
{
    if (!paystack_hide_non_ngn_enabled()) {
        return [];
    }

    $code = paystack_page_currency_code($vars);
    if ($code === '' || $code === 'NGN') {
        return [];
    }

    if (empty($vars['gateways']) || !is_array($vars['gateways'])) {
        return [];
    }

    return ['gateways' => paystack_strip_gateway($vars['gateways'])];
}

// Order forms (Standard Cart, One Step Checkout, etc.), invoice payment selector
// and Add Funds all expose the payment options as the `gateways` template variable.
add_hook('ClientAreaPageCart', 1, 'paystack_hide_non_ngn_filter');
add_hook('ClientAreaPageViewInvoice', 1, 'paystack_hide_non_ngn_filter');
add_hook('ClientAreaPageAddFunds', 1, 'paystack_hide_non_ngn_filter');
