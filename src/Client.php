<?php

declare(strict_types=1);

namespace Finansfatura;

// HTTP client for the Finansfatura API.
//
// The integration is two steps: create the sale, then invoice it.
//
//   use Finansfatura\Client;
//   use Finansfatura\Payload;
//
//   $ff = new Client(['apiKey' => 'ff_live_...']);
//
//   $sale = $ff->createOrder([
//       'provider' => 'ECOMSOFT',
//       'external_id' => 'ORD-1042',
//       'total_price' => 120.0,                       // KDV *dahil*
//       'buyer' => ['title' => 'Ahmet Yılmaz', 'tckn' => '11111111111'],
//       'lines' => [['title' => 'Kulaklık', 'sku' => 'SKU-1042',
//                    'quantity' => 1, 'unit_price' => 120.0, 'vat_rate' => 20]],
//   ]);
//
//   $payload = Payload::earsiv(
//       ['vkn_tckn' => '11111111111', 'title' => 'Ahmet Yılmaz'],
//       [['title' => 'Kulaklık', 'qty' => 1, 'unit_price' => 100.0,   // KDV *hariç*
//         'vat_rate' => 0.20]],
//       ['transactionHeaderId' => $sale['transaction_id']],
//   );
//   $result = $ff->issueInvoice($payload, 'ORD-1042');
//
// The order of the two is fixed and neither half is skippable mid-flow: the sale
// needs a `buyer` (it becomes the document's billing recipient, copied onto the
// sale), and the document needs the sale's `transaction_id`. Issuing the document at all
// is still your call: leave it out and the company invoices its sales from the panel.
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.finansfatura.com';
    public const SANDBOX_BASE_URL = 'https://sandbox-api.finansfatura.com';

    /** the status endpoint takes at most this many ids per call */
    public const MAX_STATUS_IDS = 50;

    public ?string $apiKey;
    public ?string $accessToken;
    public string $base;
    /** @var int per-request timeout in milliseconds */
    public int $timeout;

    /** @var callable(string,string,array<string,string>,?string):array{status:int,body:string} */
    private $transport;

    /**
     * Authenticate with either an API key (`X-Api-Key`, one company, pasted by
     * the taxpayer) or an OAuth access token (`Authorization: Bearer`, many
     * companies, see \Finansfatura\OAuth). Exactly one of the two.
     *
     * @param array{apiKey?:string,accessToken?:string,baseUrl?:string,timeout?:int,transport?:callable} $opts
     *   apiKey      — your `ff_live_...` / `ff_test_...` key.
     *   accessToken — an OAuth access token.
     *   baseUrl     — API host only; paths are built here.
     *   timeout     — per-request timeout in ms (default 15000).
     *   transport   — inject an HTTP sender (real curl by default; fake in tests).
     *                 fn(method, url, headers, ?body): {status:int, body:string}
     */
    public function __construct(array $opts)
    {
        $key = $opts['apiKey'] ?? null;
        $token = $opts['accessToken'] ?? null;
        if (($key === null || $key === '') === ($token === null || $token === '')) {
            throw new \InvalidArgumentException('pass exactly one of apiKey or accessToken');
        }
        $this->apiKey = $key ?: null;
        $this->accessToken = $token ?: null;
        $this->base = rtrim($opts['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = $opts['timeout'] ?? 15000;
        $this->transport = $opts['transport'] ?? self::curlTransport($this->timeout);
    }

    // -- sales ---------------------------------------------------------------

    /**
     * POST /v1/integrations/orders — turn an order into a sale.
     *
     * The first and mandatory step: the sale feeds the company's turnover and
     * stock, and survives a failed invoice attempt.
     *
     * `$order` needs `external_id` (your stable order id — resending it never
     * duplicates the sale), at least one line, and a `buyer`. The buyer is
     * copied onto the sale as the document's billing recipient — no current
     * account ("cari") is created. Hence `title` (or `contact_name`) is
     * required: it names who the document is issued to.
     *
     * The rest is optional but each field lands on the document: `tckn` /
     * `tax_number` (one identity field there — `tax_number` wins if you send
     * both), `tax_office`, `address`, `phone` and `email`. Send the identity
     * when the channel has it, or the recipient cannot be looked up at GİB.
     * Send the e-mail too: on an e-Arşiv document GİB's mandatory delivery-type
     * field is derived from it (`ELEKTRONIK` with an address, `KAGIT` without).
     *
     * Prices here are KDV-INCLUSIVE and `vat_rate` is a percentage (`20`) — the
     * opposite of the invoice payload, which is KDV-exclusive with a ratio
     * (`0.20`). Mixing the two up is the most common integration bug.
     *
     * Returns the API body; `transaction_id` is the sale id to pass on to
     * `issueInvoice`, and `already_imported` tells you it was a repeat.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    public function createOrder(array $order): array
    {
        self::validateOrder($order);
        return self::decode($this->request('POST', '/v1/integrations/orders', ['body' => $order]));
    }

    /**
     * Reject client-side what the server would reject anyway — one round trip
     * saved, and the error names the field instead of arriving as a 400 body.
     *
     * @param array<string,mixed> $order
     */
    private static function validateOrder(array $order): void
    {
        if (empty($order['external_id'])) {
            throw new \InvalidArgumentException("order['external_id'] is required");
        }
        if (empty($order['lines'])) {
            throw new \InvalidArgumentException("order['lines'] must have at least one line");
        }
        $buyer = $order['buyer'] ?? [];
        if (!is_array($buyer) || $buyer === []) {
            throw new \InvalidArgumentException(
                "order['buyer'] is required — it is the document's billing recipient"
            );
        }
        if (trim((string) ($buyer['title'] ?? '')) === '' && trim((string) ($buyer['contact_name'] ?? '')) === '') {
            throw new \InvalidArgumentException("order['buyer'] needs 'title' (or 'contact_name') — it names the recipient");
        }
    }

    /**
     * GET /v1/integrations/:provider/orders/status — bulk invoice status.
     *
     * `$externalIds` is a list (or comma string) of your order ids, at most 50
     * per call. Ids we never received are simply absent from the response, so
     * match on `external_id` instead of trusting the order.
     *
     * @param array<int,string>|string $externalIds
     * @return array<string,mixed>
     */
    public function orderStatus(string $provider, $externalIds): array
    {
        $ids = is_string($externalIds) ? explode(',', $externalIds) : array_values($externalIds);
        $ids = array_values(array_filter(array_map('strval', $ids), fn($i) => $i !== ''));
        if ($ids === []) {
            throw new \InvalidArgumentException('externalIds is required');
        }
        if (count($ids) > self::MAX_STATUS_IDS) {
            throw new \InvalidArgumentException('at most ' . self::MAX_STATUS_IDS . ' externalIds per call');
        }
        return self::decode($this->request(
            'GET',
            '/v1/integrations/' . rawurlencode($provider) . '/orders/status',
            ['query' => ['external_ids' => implode(',', $ids)]]
        ));
    }

    /**
     * POST /v1/integrations/refunds — send a refund.
     *
     * A refund is its OWN document (an `IADE` invoice) with its own idempotency
     * key, and it is deliberately not attached to the sale — attaching it would
     * count the sale twice.
     *
     * `$refund` needs `external_id` (your stable refund id — resending it never
     * duplicates), `order_external_id` (the sale it refunds), at least one line
     * and a `buyer`. Lines carry POSITIVE amounts: the document type, not the
     * sign, says it is a refund. Prices are KDV-INCLUSIVE and `vat_rate` is a
     * percentage, exactly as in `createOrder()`.
     *
     * A refund of a foreign-currency sale still needs the rate; send the same
     * `exchange_rate` the sale carried, or the document cannot be issued.
     *
     * @param array<string,mixed> $refund
     * @return array<string,mixed>
     */
    public function refund(array $refund): array
    {
        foreach (['external_id', 'order_external_id'] as $required) {
            if (($refund[$required] ?? '') === '') {
                throw new \InvalidArgumentException("$required is required");
            }
        }
        if (empty($refund['lines'])) {
            throw new \InvalidArgumentException('at least one line is required');
        }
        return self::decode($this->request('POST', '/v1/integrations/refunds', ['body' => $refund]));
    }

    /**
     * POST /v1/integrations/orders/invoice-attached — tell us the document has
     * been written back onto the order in your channel.
     *
     * Nothing about the document changes; this only fills the "carried to the
     * channel" column in the taxpayer's panel, so they can see which sales are
     * fully round-tripped. Safe to repeat.
     */
    public function invoiceAttached(string $provider, string $externalId, string $invoiceId): bool
    {
        $this->request('POST', '/v1/integrations/orders/invoice-attached', [
            'body' => [
                'provider' => $provider,
                'external_id' => $externalId,
                'invoice_id' => $invoiceId,
            ],
        ]);
        return true;
    }

    /**
     * GET /v1/exchange-rates — the day's central-bank (TCMB) rates.
     *
     * **OAUTH ONLY — an API key gets 401 here.** This endpoint lives in a
     * service that authenticates sessions, not API keys, and it is deliberately
     * not opened to keys.
     *
     * You almost certainly do not need it: leave `exchange_rate` off a
     * foreign-currency sale (or send 0) and the server fills in this very rate,
     * then reports what it used in the response (`exchange_rate`,
     * `exchange_rate_source: "TCMB"`, `exchange_rate_date`). Send your own rate
     * only when you want yours instead of ours — it is then used verbatim and
     * never compared against TCMB.
     *
     * Returns `{date, rates: {USD: 41.37, ...}}`. A currency the bulletin does
     * not carry is simply absent — use `exchangeRate()` if you want one
     * currency and a null when it is missing.
     *
     * @return array<string,mixed>
     */
    public function exchangeRates(): array
    {
        return self::decode($this->request('GET', '/v1/exchange-rates'));
    }

    /**
     * One currency's rate, or null when the bulletin has no usable value for it.
     *
     * **OAUTH ONLY — an API key gets 401** (see `exchangeRates()`); with a key,
     * just let the server fill the rate.
     *
     * Never invents a rate: a missing rate must leave the sale waiting, not go
     * out converted at a number nobody chose.
     *
     * @return array{rate:float,date:string}|null
     */
    public function exchangeRate(string $currency): ?array
    {
        $body = $this->exchangeRates();
        $rate = $body['rates'][strtoupper($currency)] ?? null;
        if (!is_numeric($rate) || (float) $rate <= 0 || empty($body['date'])) {
            return null;
        }
        return ['rate' => (float) $rate, 'date' => (string) $body['date']];
    }

    /**
     * GET /v1/integrations/checkouts — the cash/bank accounts a sale's payment
     * can be booked into.
     *
     * Only needed if you send `payment` on a sale: `checkout_id` has to be one
     * of these. Let the taxpayer pick; guessing books money into the wrong
     * account, which is worse than booking none.
     *
     * @return array<int,array<string,mixed>>
     */
    public function checkouts(): array
    {
        $body = self::decode($this->request('GET', '/v1/integrations/checkouts'));
        $items = $body['items'] ?? $body['data'] ?? $body;
        return is_array($items) ? array_values($items) : [];
    }

    // -- invoices ------------------------------------------------------------

    /**
     * POST /v1/invoicing/invoices/ — issue a document. `$idempotencyKey` (any
     * unique string, e.g. the order id) is required; retrying with the same key
     * never double-issues and never charges credits twice.
     *
     * The invoice number is not in the response — read it from `orderStatus()`
     * once the provider assigns it.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function issueInvoice(array $payload, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('idempotencyKey is required');
        }
        $raw = $this->request('POST', '/v1/invoicing/invoices/', [
            'body' => $payload,
            'headers' => ['Idempotency-Key' => $idempotencyKey],
        ]);
        return self::decode($raw);
    }

    /**
     * GET /v1/invoicing/invoices/:id — one invoice.
     *
     * @return array<string,mixed>
     */
    public function getInvoice(string $invoiceId): array
    {
        return self::decode($this->request('GET', '/v1/invoicing/invoices/' . rawurlencode($invoiceId)));
    }

    /**
     * GET /v1/invoicing/invoices/ — paginated list.
     *
     * @return array<string,mixed>
     */
    public function listInvoices(int $page = 1, int $pageSize = 20): array
    {
        return self::decode($this->request('GET', '/v1/invoicing/invoices/', [
            'query' => ['page' => $page, 'page_size' => $pageSize],
        ]));
    }

    /**
     * GET /v1/invoicing/invoices/:id/download — raw document bytes (pdf|html|xml).
     */
    public function download(string $invoiceId, string $format = 'pdf'): string
    {
        return $this->request('GET', '/v1/invoicing/invoices/' . rawurlencode($invoiceId) . '/download', [
            'query' => ['format' => $format],
        ]);
    }

    /**
     * POST /v1/invoicing/invoices/:id/cancel — e-Arşiv cancels outright;
     * e-Fatura starts a process that depends on the recipient.
     */
    public function cancel(string $invoiceId): bool
    {
        $this->request('POST', '/v1/invoicing/invoices/' . rawurlencode($invoiceId) . '/cancel');
        return true;
    }

    /**
     * @param array{query?:array<string,mixed>,body?:mixed,headers?:array<string,string>} $opts
     * @return string raw response body
     */
    private function request(string $method, string $path, array $opts = []): string
    {
        $url = $this->base . $path;
        if (!empty($opts['query'])) {
            $url .= '?' . http_build_query($opts['query']);
        }
        $auth = $this->apiKey !== null
            ? ['X-Api-Key' => $this->apiKey]
            : ['Authorization' => 'Bearer ' . $this->accessToken];
        $headers = $auth + ['Content-Type' => 'application/json'] + ($opts['headers'] ?? []);
        $body = array_key_exists('body', $opts) ? json_encode($opts['body']) : null;

        ['status' => $status, 'body' => $raw] = ($this->transport)($method, $url, $headers, $body);

        if ($status >= 400) {
            $parsed = json_decode($raw, true);
            throw errorFromResponse($status, $parsed ?? $raw);
        }
        return $raw;
    }

    /**
     * The default HTTP sender. Also used by \Finansfatura\OAuth.
     *
     * @return callable(string,string,array<string,string>,?string):array{status:int,body:string}
     */
    public static function curlTransport(int $timeoutMs): callable
    {
        return function (string $method, string $url, array $headers, ?string $body) use ($timeoutMs): array {
            $ch = curl_init($url);
            $hdr = [];
            foreach ($headers as $k => $v) {
                $hdr[] = "$k: $v";
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $hdr,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $raw = curl_exec($ch);
            if ($raw === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new ProviderException(0, null, "transport error: $err");
            }
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['status' => (int) $status, 'body' => (string) $raw];
        };
    }

    /** @return array<string,mixed> */
    private static function decode(string $raw): array
    {
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
