<?php

namespace App\Services\Customer;

use Vayu\Database\DB;

/**
 * Indian pincode → district + state + post offices, via a local cache in front of the public API.
 *
 * The browser only ever calls our proxy (/admin/lookup/pincode/{code}); swapping provider is a
 * change to fetchRemote() only. Results are cached for CACHE_DAYS; misses and outages are not
 * cached, so the form simply falls back to manual entry.
 */
final class PincodeLookup
{
    public const ENDPOINT = 'https://api.dharun.dev/v1/pincode/';
    public const TIMEOUT = 3;
    public const CACHE_DAYS = 180;

    /** @var (callable(string): array{status:int, body:?string})|null  test seam */
    public static $fetcher = null;

    public static function valid(string $code): bool
    {
        return (bool) preg_match('/^[1-9]\d{5}$/', $code);
    }

    /**
     * @return array{ok:bool, reason?:string, pincode?:string, district?:?string, state?:?string, state_code?:?string, areas?:list<string>, cached?:bool}
     */
    public static function find(string $code): array
    {
        $code = trim($code);
        if (!self::valid($code)) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        $hit = DB::selectOne('SELECT * FROM pincodes WHERE pincode = ?', [$code]);
        $fresh = $hit && $hit['cached_at'] && strtotime((string) $hit['cached_at']) > time() - self::CACHE_DAYS * 86400;
        if ($hit && $fresh) {
            return self::shape($hit, true);
        }

        $remote = self::fetchRemote($code);
        if ($remote['ok'] !== true) {
            // Stale cache beats nothing during an outage.
            return $hit && $remote['reason'] === 'unavailable' ? self::shape($hit, true) : $remote;
        }

        $row = [
            'district' => $remote['district'],
            'state' => $remote['state'],
            'state_code' => $remote['state_code'],
            'areas' => json_encode($remote['areas'], JSON_UNESCAPED_UNICODE),
            'cached_at' => date('Y-m-d H:i:s'),
        ];
        if ($hit) {
            DB::table('pincodes')->where('pincode', $code)->update($row);
        } else {
            DB::table('pincodes')->insert(['pincode' => $code] + $row);
        }

        return $remote + ['cached' => false];
    }

    /** @return array<string,mixed> */
    private static function fetchRemote(string $code): array
    {
        $res = self::$fetcher ? (self::$fetcher)($code) : self::http(self::ENDPOINT . $code);
        if ($res['status'] === 404 || $res['status'] === 400) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        $json = $res['status'] === 200 && $res['body'] ? json_decode($res['body'], true) : null;
        $d = is_array($json) ? ($json['data'] ?? null) : null;
        if (!is_array($d) || empty($d['state'])) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }
        $areas = [];
        foreach ((array) ($d['offices'] ?? []) as $o) {
            $name = trim(preg_replace('/\s+/', ' ', (string) ($o['name'] ?? '')) ?? '');
            if ($name !== '') {
                $areas[$name] = true;
            }
        }
        $areas = array_keys($areas);
        sort($areas, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'ok' => true,
            'pincode' => $code,
            'district' => isset($d['district']) ? (string) $d['district'] : null,
            'state' => (string) $d['state'],
            'state_code' => isset($d['stateCode']) ? mb_substr((string) $d['stateCode'], 0, 4) : null,
            'areas' => array_slice($areas, 0, 80),
        ];
    }

    /** @return array{status:int, body:?string} */
    private static function http(string $url): array
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['timeout' => self::TIMEOUT, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
            $body = @file_get_contents($url, false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                }
            }

            return ['status' => $status, 'body' => $body === false ? null : $body];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'MaaSarada-Admin/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? null : (string) $body];
    }

    /** @param array<string,mixed> $row */
    private static function shape(array $row, bool $cached): array
    {
        $areas = json_decode((string) ($row['areas'] ?? '[]'), true);

        return [
            'ok' => true,
            'pincode' => (string) $row['pincode'],
            'district' => $row['district'],
            'state' => $row['state'],
            'state_code' => $row['state_code'],
            'areas' => is_array($areas) ? array_values($areas) : [],
            'cached' => $cached,
        ];
    }
}
