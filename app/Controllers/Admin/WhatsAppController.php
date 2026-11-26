<?php

namespace App\Controllers\Admin;

use App\Services\WhatsApp\WhatsAppService as WA;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * /admin/settings/whatsapp — read-only channel status (docs/whatsapp.md): which .env values are
 * filled (✅ / ❌ only, never the values), connection + template check against Meta, a test send,
 * the month's cost estimate and the message log. Nothing here edits configuration.
 */
final class WhatsAppController
{
    public const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $f = [
            'direction' => in_array($request->query('direction'), ['in', 'out'], true) ? (string) $request->query('direction') : '',
            'status' => array_key_exists((string) $request->query('status'), WA::STATUSES) ? (string) $request->query('status') : '',
            'template' => array_key_exists((string) $request->query('template'), (array) WA::cfg('templates', [])) ? (string) $request->query('template') : '',
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? (string) $request->query('date') : '',
        ];
        $where = [];
        $bind = [];
        foreach (['direction', 'status', 'template'] as $k) {
            if ($f[$k] !== '') {
                $where[] = $k . ' = ?';
                $bind[] = $f[$k];
            }
        }
        if ($f['date'] !== '') {
            $where[] = 'created_at >= ? AND created_at < ?';
            $bind[] = $f['date'] . ' 00:00:00';
            $bind[] = date('Y-m-d', strtotime($f['date'] . ' +1 day')) . ' 00:00:00';
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $page = max(1, (int) $request->query('page', 1));
        $total = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM whatsapp_messages' . $sqlWhere, $bind)['n'] ?? 0);
        $rows = DB::select('SELECT * FROM whatsapp_messages' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $bind);
        $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        $kpi = DB::selectOne("SELECT
                SUM(CASE WHEN direction = 'out' AND mode = 'api' AND status <> 'failed' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN direction = 'out' AND status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN direction = 'out' AND mode = 'fallback' THEN 1 ELSE 0 END) AS fallback,
                SUM(CASE WHEN direction = 'in' THEN 1 ELSE 0 END) AS inbound
            FROM whatsapp_messages WHERE created_at >= ?", [$since]) ?? [];

        return view('admin.settings.whatsapp', [
            'isActive' => WA::isActive(),
            'creds' => WA::credentials(),
            'apiVersion' => (string) WA::cfg('api_version'),
            'lang' => (string) WA::cfg('default_lang'),
            'testNumber' => \App\Services\Customer\Phone::normalize((string) WA::cfg('test_number', '')),
            'check' => session()->get('wa_check'),
            'templates' => session()->get('wa_templates') ?? WA::templateStatus()['rows'],
            'cost' => WA::costEstimate(),
            'rates' => (array) WA::cfg('rates', []),
            'auto' => (array) WA::cfg('auto', []),
            'kpi' => array_map('intval', $kpi),
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'f' => $f,
            'webhookUrl' => OrderController::absolute((string) route('webhooks.whatsapp')),
        ]);
    }

    /** POST — ask Meta for the number's details and the template list (only while active). */
    public function check(Request $request): Response
    {
        if (!WA::isActive()) {
            session()->flash('warning', 'WhatsApp is dormant — fill the WHATSAPP_* values in .env and set WHATSAPP_ENABLED=true first.');

            return redirect(route('admin.settings.whatsapp'));
        }
        $c = WA::checkConnection();
        $t = WA::templateStatus();
        session()->flash('wa_check', $c);
        session()->flash('wa_templates', $t['rows']);
        if ($c['ok'] && $t['ok']) {
            session()->flash('success', 'Connected to WhatsApp.');
        } else {
            session()->flash('error', 'WhatsApp check failed: ' . ($c['error'] ?? $t['error'] ?? 'unknown error'));
        }

        return redirect(route('admin.settings.whatsapp'));
    }

    /** POST — send an auth_otp test (random code) to WHATSAPP_TEST_NUMBER. */
    public function test(Request $request): Response
    {
        $to = \App\Services\Customer\Phone::normalize((string) WA::cfg('test_number', ''));
        if (!WA::isActive() || $to === null) {
            session()->flash('warning', !WA::isActive() ? 'WhatsApp is dormant — nothing can be sent yet.' : 'Set WHATSAPP_TEST_NUMBER (your own 10-digit mobile) in .env first.');

            return redirect(route('admin.settings.whatsapp'));
        }
        $r = WA::send('auth_otp', $to, ['code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)], ['related_type' => 'test']);
        $r->sent()
            ? session()->flash('success', 'Test code sent to ' . WA::mask($to) . ' — check your WhatsApp.')
            : session()->flash('error', 'Test failed: ' . $r->error);

        return redirect(route('admin.settings.whatsapp'));
    }
}
