# finansfatura (PHP)

PHP client for the [Finansfatura](https://finansfatura.com) e-invoice API
(e-Fatura / e-Arşiv). Issue, list, download and cancel invoices with an API key —
no WooCommerce required, this is the general external REST API.

Zero runtime dependencies (native `curl` + `bcmath`, PHP 8.1+). Faithful port of
the [Node client](https://github.com/finansfatura/ff-node).

## Install

```bash
composer require finansfatura/finansfatura
```

Requires the `curl`, `json` and `bcmath` extensions (`bcmath` does the exact
kuruş arithmetic — no float drift).

## Quickstart

```php
use Finansfatura\Client;
use Finansfatura\Payload;

$ff = new Client(['apiKey' => getenv('FINANSFATURA_API_KEY')]); // ff_live_...

$payload = Payload::earsiv(
    ['vkn_tckn' => '11111111111', 'title' => 'Ahmet Yılmaz', 'email' => 'ahmet@example.com'],
    [['title' => 'Kablosuz Kulaklık', 'product_code' => 'SKU-1042', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0.20]],
);

$result = $ff->issueInvoice($payload, 'order-1042');
echo $result['invoice_id'], ' ', $result['status']; // -> ... QUEUED
```

`Payload::earsiv()` computes totals from the lines (exact decimal, no float kuruş
drift) and applies the API's exact field casing for you: the outer layer is
snake_case (`document_type`, `canonical`) but everything inside `canonical` is
PascalCase (`Recipient`, `Lines`, `Totals`, `VKNorTCKN`). A snake_case key inside
`canonical` is silently ignored by the server, so let the builder handle it.

For exact money you may pass `qty` / `unit_price` / `vat_rate` as strings
(`"33.33"`) — numbers work too, the builder rounds half-even to the kuruş.

## Idempotency

The second argument to `issueInvoice` is **required** and can be any unique string
(use your order id). Retrying with the same key never double-issues — safe on
timeout/retry.

## Reading & lifecycle

```php
$ff->getInvoice($invoiceId);              // poll while status is QUEUED
$ff->listInvoices(1);                      // page 1
$pdf = $ff->download($invoiceId, 'pdf');   // raw bytes (string); or 'html' / 'xml'
$ff->cancel($invoiceId);                   // before GİB acceptance
```

## Errors

Failed calls throw a typed exception carrying `->status` and `->body`:

| Class | HTTP | Meaning |
|-------|------|---------|
| `AuthException` | 401 | key missing/invalid/revoked/expired |
| `InsufficientCreditsException` | 402 | not enough credits (kontör) |
| `ScopeException` | 403 | key lacks the required scope |
| `OnboardingRequiredException` | 412 | e-invoice onboarding not completed |
| `ProviderException` | 5xx | transient upstream — retry same idempotency key |
| `FinansfaturaException` | other | base class |

```php
use Finansfatura\FinansfaturaException;
use Finansfatura\OnboardingRequiredException;

try {
    $ff->issueInvoice($payload, "order-{$order->id}");
} catch (OnboardingRequiredException $e) {
    // $e->body -> ['error_code' => ..., 'message' => ...]
} catch (FinansfaturaException $e) {
    error_log("issue failed [{$e->status}] " . json_encode($e->body));
}
```

## e-Fatura (registered recipients)

e-Arşiv targets final consumers (TCKN is fine). For a GİB-registered recipient
use e-Fatura, which needs the recipient's mailbox alias:

```php
$payload = Payload::efatura(
    ['vkn_tckn' => '1234567890', 'title' => 'Kurum A.Ş.'],
    [/* lines */],
    'urn:mail:defaultpk@example.com', // recipient alias (required)
);
```

## Notes

- Seller identity (`Issuer`) is filled server-side from your company profile —
  don't send it. Make sure the profile VKN is set, or issuing returns 503.
- Keep the API key in an env var; never commit it.
- Need a custom HTTP stack (proxy, PSR-18, retries)? Pass a `transport` callable
  to the constructor: `fn($method, $url, $headers, $body) => ['status' => int, 'body' => string]`.
  The default uses native `curl`.

## Development

```bash
composer test   # plain PHP asserts, no framework
```

## License

MIT
