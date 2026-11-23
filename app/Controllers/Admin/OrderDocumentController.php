<?php

namespace App\Controllers\Admin;

use App\Services\Order\DocumentService as Docs;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Printable A4 documents (own print CSS, no admin chrome). */
class OrderDocumentController
{
    public function invoice(Request $request, string $id): Response
    {
        $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [(int) $id]);
        $doc = $o ? Docs::invoiceFor((int) $o['id']) : null;
        if ($doc === null) {
            session()->flash('warning', 'The invoice is issued when the order is dispatched.');

            return redirect($o ? route('admin.orders.show', ['id' => (int) $id]) : route('admin.orders'));
        }

        return view('documents.invoice', Docs::viewData($o, $doc) + ['public' => false,
            'qrUrl' => OrderController::absolute((string) route('invoice.public', ['token' => $doc['public_token']]))]);
    }

    public function packingSlip(Request $request, string $id): Response
    {
        $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [(int) $id]);
        $doc = $o ? DB::selectOne("SELECT * FROM order_invoices WHERE order_id = ? AND document_type = 'packing_slip'", [(int) $o['id']]) : null;
        if ($doc === null) {
            session()->flash('warning', 'The packing slip is created when the order is packed.');

            return redirect($o ? route('admin.orders.show', ['id' => (int) $id]) : route('admin.orders'));
        }
        $inv = Docs::invoiceFor((int) $o['id']);

        return view('documents.packing-slip', Docs::viewData($o, null) + [
            'slip' => $doc,
            'qrUrl' => OrderController::absolute((string) ($inv ? route('invoice.public', ['token' => $inv['public_token']]) : route('order.public', ['token' => $o['public_token']]))),
            'qrLabel' => $inv ? 'Scan for the invoice' : 'Scan to track this order',
        ]);
    }
}
