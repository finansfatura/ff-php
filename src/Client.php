<?php

declare(strict_types=1);

namespace Finansfatura;

// HTTP client for the Finansfatura e-invoice API.
//
//   use Finansfatura\Client;
//   use Finansfatura\Payload;
//
//   $ff = new Client(['apiKey' => 'ff_live_...']);
//   $payload = Payload::earsiv(
//       ['vkn_tckn' => '11111111111', 'title' => 'Ahmet Yılmaz'],
//       [['title' => 'Kulaklık', 'qty' => 1, 'unit_price' => 100.0, 'vat_rate' => 0.20]],
//   );
//   $result = $ff->issueInvoice($payload, 'order-1042');
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.finansfatura.com/v1/invoicing';

    public string $apiKey;
    public string $base;
    /** @var int per-request timeout in milliseconds */
    public int $timeout;

    /** @var callable(string,string,array<string,string>,?string):array{status:int,body:string} */
    private $transport;

    /**
     * @param array{apiKey:string,baseUrl?:string,timeout?:int,transport?:callable} $opts
     *   apiKey    — your `ff_live_...` / `ff_test_...` key (sent as X-Api-Key).
     *   baseUrl   — override the API base.
     *   timeout   — per-request timeout in ms (default 15000).
     *   transport — inject an HTTP sender (real curl by default; fake in tests).
     *               fn(method, url, headers, ?body): {status:int, body:string}
     */
    public function __construct(array $opts)
    {
        if (empty($opts['apiKey'])) {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $this->apiKey = $opts['apiKey'];
        $this->base = rtrim($opts['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = $opts['timeout'] ?? 15000;
        $this->transport = $opts['transport'] ?? $this->curlTransport();
    }

    /**
     * POST /invoices/ — issue a document. `$idempotencyKey` (any unique string,
     * e.g. the order id) is required; retrying with the same key never
     * double-issues.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function issueInvoice(array $payload, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('idempotencyKey is required');
        }
        $raw = $this->request('POST', '/invoices/', [
            'body' => $payload,
            'headers' => ['Idempotency-Key' => $idempotencyKey],
        ]);
        return self::decode($raw);
    }

    /**
     * GET /invoices/:id — one invoice (poll here while status is QUEUED).
     *
     * @return array<string,mixed>
     */
    public function getInvoice(string $invoiceId): array
    {
        return self::decode($this->request('GET', '/invoices/' . rawurlencode($invoiceId)));
    }

    /**
     * GET /invoices/ — paginated list.
     *
     * @return array<string,mixed>
     */
    public function listInvoices(int $page = 1): array
    {
        return self::decode($this->request('GET', '/invoices/', ['query' => ['page' => $page]]));
    }

    /**
     * GET /invoices/:id/download — raw document bytes (pdf|html|xml).
     */
    public function download(string $invoiceId, string $format = 'pdf'): string
    {
        return $this->request('GET', '/invoices/' . rawurlencode($invoiceId) . '/download', [
            'query' => ['format' => $format],
        ]);
    }

    /** POST /invoices/:id/cancel — cancel before GİB acceptance. */
    public function cancel(string $invoiceId): bool
    {
        $this->request('POST', '/invoices/' . rawurlencode($invoiceId) . '/cancel');
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
        $headers = [
            'X-Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ] + ($opts['headers'] ?? []);
        $body = array_key_exists('body', $opts) ? json_encode($opts['body']) : null;

        ['status' => $status, 'body' => $raw] = ($this->transport)($method, $url, $headers, $body);

        if ($status >= 400) {
            $parsed = json_decode($raw, true);
            throw errorFromResponse($status, $parsed ?? $raw);
        }
        return $raw;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private function curlTransport(): callable
    {
        return function (string $method, string $url, array $headers, ?string $body): array {
            $ch = curl_init($url);
            $hdr = [];
            foreach ($headers as $k => $v) {
                $hdr[] = "$k: $v";
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $hdr,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $this->timeout,
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
