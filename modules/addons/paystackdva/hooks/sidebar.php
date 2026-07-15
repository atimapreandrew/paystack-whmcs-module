<?php
/**
 * Paystack Dedicated Accounts — Client Area Sidebar
 *
 * Surfaces the client's dedicated virtual account directly in the client-area
 * sidebar (no dedicated page). Companion to the paystackdva addon module
 * (modules/addons/paystackdva/lib.php), which owns the DB tables and the
 * shared Paystack helpers reused here.
 *
 * Two hook points:
 *  1. ClientAreaPage          — handles the "generate account" request. Responds
 *                               with JSON for AJAX submits (button spinner, no
 *                               page reload) and falls back to Post/Redirect/Get
 *                               for no-JS clients.
 *  2. ClientAreaPage (priority 2) — exposes the account markup/data to templates.
 *
 * Theme-friendly: uses Bootstrap/Lagom classes instead of bespoke layout markup.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . "/../lib.php";

/**
 * Handle the account-generation request before the page renders.
 */
add_hook("ClientAreaPage", 1, function ($vars) {
    if (($_POST["paystackdva_action"] ?? "") !== "generate") {
        return;
    }

    $isAjax =
        !empty($_POST["paystackdva_ajax"]) ||
        ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";

    $userid = (int) ($_SESSION["uid"] ?? 0);

    // Respect the addon's "Enable Dedicated Accounts" setting.
    if (!paystackdva_enabled()) {
        paystackdva_respond(
            $isAjax,
            "error",
            "This feature is currently unavailable.",
        );
    }

    if ($userid <= 0) {
        paystackdva_respond($isAjax, "error", "Please log in and try again.");
    }

    // NGN-only: virtual accounts are never generated for clients billed in
    // another currency (their deposits could not be credited correctly).
    if (!paystackdva_client_is_ngn($userid)) {
        paystackdva_respond(
            $isAjax,
            "error",
            "Virtual accounts are only available for accounts billed in NGN.",
        );
    }

    // CSRF: WHMCS core validates the standard token on client-area POSTs; the
    // module nonce is validated here as well so the protection is
    // self-contained and does not depend on that core behaviour.
    $nonce = (string) ($_POST["paystackdva_nonce"] ?? "");
    $sessionNonce = (string) ($_SESSION["paystackdva_nonce"] ?? "");
    if (
        empty($_POST["token"]) ||
        $sessionNonce === "" ||
        !hash_equals($sessionNonce, $nonce)
    ) {
        paystackdva_respond(
            $isAjax,
            "error",
            "Session expired. Please refresh and try again.",
        );
    }

    // Server-side eligibility gate (e.g. must have an active service).
    if (!paystackdva_can_generate($userid)) {
        paystackdva_respond(
            $isAjax,
            "error",
            "You need at least one active service before you can generate a virtual account.",
        );
    }

    $client = Capsule::table("tblclients")
        ->where("id", $userid)
        ->first(["id", "email", "firstname", "lastname", "phonenumber"]);

    if (!$client) {
        paystackdva_respond(
            $isAjax,
            "error",
            "We could not load your account details.",
        );
    }

    $result = paystackdva_create_account([
        "id" => $client->id,
        "email" => $client->email,
        "firstname" => $client->firstname,
        "lastname" => $client->lastname,
        "phonenumber" => $client->phonenumber,
    ]);

    if (isset($result["error"])) {
        paystackdva_respond($isAjax, "error", $result["error"]);
    }

    $pending = !empty($result["pending"]);

    // Email the details only for a newly created, active account. (Pending
    // accounts are emailed when Paystack finishes provisioning — see the
    // callback's dedicatedaccount.assign.success handler. An idempotent return
    // for an existing account is not re-emailed.)
    if (!$pending && empty($result["existing"])) {
        paystackdva_send_account_email($userid);
    }

    paystackdva_respond(
        $isAjax,
        $pending ? "pending" : "ok",
        "",
        paystackdva_account_html($result["account"]),
    );
});

/**
 * Send the generation response — JSON for AJAX, redirect for plain POST — then stop.
 *
 * @param bool   $isAjax
 * @param string $status  ok|pending|error
 * @param string $message Human message (errors)
 * @param string $html    Rendered account block (success)
 */
function paystackdva_respond($isAjax, $status, $message = "", $html = "")
{
    if ($isAjax) {
        header("Content-Type: application/json");
        echo json_encode([
            "status" => $status,
            "message" => $message,
            "html" => $html,
        ]);
        exit();
    }

    // No-JS fallback: redirect back with a flash flag the sidebar reads.
    $uri = strtok($_SERVER["REQUEST_URI"] ?? "clientarea.php", "?");
    header("Location: " . $uri . "?paystackdva=" . urlencode($status));
    exit();
}

/**
 * Expose the dedicated-account markup + data to the client-area templates so it
 * can be embedded wherever you like — e.g. inside Lagom's Add Funds card.
 *
 * Available Smarty variables on every client-area page (when the feature is on
 * and a client is logged in):
 *   {$paystackdva_html nofilter}   — ready-to-use block (account or generate
 *                                    button) including the AJAX script
 *   {$paystackdva_has_account}     — bool, whether an account exists
 *   {$paystackdva_account}         — array: account_number, bank_name,
 *                                    account_name, status, currency
 *   {$paystackdva_token}           — CSRF token value (for custom markup)
 *   {$paystackdva_nonce}           — module CSRF nonce; custom generate forms
 *                                    must POST it as "paystackdva_nonce"
 *
 * The variables are only exposed for clients whose account currency is NGN —
 * dedicated accounts are NGN-only, so other clients never see the feature.
 */
add_hook("ClientAreaPage", 2, function ($vars) {
    $userid = (int) ($_SESSION["uid"] ?? 0);
    if (
        $userid <= 0 ||
        !paystackdva_enabled() ||
        !paystackdva_client_is_ngn($userid)
    ) {
        return [];
    }

    $account = paystackdva_get_account($userid);
    $flash = $_GET["paystackdva"] ?? "";
    $canGenerate = paystackdva_can_generate($userid);

    return [
        "paystackdva_html" => paystackdva_panel_html(
            $account,
            $flash,
            $canGenerate,
        ),
        "paystackdva_has_account" => (bool) $account,
        "paystackdva_account" => $account ? (array) $account : null,
        "paystackdva_can_generate" => $canGenerate,
        "paystackdva_token" => function_exists("generate_token")
            ? generate_token("plain")
            : "",
        "paystackdva_nonce" => paystackdva_nonce(),
    ];
});

/**
 * Per-session anti-CSRF nonce for the generate action, validated with
 * hash_equals() in the POST handler. Self-contained so the protection holds
 * even if WHMCS core token validation is unavailable.
 *
 * @return string
 */
function paystackdva_nonce()
{
    if (empty($_SESSION["paystackdva_nonce"])) {
        $_SESSION["paystackdva_nonce"] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION["paystackdva_nonce"];
}

/**
 * Build the full sidebar panel body (account block or generate form + script).
 *
 * @param object|null $account
 * @param string      $flash
 * @param bool        $canGenerate Whether the client is eligible to generate.
 * @return string
 */
function paystackdva_panel_html($account, $flash, $canGenerate = true)
{
    // Has an account (active or still provisioning) → show details.
    if ($account) {
        return '<div id="paystackdva-body">' .
            paystackdva_account_html($account) .
            "</div>" .
            paystackdva_panel_script();
    }

    // Not eligible yet → disabled button + explanatory notice.
    if (!$canGenerate) {
        return '<div id="paystackdva-body">' .
            '<div class="alert alert-lagom alert-warning alert-sm alert-panel">' .
            '<div class="alert-icon ls ls-info-circle"></div>' .
            '<div class="alert-body">A virtual account becomes available once you have an active ' .
            "service with us. Place an order first, then come back to generate your account.</div>" .
            "</div>" .
            '<button type="button" class="btn btn-primary-faded btn-block" disabled>' .
            '<span><i class="ls ls-bank-note"></i> Generate Virtual Account</span></button>' .
            "</div>";
    }

    // No account yet → generate form. Lagom-native markup.
    $notice = "";
    if ($flash === "error") {
        $notice =
            '<div class="alert alert-lagom alert-danger alert-sm alert-panel">' .
            '<div class="alert-icon ls ls-info-circle"></div>' .
            '<div class="alert-body">We could not create your account. Please try again or contact support.</div>' .
            "</div>";
    } elseif ($flash === "csrf") {
        $notice =
            '<div class="alert alert-lagom alert-danger alert-sm alert-panel">' .
            '<div class="alert-icon ls ls-info-circle"></div>' .
            '<div class="alert-body">Session expired. Please try again.</div>' .
            "</div>";
    }

    $token = function_exists("generate_token") ? generate_token("form") : "";

    return '<div id="paystackdva-body">' .
        $notice .
        '<div class="alert alert-lagom alert-info alert-sm alert-panel">' .
        '<div class="alert-icon ls ls-info-circle"></div>' .
        '<div class="alert-body">Generate your own bank account to fund your balance by transfer ' .
        "<strong>(NGN deposits only)</strong>.</div>" .
        "</div>" .
        '<form method="post" action="" id="paystackdva-form">' .
        $token .
        '<input type="hidden" name="paystackdva_nonce" value="' .
        htmlspecialchars(paystackdva_nonce()) .
        '">' .
        '<input type="hidden" name="paystackdva_action" value="generate">' .
        '<input type="hidden" name="paystackdva_ajax" value="1">' .
        '<button type="submit" id="paystackdva-btn" class="btn btn-primary-faded btn-block">' .
        '<span><i class="ls ls-bank-note"></i> Generate Virtual Account</span></button>' .
        "</form>" .
        '<div id="paystackdva-msg"></div>' .
        "</div>" .
        paystackdva_panel_script();
}

/**
 * Render the account details block (active or pending).
 * Reused by the AJAX response so it can be swapped into the panel without a reload.
 * Uses Lagom's native summary-list / alert markup to blend into the Add Funds card.
 *
 * @param object $account
 * @return string
 */
function paystackdva_account_html($account)
{
    if ($account->status === "pending" || empty($account->account_number)) {
        return '<div class="alert alert-lagom alert-info alert-sm alert-panel">' .
            '<div class="alert-icon ls ls-info-circle"></div>' .
            '<div class="alert-body">Your virtual account is being created. ' .
            "It will appear here shortly — please refresh in a moment.</div>" .
            "</div>";
    }

    $acctNo = htmlspecialchars($account->account_number);
    $bank = htmlspecialchars($account->bank_name);
    $name = htmlspecialchars($account->account_name);

    return '<div class="summary-content">' .
        '<ul class="summary-list">' .
        '<li class="list-item" style="display:flex;align-items:center;justify-content:space-between;padding-bottom:6px;">' .
        '<span class="item-name">Virtual Account</span>' .
        '<span class="item-value" style="display:inline-flex;align-items:center;gap:6px;' .
        'font-weight:700;letter-spacing:1px;white-space:nowrap;">' .
        "<span>" .
        $acctNo .
        "</span>" .
        '<button type="button" title="Copy" style="padding:0;border:0;background:transparent;' .
        'line-height:1;cursor:pointer;"' .
        ' onclick="(function(b){var t=\'' .
        $acctNo .
        '\';' .
        "if(navigator.clipboard){navigator.clipboard.writeText(t);}" .
        'var o=b.innerHTML;b.innerHTML=\'Copied\';setTimeout(function(){b.innerHTML=o;},1500);})(this)">' .
        '<i class="ls ls-copy"></i></button>' .
        "</span>" .
        "</li>" .
        '<li class="list-item faded" style="padding-bottom:6px;">' .
        '<span class="item-name">Bank</span><span class="item-value">' .
        $bank .
        "</span>" .
        "</li>" .
        '<li class="list-item faded" style="padding-bottom:6px;">' .
        '<span class="item-name">Account Name</span><span class="item-value">' .
        $name .
        "</span>" .
        "</li>" .
        "</ul>" .
        "</div>" .
        '<div class="alert alert-lagom alert-info alert-sm alert-panel" ' .
        'style="margin-bottom:0;padding-top:15px;padding-bottom:8px;">' .
        '<div class="alert-icon ls ls-info-circle"></div>' .
        '<div class="alert-body">Transfer to this account <strong>(NGN only)</strong> to top up ' .
        "your balance. Funds are credited automatically.</div>" .
        "</div>";
}

/**
 * The inline JS for the panel: submits the generate form over AJAX (button
 * spinner, no page reload).
 *
 * @return string
 */
function paystackdva_panel_script()
{
    return <<<'HTML'
    <script>
    (function () {
        var form = document.getElementById('paystackdva-form');
        if (!form || form.dataset.bound) { return; }
        form.dataset.bound = '1';

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('paystackdva-btn');
            var msg = document.getElementById('paystackdva-msg');
            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span>Generating...</span>';
            if (msg) { msg.innerHTML = ''; }

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var body = document.getElementById('paystackdva-body');
                if ((res.status === 'ok' || res.status === 'pending') && res.html && body) {
                    body.innerHTML = res.html;
                } else {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    if (msg) {
                        msg.innerHTML = '<div class="alert alert-lagom alert-danger alert-sm alert-panel" style="margin-top:8px;">'
                            + '<div class="alert-icon ls ls-info-circle"></div><div class="alert-body">'
                            + (res.message || 'Could not create your account. Please try again or contact support.')
                            + '</div></div>';
                    }
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = original;
                if (msg) {
                    msg.innerHTML = '<div class="alert alert-lagom alert-danger alert-sm alert-panel" style="margin-top:8px;">'
                        + '<div class="alert-icon ls ls-info-circle"></div><div class="alert-body">'
                        + 'Something went wrong. Please try again.</div></div>';
                }
            });
        });
    })();
    </script>
    HTML;
}
