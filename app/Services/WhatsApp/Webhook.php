<?php

namespace App\Services\WhatsApp;

use App\Services\Customer\Phone;
use App\Services\NotificationService;
use Vayu\Database\DB;

/**
 * Meta webhook for /webhooks/whatsapp (docs/whatsapp.md §4): verification handshake, HMAC
 * signature check, delivery statuses and incoming messages (incl. STOP opt-out).
 * Idempotent on wamid — Meta retries, so a repeated delivery is a no-op.
 */
final class Webhook
{
    /** Status only moves forward; a late "delivered" after "read" is ignored. */
    public const RANK = ['queued' => 0, 'opened' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
    public const STOP_WORDS = ['STOP', 'UNSUBSCRIBE', 'STOP ALL', 'STOP OFFERS'];

    /**
     * GET handshake. PHP turns "hub.verify_token" into "hub_verify_token" in $_GET, so both spellings work.
     *
     * @param array<string,mixed> $query
     * @return string|null the challenge to echo, or null (→ 403)
     */
    public static function verify(array $query): ?string
    {
        $q = static fn(string $k): string => (string) ($query['hub.' . $k] ?? $query['hub_' . $k] ?? '');
        $expected = (string) WhatsAppService::cfg('webhook_verify_token', '');
        if ($expected === '' || $q('mode') !== 'subscribe' || !hash_equals($expected, $q('verify_token'))) {
            return null;
        }

        return $q('challenge');
    }

    /** X-Hub-Signature-256 = "sha256=" + HMAC-SHA256(raw body, app secret), constant-time compare. */
    public static function validSignature(string $raw, ?string $header): bool
    {
        $secret = (string) WhatsAppService::cfg('app_secret', '');
        $header = trim((string) $header);
        if ($secret === '' || !str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $raw, $secret), strtolower(substr($header, 7)));
    }

    /**
     * @param array<string,mixed> $payload decoded JSON body
     * @return array{statuses:int, inbound:int, opt_outs:int, ignored:int}
     */
    public static function handle(array $payload): array
    {
        $stats = ['statuses' => 0, 'inbound' => 0, 'opt_outs' => 0, 'ignored' => 0];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                foreach ((array) ($value['statuses'] ?? []) as $s) {
                    self::status((array) $s) ? $stats['statuses']++ : $stats['ignored']++;
                }
                $names = [];
                foreach ((array) ($value['contacts'] ?? []) as $c) {
                    $names[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
                }
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $r = self::inbound((array) $m, $names);
                    $r === null ? $stats['ignored']++ : $stats['inbound']++;
                    if ($r === 'stop') {
                        $stats['opt_outs']++;
                    }
                }
            }
        }

        return $stats;
    }

    /** @param array<string,mixed> $s */
    private static function status(array $s): bool
    {
        $wamid = (string) ($s['id'] ?? '');
        $new = strtolower((string) ($s['status'] ?? ''));
        if ($wamid === '' || ($new !== 'failed' && !isset(self::RANK[$new]))) {
            return false;
        }
        $row = DB::selectOne("SELECT id, status FROM whatsapp_messages WHERE wamid = ? AND direction = 'out'", [$wamid]);
        if ($row === null) {
            return false;
        }
        $cur = (string) $row['status'];
        if ($cur === 'failed' || $cur === $new) {
            return false;
        }
        if ($new === 'failed') {
            // A failure after it was already delivered / read makes no sense — ignore it.
            if ((self::RANK[$cur] ?? 0) >= self::RANK['delivered']) {
                return false;
            }
        } elseif ((self::RANK[$new] ?? 0) <= (self::RANK[$cur] ?? 0)) {
            return false;
        }
        $err = (array) ($s['errors'][0] ?? []);
        $set = ['status' => $new, 'status_at' => self::at($s['timestamp'] ?? null)];
        if ($new === 'failed') {
            $set['error_code'] = isset($err['code']) ? mb_substr((string) $err['code'], 0, 20) : null;
            $set['error_text'] = mb_substr(trim((string) ($err['error_data']['details'] ?? '') ?: (string) ($err['title'] ?? $err['message'] ?? 'Not delivered.')), 0, 255);
        }
        // Guarded on the status we read, so two retries can't both "win".
        $sets = implode(', ', array_map(static fn($k) => $k . ' = ?', array_keys($set)));

        return DB::update("UPDATE whatsapp_messages SET $sets WHERE id = ? AND status = ?", array_merge(array_values($set), [(int) $row['id'], $cur])) === 1;
    }

    /**
     * @param array<string,mixed> $m
     * @param array<string,string> $names wa_id → profile name
     * @return string|null 'stop' | 'message' | null (duplicate / unusable)
     */
    private static function inbound(array $m, array $names): ?string
    {
        $wamid = (string) ($m['id'] ?? '');
        $from = preg_replace('/\D+/', '', (string) ($m['from'] ?? '')) ?? '';
        if ($wamid === '' || $from === '') {
            return null;
        }
        if (DB::selectOne('SELECT id FROM whatsapp_messages WHERE wamid = ?', [$wamid]) !== null) {
            return null; // Meta retry
        }
        $phone = Phone::normalize($from) ?? mb_substr($from, 0, 20);
        $type = (string) ($m['type'] ?? 'text');
        $text = match ($type) {
            'text' => (string) ($m['text']['body'] ?? ''),
            'button' => (string) ($m['button']['text'] ?? ''),
            'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
            default => '[' . $type . ']' . (isset($m[$type]['caption']) ? ' ' . $m[$type]['caption'] : ''),
        };
        $customer = DB::selectOne('SELECT id, name, marketing_consent FROM customers WHERE phone = ?', [$phone]);
        $now = date('Y-m-d H:i:s');
        $isStop = in_array(strtoupper(trim(preg_replace('/[^\pL\s]/u', '', $text) ?? '')), self::STOP_WORDS, true);
        try {
            DB::table('whatsapp_messages')->insert([
                'direction' => 'in', 'wamid' => $wamid, 'phone' => $phone, 'template' => null, 'category' => 'service',
                'params_json' => null, 'body_preview' => mb_substr($text, 0, 1000), 'mode' => 'api', 'status' => 'received',
                'error_text' => $isStop ? 'Opt-out (STOP)' . ($customer ? '' : ' — no customer with this number') : null,
                'related_type' => $customer ? 'customer' : null, 'related_id' => $customer ? (int) $customer['id'] : null,
                'created_at' => self::at($m['timestamp'] ?? null), 'status_at' => $now,
            ]);
        } catch (\Throwable $e) {
            return null; // unique wamid race with a parallel retry
        }
        $who = $customer['name'] ?? (($names[$from] ?? '') !== '' ? $names[$from] : WhatsAppService::mask($phone));
        $url = route('admin.settings.whatsapp') . '?direction=in';
        if ($isStop) {
            if ($customer && (int) $customer['marketing_consent'] === 1) {
                DB::table('customers')->where('id', (int) $customer['id'])->update(['marketing_consent' => 0, 'updated_at' => $now]);
            }
            NotificationService::notify('whatsapp.inbound', $who . ' unsubscribed from WhatsApp offers', 'They replied STOP. Marketing messages are off; order and payment updates still go out.',
                $url, 'warning', ['phone' => $phone], null, 'message-circle-off');

            return 'stop';
        }
        NotificationService::notify('whatsapp.inbound', $who . ' replied on WhatsApp', mb_substr($text, 0, 200) ?: null, $url, 'info',
            ['phone' => $phone], 'whatsapp:in:' . $phone, 'message-circle');

        return 'message';
    }

    /** Unix timestamp from Meta → IST datetime (now when missing). */
    private static function at(mixed $ts): string
    {
        return is_numeric($ts) && (int) $ts > 0 ? date('Y-m-d H:i:s', (int) $ts) : date('Y-m-d H:i:s');
    }
}
