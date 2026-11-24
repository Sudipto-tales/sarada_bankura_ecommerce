<?php

namespace App\Services\AdminAccess;

/** Where a password code goes. Email now; a WhatsApp provider (docs/whatsapp.md W3) can be added later. */
interface OtpChannel
{
    public function name(): string;

    public function canReach(array $user): bool;

    /** @throws \RuntimeException with a message safe to show */
    public function send(array $user, string $code, string $purpose): void;
}
