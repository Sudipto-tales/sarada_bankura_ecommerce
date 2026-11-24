<?php

namespace App\Controllers;

use App\Services\WhatsApp\Webhook;
use App\Services\WhatsApp\WhatsAppService;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * /webhooks/whatsapp — Meta Cloud API webhook (docs/whatsapp.md §4). Public, CSRF-exempt
 * ("webhooks" prefix in VerifyCsrfToken), protected by the verify token (GET) and the
 * X-Hub-Signature-256 HMAC of the raw body (POST). 404 while the channel is dormant.
 */
final class WhatsAppWebhookController
{
    public function verify(Request $request): Response
    {
        if (!WhatsAppService::isActive()) {
            return response('Not found', 404, ['Content-Type' => 'text/plain']);
        }
        $challenge = Webhook::verify($request->query);

        return $challenge === null
            ? response('Forbidden', 403, ['Content-Type' => 'text/plain'])
            : response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request): Response
    {
        if (!WhatsAppService::isActive()) {
            return response('Not found', 404, ['Content-Type' => 'text/plain']);
        }
        $raw = (string) $request->rawBody;
        if (!Webhook::validSignature($raw, $request->header('X-Hub-Signature-256'))) {
            return response('Forbidden', 403, ['Content-Type' => 'text/plain']);
        }
        $payload = json_decode($raw, true);
        if (is_array($payload)) {
            try {
                Webhook::handle($payload);
            } catch (\Throwable $e) {
                // Still 200: Meta would retry forever; the log keeps what we could store.
                error_log('[whatsapp] webhook handling failed: ' . $e->getMessage());
            }
        }

        return response('EVENT_RECEIVED', 200, ['Content-Type' => 'text/plain']);
    }
}
