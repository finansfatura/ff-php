# finansfatura/finansfatura

PHP client for the [Finansfatura](https://finansfatura.com) API — turn orders
into sales, issue e-Fatura / e-Arşiv documents, and follow their status.
Full API reference: [apidocs.finansfatura.com](https://apidocs.finansfatura.com).

No dependencies beyond `ext-curl`, `ext-json` and `ext-bcmath`. PHP 8.1+.

## Install

```bash
composer require finansfatura/finansfatura
```

## The flow

An integration is two steps, in this order:

```
1. createOrder()   POST /v1/integrations/orders     → transaction_id
2. issueInvoice()  POST /v1/invoicing/invoices/     → invoice_id
   orderStatus()   GET  /v1/integrations/…/status   → invoice_number
```

Step 2 is **optional** — if you only push sales, the taxpayer invoices them from
the panel, one by one or in bulk. That is the smoothest start for most
integrations.

Step 1 is not optional. It is what puts the order in the turnover report, the
current account and the stock, and what keeps the order alive when invoicing
fails (no credits, bad VKN, GİB down).

## Quickstart

```php
use Finansfatura\Client;
use Finansfatura\Payload;

$ff = new Client(['apiKey' => getenv('FINANSFATURA_API_KEY')]); // ff_live_...

// 1 — the sale. Prices KDV-INCLUSIVE, vat_rate as a percentage.
$sale = $ff->createOrder([
    'provider' => 'ECOMSOFT',           // your brand; unknown values show as FINANSFATURA
    'external_id' => 'ORD-2026-00184',  // your stable order id
    'order_number' => '184',
    'payment_status' => 'PAID',
    'currency' => 'TRY',
    'total_price' => 120.0,
    'buyer' => [
        'title' => 'Ahmet Yılmaz',
        'tckn' => '11111111111',
        'email' => 'ahmet@example.com',
        'address' => 'Kadıköy / İstanbul',
    ],
    'lines' => [[
        'sku' => 'SKU-1042', 'title' => 'Kablosuz Kulaklık',
        'quantity' => 1, 'unit_price' => 120.0, 'total_price' => 120.0, 'vat_rate' => 20,
    ]],
]);

// 2 — the invoice. Prices KDV-EXCLUSIVE, vat_rate as a ratio.
$payload = Payload::earsiv(
    ['vkn_tckn' => '11111111111', 'title' => 'Ahmet Yılmaz'],
    [['title' => 'Kablosuz Kulaklık', 'product_code' => 'SKU-1042',
      'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0.20]],
    ['transactionHeaderId' => $sale['transaction_id']],
);

$result = $ff->issueInvoice($payload, 'ORD-2026-00184');
echo $result['invoice_id'], ' ', $result['status']; // -> ... QUEUED
```

> **The two endpoints disagree about VAT on purpose.** The order body carries
> KDV-**inclusive** prices with a percentage (`120`, `20`); the invoice body
> carries KDV-**exclusive** prices with a ratio (`100`, `0.20`). This is the most
> common integration bug — the builders keep the invoice side honest, the order
> side is yours.

`transactionHeaderId` links the invoice to the sale. Without it the invoice
exists but the sale does not know about it: no turnover, no current account, no
stock movement.

### Sandbox

```php
$ff = new Client(['apiKey' => 'ff_test_...', 'baseUrl' => Client::SANDBOX_BASE_URL]);
```

Sandbox and production are entirely separate systems — keys, OAuth clients and
data never cross over.

### About the builders

`Payload::earsiv()` computes totals from the lines (bcmath, no float kuruş drift)
and applies the API's exact field casing for you: the outer layer is snake_case
(`document_type`, `canonical`) but everything inside `canonical` is PascalCase
(`Recipient`, `Lines`, `Totals`, `VKNorTCKN`). A snake_case key inside `canonical`
is silently ignored by the server, so let the builder handle it.

For exact money you may pass `qty` / `unit_price` / `vat_rate` as strings
(`'33.33'`) — floats work too, the builder rounds half-even to the kuruş.

## Idempotency

The second argument to `issueInvoice()` is **required** and can be any unique
string (use your order id). Retrying with the same key never double-issues and
never charges credits twice. Likewise, resending the same `external_id` to
`createOrder()` never duplicates the sale — you get `200` with
`already_imported: true` instead of `201`. Both are what make retries safe.

## Following the status

The invoice number is **not** in the issue response — the provider assigns it a
moment later. Read it from the bulk status endpoint:

```php
$res = $ff->orderStatus('ecomsoft', ['ORD-2026-00184', 'ORD-2026-00185']);
foreach ($res['statuses'] as $s) {
    echo $s['external_id'], ' ', $s['invoice_status'], ' ', $s['invoice_number'] ?? '-', PHP_EOL;
}
```

Up to 50 ids per call. Orders we never received are simply absent from the
response, so match on `external_id` — don't trust the order. Statuses are
`NOT_INVOICED`, `QUEUED`, `ISSUED`, `ACCEPTED`, `REJECTED`, `CANCELLED`; the last
three are final.

Check once in the first minute after issuing, then every few minutes for the
records that are not final yet. Polling faster does not make GİB answer sooner.

## Reading & lifecycle

```php
$ff->getInvoice($invoiceId);
$ff->listInvoices(1, 20);                    // page, page size
$pdf = $ff->download($invoiceId, 'pdf');     // raw bytes; or 'html' / 'xml'
$ff->cancel($invoiceId);                     // e-Arşiv outright; e-Fatura is a process
```

## Errors

Failed calls throw a typed exception carrying `->status`, `->body` and
`->retryable`:

| Class | HTTP | Meaning | Retry |
|-------|------|---------|-------|
| `ValidationException` | 400, 422 | bad body — `->body['errors']` names the fields | ❌ |
| `AuthException` | 401 | key/token missing, invalid, revoked or expired | ❌ |
| `InsufficientCreditsException` | 402 | not enough credits (kontör) | ❌ |
| `ScopeException` | 403 | missing scope, or endpoint closed to API keys | ❌ |
| `OnboardingRequiredException` | 412 | the taxpayer's e-invoice setup is unfinished | ❌ |
| `RateLimitException` | 429 | too many requests | ✅ |
| `ProviderException` | 5xx | transient upstream / provider unreachable | ✅ |
| `FinansfaturaException` | other | base class | — |

```php
use Finansfatura\FinansfaturaException;
use Finansfatura\OnboardingRequiredException;

try {
    $ff->issueInvoice($payload, "order-{$order->id}");
} catch (OnboardingRequiredException $e) {
    // The sale is safe. Tell the merchant to finish setup in the panel; the
    // pending sales can be invoiced later.
} catch (FinansfaturaException $e) {
    if ($e->retryable) {
        scheduleRetry($order->id);          // 1s, 2s, 4s, 8s …
    } else {
        error_log("issue failed [{$e->status}] " . json_encode($e->body));
    }
}
```

## e-Fatura vs e-Arşiv

Send `EARSIV` and let the server correct it. When `RecipientAlias` is left empty
(the default), we ask GİB about the recipient's VKN: registered taxpayers are
upgraded to `EFATURA` with the mailbox alias resolved, everyone else stays
`EARSIV`. You don't need to run the lookup yourself.

```php
$payload = Payload::efatura(
    ['vkn_tckn' => '1234567890', 'title' => 'Kurum A.Ş.'],
    [/* lines */],
    // 'urn:mail:defaultpk@example.com',   // only if you already know the alias
);
```

Other document types (`EIRSALIYE`, `ESMM`, `EMM`, `EADISYON`) go through
`Payload::build($documentType, ...)`.

## OAuth 2.0

API keys bind to one company. If your product serves many taxpayers, register an
OAuth client (partner@finansfatura.com) and drop the copy-paste step:

```php
use Finansfatura\Client;
use Finansfatura\OAuth;

$oauth = new OAuth([
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'redirectUri' => 'https://app.example.com/ff/callback',
]);

// 1 — send the taxpayer to the consent screen
['verifier' => $verifier, 'challenge' => $challenge] = OAuth::generatePkce();
$_SESSION['ff_verifier'] = $verifier;      // keep the verifier server-side
header('Location: ' . $oauth->authorizeUrl([
    'codeChallenge' => $challenge,
    'state' => $csrfToken,
]));

// 2 — the callback comes back with ?code=…
$token = $oauth->exchangeCode($_GET['code'], ['codeVerifier' => $_SESSION['ff_verifier']]);
store($token['access_token'], $token['refresh_token'], $token['expires_in']);

// 3 — use it
$ff = new Client(['accessToken' => $token['access_token']]);
```

```php
$fresh = $oauth->refresh($storedRefreshToken);  // store the NEW refresh token
$oauth->revoke($storedRefreshToken);            // end the connection
```

- `redirectUri` must match a registered address **exactly**; partial matches are
  rejected.
- Every refresh invalidates the previous refresh token. Persist the newest one or
  the connection dies.
- Scopes: `invoice:read` (status/reads) and `invoice:write` (sales, issuing,
  cancelling). Ask only for what you use.
- Token and revoke URLs keep their **trailing slash** — the client handles it.

## Notes

- Seller identity (`Issuer`) is filled server-side from your company profile —
  don't send it. Make sure the profile VKN is set, or issuing returns 503.
- Keep the API key server-side and encrypted; it acts on the taxpayer's company.
  Never ship it to a browser or mobile app.
- `baseUrl` is the API host only (`https://api.finansfatura.com`) — paths are
  built by the client. Since 1.1.0 it no longer includes `/v1/invoicing`.

## Development

```bash
composer test   # php tests/*.php, no framework
```

## License

MIT
