<?php

declare(strict_types=1);

namespace Finansfatura\Exception;

// Typed errors for the Finansfatura API.
//
// Every failed HTTP call throws a FinansfaturaException (or a subclass) carrying
// the HTTP `status` and the parsed `body`, so callers branch on the kind of
// failure with `instanceof` instead of inspecting status codes by hand.
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
