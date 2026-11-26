<?php

namespace App\Services\Payment;

use App\Services\Customer\Phone;
use App\Services\Order\Money;
use App\Services\Order\PaymentService;
use App\Services\Mail\Mail;
use App\Services\WhatsApp\WhatsAppService;
use Vayu\Database\DB;

/**
 * Admin → customer payment requests (docs/payments.md). The link is the order's /pay/{token},
 * which recomputes the due amount when opened, so an old link never over-asks.
 *
 *   email     real send through Mail (SMTP, MAIL_* in .env) → logged sent / failed
 *   whatsapp  Cloud API template when active → logged sent; dormant / API failed → wa.me link the
 *             admin sends from their own WhatsApp → logged opened (WhatsAppService decides)
 *   sms       sms: link for the admin's phone → logged opened
 *
 * One request per order per 30 s (any channel), enforced with a guarded UPDATE on
 * orders.last_payment_request_at. A request never changes order or payment status.
 */
final class PaymentRequests
{
    public const CHANNELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'];
    public const COOLDOWN = 30;

    /** @var (\Closure(string,string,string):void)|null test hook replacing the SMTP send */
    public static ?\Closure $mailer = null;

    /** Absolute pay link for an order. */
    public static function link(array $order): string
    {
        $path = (string) route('pay.show', ['token' => $order['public_token']]);

        return preg_match('#^https?://#', $path) ? $path : base_url(ltrim($path, '/'));
    }

    public static function defaultMessage(array $order, ?float $amount = null): string
    {
        $amount ??= PaymentService::dueNow($order);
        $first = trim(explode(' ', trim((string) $order['customer_name']))[0] ?? '');

        return 'Namaskar' . ($first !== '' ? ' ' . $first : '') . ', ' . Money::inr($amount) . ' is due for your order ' . $order['order_number']
            . ' from ' . business('name') . '. Pay securely here: ' . self::link($order);
    }

    /** Seconds until the next request is allowed (0 = now). */
    public static function cooldownLeft(array $order): int
    {
        if (empty($order['last_payment_request_at'])) {
            return 0;
        }

        return max(0, strtotime((string) $order['last_payment_request_at']) + self::COOLDOWN - time());
    }

    /**
     * @return array{status:string, open_url:?string, recipient:string, amount:float, log_id:int}
     */
    public static function send(int $orderId, string $channel, string $to, ?string $message = null, ?int $by = null): array
    {
        $order = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null) {
            throw new PaymentException('Order not found.');
        }
        if ($order['order_status'] === 'rejected') {
            throw new PaymentException('This order was declined — there is nothing to collect.');
        }
        $amount = PaymentService::dueNow($order);
        if ($amount <= 0) {
            throw new PaymentException((float) $order['pending_verification_amount'] > 0
                ? 'A payment is waiting for verification — verify it first; nothing more is due until then.'
                : 'Nothing is due on this order.');
        }
        if (!isset(self::CHANNELS[$channel])) {
            throw new PaymentException('Choose Email, WhatsApp or SMS.');
        }
        $to = trim($to);
        if ($channel === 'email') {
            $to = mb_strtolower($to);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new PaymentException('Enter a valid email address.');
            }
        } else {
            $phone = Phone::normalize($to);
            if ($phone === null) {
                throw new PaymentException('Enter a valid 10-digit Indian mobile number.');
            }
            $to = $phone;
        }
        $link = self::link($order);
        $message = trim((string) $message) !== '' ? mb_substr(trim((string) $message), 0, 1000) : self::defaultMessage($order, $amount);
        if (!str_contains($message, $link)) {
            $message .= "\n" . $link;
        }

        // Cooldown: only one request can claim the slot.
        $now = date('Y-m-d H:i:s');
        $claimed = DB::update('UPDATE orders SET last_payment_request_at = ? WHERE id = ? AND (last_payment_request_at IS NULL OR last_payment_request_at <= ?)',
            [$now, $orderId, date('Y-m-d H:i:s', time() - self::COOLDOWN)]);
        if ($claimed !== 1) {
            throw new PaymentException('A request was just sent — wait ' . max(1, self::cooldownLeft($order)) . ' s before sending another.');
        }

        $status = 'opened';
        $error = null;
        $open = null;
        if ($channel === 'email') {
            try {
                $subject = 'Payment due ' . Money::inr($amount) . ' · Order ' . $order['order_number'];
                if (self::$mailer) {
                    (self::$mailer)($to, $subject, $message);
                } else {
                    Mail::send($to, $subject, $message);
                }
                $status = 'sent';
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = mb_substr($e->getMessage(), 0, 255);
                // A failed send doesn't use up the cooldown.
                DB::update('UPDATE orders SET last_payment_request_at = ? WHERE id = ?', [$order['last_payment_request_at'], $orderId]);
            }
        } elseif ($channel === 'whatsapp') {
            // WhatsApp (Stage 7): real payment_request template once the Cloud API is active; while
            // dormant (or if the API fails) the same wa.me link as before, sent from the admin's app.
            $first = trim(explode(' ', trim((string) $order['customer_name']))[0] ?? '');
            $wa = WhatsAppService::send('payment_request', $to, ['name' => $first ?: 'Customer', 'amount' => Money::inr($amount), 'order_number' => $order['order_number']],
                ['button' => (string) $order['public_token'], 'fallback_text' => $message, 'related_type' => 'order', 'related_id' => $orderId, 'sent_by' => $by]);
            if ($wa->sent()) {
                $status = 'sent';
            } else {
                $open = $wa->fallbackUrl ?? 'https://wa.me/91' . $to . '?text=' . rawurlencode($message);
                $error = $wa->mode === 'api' && $wa->error ? mb_substr('WhatsApp API failed: ' . $wa->error, 0, 255) : null;
            }
        } else {
            $open = 'sms:+91' . $to . '?body=' . rawurlencode($message);
        }

        $logId = (int) DB::table('payment_request_logs')->insertGetId([
            'order_id' => $orderId, 'channel' => $channel, 'recipient' => $to, 'amount' => $amount, 'message' => $message,
            'status' => $status, 'error' => $error, 'sent_by' => $by, 'created_at' => $now,
        ]);
        if ($status === 'failed') {
            throw new PaymentException('The email could not be sent: ' . $error . ' Try WhatsApp or SMS instead.');
        }

        return ['status' => $status, 'open_url' => $open, 'recipient' => $to, 'amount' => $amount, 'log_id' => $logId];
    }

    /** "98•••••210" / "ra•••@example.com" for timelines. */
    public static function maskRecipient(string $channel, string $to): string
    {
        if ($channel === 'email' && str_contains($to, '@')) {
            [$u, $d] = explode('@', $to, 2);

            return mb_substr($u, 0, 2) . '•••@' . $d;
        }

        return strlen($to) === 10 ? substr($to, 0, 2) . '•••••' . substr($to, -3) : $to;
    }
}
