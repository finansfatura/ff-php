<?php

declare(strict_types=1);

namespace Finansfatura;

// Typed errors for the Finansfatura API.
//
// Every failed HTTP call throws a FinansfaturaException (or a subclass) carrying
// the HTTP `status` and the parsed `body`, so callers branch on the kind of
// failure with `instanceof` instead of inspecting status codes by hand.
//
// This file is loaded eagerly via Composer's "files" autoload (several classes +
// one helper function live here — PSR-4 wants one class per file).

class FinansfaturaException extends \RuntimeException
{
    public int $status;

    /** @var mixed parsed response body (array for JSON, string otherwise) */
    public $body;

    /** @param mixed $body */
    public function __construct(int $status, $body, ?string $message = null)
    {
        $rendered = is_string($body) ? $body : json_encode($body);
        parent::__construct($message ?? "[$status] $rendered");
        $this->status = $status;
        $this->body = $body;
    }
}

/** 401 — API key missing, invalid, revoked or expired. */
class AuthException extends FinansfaturaException {}

/** 402 — not enough credits (kontör) to issue the document. */
class InsufficientCreditsException extends FinansfaturaException {}

/** 403 — the API key lacks the required scope (e.g. invoice:write). */
class ScopeException extends FinansfaturaException {}

/** 412 — e-invoice onboarding not completed. Body carries error_code/message. */
class OnboardingRequiredException extends FinansfaturaException {}

/** 5xx — gateway/provider or transient upstream. Safe to retry with the same Idempotency-Key. */
class ProviderException extends FinansfaturaException {}

/**
 * Map an HTTP status + parsed body to the right exception class.
 *
 * @param mixed $body
 */
function errorFromResponse(int $status, $body): FinansfaturaException
{
    switch ($status) {
        case 401: return new AuthException($status, $body);
        case 402: return new InsufficientCreditsException($status, $body);
        case 403: return new ScopeException($status, $body);
        case 412: return new OnboardingRequiredException($status, $body);
        default:
            return $status >= 500
                ? new ProviderException($status, $body)
                : new FinansfaturaException($status, $body);
    }
}
