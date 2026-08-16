<?php

declare(strict_types=1);

namespace Finansfatura;

// OAuth 2.0 authorization code flow (RFC 6749) with PKCE.
//
// Use this instead of API keys when your product serves many taxpayers: they
// click "connect" in your app, approve the consent screen, and you get a token —
// no copy-pasted key.
//
//   use Finansfatura\Client;
//   use Finansfatura\OAuth;
//
//   $oauth = new OAuth([
//       'clientId' => '...', 'clientSecret' => '...',
//       'redirectUri' => 'https://app.example.com/ff/callback',
//   ]);
//
//   ['verifier' => $verifier, 'challenge' => $challenge] = OAuth::generatePkce();
//   $_SESSION['ff_verifier'] = $verifier;                       // keep it server-side
//   header('Location: ' . $oauth->authorizeUrl(['codeChallenge' => $challenge,
//                                               'state' => $csrfToken]));
//
//   // ... taxpayer approves, we get ?code=... on the redirectUri ...
//   $token = $oauth->exchangeCode($_GET['code'], ['codeVerifier' => $_SESSION['ff_verifier']]);
//   $ff = new Client(['accessToken' => $token['access_token']]);
//
// Every refresh invalidates the previous refresh token — always persist the
// newest one you were handed.
final class OAuth
{
    /** where the taxpayer approves the connection (panel, not API) */
    public const AUTHORIZE_BASE_URL = 'https://app.finansfatura.com';
    public const SANDBOX_AUTHORIZE_BASE_URL = 'https://sandbox-app.finansfatura.com';

    public const DEFAULT_SCOPE = 'invoice:read invoice:write';

    public string $clientId;
    public ?string $clientSecret;
    public ?string $redirectUri;
    public string $base;
    public string $authorizeBase;
    public int $timeout;

    /** @var callable(string,string,array<string,string>,?string):array{status:int,body:string} */
    private $transport;

    /**
     * Get `clientId` / `clientSecret` and register your `redirectUri` through
     * partner@finansfatura.com.
     *
     * @param array{clientId:string,clientSecret?:string,redirectUri?:string,baseUrl?:string,authorizeBaseUrl?:string,timeout?:int,transport?:callable} $opts
     */
    public function __construct(array $opts)
    {
        if (empty($opts['clientId'])) {
            throw new \InvalidArgumentException('clientId is required');
        }
        $this->clientId = $opts['clientId'];
        $this->clientSecret = $opts['clientSecret'] ?? null;
        $this->redirectUri = $opts['redirectUri'] ?? null;
        $this->base = rtrim($opts['baseUrl'] ?? Client::DEFAULT_BASE_URL, '/');
        // sandbox api ⇒ sandbox panel, so callers only override one url
        $this->authorizeBase = rtrim(
            $opts['authorizeBaseUrl']
                ?? ($this->base === Client::SANDBOX_BASE_URL
                    ? self::SANDBOX_AUTHORIZE_BASE_URL
                    : self::AUTHORIZE_BASE_URL),
            '/'
        );
        $this->timeout = $opts['timeout'] ?? 15000;
        $this->transport = $opts['transport'] ?? Client::curlTransport($this->timeout);
    }

    /**
     * Generate a `['verifier' => ..., 'challenge' => ...]` pair for PKCE `S256`.
     *
     * Keep the verifier server-side until the callback comes back; send only the
     * challenge to the authorize URL.
     *
     * @return array{verifier:string,challenge:string}
     */
    public static function generatePkce(): array
    {
        $verifier = self::b64url(random_bytes(32));
        return [
            'verifier' => $verifier,
            'challenge' => self::b64url(hash('sha256', $verifier, true)),
        ];
    }

    /**
     * Build the consent-screen URL to send the taxpayer to.
     *
     * `redirectUri` must match one of your registered addresses exactly; partial
     * matches are rejected. Requested scopes must be a subset of the ones granted
     * to your client.
     *
     * $opts keys: codeChallenge(string), state(string), scope(string),
     * redirectUri(string).
     *
     * @param array<string,string> $opts
     */
    public function authorizeUrl(array $opts = []): string
    {
        $redirect = $opts['redirectUri'] ?? $this->redirectUri;
        if (!$redirect) {
            throw new \InvalidArgumentException('redirectUri is required');
        }
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => $opts['scope'] ?? self::DEFAULT_SCOPE,
        ];
        if (!empty($opts['codeChallenge'])) {
            $params['code_challenge'] = $opts['codeChallenge'];
            $params['code_challenge_method'] = 'S256';
        }
        if (!empty($opts['state'])) {
            $params['state'] = $opts['state'];
        }
        return $this->authorizeBase . '/oauth/authorize?' . http_build_query($params);
    }

    /**
     * POST /v1/oauth/token/ — trade the callback `$code` for tokens.
     *
     * $opts keys: codeVerifier(string), redirectUri(string).
     *
     * @param array<string,string> $opts
     * @return array<string,mixed>
     */
    public function exchangeCode(string $code, array $opts = []): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $opts['redirectUri'] ?? $this->redirectUri,
            'code_verifier' => $opts['codeVerifier'] ?? null,
        ]);
    }

    /**
     * POST /v1/oauth/token/ — swap a refresh token for a fresh pair. The old
     * refresh token dies here; store the one you get back.
     *
     * @return array<string,mixed>
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** POST /v1/oauth/revoke/ — end the connection from your side. */
    public function revoke(string $token): bool
    {
        // trailing slash is mandatory — the slashless form is not redirected
        $this->post('/v1/oauth/revoke/', ['token' => $token]);
        return true;
    }

    // -- internals -----------------------------------------------------------

    /**
     * @param array<string,mixed> $form
     * @return array<string,mixed>
     */
    private function token(array $form): array
    {
        $data = json_decode($this->post('/v1/oauth/token/', $form), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $form */
    private function post(string $path, array $form): string
    {
        $form = array_filter($form, fn($v) => $v !== null && $v !== '');
        $form['client_id'] = $this->clientId;
        if ($this->clientSecret) {
            $form['client_secret'] = $this->clientSecret;
        }

        ['status' => $status, 'body' => $raw] = ($this->transport)(
            'POST',
            $this->base . $path,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($form)
        );

        if ($status >= 400) {
            $parsed = json_decode($raw, true);
            throw errorFromResponse($status, $parsed ?? $raw);
        }
        return $raw;
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
