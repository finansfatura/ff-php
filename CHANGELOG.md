# Changelog

Notable changes per release. Versions follow [semver](https://semver.org).

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
