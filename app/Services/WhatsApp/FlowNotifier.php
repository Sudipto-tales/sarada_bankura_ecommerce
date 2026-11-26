<?php

namespace App\Services\WhatsApp;

use App\Services\Mail\Mail;
use App\Services\Order\DocumentService;
use App\Services\Order\Money;
use Vayu\Database\DB;

/**
 * W3 hooks: automatic WhatsApp updates from the order and payment flows (docs/whatsapp.md).
 *
 * Rules:
 *  - Dormant → every hook is a no-op, so the flows behave exactly as before (wa.me links / email).
 *  - Hooks run AFTER the flow's own transaction and never throw: a WhatsApp problem can't undo
 *    or block an order / payment change. Failures are logged in whatsapp_messages.
 *  - Per-event switches in config/whatsapp.php → auto.*.
 *
 * Not here yet (other branches): return_update (Stage 6 returns), admin OTP + staff_invite (admin access).
 */
final class FlowNotifier
{
    /** @var (\Closure(string,string,string):void)|null test hook replacing the OTP email */
    public static ?\Closure $mailer = null;

    private static function on(string $key): bool
    {
        return WhatsAppService::isActive() && (bool) WhatsAppService::cfg('auto.' . $key, true);
    }

    private static function first(array $o): string
    {
        return trim(explode(' ', trim((string) ($o['customer_name'] ?? '')))[0] ?? '') ?: 'Customer';
    }

    /**
     * After OrderService::transition(). Returns how the delivery OTP reached the customer
     * ('whatsapp' | 'email' | null = not sent, the admin sends it from the order page as before).
     */
    public static function orderTransition(int $orderId, string $to, ?string $otp = null): ?string
    {
        try {
            if (!WhatsAppService::isActive()) {
                return null;
            }
            $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
            if ($o === null || !$o['customer_phone']) {
                return null;
            }
            $rel = ['related_type' => 'order', 'related_id' => $orderId, 'button' => (string) $o['public_token']];
            if ($to === 'accepted' && self::on('order_confirmed')) {
                WhatsAppService::send('order_confirmed', (string) $o['customer_phone'],
                    ['name' => self::first($o), 'order_number' => $o['order_number'], 'total' => Money::inr($o['grand_total'])], $rel);
            }
            if ($to === 'dispatched' && self::on('order_dispatched')) {
                WhatsAppService::send('order_dispatched', (string) $o['customer_phone'],
                    ['name' => self::first($o), 'order_number' => $o['order_number'], 'dispatch_date' => date('j M Y', strtotime((string) ($o['dispatched_at'] ?: 'now')))], $rel);
            }
            if ($to === 'delivered' && self::on('order_delivered')) {
                $inv = DocumentService::invoiceFor($orderId);
                WhatsAppService::send('order_delivered', (string) $o['customer_phone'],
                    ['name' => self::first($o), 'order_number' => $o['order_number']], ['button' => (string) ($inv['public_token'] ?? '')] + $rel);
            }
        } catch (\Throwable $e) {
            error_log('[whatsapp] order hook failed: ' . $e->getMessage());
        }

        return $to === 'dispatched' && $otp !== null ? self::deliveryOtp($orderId, $otp) : null;
    }

    /**
     * Delivery OTP via the auth_otp template. If the API fails, the code goes by email (never via a
     * wa.me hand-off). Dormant → null: the admin keeps today's "Send on WhatsApp" link on the order.
     *
     * @return string|null 'whatsapp' | 'email' | null
     */
    public static function deliveryOtp(int $orderId, string $otp): ?string
    {
        try {
            if (!self::on('delivery_otp')) {
                return null;
            }
            $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
            if ($o === null) {
                return null;
            }
            if ($o['customer_phone']) {
                $r = WhatsAppService::send('auth_otp', (string) $o['customer_phone'], ['code' => $otp], ['related_type' => 'order', 'related_id' => $orderId]);
                if ($r->sent()) {
                    return 'whatsapp';
                }
            }
            $email = (string) ($o['customer_email'] ?? '');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && (self::$mailer || Mail::configured())) {
                $subject = 'Delivery OTP for order ' . $o['order_number'];
                $body = 'Namaskar ' . self::first($o) . ",\n\nYour delivery OTP for order " . $o['order_number'] . ' from ' . business('name') . ' is ' . $otp
                    . ".\nShare it with our delivery person only after you receive the material.\n";
                self::$mailer ? (self::$mailer)($email, $subject, $body) : Mail::send($email, $subject, $body);

                return 'email';
            }
        } catch (\Throwable $e) {
            error_log('[whatsapp] delivery OTP hook failed: ' . $e->getMessage());
        }

        return null;
    }

    /** After Ledger::verify() / Ledger::record(): "₹X received for order …, balance ₹Y". */
    public static function paymentReceived(int $paymentId): void
    {
        try {
            if (!self::on('payment_received')) {
                return;
            }
            $p = DB::selectOne('SELECT * FROM payments WHERE id = ?', [$paymentId]);
            $o = $p ? DB::selectOne('SELECT * FROM orders WHERE id = ?', [(int) $p['order_id']]) : null;
            if ($o === null || !$o['customer_phone']) {
                return;
            }
            WhatsAppService::send('payment_received', (string) $o['customer_phone'],
                ['name' => self::first($o), 'amount' => Money::inr($p['amount']), 'order_number' => $o['order_number'], 'balance' => Money::inr(max(0, (float) $o['due_amount']))],
                ['related_type' => 'payment', 'related_id' => $paymentId, 'button' => (string) $o['public_token']]);
        } catch (\Throwable $e) {
            error_log('[whatsapp] payment received hook failed: ' . $e->getMessage());
        }
    }

    /** After Ledger::reject(): tells the customer why (the same reason shown on their order page). */
    public static function paymentRejected(int $attemptId): void
    {
        try {
            if (!self::on('payment_rejected')) {
                return;
            }
            $a = DB::selectOne('SELECT * FROM payment_attempts WHERE id = ?', [$attemptId]);
            $o = $a && $a['order_id'] ? DB::selectOne('SELECT * FROM orders WHERE id = ?', [(int) $a['order_id']]) : null;
            if ($o === null || !$o['customer_phone']) {
                return;
            }
            WhatsAppService::send('payment_rejected', (string) $o['customer_phone'],
                ['name' => self::first($o), 'order_number' => $o['order_number'], 'reason' => (string) $a['reject_reason']],
                ['related_type' => 'payment_attempt', 'related_id' => $attemptId, 'button' => (string) $o['public_token']]);
        } catch (\Throwable $e) {
            error_log('[whatsapp] payment rejected hook failed: ' . $e->getMessage());
        }
    }

    /**
     * API messages about one order (order updates, OTP, payment received / rejected) for the order timeline.
     *
     * @return list<array<string,mixed>>
     */
    public static function forOrder(int $orderId): array
    {
        try {
            return DB::select("SELECT * FROM whatsapp_messages WHERE direction = 'out' AND mode = 'api' AND (
                    (related_type = 'order' AND related_id = ?)
                 OR (related_type = 'payment' AND related_id IN (SELECT id FROM payments WHERE order_id = ?))
                 OR (related_type = 'payment_attempt' AND related_id IN (SELECT id FROM payment_attempts WHERE order_id = ?)))
                ORDER BY id DESC LIMIT 50", [$orderId, $orderId, $orderId]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Tick + label for a message status (✓ sent, ✓✓ delivered, ✓✓ read in blue, ✗ failed). */
    public static function tick(string $status): string
    {
        return ['sent' => '✓ sent', 'delivered' => '✓✓ delivered', 'read' => '✓✓ read', 'failed' => '✗ failed', 'opened' => 'opened in wa.me', 'queued' => 'queued', 'received' => 'received'][$status] ?? $status;
    }
}
