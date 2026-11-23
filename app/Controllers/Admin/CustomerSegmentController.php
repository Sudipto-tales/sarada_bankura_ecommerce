<?php

namespace App\Controllers\Admin;

use App\Services\Customer\SegmentMatcher as Matcher;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Validation\ValidationException;

/** Customer segments: dynamic (rule builder + live preview) or static (hand-picked members). */
class CustomerSegmentController
{
    private const PER_PAGE = 25;

    public function index(): Response
    {
        $segments = DB::select('SELECT * FROM customer_segments ORDER BY is_active DESC, name');
        $rules = [];
        foreach (DB::select('SELECT segment_id, field, operator, value FROM customer_segment_conditions ORDER BY sort_order, id') as $r) {
            $rules[(int) $r['segment_id']][] = $r;
        }
        // Live counts (cheap at shop scale) — and keep the cache honest while we are at it.
        foreach ($segments as &$s) {
            [$sql, $bind] = Matcher::forSegment($s);
            $s['live_count'] = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM customers c WHERE c.merged_into_id IS NULL AND (' . $sql . ')', $bind)['n'] ?? 0);
        }
        unset($s);

        return view('admin.customers.segments.index', [
            'segments' => $segments,
            'rules' => $rules,
            'tagNames' => $this->tagNames(),
            'totalCustomers' => (int) DB::table('customers')->whereNull('merged_into_id')->count(),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null, []);
    }

    public function edit(Request $request, string $id): Response
    {
        $s = DB::selectOne('SELECT * FROM customer_segments WHERE id = ?', [(int) $id]);

        return $s ? $this->form($s, Matcher::conditionsOf((int) $s['id'])) : redirect(route('admin.customers.segments'));
    }

    public function store(Request $request): Response
    {
        [$row, $conditions] = $this->validated($request, null);
        $now = date('Y-m-d H:i:s');
        $sid = DB::transaction(function () use ($row, $conditions, $now): int {
            $sid = (int) DB::table('customer_segments')->insertGetId($row + ['slug' => CatalogController::slug('customer_segments', $row['name']), 'created_at' => $now, 'updated_at' => $now]);
            $this->saveConditions($sid, $conditions);

            return $sid;
        });
        $n = Matcher::refresh($sid);
        session()->flash('success', 'Segment "' . $row['name'] . '" created — ' . $n . ' customer' . ($n === 1 ? '' : 's') . ' match' . ($n === 1 ? 'es' : '') . '.');

        return redirect(route('admin.customers.segments.show', ['id' => $sid]));
    }

    public function update(Request $request, string $id): Response
    {
        $sid = (int) $id;
        $seg = DB::selectOne('SELECT * FROM customer_segments WHERE id = ?', [$sid]);
        if ($seg === null) {
            return redirect(route('admin.customers.segments'));
        }
        [$row, $conditions] = $this->validated($request, $seg);
        DB::transaction(function () use ($sid, $row, $conditions, $seg): void {
            DB::table('customer_segments')->where('id', $sid)->update($row + ['updated_at' => date('Y-m-d H:i:s')]);
            $this->saveConditions($sid, $conditions);
            if ($seg['type'] === 'static' && $row['type'] === 'dynamic') {
                DB::table('customer_segment_members')->where('segment_id', $sid)->delete();
            }
        });
        Matcher::refresh($sid);
        session()->flash('success', 'Segment "' . $row['name'] . '" saved.');

        return redirect(route('admin.customers.segments.show', ['id' => $sid]));
    }

    public function show(Request $request, string $id): Response
    {
        $sid = (int) $id;
        $seg = DB::selectOne('SELECT * FROM customer_segments WHERE id = ?', [$sid]);
        if ($seg === null) {
            session()->flash('warning', 'That segment no longer exists.');

            return redirect(route('admin.customers.segments'));
        }
        [$sql, $bind] = Matcher::forSegment($seg);
        $base = 'FROM customers c WHERE c.merged_into_id IS NULL AND (' . $sql . ')';
        $total = (int) (DB::selectOne('SELECT COUNT(*) AS n ' . $base, $bind)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $members = DB::select('SELECT c.* ' . $base . ' ORDER BY c.name LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $bind);
        $cities = [];
        if ($members) {
            $ids = array_map(static fn($r) => (int) $r['id'], $members);
            foreach (DB::select('SELECT customer_id, city, district FROM customer_addresses WHERE customer_id IN (' . implode(',', $ids) . ') ORDER BY is_default DESC, id') as $a) {
                $cities[(int) $a['customer_id']] ??= $a['city'] ?: $a['district'];
            }
        }
        $stats = DB::selectOne('SELECT SUM(c.total_spent) AS spent, SUM(c.total_orders) AS orders, SUM(CASE WHEN c.marketing_consent = 1 THEN 1 ELSE 0 END) AS subscribed, SUM(CASE WHEN c.is_online = 1 THEN 1 ELSE 0 END) AS online ' . $base, $bind) ?? [];

        return view('admin.customers.segments.show', [
            'seg' => $seg,
            'conditions' => Matcher::conditionsOf($sid),
            'tagNames' => $this->tagNames(),
            'members' => $members,
            'cities' => $cities,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'stats' => $stats,
            // static segments: everyone not yet in it, for the "add customers" picker
            'candidates' => $seg['type'] === 'static'
                ? DB::select('SELECT id, name, phone FROM customers WHERE merged_into_id IS NULL AND id NOT IN (SELECT customer_id FROM customer_segment_members WHERE segment_id = ?) ORDER BY name LIMIT 500', [$sid])
                : [],
        ]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $sid = (int) $id;
        $name = DB::table('customer_segments')->where('id', $sid)->value('name');
        if ($name !== null) {
            DB::transaction(static function () use ($sid): void {
                DB::table('customer_segment_conditions')->where('segment_id', $sid)->delete();
                DB::table('customer_segment_members')->where('segment_id', $sid)->delete();
                DB::table('customer_segments')->where('id', $sid)->delete();
            });
            session()->flash('success', 'Segment "' . $name . '" deleted. Customers were not touched.');
        }

        return redirect(route('admin.customers.segments'));
    }

    /** Live preview for the builder: POST the form, get the match count + a few names back. */
    public function preview(Request $request): Response
    {
        [$conditions, $errors] = Matcher::clean((array) $request->input('conditions', []));
        $match = $request->input('match_type') === 'any' ? 'any' : 'all';

        return json([
            'ok' => true,
            'count' => $conditions ? Matcher::count($conditions, $match) : 0,
            'total' => (int) DB::table('customers')->whereNull('merged_into_id')->count(),
            'rules' => count($conditions),
            'errors' => $errors,
            'sample' => array_map(static fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'url' => route('admin.customers.show', ['id' => $r['id']])],
                $conditions ? Matcher::sample($conditions, $match) : []),
        ]);
    }

    public function refresh(Request $request, string $id): Response
    {
        $n = Matcher::refresh((int) $id);
        session()->flash('success', 'Segment refreshed — ' . $n . ' member' . ($n === 1 ? '' : 's') . '.');

        return redirect(route('admin.customers.segments.show', ['id' => (int) $id]));
    }

    /** Static segments: add hand-picked customers. */
    public function addMembers(Request $request, string $id): Response
    {
        $sid = (int) $id;
        $seg = DB::selectOne("SELECT * FROM customer_segments WHERE id = ? AND type = 'static'", [$sid]);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('customers', [])))));
        if ($seg && $ids) {
            $have = array_map(static fn($r) => (int) $r['customer_id'], DB::select('SELECT customer_id FROM customer_segment_members WHERE segment_id = ?', [$sid]));
            $real = array_map(static fn($r) => (int) $r['id'], DB::table('customers')->whereIn('id', $ids)->get(['id']));
            $add = array_values(array_diff($real, $have));
            $now = date('Y-m-d H:i:s');
            if ($add) {
                DB::table('customer_segment_members')->insert(array_map(static fn($c) => ['segment_id' => $sid, 'customer_id' => $c, 'matched_at' => $now], $add));
            }
            Matcher::refresh($sid);
            session()->flash('success', count($add) . ' customer' . (count($add) === 1 ? '' : 's') . ' added to "' . $seg['name'] . '".');
        }

        return redirect(route('admin.customers.segments.show', ['id' => $sid]));
    }

    public function removeMember(Request $request, string $id, string $customerId): Response
    {
        $sid = (int) $id;
        if (DB::table('customer_segments')->where('id', $sid)->where('type', 'static')->exists()) {
            DB::table('customer_segment_members')->where('segment_id', $sid)->where('customer_id', (int) $customerId)->delete();
            Matcher::refresh($sid);
            session()->flash('success', 'Removed from the segment.');
        }

        return redirect(route('admin.customers.segments.show', ['id' => $sid]));
    }

    /* ---------------------------------------------------------------- */

    /** @param list<array<string,mixed>> $conditions */
    private function form(?array $seg, array $conditions): Response
    {
        return view('admin.customers.segments.create', [
            'seg' => $seg,
            'isEdit' => $seg !== null,
            'conditions' => $conditions,
            'fields' => Matcher::fields(),
            'tags' => DB::select('SELECT id, name FROM customer_tags ORDER BY name'),
            'totalCustomers' => (int) DB::table('customers')->whereNull('merged_into_id')->count(),
        ]);
    }

    /** @return array{0: array<string,mixed>, 1: list<array<string,mixed>>} */
    private function validated(Request $request, ?array $seg): array
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $request->input('name')) ?? '');
        $type = $request->input('type') === 'static' ? 'static' : 'dynamic';
        [$conditions, $rowErrors] = Matcher::clean((array) $request->input('conditions', []));
        $err = [];
        if (mb_strlen($name) < 2) {
            $err['name'][] = 'Give the segment a name.';
        } else {
            $q = DB::table('customer_segments')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
            if ($seg) {
                $q->where('id', '!=', (int) $seg['id']);
            }
            if ($q->exists()) {
                $err['name'][] = 'Another segment already has this name.';
            }
        }
        if ($type === 'dynamic') {
            foreach ($rowErrors as $i => $m) {
                $err['conditions.' . $i][] = $m;
            }
            if (!$conditions && !$rowErrors) {
                $err['conditions'][] = 'Add at least one rule, or make this a static (hand-picked) segment.';
            }
        }
        if ($err) {
            throw new ValidationException($err);
        }

        return [[
            'name' => mb_substr($name, 0, 120),
            'description' => blank_to_null(mb_substr(trim((string) $request->input('description')), 0, 255)),
            'type' => $type,
            'match_type' => $request->input('match_type') === 'any' ? 'any' : 'all',
            'is_active' => $request->input('is_active', '1') === '0' ? 0 : 1,
        ], $type === 'dynamic' ? $conditions : []];
    }

    /** @param list<array<string,mixed>> $conditions */
    private function saveConditions(int $sid, array $conditions): void
    {
        DB::table('customer_segment_conditions')->where('segment_id', $sid)->delete();
        foreach ($conditions as $i => $c) {
            DB::table('customer_segment_conditions')->insert($c + ['segment_id' => $sid, 'sort_order' => $i]);
        }
    }

    /** @return array<int,string> */
    private function tagNames(): array
    {
        $out = [];
        foreach (DB::select('SELECT id, name FROM customer_tags') as $t) {
            $out[(int) $t['id']] = (string) $t['name'];
        }

        return $out;
    }
}
