<?php

namespace App\Services\Inventory;

use Vayu\Database\DB;

/** Key/value inventory configuration (table inventory_settings), cached per request. */
final class InventorySettings
{
    public const DEFAULTS = [
        'is_enabled' => '1',
        'default_threshold' => '10',
        'notify_low' => '1',
        'notify_out' => '1',
        'notify_recovered' => '1',
        'notify_import' => '1',
        'retention_days' => '90',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = DB::table('inventory_settings')->get();
            $values = [];
            foreach ($rows as $r) {
                $values[(string) $r['key']] = (string) $r['value'];
            }
            self::$cache = $values + self::DEFAULTS;
        }

        return self::$cache;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function enabled(): bool
    {
        return self::bool('is_enabled');
    }

    public static function defaultThreshold(): float
    {
        return max(0.0, (float) self::get('default_threshold'));
    }

    public static function set(string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');
        if (DB::table('inventory_settings')->where('key', $key)->exists()) {
            DB::table('inventory_settings')->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);
        } else {
            DB::table('inventory_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
