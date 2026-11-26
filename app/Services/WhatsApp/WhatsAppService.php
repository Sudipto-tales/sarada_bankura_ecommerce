<?php

namespace App\Services\WhatsApp;

use App\Services\Customer\Phone;
use Vayu\Database\DB;

/**
 * The only class that talks to the Meta WhatsApp Cloud API and writes outgoing `whatsapp_messages`
 * (docs/whatsapp.md). Dormant-safe: until WHATSAPP_ENABLED=true and the five required .env values
 * are set, send() never calls Meta and returns a wa.me fallback instead.
 *
 * Never logged anywhere: the access token and OTP codes (stored as ••••••).
 */
final class WhatsAppService
{
    public const REQUIRED = [
        'access_token' => 'WHATSAPP_ACCESS_TOKEN',
        'phone_number_id' => 'WHATSAPP_PHONE_NUMBER_ID',
        'business_account_id' => 'WHATSAPP_BUSINESS_ACCOUNT_ID',
        'app_secret' => 'WHATSAPP_APP_SECRET',
        'webhook_verify_token' => 'WHATSAPP_WEBHOOK_VERIFY_TOKEN',
    ];
    public const STATUSES = ['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Failed', 'received' => 'Received', 'opened' => 'Opened in wa.me'];
    public const MASK = '••••••';

    /**
     * Test hook replacing the HTTP layer: fn(string $method, string $url, array $headers, ?string $body): array{status:int, body:string}.
     * Tests must set this — the real Graph API is never called from tests.
     *
     * @var (\Closure(string,string,array,?string):array{status:int,body:string})|null
     */
    public static ?\Closure $http = null;

    /* ============================================================ state */

    public static function cfg(string $key, mixed $default = null): mixed
    {
        return config('whatsapp.' . $key, $default);
    }

    /** @return array<string,bool> env key → filled? (for the status page; values are never returned) */
    public static function credentials(): array
    {
        $out = ['WHATSAPP_ENABLED' => (bool) self::cfg('enabled', false)];
        foreach (self::REQUIRED as $key => $env) {
            $out[$env] = trim((string) self::cfg($key, '')) !== '';
        }

        return $out;
    }

    /** Active = switched on AND every required value present. Anything less is dormant. */
    public static function isActive(): bool
    {
        return !in_array(false, self::credentials(), true);
    }

    /** @return array<string,mixed> */
    public static function template(string $event): array
    {
        $t = self::cfg('templates.' . $event);
        if (!is_array($t)) {
            throw new \InvalidArgumentException('Unknown WhatsApp event "' . $event . '".');
        }

        return $t + ['name' => $event, 'category' => 'utility', 'params' => [], 'button' => null];
    }

    /** 10-digit mobile → "919830012345" (Meta format, no +). */
    public static function international(string $phone): string
    {
        return '91' . $phone;
    }

    public static function waMeUrl(string $phone, string $text): string
    {
        return 'https://wa.me/' . self::international($phone) . ($text !== '' ? '?text=' . rawurlencode($text) : '');
    }

    /* ============================================================ send */

    /**
     * Send an app event as its approved template.
     *
     * @param array<string,scalar|null> $params body variables by name (config templates.*.params)
     * @param array{button?:?string, fallback_text?:?string, related_type?:?string, related_id?:?int, sent_by?:?int, campaign_id?:?int} $opts
     *   button         URL button token (/order/{token}…) or nothing; the OTP button gets the code automatically
     *   fallback_text  the plain text for a wa.me fallback; leave out for automatic sends (no fallback)
     */
    public static function send(string $event, string $phone, array $params, array $opts = []): SendResult
    {
        $tpl = self::template($event);
        $isOtp = $tpl['category'] === 'authentication';
        $to = Phone::normalize($phone);
        if ($to === null) {
            return new SendResult('fallback', false, null, null, 'Not a valid 10-digit Indian mobile number.');
        }
        $ordered = [];
        foreach ((array) $tpl['params'] as $name) {
            $v = trim((string) ($params[$name] ?? ''));
            $ordered[$name] = $v === '' ? '-' : mb_substr(preg_replace('/\s+/', ' ', $v) ?? $v, 0, 900);
        }
        $logged = $isOtp ? array_map(static fn() => self::MASK, $ordered) : $ordered;
        $fallbackText = $isOtp ? null : (isset($opts['fallback_text']) && trim((string) $opts['fallback_text']) !== '' ? (string) $opts['fallback_text'] : null);
        $fallbackUrl = $fallbackText !== null ? self::waMeUrl($to, $fallbackText) : null;
        $row = [
            'direction' => 'out', 'phone' => $to, 'template' => $event, 'category' => $tpl['category'],
            'params_json' => json_encode($logged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'body_preview' => mb_substr($fallbackText ?? ($tpl['name'] . ': ' . implode(' · ', $logged)), 0, 1000),
            'related_type' => $opts['related_type'] ?? null, 'related_id' => isset($opts['related_id']) ? (int) $opts['related_id'] : null,
            'campaign_id' => $opts['campaign_id'] ?? null, 'sent_by' => $opts['sent_by'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (!self::isActive()) {
            // Dormant: today's behaviour. Only a hand-off the admin actually opens is logged.
            $id = $fallbackUrl !== null ? self::log($row + ['mode' => 'fallback', 'status' => 'opened', 'status_at' => $row['created_at']]) : null;

            return new SendResult('fallback', $fallbackUrl !== null, null, $fallbackUrl, $fallbackUrl === null ? 'WhatsApp isn’t switched on.' : null, $id);
        }

        if ($isOtp && ($limit = self::otpLimited($to)) !== null) {
            return new SendResult('api', false, null, null, $limit);
        }

        $components = [];
        if ($ordered) {
            $components[] = ['type' => 'body', 'parameters' => array_map(static fn($v) => ['type' => 'text', 'text' => $v], array_values($ordered))];
        }
        $buttonText = $tpl['button'] === 'copy_code' ? (string) reset($ordered) : (string) ($opts['button'] ?? '');
        if ($tpl['button'] && $buttonText !== '') {
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $buttonText]]];
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => self::international($to),
            'type' => 'template',
            'template' => ['name' => (string) $tpl['name'], 'language' => ['code' => (string) self::cfg('default_lang', 'en')]] + ($components ? ['components' => $components] : []),
        ];

        $res = self::call('POST', '/' . rawurlencode((string) self::cfg('phone_number_id')) . '/messages', $payload);
        $wamid = $res['json']['messages'][0]['id'] ?? null;
        if ($res['status'] >= 200 && $res['status'] < 300 && is_string($wamid) && $wamid !== '') {
            $id = self::log($row + ['mode' => 'api', 'status' => 'sent', 'wamid' => $wamid, 'status_at' => date('Y-m-d H:i:s')]);

            return new SendResult('api', true, $wamid, null, null, $id);
        }
        [$code, $text] = self::errorOf($res);
        $id = self::log($row + ['mode' => 'api', 'status' => 'failed', 'error_code' => $code, 'error_text' => $text, 'status_at' => date('Y-m-d H:i:s')]);

        return new SendResult('api', false, null, $fallbackUrl, $text, $id);
    }

    /** Refusal message when this phone already got too many codes, else null. */
    private static function otpLimited(string $phone): ?string
    {
        $lim = (array) self::cfg('otp_limits', []);
        $count = static fn(int $seconds): int => (int) (DB::selectOne("SELECT COUNT(*) AS n FROM whatsapp_messages WHERE direction = 'out' AND phone = ? AND category = 'authentication' AND mode = 'api' AND status <> 'failed' AND created_at > ?",
            [$phone, date('Y-m-d H:i:s', time() - $seconds)])['n'] ?? 0);
        if ($count(60) >= max(1, (int) ($lim['per_minute'] ?? 1))) {
            return 'A code was just sent to this number — wait a minute before sending another.';
        }
        if ($count(3600) >= max(1, (int) ($lim['per_hour'] ?? 5))) {
            return 'Too many codes sent to this number in the last hour.';
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    private static function log(array $row): int
    {
        return (int) DB::table('whatsapp_messages')->insertGetId($row);
    }

    /* ============================================================ status page helpers */

    /**
     * Phone number details from Meta (display name, number, quality rating, daily limit tier).
     *
     * @return array{ok:bool, error:?string, data:array<string,mixed>}
     */
    public static function checkConnection(): array
    {
        if (!self::isActive()) {
            return ['ok' => false, 'error' => 'WhatsApp is dormant — fill the .env values first.', 'data' => []];
        }
        $res = self::call('GET', '/' . rawurlencode((string) self::cfg('phone_number_id')) . '?fields=display_phone_number,verified_name,quality_rating,messaging_limit_tier,name_status');
        if ($res['status'] >= 200 && $res['status'] < 300 && is_array($res['json'])) {
            return ['ok' => true, 'error' => null, 'data' => $res['json']];
        }

        return ['ok' => false, 'error' => self::errorOf($res)[1], 'data' => []];
    }

    /**
     * Required templates vs what Meta has (approved / pending / rejected / missing).
     *
     * @return array{ok:bool, error:?string, rows:list<array{event:string,name:string,category:string,status:string}>}
     */
    public static function templateStatus(): array
    {
        $remote = [];
        $error = null;
        if (self::isActive()) {
            $res = self::call('GET', '/' . rawurlencode((string) self::cfg('business_account_id')) . '/message_templates?fields=name,status,category,language&limit=200');
            if ($res['status'] >= 200 && $res['status'] < 300) {
                foreach ((array) ($res['json']['data'] ?? []) as $t) {
                    $remote[(string) ($t['name'] ?? '')] = strtolower((string) ($t['status'] ?? ''));
                }
            } else {
                $error = self::errorOf($res)[1];
            }
        }
        $rows = [];
        foreach ((array) self::cfg('templates', []) as $event => $t) {
            $name = (string) ($t['name'] ?? $event);
            $rows[] = ['event' => (string) $event, 'name' => $name, 'category' => (string) ($t['category'] ?? 'utility'),
                'status' => self::isActive() && $error === null ? ($remote[$name] ?? 'missing') : 'unknown'];
        }

        return ['ok' => self::isActive() && $error === null, 'error' => $error, 'rows' => $rows];
    }

    /** Last inbound message within 24 h = Meta's free reply window is open for this phone. */
    public static function windowOpen(string $phone): bool
    {
        return DB::selectOne("SELECT id FROM whatsapp_messages WHERE direction = 'in' AND phone = ? AND created_at > ? LIMIT 1",
            [$phone, date('Y-m-d H:i:s', time() - 86400)]) !== null;
    }

    /**
     * Month-to-date API sends per category × config rates (estimate only).
     *
     * @return array{rows:array<string,array{n:int,cost:float}>, total:float}
     */
    public static function costEstimate(): array
    {
        $rates = (array) self::cfg('rates', []);
        $from = date('Y-m-01 00:00:00');
        $rows = [];
        $total = 0.0;
        foreach (DB::select("SELECT category, COUNT(*) AS n FROM whatsapp_messages WHERE direction = 'out' AND mode = 'api' AND status <> 'failed' AND created_at >= ? GROUP BY category", [$from]) as $r) {
            $cost = round((int) $r['n'] * (float) ($rates[$r['category']] ?? 0), 2);
            $rows[(string) $r['category']] = ['n' => (int) $r['n'], 'cost' => $cost];
            $total += $cost;
        }

        return ['rows' => $rows, 'total' => round($total, 2)];
    }

    /** "98•••••210" */
    public static function mask(?string $phone): string
    {
        $p = (string) $phone;

        return strlen($p) === 10 ? substr($p, 0, 2) . '•••••' . substr($p, -3) : $p;
    }

    /* ============================================================ HTTP */

    /**
     * @param array<string,mixed>|null $json
     * @return array{status:int, json:?array, error:?string}
     */
    private static function call(string $method, string $path, ?array $json = null): array
    {
        $url = rtrim((string) self::cfg('graph_url', 'https://graph.facebook.com'), '/') . '/' . self::cfg('api_version', 'v21.0') . $path;
        $token = (string) self::cfg('access_token', '');
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        try {
            $r = self::$http ? (self::$http)($method, $url, $headers, $body) : self::curl($method, $url, $headers, $body);
            $status = (int) ($r['status'] ?? 0);
            $decoded = json_decode((string) ($r['body'] ?? ''), true);

            return ['status' => $status, 'json' => is_array($decoded) ? $decoded : null, 'error' => $status === 0 ? (string) ($r['error'] ?? 'No response from WhatsApp.') : null];
        } catch (\Throwable $e) {
            return ['status' => 0, 'json' => null, 'error' => self::scrub($e->getMessage())];
        }
    }

    /** @return array{status:int, body:string, error?:string} */
    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'The PHP curl extension is not installed on the server.'];
        }
        $ch = curl_init($url);
        $timeout = max(1, (int) self::cfg('timeout', 8));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'MaaSarada-Admin/1.0',
        ] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return $out === false ? ['status' => 0, 'body' => '', 'error' => 'Could not reach WhatsApp: ' . $err] : ['status' => $status, 'body' => (string) $out];
    }

    /**
     * @param array{status:int, json:?array, error:?string} $res
     * @return array{0:?string, 1:string} [code, admin-safe text]
     */
    private static function errorOf(array $res): array
    {
        $e = $res['json']['error'] ?? null;
        if (is_array($e)) {
            $code = isset($e['code']) ? (string) $e['code'] : null;
            $text = trim((string) ($e['error_data']['details'] ?? '') ?: (string) ($e['message'] ?? 'WhatsApp refused the message.'));

            return [$code, mb_substr(self::scrub($text), 0, 255)];
        }
        if ($res['status'] === 0) {
            return [null, mb_substr(self::scrub((string) $res['error']), 0, 255)];
        }

        return [(string) $res['status'], 'WhatsApp answered HTTP ' . $res['status'] . '.'];
    }

    /** The token must never end up in a log, flash or exception text. */
    private static function scrub(string $text): string
    {
        $token = (string) self::cfg('access_token', '');

        return $token !== '' ? str_replace($token, '[token]', $text) : $text;
    }
}
