# Changelog

Notable changes per release. Versions follow [semver](https://semver.org).

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
