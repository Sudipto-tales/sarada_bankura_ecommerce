<?php

namespace App\Services\Push;

use App\Services\Order\Money;
use Vayu\Database\DB;

/**
 * Order updates as push notifications to the customer's app (docs/mobile-app.md phase 4).
 * Triggers (owner, 2026-11-27): order placed, payment verified, dispatched, delivered.
 *
 * Like the WhatsApp hooks, these run after the flow's own transaction and never throw. With push
 * off (no FCM_SERVICE_ACCOUNT) or no app device for the customer, they return before any lookup
 * beyond one indexed query. Tapping a notification opens the order page in the app.
 */
final class OrderPush
{
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
     * @return int notifications sent
     */
    private static function forOrder(int $orderId, \Closure $text): int
    {
        $sent = 0;
        try {
            if (!Fcm::configured()) {
                return 0;
            }
            $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
            if ($o === null || !$o['customer_id']) {
                return 0;
            }
            $tokens = Devices::tokensForCustomer((int) $o['customer_id']);
            if ($tokens === []) {
                return 0;
            }
            [$title, $body] = $text($o);
            $data = ['url' => '/order/' . $o['public_token'], 'tag' => 'order-' . $orderId];
            foreach ($tokens as $token) {
                $sent += Fcm::send($token, $title, $body, $data) === 'sent' ? 1 : 0;
            }
        } catch (\Throwable $e) {
            error_log('[push] order hook failed: ' . $e->getMessage());
        }

        return $sent;
    }
}
