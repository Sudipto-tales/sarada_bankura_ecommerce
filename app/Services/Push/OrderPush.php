<?php

namespace App\Services\Push;

use App\Services\Order\Money;
use App\Services\Store\Inbox;
use Vayu\Database\DB;

/**
 * Order updates as push notifications to the customer's app (docs/mobile-app.md phase 4).
 * Triggers (owner, 2026-11-27): order placed, payment verified, dispatched, delivered.
 *
 * Every update is also stored in the customer's in-app inbox (Store\Inbox), which works without FCM.
 * Like the WhatsApp hooks, these run after the flow's own transaction and never throw. With push
 * off (no FCM_SERVICE_ACCOUNT) or no app device for the customer, only the inbox row is written.
 * Tapping a notification opens the order page in the app.
 */
final class OrderPush
{
    /**
     * Seconds one event may spend on FCM. Sending runs inside the admin's (or the customer's)
     * request, and each call can wait up to 6 s, so a slow or down FCM must not stall "Mark
     * dispatched" for a minute across ten phones. Phones past the budget miss that one update.
     */
    public static float $budget = 8.0;

    /** After a checkout payment is submitted and its order created (PayWindow::submit). */
    public static function placed(int $orderId, float|string $amount): int
    {
        return self::forOrder($orderId, static fn(array $o): array => [
            'Order ' . $o['order_number'] . ' placed',
            'Thank you! We’re checking your payment of ' . Money::inr($amount) . ' and will confirm your order soon.',
        ]);
    }

    /** After Ledger::verify() / Ledger::record(). */
    public static function paymentReceived(int $paymentId): int
    {
        try {
            $p = DB::selectOne('SELECT order_id, amount FROM payments WHERE id = ?', [$paymentId]);
        } catch (\Throwable $e) {
            return 0;
        }
        if ($p === null || !$p['order_id']) {
            return 0;
        }

        return self::forOrder((int) $p['order_id'], static fn(array $o): array => [
            'Payment received · ' . $o['order_number'],
            Money::inr($p['amount']) . ' received, thank you.'
                . ((float) $o['due_amount'] > 0.004 ? ' Balance ' . Money::inr($o['due_amount']) . '.' : ' Fully paid.'),
        ]);
    }

    /** After OrderService::transition(): only dispatched and delivered reach the customer's phone. */
    public static function transition(int $orderId, string $to): int
    {
        return match ($to) {
            'dispatched' => self::forOrder($orderId, static fn(array $o): array => [
                'Order ' . $o['order_number'] . ' is on its way',
                'Your stone has left our warehouse. Keep the delivery OTP ready for our delivery person.',
            ]),
            'delivered' => self::forOrder($orderId, static fn(array $o): array => [
                'Order ' . $o['order_number'] . ' delivered',
                'Thank you for shopping with ' . business('name', 'Maa Sarada') . '. Your invoice is on the order page.',
            ]),
            default => 0,
        };
    }

    /**
     * @param \Closure(array<string,mixed>):array{0:string,1:string} $text
     * @return int push notifications sent (the inbox row is written either way)
     */
    private static function forOrder(int $orderId, \Closure $text): int
    {
        $sent = 0;
        try {
            $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
            if ($o === null || !$o['customer_id']) {
                return 0;
            }
            [$title, $body] = $text($o);
            $data = ['url' => '/order/' . $o['public_token'], 'tag' => 'order-' . $orderId];
            // The in-app inbox gets every update, with or without push.
            try {
                Inbox::add((int) $o['customer_id'], $title, $body, $data['url'], $data['tag']);
            } catch (\Throwable $e) {
                error_log('[inbox] order ' . $orderId . ': ' . $e->getMessage());
            }
            if (!Fcm::configured()) {
                return 0;
            }
            $tokens = Devices::tokensForCustomer((int) $o['customer_id']);
            if ($tokens === []) {
                return 0;
            }
            $start = microtime(true);
            foreach ($tokens as $i => $token) {
                if ($i > 0 && microtime(true) - $start > self::$budget) {
                    error_log('[push] time budget used up, skipped ' . (count($tokens) - $i) . ' device(s) for order ' . $orderId);
                    break;
                }
                $sent += Fcm::send($token, $title, $body, $data) === 'sent' ? 1 : 0;
            }
        } catch (\Throwable $e) {
            error_log('[push] order hook failed: ' . $e->getMessage());
        }

        return $sent;
    }
}
