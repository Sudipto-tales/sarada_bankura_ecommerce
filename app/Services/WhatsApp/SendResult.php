<?php

namespace App\Services\WhatsApp;

/**
 * What WhatsAppService::send() returns. One shape for both states, so callers have one code path:
 *   mode api       the Cloud API was called (ok = Meta accepted it, messageId = wamid)
 *   mode fallback  dormant (or the API failed): fallbackUrl is a wa.me link with the same text,
 *                  or null when there is no safe fallback (OTP codes are never relayed via wa.me)
 */
final class SendResult
{
    public function __construct(
        public readonly string $mode,
        public readonly bool $ok,
        public readonly ?string $messageId = null,
        public readonly ?string $fallbackUrl = null,
        public readonly ?string $error = null,
        public readonly ?int $logId = null,
    ) {
    }

    /** Delivered to Meta for real ("Sent on WhatsApp ✓"). */
    public function sent(): bool
    {
        return $this->mode === 'api' && $this->ok;
    }
}
