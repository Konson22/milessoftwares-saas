<?php

namespace App\Collect;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A collected submission (or one of its files or corrections) the server
 * will not take, with a stable code the field app acts on (ADR-0012 §9):
 * 403, 409 and 422 put the item under "Action required" on the phone; 404
 * means the form is not (or no longer) the collector's to send.
 */
final class Refused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(public readonly int $status, public readonly string $reason, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $errors  question name → error code (Rules)
     */
    public static function invalid(string $message, array $errors = []): self
    {
        return new self(422, 'invalid', $message, $errors === [] ? [] : ['errors' => $errors]);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason, ...$this->extra], $this->status);
    }
}
