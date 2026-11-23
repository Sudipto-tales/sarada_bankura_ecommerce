<?php

namespace App\Controllers\Admin;

use App\Services\Customer\CustomerService;
use App\Services\Customer\SegmentMatcher;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Customer tags (VIP, Builder, Dealer…) — their own vocabulary, never mixed with product tags. */
class CustomerTagController
{
    public function index(): Response
    {
        $rows = DB::select('SELECT t.*, (SELECT COUNT(*) FROM customer_tag_map m WHERE m.tag_id = t.id) AS customer_count
            FROM customer_tags t ORDER BY customer_count DESC, t.name');
        $examples = [];
        foreach (DB::select('SELECT m.tag_id, c.name FROM customer_tag_map m JOIN customers c ON c.id = m.customer_id ORDER BY c.name') as $r) {
            if (count($examples[(int) $r['tag_id']] ?? []) < 3) {
                $examples[(int) $r['tag_id']][] = $r['name'];
            }
        }

        return view('admin.customers.tags.index', [
            'rows' => $rows,
            'examples' => $examples,
            'stats' => [
                'tags' => count($rows),
                'tagged' => (int) (DB::selectOne('SELECT COUNT(DISTINCT customer_id) AS n FROM customer_tag_map')['n'] ?? 0),
                'customers' => (int) DB::table('customers')->count(),
                'unused' => count(array_filter($rows, static fn($r) => (int) $r['customer_count'] === 0)),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return redirect(route('admin.customers.tags'));
        }
        $slug = CatalogController::slugify($name) ?: 'tag';
        if (DB::table('customer_tags')->where('slug', $slug)->exists()) {
            session()->flash('error', 'A customer tag "' . $name . '" already exists.');

            return redirect(route('admin.customers.tags'));
        }
        $now = date('Y-m-d H:i:s');
        DB::table('customer_tags')->insert(['name' => mb_substr($name, 0, 60), 'slug' => $slug, 'created_at' => $now, 'updated_at' => $now] + $this->meta($request));
        session()->flash('success', 'Customer tag "' . $name . '" created.');

        return redirect(route('admin.customers.tags'));
    }

    public function update(Request $request, string $id): Response
    {
        $tid = (int) $id;
        $name = trim((string) $request->input('name'));
        if ($name === '' || !DB::table('customer_tags')->where('id', $tid)->exists()) {
            return redirect(route('admin.customers.tags'));
        }
        $slug = CatalogController::slugify($name) ?: 'tag';
        if (DB::table('customer_tags')->where('slug', $slug)->where('id', '!=', $tid)->exists()) {
            session()->flash('error', 'Another customer tag already uses the name "' . $name . '".');

            return redirect(route('admin.customers.tags'));
        }
        DB::table('customer_tags')->where('id', $tid)->update(['name' => mb_substr($name, 0, 60), 'slug' => $slug, 'updated_at' => date('Y-m-d H:i:s')] + $this->meta($request));
        session()->flash('success', 'Customer tag "' . $name . '" updated.');

        return redirect(route('admin.customers.tags'));
    }

    public function destroy(Request $request, string $id): Response
    {
        $tid = (int) $id;
        $name = DB::table('customer_tags')->where('id', $tid)->value('name');
        if ($name !== null) {
            $inRules = (int) DB::table('customer_segment_conditions')->where('field', 'tag')->where('value', (string) $tid)->count();
            if ($inRules > 0) {
                session()->flash('error', 'Tag "' . $name . '" is used in ' . $inRules . ' segment rule' . ($inRules === 1 ? '' : 's') . '. Remove those rules first.');

                return redirect(route('admin.customers.tags'));
            }
            $customers = array_map(static fn($r) => (int) $r['customer_id'], DB::select('SELECT customer_id FROM customer_tag_map WHERE tag_id = ?', [$tid]));
            DB::table('customer_tag_map')->where('tag_id', $tid)->delete();
            DB::table('customer_tags')->where('id', $tid)->delete();
            foreach ($customers as $cid) {
                SegmentMatcher::refreshForCustomer($cid);
            }
            session()->flash('success', 'Customer tag "' . $name . '" deleted and removed from ' . count($customers) . ' customer' . (count($customers) === 1 ? '' : 's') . '.');
        }

        return redirect(route('admin.customers.tags'));
    }

    /** @return array<string,mixed> */
    private function meta(Request $request): array
    {
        $color = (string) $request->input('color', 'gold');

        return [
            'color' => isset(CustomerService::TAG_COLORS[$color]) ? $color : 'gold',
            'description' => blank_to_null(mb_substr(trim((string) $request->input('description')), 0, 255)),
        ];
    }
}
