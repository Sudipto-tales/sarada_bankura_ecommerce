<?php

namespace App\Controllers;

use App\Controllers\Admin\OrderController;
use App\Services\Order\DocumentService as Docs;
use App\Services\Order\OrderService as Orders;
use App\Services\Order\PaymentService;
use App\Services\Payment\Accounts;
use App\Services\Store\Context as StoreContext;
use App\Services\Store\View as StoreView;
use Vayu\Database\DB;
use Vayu\Http\Response;

/**
 * Public, no-login pages reached by unguessable tokens (QR codes, links sent to the customer):
 *   /invoice/{token}  the issued invoice
 *   /order/{token}    order status, items, paid / due, the decline note (storefront look, Stage 8 S4)
 * Both carry the payment window while money is due (invoice: documents/_pay-window.php,
 * order: store/partials/pay-window.php).
 */
class PublicOrderController
{
    public function invoice(string $token): Response
    {
        $doc = preg_match('/^[a-f0-9]{32}$/', $token)
            ? DB::selectOne("SELECT * FROM order_invoices WHERE public_token = ? AND document_type = 'tax_invoice'", [$token]) : null;
        $o = $doc ? DB::selectOne('SELECT * FROM orders WHERE id = ?', [(int) $doc['order_id']]) : null;
        if ($o === null) {
            return Response::make(view('documents.not-found', ['what' => 'invoice'])->getContent(), 404);
        }

        return view('documents.invoice', Docs::viewData($o, $doc) + ['public' => true,
            'qrUrl' => OrderController::absolute((string) route('invoice.public', ['token' => $token])),
            'orderUrl' => route('order.public', ['token' => $o['public_token']])]);
    }

    public function order(string $token): Response
    {
        $o = preg_match('/^[a-f0-9]{32}$/', $token) ? DB::selectOne('SELECT * FROM orders WHERE public_token = ?', [$token]) : null;
        if ($o === null) {
            return Response::make(view('documents.not-found', ['what' => 'order'])->getContent(), 404);
        }
        \App\Services\Payment\PayWindow::expireStale();
        $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [(int) $o['id']]);
        $inv = Docs::invoiceFor((int) $o['id']);
        $data = self::storeData($o) + [
            'delivery' => DB::selectOne('SELECT courier_name, tracking_number, tracking_url, dispatch_date, delivery_date FROM order_deliveries WHERE order_id = ?', [(int) $o['id']]),
            'shipping' => Orders::address($o['shipping_address']),
            'invoiceUrl' => $inv ? route('invoice.public', ['token' => $inv['public_token']]) : null,
            'invoiceNumber' => $inv['invoice_number'] ?? null,
            'history' => self::history((int) $o['id']),
            'payments' => self::payments((int) $o['id']),
            'returns' => \App\Services\Order\ReturnService::forOrder((int) $o['id']),
            'placed' => session()->get('order_placed'),
        ];

        return StoreView::page('store.order', $data, [
            'title' => 'Order ' . $o['order_number'],
            'layout' => StoreContext::visible() ? 'app' : 'minimal',
            'active' => 'order',
            'head' => '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">',
        ]);
    }

    /**
     * What both reskinned pages (/order, /pay) need: items with pictures, tax rows, amounts.
     *
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    public static function storeData(array $o): array
    {
        StoreView::loadHelpers();
        $items = DB::select('SELECT * FROM order_items WHERE order_id = ? ORDER BY sort, id', [(int) $o['id']]);
        $cards = [];
        try {
            foreach (\App\Services\Store\Catalog::cards(array_map(fn ($i) => (int) $i['product_id'], $items)) as $c) {
                $cards[(int) $c['id']] = $c;
            }
        } catch (\Throwable) {
            $cards = [];
        }
        foreach ($items as &$it) {
            $c = $cards[(int) $it['product_id']] ?? null;
            $it['image'] = $c['image'] ?? null;
            $it['slug'] = $c['slug'] ?? null;
        }
        unset($it);

        return [
            'o' => $o,
            'items' => $items,
            'taxRows' => Docs::taxRows(Docs::taxMode($o), $o['gst_rate'] !== null ? (float) $o['gst_rate'] : null, (float) $o['tax_amount']),
            'dueNow' => PaymentService::dueNow($o),
            'canPay' => Accounts::defaultUpi() !== null,
            'lastRejected' => PayController::lastRejected((int) $o['id']),
        ];
    }

    /** First time the order reached each status. @return array<string,string> */
    private static function history(int $orderId): array
    {
        $out = [];
        foreach (DB::select('SELECT new_status, created_at FROM order_status_history WHERE order_id = ? ORDER BY id', [$orderId]) as $h) {
            $out[(string) $h['new_status']] ??= (string) $h['created_at'];
        }

        return $out;
    }

    /**
     * Money in and out, newest first: confirmed payments, submissions being checked, rejected
     * submissions, refunds.
     *
     * @return list<array{kind: string, ref: string, amount: float, method: string, reference: ?string, at: string}>
     */
    private static function payments(int $orderId): array
    {
        $methods = ['upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];
        $out = [];
        foreach (DB::select("SELECT payment_number, amount, method, reference, received_at FROM payments WHERE order_id = ? AND status = 'received'", [$orderId]) as $p) {
            $out[] = ['kind' => 'verified', 'ref' => (string) $p['payment_number'], 'amount' => (float) $p['amount'], 'method' => $methods[$p['method']] ?? (string) $p['method'], 'reference' => $p['reference'], 'at' => (string) $p['received_at']];
        }
        foreach (DB::select("SELECT attempt_number, amount, payer_reference, submitted_at, updated_at, status FROM payment_attempts WHERE order_id = ? AND status IN ('submitted', 'rejected')", [$orderId]) as $a) {
            $out[] = ['kind' => $a['status'] === 'submitted' ? 'pending' : 'rejected', 'ref' => (string) $a['attempt_number'], 'amount' => (float) $a['amount'], 'method' => 'UPI', 'reference' => $a['payer_reference'], 'at' => (string) ($a['submitted_at'] ?? $a['updated_at'])];
        }
        foreach (DB::select("SELECT refund_number, amount, method, reference, refunded_at FROM payment_refunds WHERE order_id = ? AND status = 'paid'", [$orderId]) as $r) {
            $out[] = ['kind' => 'refund', 'ref' => (string) $r['refund_number'], 'amount' => (float) $r['amount'], 'method' => $methods[$r['method']] ?? (string) $r['method'], 'reference' => $r['reference'], 'at' => (string) $r['refunded_at']];
        }
        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $out;
    }
}
