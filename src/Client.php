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
// Invoicing is optional: skip it and the company invoices the sales from the panel.
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
     * The first and mandatory step: the sale feeds the company's turnover,
     * current account and stock, and survives a failed invoice attempt.
     *
     * `$order` needs `external_id` (your stable order id — resending it never
     * duplicates the sale) and at least one line. Prices here are KDV-INCLUSIVE
     * and `vat_rate` is a percentage (`20`) — the opposite of the invoice
     * payload, which is KDV-exclusive with a ratio (`0.20`). Mixing the two up is
     * the most common integration bug.
     *
     * Returns the API body; `transaction_id` is the sale id to pass on to
     * `issueInvoice`, and `already_imported` tells you it was a repeat.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    public function createOrder(array $order): array
    {
        return self::decode($this->request('POST', '/v1/integrations/orders', ['body' => $order]));
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
