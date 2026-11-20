<?php

namespace App\Controllers\Admin;

use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/** Brand portfolio: house labels, quarries, importers and suppliers. */
class BrandController
{
    public const TIERS = ['signature' => 'Signature', 'core' => 'Core', 'value' => 'Value'];
    public const TYPES = ['house' => 'House label', 'quarry' => 'Quarry', 'importer' => 'Importer', 'supplier' => 'Supplier'];
    public const STATUSES = ['active' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived'];

    public function index(): Response
    {
        $rows = DB::select(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id) AS product_count,
                    (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.status = 'active') AS active_count,
                    (SELECT COUNT(*) FROM product_reviews r JOIN products p ON p.id = r.product_id
                      WHERE p.brand_id = b.id AND r.status = 'published') AS review_count,
                    (SELECT AVG(r.rating) FROM product_reviews r JOIN products p ON p.id = r.product_id
                      WHERE p.brand_id = b.id AND r.status = 'published') AS avg_rating
             FROM brands b ORDER BY b.is_featured DESC, b.name"
        );
        $live = array_filter($rows, static fn($r) => $r['status'] !== 'archived');

        return view('admin.catalog.brands', [
            'active' => 'brands',
            'rows' => $rows,
            'stats' => [
                'active' => count(array_filter($rows, static fn($r) => $r['status'] === 'active')),
                'products' => array_sum(array_map(static fn($r) => (int) $r['product_count'], $rows)),
                'unbranded' => (int) DB::table('products')->whereNull('brand_id')->count(),
                'featured' => count(array_filter($live, static fn($r) => (int) $r['is_featured'] === 1)),
                'countries' => count(array_unique(array_filter(array_map(static fn($r) => $r['country'], $live)))),
            ],
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->data($request);
        if ($data === null) {
            return redirect(route('admin.brands'));
        }
        $data['slug'] = CatalogController::slug('brands', $data['name']);
        $now = date('Y-m-d H:i:s');
        $id = DB::table('brands')->insertGetId($data + ['created_at' => $now, 'updated_at' => $now]);
        $this->storeLogo($request, $id);
        session()->flash('success', 'Brand "' . $data['name'] . '" created.');

        return redirect(route('admin.brands'));
    }

    public function update(Request $request, string $id): Response
    {
        $bid = (int) $id;
        $data = $this->data($request);
        if ($data === null || !DB::table('brands')->where('id', $bid)->exists()) {
            return redirect(route('admin.brands'));
        }
        DB::table('brands')->where('id', $bid)->update($data + ['updated_at' => date('Y-m-d H:i:s')]);
        $this->storeLogo($request, $bid);
        session()->flash('success', 'Brand "' . $data['name'] . '" updated.');

        return redirect(route('admin.brands'));
    }

    public function destroy(Request $request, string $id): Response
    {
        $bid = (int) $id;
        $brand = DB::table('brands')->where('id', $bid)->first();
        if ($brand === null) {
            return redirect(route('admin.brands'));
        }
        $n = (int) DB::table('products')->where('brand_id', $bid)->count();
        if ($n > 0) {
            session()->flash('error', '"' . $brand['name'] . '" is used by ' . $n . ' product(s). Reassign them or archive the brand instead.');

            return redirect(route('admin.brands'));
        }
        if (!empty($brand['logo']) && is_file(app()->publicPath($brand['logo']))) {
            @unlink(app()->publicPath($brand['logo']));
        }
        DB::table('brands')->where('id', $bid)->delete();
        session()->flash('success', 'Brand "' . $brand['name'] . '" deleted.');

        return redirect(route('admin.brands'));
    }

    /** @return array<string,mixed>|null */
    private function data(Request $request): ?array
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            session()->flash('error', 'Brand name is required.');

            return null;
        }
        $pick = static fn(string $v, array $allowed, string $def): string => isset($allowed[$v]) ? $v : $def;
        $email = trim((string) $request->input('contact_email'));
        $site = trim((string) $request->input('website'));

        return [
            'name' => mb_substr($name, 0, 120),
            'country' => trim((string) $request->input('country')) ?: null,
            'tier' => $pick((string) $request->input('tier'), self::TIERS, 'core'),
            'brand_type' => $pick((string) $request->input('brand_type'), self::TYPES, 'house'),
            'status' => $pick((string) $request->input('status'), self::STATUSES, 'active'),
            'is_featured' => $request->input('is_featured') === '1' ? 1 : 0,
            'description' => trim((string) $request->input('description')) ?: null,
            'contact_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'website' => $site !== '' && filter_var(preg_match('#^https?://#i', $site) ? $site : 'https://' . $site, FILTER_VALIDATE_URL)
                ? (preg_match('#^https?://#i', $site) ? $site : 'https://' . $site) : null,
        ];
    }

    private function storeLogo(Request $request, int $id): void
    {
        $file = $request->file('logo');
        if (!$file || !$file->isValid()) {
            return;
        }
        $ext = strtolower($file->getClientExtension());
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) || $file->getSize() > 2 * 1024 * 1024) {
            session()->flash('error', 'Logo must be a PNG, JPG or WEBP under 2 MB.');

            return;
        }
        $dir = app()->publicPath('assets/uploads/brands');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = 'brand-' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@move_uploaded_file($file->getPathname(), $dir . '/' . $name)) {
            return;
        }
        $old = DB::table('brands')->where('id', $id)->value('logo');
        if ($old && is_file(app()->publicPath($old))) {
            @unlink(app()->publicPath($old));
        }
        DB::table('brands')->where('id', $id)->update(['logo' => 'assets/uploads/brands/' . $name]);
    }
}
