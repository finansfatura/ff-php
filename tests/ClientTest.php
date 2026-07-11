<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Finansfatura\Client;
use Finansfatura\InsufficientCreditsException;

// Minimal fake transport: records calls, returns a canned status + JSON body.
function fakeTransport(int $status, $payload, array &$calls): callable
{
    return function (string $method, string $url, array $headers, ?string $body) use ($status, $payload, &$calls): array {
        $calls[] = compact('method', 'url', 'headers', 'body');
        return ['status' => $status, 'body' => $payload === null ? '' : json_encode($payload)];
    };
}

// issue sets idempotency header and returns json
$calls = [];
$ff = new Client(['apiKey' => 'ff_live_x', 'transport' => fakeTransport(200, ['invoice_id' => 'abc', 'status' => 'QUEUED'], $calls)]);
$out = $ff->issueInvoice(['document_type' => 'EARSIV'], 'order-1');
eq($out['status'], 'QUEUED', 'issue returns status');
eq($calls[0]['method'], 'POST', 'method POST');
ok(str_ends_with(explode('?', $calls[0]['url'])[0], '/invoices/'), 'url path /invoices/');
eq($calls[0]['headers']['X-Api-Key'], 'ff_live_x', 'api key header');
eq($calls[0]['headers']['Idempotency-Key'], 'order-1', 'idempotency header');

// error status maps to typed exception
$calls = [];
$ff = new Client(['apiKey' => 'ff_live_x', 'transport' => fakeTransport(402, ['message' => 'insufficient credits'], $calls)]);
throwsMatching(
    fn() => $ff->issueInvoice([], 'order-2'),
    InsufficientCreditsException::class,
    '402 maps to InsufficientCreditsException'
);

// missing idempotency key rejected client-side
$calls = [];
$ff = new Client(['apiKey' => 'ff_live_x', 'transport' => fakeTransport(200, [], $calls)]);
throwsMatching(
    fn() => $ff->issueInvoice([], ''),
    InvalidArgumentException::class,
    'empty idempotency key rejected'
);
eq(count($calls), 0, 'no HTTP call made when idempotency key missing');

// apiKey required
throwsMatching(
    fn() => new Client(['apiKey' => '']),
    InvalidArgumentException::class,
    'empty apiKey rejected'
);

done('ClientTest');
