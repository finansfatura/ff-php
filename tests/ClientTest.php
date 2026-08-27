<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Finansfatura\Client;
use Finansfatura\InsufficientCreditsException;
use Finansfatura\OAuth;
use Finansfatura\RateLimitException;
use Finansfatura\ValidationException;

// Minimal fake transport: records calls, returns a canned status + JSON body.
function fakeTransport(int $status, $payload, array &$calls): callable
{
    return function (string $method, string $url, array $headers, ?string $body) use ($status, $payload, &$calls): array {
        $calls[] = compact('method', 'url', 'headers', 'body');
        return ['status' => $status, 'body' => $payload === null ? '' : json_encode($payload)];
    };
}

// A minimally valid order: id, one line, and a buyer that names the cari.
function ffOrder(array $over = []): array
{
    return $over + [
        'external_id' => 'ORD-1',
        'buyer' => ['title' => 'Ahmet Yılmaz', 'tckn' => '11111111111'],
        'lines' => [['title' => 'A', 'quantity' => 1, 'unit_price' => 120.0, 'vat_rate' => 20]],
    ];
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

// exactly one credential required
throwsMatching(
    fn() => new Client(['apiKey' => '']),
    InvalidArgumentException::class,
    'empty apiKey rejected'
);
throwsMatching(
    fn() => new Client(['apiKey' => 'k', 'accessToken' => 't']),
    InvalidArgumentException::class,
    'two credentials rejected'
);

// access token sends bearer, not api key
$calls = [];
$ff = new Client(['accessToken' => 'at_123', 'transport' => fakeTransport(200, ['statuses' => []], $calls)]);
$ff->orderStatus('ecomsoft', ['A']);
eq($calls[0]['headers']['Authorization'], 'Bearer at_123', 'bearer header');
ok(!isset($calls[0]['headers']['X-Api-Key']), 'no api key header when using a token');

// createOrder hits the integrations path
$calls = [];
$ff = new Client(['apiKey' => 'ff_live_x', 'transport' => fakeTransport(201, ['imported' => true, 'transaction_id' => 't-1'], $calls)]);
$out = $ff->createOrder(ffOrder());
eq($out['transaction_id'], 't-1', 'createOrder returns transaction_id');
eq($calls[0]['method'], 'POST', 'createOrder method POST');
ok(str_ends_with($calls[0]['url'], '/v1/integrations/orders'), 'createOrder url');

// createOrder needs a buyer to hang the cari off
$calls = [];
$ff = new Client(['apiKey' => 'ff_live_x', 'transport' => fakeTransport(201, [], $calls)]);
foreach ([
    ffOrder(['external_id' => '']),
    ffOrder(['lines' => []]),
    ffOrder(['buyer' => []]),
    ffOrder(['buyer' => ['email' => 'a@b.c']]),
] as $bad) {
    throwsMatching(fn() => $ff->createOrder($bad), \InvalidArgumentException::class, 'invalid order rejected');
}
// rejected before the request — no round trip burned on a known-bad body
eq(count($calls), 0, 'no HTTP call for an invalid order');
// contact_name stands in for title: it is what names the cari
$ff->createOrder(ffOrder(['buyer' => ['contact_name' => 'Ahmet Yılmaz']]));
eq(count($calls), 1, 'contact_name is enough to name the cari');

// orderStatus joins ids and caps at 50
$calls = [];
$ff = new Client(['apiKey' => 'ff_live_x', 'transport' => fakeTransport(200, ['statuses' => []], $calls)]);
$ff->orderStatus('ecomsoft', ['ORD-1', 'ORD-2']);
eq($calls[0]['method'], 'GET', 'orderStatus method GET');
ok(str_contains($calls[0]['url'], '/v1/integrations/ecomsoft/orders/status?'), 'orderStatus url');
ok(str_ends_with($calls[0]['url'], 'external_ids=ORD-1%2CORD-2'), 'ids joined with a comma');
throwsMatching(
    fn() => $ff->orderStatus('ecomsoft', array_map('strval', range(1, 51))),
    InvalidArgumentException::class,
    'more than 50 ids rejected'
);
throwsMatching(
    fn() => $ff->orderStatus('ecomsoft', []),
    InvalidArgumentException::class,
    'empty id list rejected'
);

// 429 is retryable, 400 is not
$calls = [];
$ff = new Client(['apiKey' => 'k', 'transport' => fakeTransport(429, ['message' => 'slow down'], $calls)]);
throwsMatching(fn() => $ff->createOrder(ffOrder()), RateLimitException::class, '429 maps to RateLimitException');
try {
    $ff->createOrder(ffOrder());
} catch (RateLimitException $e) {
    ok($e->retryable, '429 is retryable');
}
$calls = [];
$ff = new Client(['apiKey' => 'k', 'transport' => fakeTransport(400, ['message' => 'validation error'], $calls)]);
try {
    $ff->createOrder(ffOrder());
} catch (ValidationException $e) {
    ok(!$e->retryable, '400 is not retryable');
}

// --- OAuth ------------------------------------------------------------------

// pkce pair is url-safe and verifiable
['verifier' => $verifier, 'challenge' => $challenge] = OAuth::generatePkce();
ok(strpbrk($verifier . $challenge, '+/=') === false, 'pkce pair is url-safe');
eq(
    $challenge,
    rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
    'challenge is S256(verifier)'
);

// authorize url carries pkce and state
$calls = [];
$oauth = new OAuth([
    'clientId' => 'cid',
    'clientSecret' => 'sec',
    'redirectUri' => 'https://app.example.com/cb',
    'transport' => fakeTransport(200, [], $calls),
]);
$url = $oauth->authorizeUrl(['codeChallenge' => 'chal', 'state' => 'xyz']);
ok(str_starts_with($url, 'https://app.finansfatura.com/oauth/authorize?'), 'authorize url host');
ok(str_contains($url, 'code_challenge=chal'), 'code_challenge in url');
ok(str_contains($url, 'code_challenge_method=S256'), 'S256 method in url');
ok(str_contains($url, 'response_type=code'), 'response_type in url');
ok(str_contains($url, 'state=xyz'), 'state in url');

// sandbox api url implies sandbox panel
$calls = [];
$sandbox = new OAuth([
    'clientId' => 'cid',
    'baseUrl' => Client::SANDBOX_BASE_URL,
    'redirectUri' => 'https://app.example.com/cb',
    'transport' => fakeTransport(200, [], $calls),
]);
ok(str_starts_with($sandbox->authorizeUrl(), 'https://sandbox-app.finansfatura.com/'), 'sandbox panel host');

// token endpoints keep their trailing slash and post a form
$calls = [];
$oauth = new OAuth([
    'clientId' => 'cid',
    'clientSecret' => 'sec',
    'redirectUri' => 'https://app.example.com/cb',
    'transport' => fakeTransport(200, ['access_token' => 'at', 'refresh_token' => 'rt'], $calls),
]);
$token = $oauth->exchangeCode('the-code', ['codeVerifier' => 'ver']);
eq($token['access_token'], 'at', 'exchangeCode returns the token');
ok(str_ends_with($calls[0]['url'], '/v1/oauth/token/'), 'token url keeps its trailing slash');
eq($calls[0]['headers']['Content-Type'], 'application/x-www-form-urlencoded', 'form content type');
parse_str($calls[0]['body'], $form);
eq($form['grant_type'], 'authorization_code', 'grant_type');
eq($form['code_verifier'], 'ver', 'code_verifier sent');
eq($form['client_secret'], 'sec', 'client_secret sent');

$oauth->revoke('rt');
ok(str_ends_with($calls[1]['url'], '/v1/oauth/revoke/'), 'revoke url keeps its trailing slash');
parse_str($calls[1]['body'], $form);
eq($form['token'], 'rt', 'revoked token sent');

done('ClientTest');
