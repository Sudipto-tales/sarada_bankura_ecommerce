<?php

namespace App\Services\Store;

use Vayu\Database\DB;

/**
 * In-app notifications for customers (docs/mobile-app.md, "In-app notifications").
 * The only writer of customer_notifications.
 *
 * Order hooks (Push\OrderPush) add a row for every order update, whether or not FCM push is set up.
 * The header bell shows the unread count, /account/notifications lists them (opening the tab marks
 * them read), and store.js polls /account/notifications/new while a page is open, so an update
 * shows as a toast without a reload. Each customer keeps the newest KEEP rows.
 */
final class Inbox
{
    public const KEEP = 50;
    public const PAGE = 30;

    /** @return int the new row's id, 0 when it could not be stored */
    public static function add(int $customerId, string $title, string $body, ?string $url = null, ?string $tag = null): int
    {
        if ($customerId <= 0) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('customer_notifications')->insertGetId([
            'customer_id' => $customerId,
            'title' => mb_substr($title, 0, 150),
            'body' => mb_substr($body, 0, 500),
            'url' => $url !== null && str_starts_with($url, '/') ? mb_substr($url, 0, 255) : null,
            'tag' => $tag !== null ? mb_substr($tag, 0, 40) : null,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // Keep the newest KEEP: the id of the KEEP-th newest row is the cut-off.
        $cut = DB::selectOne('SELECT id FROM customer_notifications WHERE customer_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . (self::KEEP - 1), [$customerId]);
        if ($cut !== null) {
            DB::delete('DELETE FROM customer_notifications WHERE customer_id = ? AND id < ?', [$customerId, (int) $cut['id']]);
        }

        return $id;
    }

    public static function unread(int $customerId): int
    {
        if ($customerId <= 0) {
            return 0;
        }

        return (int) (DB::selectOne('SELECT COUNT(*) AS n FROM customer_notifications WHERE customer_id = ? AND read_at IS NULL', [$customerId])['n'] ?? 0);
    }

    /** Highest id the customer has, so the page's poller only reports rows added after it rendered. */
    public static function lastId(int $customerId): int
    {
        if ($customerId <= 0) {
            return 0;
        }

        return (int) (DB::selectOne('SELECT MAX(id) AS m FROM customer_notifications WHERE customer_id = ?', [$customerId])['m'] ?? 0);
    }

    /** @return list<array<string,mixed>> newest first */
    public static function latest(int $customerId, int $limit = self::PAGE): array
    {
        if ($customerId <= 0) {
            return [];
        }

        return DB::select('SELECT id, title, body, url, read_at, created_at FROM customer_notifications WHERE customer_id = ? ORDER BY id DESC LIMIT ' . max(1, min($limit, self::KEEP)), [$customerId]);
    }

    /** @return list<array{id:int,title:string,body:string,url:?string}> rows after $afterId, oldest first (at most 5) */
    public static function since(int $customerId, int $afterId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        $rows = DB::select('SELECT id, title, body, url FROM customer_notifications WHERE customer_id = ? AND id > ? AND read_at IS NULL ORDER BY id DESC LIMIT 5', [$customerId, max(0, $afterId)]);

        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'title' => (string) $r['title'], 'body' => (string) $r['body'], 'url' => $r['url'] !== null ? (string) $r['url'] : null,
        ], array_reverse($rows));
    }

    public static function markAllRead(int $customerId): void
    {
        if ($customerId > 0) {
            DB::update('UPDATE customer_notifications SET read_at = ?, updated_at = ? WHERE customer_id = ? AND read_at IS NULL',
                [$now = date('Y-m-d H:i:s'), $now, $customerId]);
        }
    }

    /** Account deletion. */
    public static function forget(int $customerId): void
    {
        if ($customerId > 0) {
            DB::delete('DELETE FROM customer_notifications WHERE customer_id = ?', [$customerId]);
        }
    }
}
