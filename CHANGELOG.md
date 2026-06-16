## 3.1.0 (June 15, 2026)

### New Features
- **Dedicated funding accounts (Dedicated Virtual Account / NUBAN)**: A new companion addon module (`modules/addons/paystackdva/`) lets each client generate their own permanent Paystack bank account from the client-area sidebar. Bank transfers into that account are credited automatically to the client's WHMCS funds/credit balance.
  - The addon ships as one folder: its entry point (`paystackdva.php`), shared library (`lib.php`, creates the `mod_paystack_dva`, `mod_paystack_dva_tx`, and `mod_paystack_dva_state` tables), and bundled hooks (`hooks/`) for the sidebar panel, admin tools, email notifications, and cron poller.
  - Client-area sidebar generates and displays the account with **AJAX** (spinner, no page reload), Lagom-friendly markup, and a clear "NGN deposits only" note.
  - **Enable Dedicated Accounts** toggle and all dedicated-account settings live in the addon's own Configure screen; the Paystack gateway only supplies the shared API keys and test/live mode.
  - Account provisioning uses **Paystack Titan** (`titan-paystack`) by default in live mode (selectable in the addon settings); test mode uses `test-bank`. The customer code is always stored even when Paystack provisions asynchronously, so generation never fails after the account is created; async accounts are completed via the `dedicatedaccount.assign.success` webhook.
  - The gateway callback handles `charge.success` on the `dedicated_nuban` channel: verifies server-side, dedupes via a unique transaction reference, and credits the matched client **instantly** via the `AddCredit` API. A cron poller (`hooks/poll.php` plus standalone `poll.php`) reconciles any deposits or stuck invoice payments the webhook misses.
  - **Admin visibility**: each client's dedicated account and total funded amount show on the client's Summary page, with a global summary on the addon's admin screen.
- **Add Funds email notifications**: Bundled with the addon, the client is emailed whenever funds are credited to their balance — through any payment gateway (Add Funds invoices) and through Paystack dedicated accounts. Uses the editable "Funds Added Confirmation" template, with a custom-email fallback.

## 3.0.0 (May 2, 2026)

### New Features
- **Multi-channel payments**: Admins can enable/disable specific payment channels (card, bank transfer, USSD, bank account, mobile money, QR code) from the WHMCS gateway settings
- **Admin refund support**: Full and partial refunds can now be initiated directly from the WHMCS admin transaction list via the Paystack API
- **Payment method logging**: Transaction logs now show the payment channel used, card type, last 4 digits, and bank name
- **Webhook event logging**: All webhook events (charge.success, charge.failed, refund.processed) are logged when gateway logging is enabled
- **Rich metadata**: Invoice ID and customer name are now sent to Paystack as custom fields for easier reconciliation in the Paystack dashboard

### Security Fixes
- **Amount validation**: Paid amounts are now compared against invoice totals to detect and log underpayment attempts
- **HMAC-signed fallback URLs**: The standard/fallback checkout flow now includes a cryptographic signature to prevent URL parameter tampering
- **Proper webhook signature verification**: Uses `hash_equals()` for timing-safe signature comparison
- **Secret keys use password fields**: Secret keys are now masked in the WHMCS admin panel

### Bug Fixes
- **Replaced deprecated `mysql_fetch_array()`**: Currency conversion now uses WHMCS Capsule (Illuminate DB), fixing compatibility with PHP 7+
- **Webhook fall-through fixed**: Webhook handler now properly exits after returning HTTP 200, preventing unnecessary duplicate verification
- **Shutdown function guard**: The browser redirect shutdown function no longer fires on webhook/API requests where there is no browser
- **Fixed "Test Secrect Key" typo** in gateway configuration
- **Graceful error handling**: Errors now redirect to the invoice page instead of calling `die()` with raw error messages

### Improvements
- Extracted reusable helpers: `paystack_is_ssl()`, `paystack_base_url()`, `paystack_get_keys()`, `paystack_api_request()`
- Modernized jQuery fallback from 1.12.3 to 3.7.1
- Cleaner button styling with hover state
- Double-click prevention on pay button
- Cancel handling resets button state
- Uses `json_encode()` for JS values instead of string concatenation with `addslashes()`
- Plugin tracker class refactored with proper visibility and shorter timeout
- `htmlspecialchars()` used on all user-facing output

## 2.3.0 (April 25, 2024)

- Upgraded to Paystack inlinejs v2
- Added support for Apple pay
- Included fees in the payment data
- Added support for merchants who pass charges to customers
- Bug fixes

## 2.2.1 (November 13, 2020)

- Fix broken payment options image link

## 2.2.0 (November 06, 2020)

- Add ZAR to supported currencies

## 1.0.1 (June 13, 2016)

- [fix] Add logic to avoid duplication of payment verification
- [fix] Add logic to convert back to client currency after payment
- [chore] Use Language Pay now for button prompt
- [chore] Shorten Gateway name
- Append button if `payment-btn-container` not found

## 1.0.0 (May 05, 2016)

- Initial release
