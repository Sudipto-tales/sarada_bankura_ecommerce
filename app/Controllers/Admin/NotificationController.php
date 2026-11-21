<?php

namespace App\Controllers\Admin;

use App\Services\NotificationService as Notes;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Admin notifications: the topbar bell panel (JSON feed + actions) and the full page.
 * Actions answer JSON when called from the panel (Accept: application/json), else redirect.
 */
class NotificationController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = [
            'type' => (string) $request->query('type', ''),
            'state' => in_array($request->query('state'), ['unread', 'read'], true) ? (string) $request->query('state') : '',
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];
        $page = max(1, (int) $request->query('page', 1));
        $res = Notes::search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return view('admin.notifications.index', [
            'active' => 'notifications',
            'items' => array_map([self::class, 'present'], $res['items']),
            'total' => $res['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($res['total'] / self::PER_PAGE)),
            'filters' => $filters,
            'types' => array_map(static fn($t) => $t[0], Notes::TYPES),
            'counts' => Notes::tabCounts(),
            'success' => session()->get('success'),
        ]);
    }

    /** Panel data: unread badge count, per-tab unread counts and the latest items for a tab. */
    public function feed(Request $request): Response
    {
        $tab = in_array($request->query('tab'), Notes::TABS, true) ? (string) $request->query('tab') : 'all';
        $counts = Notes::tabCounts();
        $out = ['unread' => $counts['unread'], 'counts' => $counts];
        if (!$request->query('count_only')) {
            $out['items'] = array_map([self::class, 'present'], Notes::search(['tab' => $tab], 8)['items']);
        }

        return json($out);
    }

    /** Open a notification: mark it read and go to its page (works as a plain link). */
    public function open(Request $request, string $id): Response
    {
        $n = Notes::find((int) $id);
        if (!$n) {
            return redirect(route('admin.notifications'));
        }
        Notes::markRead((int) $id);
        $p = self::present($n);
        $to = $request->query('action') === 'receive' && $p['action'] ? $p['action']['url'] : ($p['url'] ?: route('admin.notifications'));

        return redirect($to);
    }

    public function read(Request $request, string $id): Response
    {
        Notes::markRead((int) $id);

        return $this->done($request, 'Marked as read.');
    }

    public function readAll(Request $request): Response
    {
        $n = Notes::markAllRead();

        return $this->done($request, $n ? 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' as read.' : 'You are all caught up.');
    }

    public function destroy(Request $request, string $id): Response
    {
        Notes::delete((int) $id);

        return $this->done($request, 'Notification removed.');
    }

    public function bulk(Request $request): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($request->input('ids') ?? []))));
        $action = (string) $request->input('action');
        if (!$ids) {
            return $this->done($request, 'Select at least one notification.');
        }
        $n = match ($action) {
            'read' => Notes::markReadMany($ids),
            'unread' => Notes::markUnreadMany($ids),
            'delete' => Notes::deleteMany($ids),
            default => 0,
        };
        $verb = ['read' => 'marked as read', 'unread' => 'marked as unread', 'delete' => 'deleted'][$action] ?? 'updated';

        return $this->done($request, $n . ' notification' . ($n === 1 ? '' : 's') . ' ' . $verb . '.');
    }

    /* ---------------------------------------------------------------- */

    /**
     * View model shared by the panel JSON and the page.
     *
     * @param array<string,mixed> $n hydrated notification row
     * @return array<string,mixed>
     */
    public static function present(array $n): array
    {
        $type = (string) $n['type'];
        $meta = Notes::TYPES[$type] ?? [ucfirst(str_replace(['.', '_'], ' ', $type)), 'bell', 'system'];
        $ts = strtotime((string) $n['created_at']) ?: time();
        $id = (int) $n['id'];
        $url = $n['url'] ? (preg_match('~^https?://~', (string) $n['url']) ? (string) $n['url'] : base_url(ltrim((string) $n['url'], '/'))) : null;

        // Stock alerts offer a one-click "Receive stock" while their alert is still active.
        $action = null;
        $pid = (int) ($n['data']['product_id'] ?? 0);
        $alertId = (int) ($n['data']['alert_id'] ?? 0);
        if ($pid && $alertId && in_array($type, ['stock.out', 'stock.low'], true)
            && \Vayu\Database\DB::table('inventory_alerts')->where('id', $alertId)->where('status', 'active')->exists()) {
            $action = ['label' => 'Receive stock', 'url' => route('admin.inventory.stock') . '?product=' . $pid . '&receive=1',
                'open' => route('admin.notifications.open', ['id' => $id]) . '?action=receive'];
        }

        return [
            'id' => $id,
            'type' => $type,
            'type_label' => $meta[0],
            'tab' => $meta[2],
            'level' => in_array($n['level'], Notes::LEVELS, true) ? $n['level'] : 'info',
            'icon' => $n['icon'] ?: $meta[1],
            'title' => (string) $n['title'],
            'body' => (string) ($n['body'] ?? ''),
            'url' => $url,
            'open' => route('admin.notifications.open', ['id' => $id]),
            'read' => route('admin.notifications.read', ['id' => $id]),
            'delete' => route('admin.notifications.delete', ['id' => $id]),
            'action' => $action,
            'is_read' => (bool) $n['is_read'],
            'group' => self::group($ts),
            'ago' => self::ago($ts),
            'when' => date('d M Y, H:i', $ts),
        ];
    }

    private static function group(int $ts): string
    {
        $days = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $ts))) / 86400);

        return $days <= 0 ? 'Today' : ($days === 1 ? 'Yesterday' : 'Earlier');
    }

    private static function ago(int $ts): string
    {
        $s = max(0, time() - $ts);

        return match (true) {
            $s < 60 => 'now',
            $s < 3600 => floor($s / 60) . 'm',
            $s < 86400 => floor($s / 3600) . 'h',
            $s < 7 * 86400 => floor($s / 86400) . 'd',
            default => date('d M', $ts),
        };
    }

    private function done(Request $request, string $message): Response
    {
        if ($request->wantsJson() || $request->isJson()) {
            return json(['ok' => true, 'message' => $message, 'unread' => Notes::unreadCount()]);
        }
        session()->flash('success', $message);
        $back = (string) ($request->header('Referer') ?? '');

        return redirect(str_contains($back, '/admin/notifications') ? $back : route('admin.notifications'));
    }
}
