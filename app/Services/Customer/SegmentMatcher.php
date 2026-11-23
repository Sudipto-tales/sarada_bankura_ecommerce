<?php

namespace App\Services\Customer;

use Vayu\Database\DB;

/**
 * Compiles segment rules (field + operator + value) into a portable SQL WHERE fragment over
 * `customers c`. Shared by the segment builder's live preview, the customers-list segment
 * filter and the member cache refresh.
 *
 * Only whitelisted fields/operators ever reach SQL; every value is bound. Dates are computed in
 * PHP (IST) and bound, so the same SQL runs on SQLite (dev) and MySQL (production).
 */
final class SegmentMatcher
{
    public const GROUPS = ['Behavior', 'Recency', 'Profile', 'Consent'];

    public const OPERATORS = [
        'number' => ['eq' => 'is', 'neq' => 'is not', 'gt' => 'more than', 'gte' => 'at least', 'lt' => 'less than', 'lte' => 'at most'],
        'text' => ['is' => 'is', 'is_not' => 'is not', 'contains' => 'contains', 'starts_with' => 'starts with', 'is_empty' => 'is not set'],
        'select' => ['is' => 'is', 'is_not' => 'is not'],
        'bool' => ['is_true' => 'is yes', 'is_false' => 'is no'],
        'date' => ['in_last_days' => 'within the last … days', 'older_than_days' => 'more than … days ago', 'before' => 'before', 'after' => 'after', 'is_empty' => 'never'],
        'tag' => ['has' => 'has', 'has_not' => 'does not have'],
    ];

    /** Operators that take no value. */
    public const NO_VALUE = ['is_true', 'is_false', 'is_empty'];

    /** @return array<string, array{label:string, group:string, type:string, sql?:string, address?:string, options?:array<string,string>, hint?:string}> */
    public static function fields(): array
    {
        return [
            'total_orders' => ['label' => 'Number of orders', 'group' => 'Behavior', 'type' => 'number', 'sql' => 'c.total_orders', 'hint' => 'Delivered orders (live from Stage 4)'],
            'total_spent' => ['label' => 'Amount spent (₹)', 'group' => 'Behavior', 'type' => 'number', 'sql' => 'c.total_spent'],
            'aov' => ['label' => 'Average order value (₹)', 'group' => 'Behavior', 'type' => 'number', 'sql' => '(CASE WHEN c.total_orders > 0 THEN c.total_spent / c.total_orders ELSE 0 END)'],
            'last_order_at' => ['label' => 'Last order', 'group' => 'Recency', 'type' => 'date', 'sql' => 'c.last_order_at'],
            'created_at' => ['label' => 'Customer since', 'group' => 'Recency', 'type' => 'date', 'sql' => 'c.created_at'],
            'customer_type' => ['label' => 'Customer type', 'group' => 'Profile', 'type' => 'select', 'sql' => 'c.customer_type', 'options' => CustomerService::TYPES],
            'lifecycle' => ['label' => 'Lifecycle', 'group' => 'Profile', 'type' => 'select', 'sql' => 'c.lifecycle', 'options' => CustomerService::LIFECYCLES],
            'status' => ['label' => 'Status', 'group' => 'Profile', 'type' => 'select', 'sql' => 'c.status', 'options' => CustomerService::STATUSES],
            'source' => ['label' => 'Joined via', 'group' => 'Profile', 'type' => 'select', 'sql' => 'c.source', 'options' => CustomerService::SOURCES],
            'city' => ['label' => 'City', 'group' => 'Profile', 'type' => 'text', 'address' => 'city'],
            'district' => ['label' => 'District', 'group' => 'Profile', 'type' => 'text', 'address' => 'district'],
            'state' => ['label' => 'State', 'group' => 'Profile', 'type' => 'text', 'address' => 'state'],
            'pincode' => ['label' => 'Pincode', 'group' => 'Profile', 'type' => 'text', 'address' => 'pincode'],
            'country' => ['label' => 'Country', 'group' => 'Profile', 'type' => 'text', 'address' => 'country'],
            'is_online' => ['label' => 'Has an online account', 'group' => 'Profile', 'type' => 'bool', 'sql' => 'c.is_online'],
            'tag' => ['label' => 'Customer tag', 'group' => 'Profile', 'type' => 'tag'],
            'marketing_consent' => ['label' => 'Subscribed to marketing', 'group' => 'Consent', 'type' => 'bool', 'sql' => 'c.marketing_consent'],
            'has_email' => ['label' => 'Has an email address', 'group' => 'Consent', 'type' => 'bool', 'sql' => "(CASE WHEN c.email IS NOT NULL AND c.email <> '' THEN 1 ELSE 0 END)"],
        ];
    }

    /**
     * Clean submitted rule rows: drop blanks and anything not on the whitelist.
     *
     * @param  array<int|string, mixed> $rows
     * @return array{0: list<array{field:string, operator:string, value:?string}>, 1: array<int,string>}  [conditions, errors by row]
     */
    public static function clean(array $rows): array
    {
        $fields = self::fields();
        $out = [];
        $errors = [];
        foreach (array_values($rows) as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $field = (string) ($r['field'] ?? '');
            $op = (string) ($r['operator'] ?? '');
            $value = trim((string) ($r['value'] ?? ''));
            if ($field === '' && $value === '') {
                continue;
            }
            $def = $fields[$field] ?? null;
            if ($def === null || !isset(self::OPERATORS[$def['type']][$op])) {
                $errors[$i] = 'Pick a field and a condition.';
                continue;
            }
            if (in_array($op, self::NO_VALUE, true)) {
                $value = '';
            } elseif ($value === '') {
                $errors[$i] = $def['label'] . ': enter a value.';
                continue;
            } elseif ($def['type'] === 'number' && !is_numeric($value)) {
                $errors[$i] = $def['label'] . ': enter a number.';
                continue;
            } elseif ($def['type'] === 'date' && in_array($op, ['in_last_days', 'older_than_days'], true) && !ctype_digit($value)) {
                $errors[$i] = $def['label'] . ': enter a whole number of days.';
                continue;
            } elseif ($def['type'] === 'date' && in_array($op, ['before', 'after'], true) && !self::isDate($value)) {
                $errors[$i] = $def['label'] . ': pick a date.';
                continue;
            } elseif ($def['type'] === 'select' && !isset($def['options'][$value])) {
                $errors[$i] = $def['label'] . ': pick an option.';
                continue;
            } elseif ($def['type'] === 'tag' && !ctype_digit($value)) {
                $errors[$i] = 'Pick a tag.';
                continue;
            }
            $out[] = ['field' => $field, 'operator' => $op, 'value' => $value === '' ? null : mb_substr($value, 0, 255)];
        }

        return [$out, $errors];
    }

    /**
     * @param  list<array{field:string, operator:string, value:?string}> $conditions
     * @return array{0:string, 1:list<mixed>}  SQL over alias `c` + bindings
     */
    public static function compile(array $conditions, string $matchType = 'all'): array
    {
        $parts = [];
        $bind = [];
        foreach ($conditions as $c) {
            $piece = self::condition($c['field'], $c['operator'], $c['value'] ?? null);
            if ($piece !== null) {
                $parts[] = '(' . $piece[0] . ')';
                array_push($bind, ...$piece[1]);
            }
        }
        if (!$parts) {
            return ['1 = 0', []]; // a dynamic segment with no rules matches nobody
        }

        return [implode($matchType === 'any' ? ' OR ' : ' AND ', $parts), $bind];
    }

    /** @return array{0:string, 1:list<mixed>}|null */
    private static function condition(string $field, string $op, ?string $value): ?array
    {
        $def = self::fields()[$field] ?? null;
        if ($def === null) {
            return null;
        }
        $v = (string) $value;

        if ($def['type'] === 'tag') {
            $sql = 'EXISTS (SELECT 1 FROM customer_tag_map m WHERE m.customer_id = c.id AND m.tag_id = ?)';

            return [$op === 'has_not' ? 'NOT ' . $sql : $sql, [(int) $v]];
        }

        if (isset($def['address'])) {
            $col = 'a.' . $def['address'];
            $exists = static fn(string $cond): string => 'EXISTS (SELECT 1 FROM customer_addresses a WHERE a.customer_id = c.id AND ' . $cond . ')';
            $lv = mb_strtolower(trim($v));

            return match ($op) {
                'is' => [$exists("LOWER($col) = ?"), [$lv]],
                'is_not' => ['NOT ' . $exists("LOWER($col) = ?"), [$lv]],
                'contains' => [$exists("LOWER($col) LIKE ?"), ['%' . self::like($lv) . '%']],
                'starts_with' => [$exists("LOWER($col) LIKE ?"), [self::like($lv) . '%']],
                'is_empty' => ['NOT ' . $exists("$col IS NOT NULL AND $col <> ''"), []],
                default => null,
            };
        }

        $col = $def['sql'];

        return match ($def['type']) {
            'number' => self::numberOp($col, $op, (float) $v),
            'select' => [$op === 'is_not' ? "($col IS NULL OR $col <> ?)" : "$col = ?", [$v]],
            'bool' => [$op === 'is_true' ? "$col = 1" : "($col = 0 OR $col IS NULL)", []],
            'date' => self::dateOp($col, $op, $v),
            default => null,
        };
    }

    /** @return array{0:string, 1:list<mixed>}|null */
    private static function numberOp(string $col, string $op, float $n): ?array
    {
        $sym = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op] ?? null;

        // Bound values arrive as strings; cast so computed expressions (AOV) compare numerically on SQLite too.
        return $sym === null ? null : ["COALESCE($col, 0) $sym CAST(? AS DECIMAL(14,2))", [$n]];
    }

    /** @return array{0:string, 1:list<mixed>}|null */
    private static function dateOp(string $col, string $op, string $v): ?array
    {
        $now = time();

        return match ($op) {
            'in_last_days' => ["$col IS NOT NULL AND $col >= ?", [date('Y-m-d H:i:s', $now - (int) $v * 86400)]],
            'older_than_days' => ["$col IS NOT NULL AND $col < ?", [date('Y-m-d H:i:s', $now - (int) $v * 86400)]],
            'before' => ["$col IS NOT NULL AND $col < ?", [date('Y-m-d 00:00:00', (int) strtotime($v))]],
            'after' => ["$col IS NOT NULL AND $col >= ?", [date('Y-m-d 00:00:00', (int) strtotime($v . ' +1 day'))]],
            'is_empty' => ["$col IS NULL", []],
            default => null,
        };
    }

    /**
     * SQL fragment "which customers belong to this segment" (dynamic → rules, static → members).
     *
     * @param  array<string,mixed> $segment  customer_segments row
     * @return array{0:string, 1:list<mixed>}
     */
    public static function forSegment(array $segment): array
    {
        if (($segment['type'] ?? 'dynamic') === 'static') {
            return ['c.id IN (SELECT customer_id FROM customer_segment_members WHERE segment_id = ?)', [(int) $segment['id']]];
        }

        return self::compile(self::conditionsOf((int) $segment['id']), (string) ($segment['match_type'] ?? 'all'));
    }

    /** @return list<array{field:string, operator:string, value:?string}> */
    public static function conditionsOf(int $segmentId): array
    {
        return array_map(static fn($r) => ['field' => $r['field'], 'operator' => $r['operator'], 'value' => $r['value']],
            DB::select('SELECT field, operator, value FROM customer_segment_conditions WHERE segment_id = ? ORDER BY sort_order, id', [$segmentId]));
    }

    /** Count live matches for a rule set (segment builder preview). */
    public static function count(array $conditions, string $matchType = 'all'): int
    {
        [$sql, $bind] = self::compile($conditions, $matchType);

        return (int) (DB::selectOne('SELECT COUNT(*) AS n FROM customers c WHERE c.merged_into_id IS NULL AND (' . $sql . ')', $bind)['n'] ?? 0);
    }

    /** @return list<array<string,mixed>> a few matching customers for the preview list */
    public static function sample(array $conditions, string $matchType = 'all', int $limit = 5): array
    {
        [$sql, $bind] = self::compile($conditions, $matchType);

        return DB::select('SELECT c.id, c.name, c.phone, c.customer_type FROM customers c WHERE c.merged_into_id IS NULL AND (' . $sql . ') ORDER BY c.name LIMIT ' . max(1, $limit), $bind);
    }

    /** Rebuild one segment's member cache (dynamic) or recount it (static). Returns the member count. */
    public static function refresh(int $segmentId): int
    {
        $seg = DB::selectOne('SELECT * FROM customer_segments WHERE id = ?', [$segmentId]);
        if ($seg === null) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        if ($seg['type'] === 'static') {
            $n = (int) DB::table('customer_segment_members')->where('segment_id', $segmentId)->count();
        } else {
            [$sql, $bind] = self::forSegment($seg);
            $ids = array_map(static fn($r) => (int) $r['id'],
                DB::select('SELECT c.id FROM customers c WHERE c.merged_into_id IS NULL AND (' . $sql . ')', $bind));
            DB::transaction(static function () use ($segmentId, $ids, $now): void {
                DB::table('customer_segment_members')->where('segment_id', $segmentId)->delete();
                foreach (array_chunk($ids, 200) as $chunk) {
                    DB::table('customer_segment_members')->insert(array_map(
                        static fn($cid) => ['segment_id' => $segmentId, 'customer_id' => $cid, 'matched_at' => $now], $chunk
                    ));
                }
            });
            $n = count($ids);
        }
        DB::table('customer_segments')->where('id', $segmentId)->update(['member_count' => $n, 'refreshed_at' => $now]);

        return $n;
    }

    public static function refreshAll(): void
    {
        foreach (DB::select('SELECT id FROM customer_segments') as $s) {
            self::refresh((int) $s['id']);
        }
    }

    /** After one customer changes: move them in/out of each dynamic segment's cache. */
    public static function refreshForCustomer(int $customerId): void
    {
        $now = date('Y-m-d H:i:s');
        foreach (DB::select("SELECT * FROM customer_segments WHERE type = 'dynamic'") as $seg) {
            [$sql, $bind] = self::forSegment($seg);
            $match = DB::selectOne('SELECT 1 AS ok FROM customers c WHERE c.id = ? AND c.merged_into_id IS NULL AND (' . $sql . ')', array_merge([$customerId], $bind)) !== null;
            $has = DB::table('customer_segment_members')->where('segment_id', (int) $seg['id'])->where('customer_id', $customerId)->exists();
            if ($match === $has) {
                continue;
            }
            if ($match) {
                DB::table('customer_segment_members')->insert(['segment_id' => (int) $seg['id'], 'customer_id' => $customerId, 'matched_at' => $now]);
            } else {
                DB::table('customer_segment_members')->where('segment_id', (int) $seg['id'])->where('customer_id', $customerId)->delete();
            }
            $n = (int) DB::table('customer_segment_members')->where('segment_id', (int) $seg['id'])->count();
            DB::table('customer_segments')->where('id', (int) $seg['id'])->update(['member_count' => $n]);
        }
    }

    /** Human sentence for one rule, e.g. "Amount spent (₹) at least 200000". */
    public static function describe(array $c, array $tagNames = []): string
    {
        $def = self::fields()[$c['field']] ?? null;
        if ($def === null) {
            return $c['field'];
        }
        $op = self::OPERATORS[$def['type']][$c['operator']] ?? $c['operator'];
        $v = (string) ($c['value'] ?? '');
        if ($def['type'] === 'select') {
            $v = $def['options'][$v] ?? $v;
        } elseif ($def['type'] === 'tag') {
            $v = $tagNames[(int) $v] ?? ('#' . $v);
        } elseif ($def['type'] === 'number' && is_numeric($v)) {
            $v = rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
        } elseif ($def['type'] === 'date' && in_array($c['operator'], ['before', 'after'], true) && self::isDate($v)) {
            $v = date('j M Y', (int) strtotime($v));
        } elseif ($def['type'] === 'date' && in_array($c['operator'], ['in_last_days', 'older_than_days'], true)) {
            $days = (int) $v . ' day' . ((int) $v === 1 ? '' : 's');

            return $def['label'] . ($c['operator'] === 'in_last_days' ? ' within the last ' . $days : ' more than ' . $days . ' ago');
        }

        return trim($def['label'] . ' ' . $op . ($v !== '' ? ' ' . $v : ''));
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }

    private static function like(string $v): string
    {
        return str_replace(['%', '_'], ['', ''], $v);
    }
}
