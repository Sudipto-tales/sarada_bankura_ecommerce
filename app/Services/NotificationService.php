<?php

namespace App\Services;

use Vayu\Database\DB;

/**
 * Shared admin notifications (the topbar bell panel + /admin/notifications).
 *
 * Any feature can call notify(). A notification with a dedupe_key is skipped while an
 * unread one with the same key exists, so repeated checks never spam the panel.
 */
final class NotificationService
{
    public const LEVELS = ['info', 'success', 'warning', 'danger'];

    /** Known types → [label, default lucide icon, panel tab]. Unknown types fall back to "system". */
    public const TYPES = [
        'stock.out' => ['Out of stock', 'package-x', 'stock'],
        'stock.low' => ['Low stock', 'triangle-alert', 'stock'],
        'stock.recovered' => ['Back in stock', 'package-check', 'stock'],
        'import.completed' => ['Bulk upload', 'file-spreadsheet', 'system'],
        'system.check_failed' => ['System check failed', 'octagon-alert', 'system'],
    ];
    public const TABS = ['all', 'unread', 'stock', 'system'];

    /**
     * @param array<string,mixed> $data
     * @return int|null new notification id, or null when skipped as a duplicate
     */
    public static function notify(
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        string $level = 'info',
        array $data = [],
        ?string $dedupeKey = null,
        ?string $icon = null,
        ?int $userId = null,
    ): ?int {
        if ($dedupeKey !== null && DB::table('notifications')->where('dedupe_key', $dedupeKey)->whereNull('read_at')->exists()) {
            return null;
        }

        return DB::table('notifications')->insertGetId([
            'type' => $type,
            'level' => in_array($level, self::LEVELS, true) ? $level : 'info',
            'title' => mb_substr($title, 0, 255),
            'body' => $body !== null ? mb_substr($body, 0, 500) : null,
            'url' => $url,
            'icon' => $icon,
            'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'dedupe_key' => $dedupeKey,
            'user_id' => $userId,
            'read_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function unreadCount(): int
    {
        return DB::table('notifications')->whereNull('read_at')->count();
    }

    /** @return list<array<string,mixed>> newest first, `data` decoded */
    public static function latest(int $limit = 8, bool $unreadOnly = false): array
    {
        $q = DB::table('notifications')->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit($limit);
        if ($unreadOnly) {
            $q->whereNull('read_at');
        }

        return array_map([self::class, 'hydrate'], $q->get());
    }

    /**
     * Filtered, paginated list for the panel and the notifications page.
     *
     * @param array{tab?:string, type?:string, state?:string, from?:string, to?:string} $f
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public static function search(array $f, int $limit = 25, int $offset = 0): array
    {
        $q = DB::table('notifications');
        $tab = $f['tab'] ?? 'all';
        if ($tab === 'unread') {
            $q->whereNull('read_at');
        } elseif ($tab === 'stock') {
            $q->where('type', 'like', 'stock.%');
        } elseif ($tab === 'system') {
            $q->where('type', 'not like', 'stock.%');
        }
        if (!empty($f['type'])) {
            $q->where('type', (string) $f['type']);
        }
        if (($f['state'] ?? '') === 'unread') {
            $q->whereNull('read_at');
        } elseif (($f['state'] ?? '') === 'read') {
            $q->whereNotNull('read_at');
        }
        if (!empty($f['from']) && strtotime((string) $f['from'])) {
            $q->where('created_at', '>=', date('Y-m-d 00:00:00', strtotime((string) $f['from'])));
        }
        if (!empty($f['to']) && strtotime((string) $f['to'])) {
            $q->where('created_at', '<=', date('Y-m-d 23:59:59', strtotime((string) $f['to'])));
        }
        $total = (clone $q)->count();
        $rows = $q->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit($limit)->offset($offset)->get();

        return ['items' => array_map([self::class, 'hydrate'], $rows), 'total' => $total];
    }

    /** @return array{all:int, unread:int, stock:int, system:int} unread counts per panel tab (all = total). */
    public static function tabCounts(): array
    {
        $unread = DB::table('notifications')->whereNull('read_at');
        $stock = (clone $unread)->where('type', 'like', 'stock.%')->count();
        $u = $unread->count();

        return ['all' => DB::table('notifications')->count(), 'unread' => $u, 'stock' => $stock, 'system' => $u - $stock];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = DB::table('notifications')->where('id', $id)->first();

        return $row ? self::hydrate($row) : null;
    }

    /** @param list<int> $ids */
    public static function markReadMany(array $ids): int
    {
        return $ids ? DB::table('notifications')->whereIn('id', $ids)->whereNull('read_at')->update(['read_at' => date('Y-m-d H:i:s')]) : 0;
    }

    /** @param list<int> $ids */
    public static function markUnreadMany(array $ids): int
    {
        return $ids ? DB::table('notifications')->whereIn('id', $ids)->update(['read_at' => null]) : 0;
    }

    /** @param list<int> $ids */
    public static function deleteMany(array $ids): int
    {
        return $ids ? DB::table('notifications')->whereIn('id', $ids)->delete() : 0;
    }

    public static function markRead(int $id): bool
    {
        return DB::table('notifications')->where('id', $id)->whereNull('read_at')->update(['read_at' => date('Y-m-d H:i:s')]) > 0;
    }

    public static function markAllRead(): int
    {
        return DB::table('notifications')->whereNull('read_at')->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    /** Mark unread notifications with this dedupe key as read (used when the condition they describe is over). */
    public static function markReadByKey(string $dedupeKey): int
    {
        return DB::table('notifications')->where('dedupe_key', $dedupeKey)->whereNull('read_at')->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public static function delete(int $id): bool
    {
        return DB::table('notifications')->where('id', $id)->delete() > 0;
    }

    /** Delete read notifications older than $days. Unread ones are never pruned. */
    public static function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

        return DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', $cutoff)->delete();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $row['data'] = $row['data'] ? (json_decode((string) $row['data'], true) ?: []) : [];
        $row['is_read'] = $row['read_at'] !== null;

        return $row;
    }
}
