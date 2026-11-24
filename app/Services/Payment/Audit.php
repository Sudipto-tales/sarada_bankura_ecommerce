<?php

namespace App\Services\Payment;

use Vayu\Database\DB;

/** audit_logs writer: every sensitive change (receiving accounts now; settings later). Values arrive already masked. */
final class Audit
{
    /** @param array<string, array{0:mixed,1:mixed}|mixed> $changes field => [old, new] (masked) */
    public static function log(string $entityType, ?int $entityId, string $action, array $changes, ?int $actorId = null): void
    {
        try {
            $req = request();
        } catch (\Throwable) {
            $req = null; // CLI / tests
        }
        DB::table('audit_logs')->insert([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            'actor_id' => $actorId,
            'ip' => $req ? mb_substr((string) $req->ip(), 0, 45) : null,
            'user_agent' => $req ? mb_substr((string) $req->header('User-Agent'), 0, 255) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function history(string $entityType, int $limit = 30): array
    {
        return array_map(static function (array $r): array {
            $r['changes'] = json_decode((string) $r['changes'], true) ?: [];

            return $r;
        }, DB::select('SELECT * FROM audit_logs WHERE entity_type = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$entityType]));
    }
}
