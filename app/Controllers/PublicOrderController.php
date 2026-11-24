<?php

namespace App\Controllers;

use App\Controllers\Admin\OrderController;
use App\Services\Order\DocumentService as Docs;
use App\Services\Order\OrderService as Orders;
use Vayu\Database\DB;
use Vayu\Http\Response;

/**
 * Public, no-login pages reached by unguessable tokens (QR codes, links sent to the customer):
 *   /invoice/{token}  the issued invoice
 *   /order/{token}    order status, items, paid / due, the decline note
 * Both carry the payment window (documents/_pay-window.php) while money is due.
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
        $inv = Docs::invoiceFor((int) $o['id']);

        return view('documents.order', [
            'o' => $o,
            'items' => DB::select('SELECT * FROM order_items WHERE order_id = ? ORDER BY sort, id', [(int) $o['id']]),
            'delivery' => DB::selectOne('SELECT courier_name, tracking_number, tracking_url, dispatch_date, delivery_date FROM order_deliveries WHERE order_id = ?', [(int) $o['id']]),
            'shipping' => Orders::address($o['shipping_address']),
            'invoiceUrl' => $inv ? route('invoice.public', ['token' => $inv['public_token']]) : null,
            'business' => business(),
            'logo' => business('logo') && is_file(app()->publicPath((string) business('logo'))) ? base_url((string) business('logo')) : null,
            'taxRows' => Docs::taxRows(Docs::taxMode($o), $o['gst_rate'] !== null ? (float) $o['gst_rate'] : null, (float) $o['tax_amount']),
            'lastRejected' => PayController::lastRejected((int) $o['id']),
        ]);
    }
}
