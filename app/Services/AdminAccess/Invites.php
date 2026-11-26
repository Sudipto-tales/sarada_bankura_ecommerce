<?php

namespace App\Services\AdminAccess;

use Vayu\Database\DB;

/**
 * Invite / reset links: /admin/login?invite=<token>. The token only pre-fills the login ID (the
 * temporary password is still needed); it is stored as sha256, lasts 72 hours, and making a new
 * one for the same person retires the old one. Only writer of admin_invites.
 */
final class Invites
{
    public const HOURS = 72;

    /** @return string the raw token (shown once in the link) */
    public static function issue(int $userId, string $purpose, ?int $by, string $channels = ''): string
    {
        $now = date('Y-m-d H:i:s');
        DB::update('UPDATE admin_invites SET used_at = ? WHERE admin_user_id = ? AND used_at IS NULL', [$now, $userId]);
        $token = bin2hex(random_bytes(24));
        DB::table('admin_invites')->insert([
            'admin_user_id' => $userId, 'token_hash' => hash('sha256', $token), 'purpose' => $purpose,
            'channels' => $channels !== '' ? $channels : null,
            'expires_at' => date('Y-m-d H:i:s', time() + self::HOURS * 3600), 'created_by' => $by, 'created_at' => $now,
        ]);

        return $token;
    }

    /** The open invite + its account for a raw token, or null (unknown, used or expired). */
    public static function find(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $row = DB::selectOne('SELECT i.*, u.name, u.email, u.phone, u.status FROM admin_invites i JOIN admin_users u ON u.id = i.admin_user_id
            WHERE i.token_hash = ?', [hash('sha256', $token)]);
        if ($row === null || $row['used_at'] !== null || (string) $row['expires_at'] < date('Y-m-d H:i:s') || $row['status'] === 'disabled') {
            return null;
        }

        return $row;
    }

    public static function setChannels(int $userId, string $channels): void
    {
        DB::update('UPDATE admin_invites SET channels = ? WHERE admin_user_id = ? AND used_at IS NULL', [$channels, $userId]);
    }

    /** Close every open link of this person (password set, account disabled). */
    public static function close(int $userId): void
    {
        DB::update('UPDATE admin_invites SET used_at = ? WHERE admin_user_id = ? AND used_at IS NULL', [date('Y-m-d H:i:s'), $userId]);
    }

    public static function link(string $token): string
    {
        return base_url('admin/login?invite=' . rawurlencode($token));
    }

    /** The message sent by email / WhatsApp (docs/admin-access.md). */
    public static function message(array $user, string $password, string $link, string $purpose): string
    {
        $roles = implode(', ', array_column(Roles::forUser((int) $user['id']), 'name')) ?: 'staff';
        $ids = array_filter([$user['email'] ?? null, isset($user['phone']) && $user['phone'] !== null ? LoginId::showPhone($user['phone']) : null]);
        $shop = (string) (business('name') ?: 'Maa Sarada');
        $first = $purpose === 'reset'
            ? "Hi {$user['name']}, your {$shop} Admin password has been reset by an admin."
            : "Hi {$user['name']}, you've been added to {$shop} Admin as {$roles}.";

        return $first . "\n\nLog in: {$link}\nLogin ID: " . implode(' or ', $ids)
            . "\nTemporary password: {$password}\n\nValid for " . self::HOURS . ' hours. You will be asked to set your own password.';
    }

    public static function whatsappLink(?string $phone, string $message): ?string
    {
        return $phone ? 'https://wa.me/91' . $phone . '?text=' . rawurlencode($message) : null;
    }
}
