# Paystack Payment Gateway for WHMCS

Accept payments in WHMCS using Paystack — supporting cards, bank transfers, USSD, mobile money, QR codes, and direct bank account payments.

## Requirements

- WHMCS 7.0 or later
- PHP 7.4 or later
- cURL extension enabled
- OpenSSL v1.0.1 or later
- A [Paystack account](https://dashboard.paystack.co/#/signup)

## Installation

1. Download or clone this repository.
2. Copy `modules/gateways/paystack.php` to your WHMCS installation's `/modules/gateways/` directory.
3. Copy `modules/gateways/callback/paystack.php` to `/modules/gateways/callback/` directory.

### Optional: Dedicated Virtual Accounts

To let clients fund their WHMCS balance by bank transfer (see [Dedicated Virtual Accounts](#dedicated-virtual-accounts)), also:

4. Copy the whole `modules/addons/paystackdva/` folder to `/modules/addons/`. That's everything — its hooks (sidebar panel, admin tools, email notifications, cron poller) live inside the folder and load automatically when the addon is active. No files go into `/includes/hooks/`.

Then:
- Activate the addon under **Setup → Addon Modules → Paystack Dedicated Accounts → Activate** (this creates the database tables and the email template).
- Open the addon's **Configure** screen and set **Enable Dedicated Accounts** to On (plus the live provider bank). All dedicated-account settings live here; the Paystack gateway only supplies the shared API keys.
- Make sure the WHMCS cron runs every few minutes (Setup → Automation Settings), or add the standalone poller (`modules/addons/paystackdva/poll.php`) to your server cron — this credits deposits the webhook misses. It can also reconcile "stuck" invoice payments, but that is **off by default**: an admin must explicitly set **Reconcile Invoice Payments** to On in the addon's Configure screen.

## Configuration

1. Go to **Setup → Payments → Payment Gateways → All Payment Gateways** in your WHMCS admin panel.
2. Click on **Paystack** to activate it.
3. Enter your API keys from your [Paystack Dashboard](https://dashboard.paystack.com/#/settings/developer).
4. Copy the **Webhook URL** displayed in the settings and paste it into your Paystack Dashboard under **Settings → API Keys & Webhooks → Webhook URL**.
5. Configure your preferred payment channels (see below).
6. Click **Save Changes**.

## Payment Channels

You can enable or disable individual payment channels from the gateway settings:

| Channel | Config Option | Notes |
|---------|--------------|-------|
| Card (Visa, Mastercard, Verve) | Enable Card Payments | Enabled by default |
| Bank Transfer | Enable Bank Transfer | Customer gets a temporary account number |
| USSD | Enable USSD | NGN only |
| Pay with Bank | Enable Bank Account | Direct bank account debit |
| Mobile Money | Enable Mobile Money | GHS, KES, RWF, XOF currencies |
| QR Code | Enable QR Code | Customer scans QR to pay |

If no channels are explicitly enabled, the plugin defaults to card payments.

## Dedicated Virtual Accounts

The optional `paystackdva` addon lets each client generate a **personal, permanent bank account** (a Paystack Dedicated Virtual Account / Dedicated NUBAN) and top up their WHMCS funds balance simply by transferring money to it.

### How it works

1. Once the addon is activated **and** **Enable Dedicated Accounts** is On in the addon's Configure screen, a **Virtual Account** panel appears in the client-area sidebar.
2. The client clicks **Generate Virtual Account**. The button shows a spinner (no page reload), the module creates a Paystack customer for them and assigns a dedicated bank account, then shows the account number, bank, and account name right in the sidebar.
3. When the client transfers money to that account, Paystack sends a `charge.success` webhook (channel `dedicated_nuban`) to the existing gateway callback. The module verifies the transaction, prevents duplicates, and **instantly adds the amount to the client's WHMCS credit/funds balance** (via the `AddCredit` API). The client can then apply that balance to any invoice.

No extra webhook configuration is needed — it reuses the same Webhook URL and API keys as the gateway.

### Requirements & notes

- **Dedicated NUBAN must be enabled on your Paystack account.** In live mode this requires completed KYC / business approval. In **test mode** the module provisions accounts on `test-bank`.
- Dedicated accounts are **NGN-only, and this is enforced**: the Virtual Account panel only appears for clients whose WHMCS account currency is NGN, account generation is refused for any other currency, and deposits are never credited to a non-NGN client (they are logged in the Activity Log for manual handling instead).
- One account is provisioned per client (re-clicking simply shows the existing account).
- The live provisioning bank defaults to **Paystack Titan** (`titan-paystack`) and is selectable in the addon's settings.
- If Paystack provisions the account asynchronously, the sidebar shows a "being created" state and the account is completed automatically via the `dedicatedaccount.assign.success` webhook.
- **Admin view**: each client's dedicated account and total funded amount appear on the client's **Summary** page in the WHMCS admin. A global summary is shown under **Setup → Addon Modules → Paystack Dedicated Accounts**.

## Add Funds Email Notifications

The addon emails a client whenever funds are successfully credited to their balance — through **any** payment gateway (standard Add Funds deposits) and through Paystack dedicated accounts. The email uses the editable **"Funds Added Confirmation"** template (created on activation) and confirms the amount added and the client's new credit balance. This is handled by the addon's bundled hooks — no separate files to install.

## Supported Currencies

NGN, USD, GHS, ZAR, EGP, XOF, KES, RWF

## Refunds

Refunds can be initiated directly from the WHMCS admin panel:

1. Go to **Billing → Transactions**.
2. Find the transaction you want to refund.
3. Click the transaction and select **Refund**.
4. Enter the refund amount (full or partial).
5. The refund is processed via the Paystack API and logged in WHMCS.

## Security

This version includes several security improvements:

- **Amount validation**: The plugin verifies that the amount paid matches the invoice total, logging any discrepancies.
- **Webhook signature verification**: All webhook events are validated using HMAC-SHA512 with timing-safe comparison.
- **Secret key masking**: Secret keys use password fields in the admin panel.

## Gateway Logging

Enable **Gateway Logs** in the settings to see detailed transaction information including:

- Payment channel used (card, bank transfer, USSD, etc.)
- Card type and last 4 digits
- Bank name
- All webhook events received
- Amount validation results
- Refund processing details

Logs are stored in WHMCS under **Utilities → Logs → Gateway Log**.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## Support

- [Paystack Documentation](https://paystack.com/docs/)
- [Paystack Helpdesk](https://paystack.com/help)
- [GitHub Issues](https://github.com/PaystackHQ/plugin-whmcs/issues)

## License

This project is open-sourced under the [MIT License](LICENSE).
