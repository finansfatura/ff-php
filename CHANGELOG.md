# Changelog

Notable changes per release. Versions follow [semver](https://semver.org).

## [3.2.0] — 2026-10-04

Everything a document needs beyond "TRY, 20%% VAT, attached to a sale". Each of
these was supported by the API but unreachable from this client, so the
documents simply could not be issued.

### Added

- VAT exemption: `exemptionCode` / `exemptionReason` on the payload builders.
  A zero-VAT line cannot be invoiced without a reason (GİB rejects it) and there
  was no way to send one — those documents were unissuable. Document-level,
  applied only to the zero-VAT lines, exactly as the server does it. Also
  `invoice_exemption_code` / `invoice_exemption_reason` on a sale, which stores
  the reason so any later issuing path finds it.
- Scenario: `scenario` (`TEMELFATURA` | `TICARIFATURA`), e-Fatura only. The
  difference is legal — on a TEMEL invoice the recipient cannot answer. A
  recipient who refuses commercial invoices could not be billed at all before.
- Refund reference: `returnInfo` (original invoice number + issue date). GİB
  rejects a refund without `cac:BillingReference`, and nothing sent it. The date
  is converted to RFC 3339 for you — a bare `2026-09-27` fails to parse
  server-side and returns a meaningless 400.
- Currency on the document: `currency`, `exchangeRate`, `exchangeRateDate`.
  Only meaningful on a refund; a sale takes both from the sale itself.
- Withholding and special tax base, per line: `withholding_code` /
  `withholding_name`, `tax_base_amount` / `tax_base_code` / `tax_base_reason`.
  **The withholding rate is not sent** — each GİB code's legal rate is fixed and
  the server derives it from the code (`612` went from 7/10 to 9/10 in 2023). A
  rate coming from the client meant a GİB update left integrations filing wrong
  declarations for years.

### Changed

- A sale no longer needs a rate for a foreign currency: omit `exchange_rate` (or
  send `0`) and the server fills in the TCMB rate, then reports what it used in
  the response (`exchange_rate`, `exchange_rate_source`, `exchange_rate_date`).
  Send your own and it is used verbatim, never compared against TCMB. A refund
  takes the ORIGINAL sale's rate, not today's.
- `TEVKIFATIADE` and `YTBIADE` are now treated as refunds like `IADE`: they too
  are issued without a sale. Before, a withholding refund could not be issued at
  all — the exemption only covered `IADE`.
- The builders now throw before the request in three cases the server would
  reject anyway, naming the field instead of returning a 400 later: a zero-VAT
  line with no exemption, a refund with no original-invoice reference, and a
  non-TRY document with no rate. No call that previously produced a valid
  document is affected.
- `exchangeRates()` / `exchangeRate()` are documented as **OAuth-only** — an API
  key gets 401 there. They are also mostly unnecessary now: the server fills the
  rate and tells you which one it used.

## [3.1.0] — 2026-09-27

Four endpoints the hosted integrations (shopify, ikas) were calling by hand.
They were never in this client, so every integration re-implemented the HTTP
and the "a missing rate is not a rate" rule — the WHMCS module made that three.

### Added

- `refund()` — `POST /v1/integrations/refunds`. A refund is its own document
  (`IADE`) with its own idempotency key and is deliberately not attached to the
  sale; attaching it would count the sale twice. `external_id`,
  `order_external_id` and at least one line are required.
- `invoiceAttached()` — `POST /v1/integrations/orders/invoice-attached`. Marks a
  sale as round-tripped in the taxpayer's panel. Safe to repeat.
- `exchangeRates()` / `exchangeRate()` — `GET /v1/exchange-rates`. A
  foreign-currency sale cannot be invoiced without a rate. `exchangeRate()`
  returns `null` for a currency the bulletin does not carry, or for a zero rate:
  a missing rate leaves the sale waiting instead of going out converted at a
  number nobody chose.
- `checkouts()` — `GET /v1/integrations/checkouts`. The cash/bank accounts a
  sale's `payment.checkout_id` may point at. Accepts both the `items`-wrapped
  and bare-list response shapes, which is how the endpoint has been seen.

## [3.0.1] — 2026-08-30

Docs only, no API change: the sale no longer opens a current account.

### Changed

- `Client::createOrder()`'s `buyer` is now **copied onto the sale** as the
  document's billing recipient (`Unvan/Ad Soyad`, `VKN/TCKN`, `Vergi Dairesi`,
  `Adres`, `E-posta`, `Telefon`). No cari is searched for or created any more —
  one-off e-commerce buyers were filling the taxpayer's contact list, and what
  the sale actually needed was the recipient, not a card. Contacts you want to
  track a balance for are still opened from the panel.
- `title` (or `contact_name`) stays required, for a different reason: it names
  the recipient the document is issued to, not a cari.
- `tckn` and `tax_number` are two fields here and **one** on the document —
  `tax_number` wins when both are sent.
- `email` documented as what it is: on an e-Arşiv document GİB's mandatory
  delivery-type field (`EREPSENDT`) is `ELEKTRONIK` when the recipient has an
  e-mail and `KAGIT` when they do not.

## [3.0.0] — 2026-08-27

The sale, and the cari behind it, are no longer optional. Both halves are now
enforced client-side, before the request, matching what the API enforces.

### Changed — BREAKING

- `Client::createOrder()` requires `buyer` with a `title` (or `contact_name`). The buyer is what
  the sale's current account ("cari") is resolved from: matched on `tax_number` →
  `tckn` → `email` → `title`, and created when nothing matches. A sale without one
  used to be accepted and left carisiz; the API now rejects it.
- `Payload::build()` (and the `earsiv()` / `efatura()` wrappers) requires `transactionHeaderId`. Every document hangs off a sale — that is what feeds
  the turnover report, the current account and stock. The one exception is a
  refund (`invoiceTypeCode` = `IADE`), which stays unattached so the sale is not counted twice.
- `Client::createOrder()` also rejects a missing `external_id` or empty `lines` up front, instead
  of spending a round trip on a known 400.

## [2.0.0] — 2026-08-16

The client only covered invoicing; the API expects the sale to exist first.

### Added

- `createOrder()` — `POST /v1/integrations/orders`, the mandatory first step.
  Prices there are KDV-**inclusive** with a percentage `vat_rate`, the opposite
  of the invoice payload.
- `orderStatus()` — bulk invoice status for up to 50 `external_id`s. This is
  where `invoice_number` shows up; the issue response never carries it.
- `transactionHeaderId` build option, linking the invoice to its sale (turnover
  report, current account, stock).
- OAuth 2.0: `OAuth` (authorize URL, `exchangeCode`, `refresh`, `revoke`) and
  `OAuth::generatePkce()`. `new Client(['accessToken' => ...])` sends
  `Authorization: Bearer` instead of `X-Api-Key`.
- `ValidationException` (400/422) and `RateLimitException` (429).
- `->retryable` on every exception, encoding the API's retry table.
- `Client::SANDBOX_BASE_URL`, `Client::MAX_STATUS_IDS`.
- `$pageSize` on `listInvoices()`.
- `Client::curlTransport()` is public and static, so `OAuth` shares one sender.

### Changed

- **Breaking:** `Client::DEFAULT_BASE_URL` is the API host only
  (`https://api.finansfatura.com`); paths are built by the client. If you passed
  a custom `baseUrl` ending in `/v1/invoicing`, drop that suffix — pointing at
  sandbox was the common reason to set it, so this is a major bump.
- `$recipientAlias` is optional in `Payload::efatura()` and always sent (as `''`
  when unknown) — that empty value is what makes the server resolve the GİB
  mailbox and upgrade `EARSIV` to `EFATURA` by itself.
- `Client` requires exactly one of `apiKey` / `accessToken`.

## [1.0.0] — 2026-07-11

First release: `issueInvoice`, `getInvoice`, `listInvoices`, `download`,
`cancel`, the `canonical` payload builders with bcmath totals, and typed
exceptions. No dependencies beyond curl/json/bcmath.
