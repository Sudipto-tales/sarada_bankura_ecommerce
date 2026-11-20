<?php

namespace App\Controllers\Admin;

use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Governed tag vocabulary: discovery (search/filter), merchandising (campaigns) and internal labels. */
class TagController
{
    public const PURPOSES = ['discovery' => 'Search & discovery', 'merchandising' => 'Merchandising', 'internal' => 'Internal'];

    public function index(): Response
    {
        $rows = DB::select(
            'SELECT t.*, (SELECT COUNT(*) FROM product_tags pt WHERE pt.tag_id = t.id) AS product_count
             FROM tags t ORDER BY product_count DESC, t.name'
        );
        // Up to two example product names per tag.
        $examples = [];
        foreach (DB::select('SELECT pt.tag_id, p.name FROM product_tags pt JOIN products p ON p.id = pt.product_id ORDER BY p.name') as $r) {
            if (count($examples[(int) $r['tag_id']] ?? []) < 2) {
                $examples[(int) $r['tag_id']][] = $r['name'];
            }
        }
        $live = array_filter($rows, static fn($r) => $r['status'] !== 'archived');

        return view('admin.catalog.tags', [
            'active' => 'tags',
            'rows' => $rows,
            'examples' => $examples,
            'stats' => [
                'active' => count($live),
                'tagged' => (int) (DB::select('SELECT COUNT(DISTINCT product_id) AS n FROM product_tags')[0]['n'] ?? 0),
                'assignments' => (int) DB::table('product_tags')->count(),
                'merch' => count(array_filter($live, static fn($r) => $r['purpose'] === 'merchandising')),
                'unused' => count(array_filter($live, static fn($r) => (int) $r['product_count'] === 0)),
            ],
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return redirect(route('admin.tags'));
        }
        $slug = CatalogController::slugify($name);
        if (DB::table('tags')->where('slug', $slug)->exists()) {
            session()->flash('error', 'A tag "' . $name . '" already exists.');

            return redirect(route('admin.tags'));
        }
        DB::table('tags')->insert(['name' => mb_substr($name, 0, 60), 'slug' => $slug] + $this->meta($request));
        session()->flash('success', 'Tag "' . $name . '" created.');

        return redirect(route('admin.tags'));
    }

    public function update(Request $request, string $id): Response
    {
        $tid = (int) $id;
        $name = trim((string) $request->input('name'));
        if ($name === '' || !DB::table('tags')->where('id', $tid)->exists()) {
            return redirect(route('admin.tags'));
        }
        $slug = CatalogController::slugify($name);
        $clash = DB::table('tags')->where('slug', $slug)->where('id', '!=', $tid)->exists();
        if ($clash) {
            session()->flash('error', 'Another tag already uses the name "' . $name . '".');

            return redirect(route('admin.tags'));
        }
        DB::table('tags')->where('id', $tid)->update(['name' => mb_substr($name, 0, 60), 'slug' => $slug] + $this->meta($request));
        session()->flash('success', 'Tag "' . $name . '" updated.');

        return redirect(route('admin.tags'));
    }

    public function destroy(Request $request, string $id): Response
    {
        $tid = (int) $id;
        $name = DB::table('tags')->where('id', $tid)->value('name');
        if ($name !== null) {
            // Tags are lightweight labels: removing one simply untags its products.
            DB::table('product_tags')->where('tag_id', $tid)->delete();
            DB::table('tags')->where('id', $tid)->delete();
            session()->flash('success', 'Tag "' . $name . '" deleted and removed from its products.');
        }

        return redirect(route('admin.tags'));
    }

    /** @return array<string,mixed> */
    private function meta(Request $request): array
    {
        $purpose = (string) $request->input('purpose', 'discovery');

        return [
            'purpose' => isset(self::PURPOSES[$purpose]) ? $purpose : 'discovery',
            'description' => trim((string) $request->input('description')) ?: null,
            'status' => $request->input('status') === 'archived' ? 'archived' : 'active',
        ];
    }
}
