<?php

namespace App\Services\Order;

use Vayu\Database\DB;

/**
 * Order documents (order_invoices rows) and the data the printable views need.
 *
 *  - packing slip: created when the order is packed (no number, no prices)
 *  - invoice: created once at dispatch with a gapless number MSM/26-27/0001 and a frozen copy of
 *    the totals; "Tax Invoice" only when the seller has a GSTIN and GST was charged, otherwise
 *    "Invoice / Bill of Supply" (docs/orders.md compliance flag)
 *
 * Every document has an unguessable public_token: /invoice/{token} works without login (QR).
 */
final class DocumentService
{
    public const TAX_INVOICE = 'Tax Invoice';
    public const BILL_OF_SUPPLY = 'Invoice / Bill of Supply';

    /** @return array<string,mixed> */
    public static function packingSlip(int $orderId, ?int $by = null): array
    {
        $doc = DB::selectOne("SELECT * FROM order_invoices WHERE order_id = ? AND document_type = 'packing_slip'", [$orderId]);
        if ($doc) {
            return $doc;
        }
        DB::table('order_invoices')->insert([
            'order_id' => $orderId, 'document_type' => 'packing_slip', 'title' => 'Packing slip',
            'public_token' => bin2hex(random_bytes(16)), 'invoice_date' => date('Y-m-d'), 'created_by' => $by, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return DB::selectOne("SELECT * FROM order_invoices WHERE order_id = ? AND document_type = 'packing_slip'", [$orderId]);
    }

    /** Issue the invoice once (at dispatch). Must run inside the dispatch transaction (numbering). */
    public static function issueInvoice(int $orderId, ?int $by = null): array
    {
        $doc = self::invoiceFor($orderId);
        if ($doc) {
            return $doc;
        }
        $o = DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        DB::table('order_invoices')->insert([
            'order_id' => $orderId,
            'document_type' => 'tax_invoice',
            'invoice_number' => DocumentNumber::next('invoice'),
            'title' => self::title($o),
            'public_token' => bin2hex(random_bytes(16)),
            'subtotal' => $o['subtotal'],
            'discount_amount' => $o['discount_amount'],
            'gst_rate' => $o['gst_rate'],
            'tax_mode' => self::taxMode($o),
            'tax_amount' => $o['tax_amount'],
            'total_amount' => $o['grand_total'],
            'invoice_date' => date('Y-m-d'),
            'created_by' => $by,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return self::invoiceFor($orderId);
    }

    public static function invoiceFor(int $orderId): ?array
    {
        return DB::selectOne("SELECT * FROM order_invoices WHERE order_id = ? AND document_type = 'tax_invoice'", [$orderId]);
    }

    /** cgst_sgst when the place of supply is the seller's state, igst otherwise, none without GST. */
    public static function taxMode(array $order): string
    {
        if ((float) ($order['gst_rate'] ?? 0) <= 0) {
            return 'none';
        }
        $norm = static fn($s) => mb_strtolower(trim((string) $s));

        return $norm($order['place_of_supply'] ?? '') === $norm(business('address.state')) || ($order['place_of_supply'] ?? '') === '' ? 'cgst_sgst' : 'igst';
    }

    public static function title(array $order): string
    {
        return trim((string) business('gstin')) !== '' && (float) ($order['gst_rate'] ?? 0) > 0 ? self::TAX_INVOICE : self::BILL_OF_SUPPLY;
    }

    /**
     * Tax rows for display: CGST + SGST halves, or one IGST row.
     *
     * @return list<array{label:string, amount:float}>
     */
    public static function taxRows(string $mode, ?float $rate, float $tax): array
    {
        if ($mode === 'none' || !$rate || $tax <= 0) {
            return [];
        }
        if ($mode === 'igst') {
            return [['label' => 'IGST @ ' . self::pct($rate), 'amount' => $tax]];
        }
        $half = round($tax / 2, 2);

        return [
            ['label' => 'CGST @ ' . self::pct($rate / 2), 'amount' => $half],
            ['label' => 'SGST @ ' . self::pct($rate / 2), 'amount' => round($tax - $half, 2)],
        ];
    }

    public static function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
    }

    /**
     * Everything the invoice / packing slip views render.
     *
     * @return array<string,mixed>
     */
    public static function viewData(array $order, ?array $doc): array
    {
        $items = DB::select('SELECT * FROM order_items WHERE order_id = ? ORDER BY sort, id', [(int) $order['id']]);
        $mode = $doc['tax_mode'] ?? self::taxMode($order);
        $rate = isset($doc['gst_rate']) ? (float) $doc['gst_rate'] : ($order['gst_rate'] !== null ? (float) $order['gst_rate'] : null);
        $total = (float) ($doc['total_amount'] ?? $order['grand_total']);

        return [
            'order' => $order,
            'doc' => $doc,
            'items' => $items,
            'billing' => OrderService::address($order['billing_address']) ?: OrderService::address($order['shipping_address']),
            'shipping' => OrderService::address($order['shipping_address']),
            'taxRows' => self::taxRows($mode, $rate, (float) ($doc['tax_amount'] ?? $order['tax_amount'])),
            'total' => $total,
            'words' => Money::words($total),
            'title' => $doc['title'] ?? self::title($order),
            'business' => business(),
            'logo' => business('logo') && is_file(app()->publicPath((string) business('logo'))) ? base_url((string) business('logo')) : null,
        ];
    }
}
