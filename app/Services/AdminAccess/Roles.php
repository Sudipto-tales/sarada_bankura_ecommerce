<?php

namespace App\Services\AdminAccess;

use Vayu\Database\DB;

/**
 * Roles and permissions. The permission list is config/permissions.php; the DB only links roles to
 * permission strings. The Owner role (slug 'owner') always has every permission.
 */
final class Roles
{
    public const OWNER = 'owner';

    /** @var array<int, list<string>> per-request cache: admin_user_id => permissions */
    private static array $cache = [];

    /**
     * Seed the starting roles that are missing (idempotent). Existing roles are left alone so the
     * owner's edits survive; the Owner role is always kept as is_system with '*'.
     * @return int roles created
     */
    public static function sync(): int
    {
        $made = 0;
        $now = date('Y-m-d H:i:s');
        $sort = 0;
        foreach ((array) config('permissions.roles', []) as $slug => $def) {
            $sort += 10;
            $row = DB::selectOne('SELECT id FROM roles WHERE slug = ?', [$slug]);
            if ($row === null) {
                $id = DB::table('roles')->insertGetId([
                    'name' => $def['name'], 'slug' => $slug, 'description' => $def['description'] ?? null,
                    'is_system' => !empty($def['system']) ? 1 : 0, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach (array_unique((array) $def['permissions']) as $p) {
                    DB::table('role_permissions')->insert(['role_id' => $id, 'permission' => $p]);
                }
                $made++;
            } elseif ($slug === self::OWNER) {
                DB::update('UPDATE roles SET is_system = 1 WHERE id = ?', [$row['id']]);
                if (!DB::selectOne("SELECT id FROM role_permissions WHERE role_id = ? AND permission = '*'", [$row['id']])) {
                    DB::table('role_permissions')->insert(['role_id' => $row['id'], 'permission' => '*']);
                }
            }
        }
        self::flush();

        return $made;
    }

    /** @return list<array<string,mixed>> roles with staff counts, in display order */
    public static function all(): array
    {
        return DB::select('SELECT r.*, (SELECT COUNT(*) FROM admin_user_roles ur WHERE ur.role_id = r.id) AS staff_count
            FROM roles r ORDER BY r.sort, r.name');
    }

    public static function ownerRoleId(): ?int
    {
        $r = DB::selectOne('SELECT id FROM roles WHERE slug = ?', [self::OWNER]);

        return $r ? (int) $r['id'] : null;
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public static function forUser(int $userId): array
    {
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug']],
            DB::select('SELECT r.id, r.name, r.slug FROM roles r JOIN admin_user_roles ur ON ur.role_id = r.id WHERE ur.admin_user_id = ? ORDER BY r.sort, r.name', [$userId]));
    }

    public static function isOwner(int $userId): bool
    {
        return in_array('*', self::permissions($userId), true);
    }

    /** @return list<string> permission strings ('*' for Owner) */
    public static function permissions(int $userId): array
    {
        if (!isset(self::$cache[$userId])) {
            $perms = array_column(DB::select('SELECT DISTINCT rp.permission FROM role_permissions rp
                JOIN admin_user_roles ur ON ur.role_id = rp.role_id WHERE ur.admin_user_id = ?', [$userId]), 'permission');
            $owner = DB::selectOne('SELECT 1 AS x FROM admin_user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.admin_user_id = ? AND r.slug = ?', [$userId, self::OWNER]);
            if ($owner) {
                $perms[] = '*';
            }
            self::$cache[$userId] = array_values(array_unique(array_map('strval', $perms)));
        }

        return self::$cache[$userId];
    }

    /** Does this staff member hold $permission? Owner: always. */
    public static function can(int $userId, string $permission): bool
    {
        $perms = self::permissions($userId);

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** Active Owners left (an Owner must always exist). */
    public static function activeOwnerCount(?int $exceptUserId = null): int
    {
        $sql = "SELECT COUNT(DISTINCT u.id) AS n FROM admin_users u JOIN admin_user_roles ur ON ur.admin_user_id = u.id
            JOIN roles r ON r.id = ur.role_id WHERE r.slug = ? AND u.status <> 'disabled'";
        $b = [self::OWNER];
        if ($exceptUserId !== null) {
            $sql .= ' AND u.id <> ?';
            $b[] = $exceptUserId;
        }

        return (int) (DB::selectOne($sql, $b)['n'] ?? 0);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
