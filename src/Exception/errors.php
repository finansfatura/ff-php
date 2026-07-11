<?php

declare(strict_types=1);

namespace Finansfatura\Exception;

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
