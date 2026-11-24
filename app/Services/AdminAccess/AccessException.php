<?php

namespace App\Services\AdminAccess;

/** A refusal whose message is safe to show to the person (login, staff, OTP flows). */
final class AccessException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}
