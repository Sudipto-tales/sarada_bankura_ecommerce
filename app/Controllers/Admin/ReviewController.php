<?php

namespace App\Controllers\Admin;

use App\Services\Media\MediaService;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Product reviews & ratings: moderation queue, merchant responses, rating distribution. */
class ReviewController
{
    public const SOURCES = ['verified' => 'Verified purchase', 'storefront' => 'Storefront', 'showroom' => 'Showroom visit', 'imported' => 'Imported'];
    public const STATUSES = ['published' => 'Published', 'pending' => 'Pending', 'flagged' => 'Flagged', 'archived' => 'Archived'];
    public const APPLICATIONS = ['Flooring', 'Wall cladding', 'Kitchen countertop', 'Vanity top', 'Staircase', 'Outdoor paving', 'Temple & murti', 'Table top'];

    public function index(): Response
    {
        $rows = DB::select(
            'SELECT r.*, p.name AS product_name, p.sku AS product_sku
             FROM product_reviews r LEFT JOIN products p ON p.id = r.product_id
             ORDER BY r.created_at DESC, r.id DESC'
        );
        // Customer photos (media links owner_type=review, role=photo), one query for the page.
        $photos = [];
        if ($rows) {
            $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $rows));
            foreach (DB::select("SELECT l.owner_id, m.* FROM media_links l JOIN media m ON m.id = l.media_id
                                 WHERE l.owner_type = 'review' AND l.role = 'photo' AND l.owner_id IN ({$ids}) ORDER BY l.sort, l.id") as $m) {
                $pm = MediaService::present($m);
                $photos[(int) $m['owner_id']][] = ['thumb' => $pm['thumb_url'], 'url' => $pm['url'], 'no' => $pm['media_no']];
            }
        }
        $published = array_values(array_filter($rows, static fn($r) => $r['status'] === 'published'));
        $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($published as $r) {
            $dist[max(1, min(5, (int) $r['rating']))]++;
        }
        $answered = count(array_filter($published, static fn($r) => trim((string) $r['response']) !== ''));

        return view('admin.catalog.reviews', [
            'active' => 'reviews',
            'rows' => $rows,
            'photos' => $photos,
            'dist' => $dist,
            'products' => DB::select('SELECT id, name, sku FROM products ORDER BY name'),
            'stats' => [
                'avg' => $published ? array_sum(array_map(static fn($r) => (int) $r['rating'], $published)) / count($published) : 0,
                'published' => count($published),
                'moderation' => count(array_filter($rows, static fn($r) => in_array($r['status'], ['pending', 'flagged'], true))),
                'response' => $published ? (int) round($answered / count($published) * 100) : 0,
            ],
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    public function store(Request $request): Response
    {
        $pid = (int) $request->input('product_id');
        $name = trim((string) $request->input('customer_name'));
        if (!$pid || !DB::table('products')->where('id', $pid)->exists() || $name === '') {
            session()->flash('error', 'Pick a product and enter the customer name.');

            return redirect(route('admin.reviews'));
        }
        $now = date('Y-m-d H:i:s');
        $email = trim((string) $request->input('customer_email'));
        $source = (string) $request->input('source', 'storefront');
        $status = (string) $request->input('status', 'published');
        $application = trim((string) $request->input('application'));

        DB::table('product_reviews')->insert([
            'product_id' => $pid,
            'customer_name' => mb_substr($name, 0, 120),
            'customer_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'city' => mb_substr(trim((string) $request->input('city')), 0, 64) ?: null,
            'application' => in_array($application, self::APPLICATIONS, true) ? $application : null,
            'rating' => max(1, min(5, (int) $request->input('rating', 5))),
            'title' => mb_substr(trim((string) $request->input('title')), 0, 160) ?: null,
            'body' => trim((string) $request->input('body')) ?: null,
            'source' => isset(self::SOURCES[$source]) ? $source : 'storefront',
            'status' => isset(self::STATUSES[$status]) ? $status : 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        session()->flash('success', 'Review from ' . $name . ' added.');

        return redirect(route('admin.reviews'));
    }

    /** Moderation + merchant response from the review drawer. */
    public function update(Request $request, string $id): Response
    {
        $rid = (int) $id;
        $review = DB::table('product_reviews')->where('id', $rid)->first();
        if ($review === null) {
            return redirect(route('admin.reviews'));
        }
        $status = (string) $request->input('status', $review['status']);
        $response = trim((string) $request->input('response'));
        $now = date('Y-m-d H:i:s');
        $data = [
            'status' => isset(self::STATUSES[$status]) ? $status : $review['status'],
            'response' => $response ?: null,
            'updated_at' => $now,
        ];
        if ($response !== '' && $response !== trim((string) $review['response'])) {
            $data['responded_at'] = $now;
        } elseif ($response === '') {
            $data['responded_at'] = null;
        }
        DB::table('product_reviews')->where('id', $rid)->update($data);
        session()->flash('success', 'Review from ' . $review['customer_name'] . ' saved as ' . strtolower(self::STATUSES[$data['status']]) . '.');

        return redirect(route('admin.reviews'));
    }

    public function bulk(Request $request): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        $action = (string) $request->input('action');
        $map = ['publish' => 'published', 'archive' => 'archived', 'flag' => 'flagged'];
        if ($ids && isset($map[$action])) {
            DB::table('product_reviews')->whereIn('id', $ids)->update(['status' => $map[$action], 'updated_at' => date('Y-m-d H:i:s')]);
            session()->flash('success', count($ids) . ' review' . (count($ids) === 1 ? '' : 's') . ' ' . $map[$action] . '.');
        }

        return redirect(route('admin.reviews'));
    }

    public function destroy(Request $request, string $id): Response
    {
        $rid = (int) $id;
        $name = DB::table('product_reviews')->where('id', $rid)->value('customer_name');
        if ($name !== null) {
            MediaService::detachAll('review', $rid);
            DB::table('product_reviews')->where('id', $rid)->delete();
            session()->flash('success', 'Review from ' . $name . ' deleted.');
        }

        return redirect(route('admin.reviews'));
    }
}
