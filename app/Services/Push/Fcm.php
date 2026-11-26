<?php

namespace App\Services\Push;

/**
 * Firebase Cloud Messaging, HTTP v1 API, without an SDK (docs/mobile-app.md phase 4).
 *
 * Auth: a service-account JWT signed with openssl (RS256) is exchanged for an OAuth access token,
 * cached until shortly before it expires. The service-account JSON path is FCM_SERVICE_ACCOUNT
 * (config('store.fcm_service_account')); without it push is off and send() does nothing.
 *
 * send() never throws: push is a nice-to-have next to the order flow, never a reason for it to fail.
 */
final class Fcm
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    public const CHANNEL = 'orders';
    private const CACHE_KEY = 'push.fcm.access_token';

    /** @var (\Closure(string,string,list<string>,string):array{status:int,body:string,error?:string})|null test hook */
    public static ?\Closure $http = null;

    /** @var array<string,mixed>|null test hook replacing the service-account file */
    public static ?array $account = null;

    public static function configured(): bool
    {
        return self::serviceAccount() !== null;
    }

    /** @return array{project_id:string, client_email:string, private_key:string, token_uri:string}|null */
    public static function serviceAccount(): ?array
    {
        $sa = self::$account;
        if ($sa === null) {
            $path = trim((string) config('store.fcm_service_account', ''));
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                return null;
            }
            $sa = json_decode((string) file_get_contents($path), true);
        }
        if (!is_array($sa) || empty($sa['project_id']) || empty($sa['client_email']) || empty($sa['private_key'])) {
            return null;
        }

        return [
            'project_id' => (string) $sa['project_id'],
            'client_email' => (string) $sa['client_email'],
            'private_key' => (string) $sa['private_key'],
            'token_uri' => (string) ($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /**
     * One notification to one device.
     *
     * @param array<string,string> $data extra keys for the app (url = the page to open on tap)
     * @return string 'sent' | 'off' | 'gone' (token removed) | 'failed'
     */
    public static function send(string $token, string $title, string $body, array $data = []): string
    {
        try {
            $sa = self::serviceAccount();
            if ($sa === null) {
                return 'off';
            }
            $access = self::accessToken($sa);
            if ($access === null) {
                return 'failed';
            }
            $message = [
                'token' => $token,
                'notification' => ['title' => mb_substr($title, 0, 100), 'body' => mb_substr($body, 0, 240)],
                'data' => array_map('strval', $data),
                'android' => [
                    'priority' => 'high',
                    'notification' => ['channel_id' => self::CHANNEL, 'tag' => (string) ($data['tag'] ?? '')],
                ],
            ];
            if ($message['data'] === []) {
                unset($message['data']);
            }
            if ($message['android']['notification']['tag'] === '') {
                unset($message['android']['notification']['tag']);
            }
            $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send';
            $r = self::request($url, ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
                (string) json_encode(['message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($r['status'] >= 200 && $r['status'] < 300) {
                return 'sent';
            }
            $err = json_decode($r['body'], true)['error'] ?? [];
            $codes = array_column(is_array($err['details'] ?? null) ? $err['details'] : [], 'errorCode');
            if ($r['status'] === 404 || in_array('UNREGISTERED', $codes, true)
                || ($r['status'] === 400 && in_array('INVALID_ARGUMENT', $codes, true) && stripos((string) ($err['message'] ?? ''), 'registration token') !== false)) {
                Devices::prune($token);

                return 'gone';
            }
            if ($r['status'] === 401) {
                cache()->forget(self::CACHE_KEY);
            }
            Devices::failed($token);
            error_log('[push] FCM answered HTTP ' . $r['status'] . ': ' . mb_substr((string) ($err['message'] ?? $r['error'] ?? ''), 0, 200));
        } catch (\Throwable $e) {
            error_log('[push] send failed: ' . $e->getMessage());
        }

        return 'failed';
    }

    /** OAuth access token for the service account, cached for its lifetime minus 5 minutes. */
    private static function accessToken(array $sa): ?string
    {
        $cached = cache()->get(self::CACHE_KEY);
        if (is_array($cached) && ($cached['for'] ?? '') === $sa['client_email'] && is_string($cached['token'] ?? null)) {
            return $cached['token'];
        }
        $jwt = self::jwt($sa);
        if ($jwt === null) {
            error_log('[push] could not sign the FCM service-account JWT (bad private_key?)');

            return null;
        }
        $r = self::request($sa['token_uri'], ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]));
        $j = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($j) || empty($j['access_token'])) {
            error_log('[push] FCM token exchange failed: HTTP ' . $r['status'] . ' ' . mb_substr((string) ($j['error_description'] ?? $j['error'] ?? $r['error'] ?? ''), 0, 200));

            return null;
        }
        $ttl = max(60, (int) ($j['expires_in'] ?? 3600) - 300);
        cache()->put(self::CACHE_KEY, ['for' => $sa['client_email'], 'token' => (string) $j['access_token']], $ttl);

        return (string) $j['access_token'];
    }

    /** RS256 JWT for Google's OAuth token endpoint. */
    public static function jwt(array $sa, ?int $now = null): ?string
    {
        $now ??= time();
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64((string) json_encode(['iss' => $sa['client_email'], 'scope' => self::SCOPE, 'aud' => $sa['token_uri'],
            'iat' => $now, 'exp' => $now + 3600], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($sa['private_key']);
        if ($key === false || !openssl_sign($head . '.' . $claims, $sig, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $head . '.' . $claims . '.' . $b64($sig);
    }

    /** @return array{status:int, body:string, error?:string} */
    private static function request(string $url, array $headers, string $body): array
    {
        if (self::$http) {
            return (self::$http)('POST', $url, $headers, $body);
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'The PHP curl extension is not installed.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'MaaSarada-Store/1.0',
        ]);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return $out === false ? ['status' => 0, 'body' => '', 'error' => $err] : ['status' => $status, 'body' => (string) $out];
    }
}
