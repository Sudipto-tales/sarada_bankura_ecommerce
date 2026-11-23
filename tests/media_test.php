<?php

use App\Services\Media\MediaException;
use App\Services\Media\MediaService as Media;
use Vayu\Application;
use Vayu\Database\DB;

/** Stage 1: MediaService (store, resize/thumb, dedupe, links, cover, purge, deferred purge). In-memory DB. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    (require dirname(__DIR__) . '/database/migrations/2026_11_23_000008_create_media_tables.php')->up();
    DB::statement('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, sku TEXT)');
    DB::statement('CREATE TABLE product_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, title TEXT, sku TEXT)');
    DB::table('products')->insert(['name' => 'Slab A', 'sku' => 'A']);
    DB::table('products')->insert(['name' => 'Slab B', 'sku' => 'B']);

    $tmp = sys_get_temp_dir() . '/media_test_' . bin2hex(random_bytes(3));
    @mkdir($tmp);
    $png = static function (string $path, int $w, int $h, array $rgb) {
        $i = imagecreatetruecolor($w, $h);
        imagefill($i, 0, 0, imagecolorallocate($i, ...$rgb));
        imagepng($i, $path);
        imagedestroy($i);
    };
    $png($tmp . '/small.png', 600, 400, [10, 120, 90]);
    $png($tmp . '/huge.png', 3600, 1800, [200, 180, 150]);
    file_put_contents($tmp . '/fake.jpg', 'not really an image');
    file_put_contents($tmp . '/notes.txt', 'hello');

    // Store + thumbnail
    $a = Media::storeFile($tmp . '/small.png', 'Small Slab.png');
    $assert->that('store: media number assigned', preg_match('/^MED-\d{6}$/', (string) $a['media_no']) === 1);
    $assert->equals('store: kind image', 'image', $a['kind']);
    $assert->that('store: file exists under public uploads', is_file(Media::absolutePath($a)) && str_contains($a['path'], 'assets/uploads/media/'));
    $assert->that('store: thumbnail written', !empty($a['thumb_path']) && is_file(Media::absolutePath($a, true)));
    $assert->equals('store: width kept', 600, (int) $a['width']);
    $assert->equals('store: title from file name', 'Small Slab', $a['title']);

    // Resize to MAX_EDGE
    $big = Media::storeFile($tmp . '/huge.png', 'huge.png');
    $assert->equals('resize: long edge capped', Media::MAX_EDGE, max((int) $big['width'], (int) $big['height']));
    $assert->equals('resize: aspect kept', 1200, (int) $big['height']);

    // Dedupe
    $dup = Media::storeFile($tmp . '/small.png', 'again.png');
    $assert->that('dedupe: same bytes reuse the row', $dup['duplicate'] === true && (int) $dup['id'] === (int) $a['id']);
    $assert->equals('dedupe: still 2 rows', 2, (int) DB::table('media')->count());

    // Rejections
    $threw = static function (callable $fn): ?string {
        try {
            $fn();
        } catch (MediaException $e) {
            return $e->getMessage();
        }

        return null;
    };
    $assert->that('reject: fake image', $threw(fn() => Media::storeFile($tmp . '/fake.jpg', 'fake.jpg')) !== null);
    $assert->that('reject: text file', str_contains((string) $threw(fn() => Media::storeFile($tmp . '/notes.txt', 'notes.txt')), 'not an allowed file type'));
    $assert->that('reject: kind not allowed', $threw(fn() => Media::storeFile($tmp . '/small.png', 'x.png', ['kinds' => ['video'], 'dedupe' => false])) !== null);

    // Links, cover, reorder
    $aid = (int) $a['id'];
    $bid = (int) $big['id'];
    Media::attach('product', 1, $aid, 'gallery');
    Media::attach('product', 1, $bid, 'gallery');
    Media::attach('product', 1, $aid, 'gallery'); // idempotent
    $assert->equals('attach: idempotent', 2, (int) DB::table('media_links')->where('owner_id', 1)->count());
    Media::ensureCover('product', 1);
    $assert->equals('ensureCover: first image becomes cover', $aid, (int) Media::cover('product', 1)['id']);
    Media::setCover('product', 1, $bid);
    $links = Media::linksFor('product', 1);
    $assert->equals('setCover: new cover listed first', $bid, (int) $links[0]['id']);
    $assert->equals('setCover: old cover demoted to gallery', 'gallery', $links[1]['role']);
    $assert->equals('setCover: still one cover', 1, (int) DB::table('media_links')->where('role', 'cover')->where('owner_id', 1)->count());
    Media::reorder('product', 1, [$aid, $bid]);
    $sorts = array_column(DB::select("SELECT media_id, sort FROM media_links WHERE owner_type='product' AND owner_id=1"), 'sort', 'media_id');
    $assert->that('reorder: saved', (int) $sorts[$aid] < (int) $sorts[$bid]);
    $assert->that('coverSql: returns thumb path', str_contains((string) DB::selectOne('SELECT ' . Media::coverSql('product', 'p.id') . ' AS c FROM products p WHERE p.id = 1')['c'], 'thumbs/'));

    // Shared file survives until its last link goes
    Media::attach('product', 2, $aid, 'gallery');
    $fileA = Media::absolutePath($a);
    Media::detach('product', 1, $aid);
    $assert->that('detach: file kept while product 2 uses it', is_file($fileA) && Media::find($aid) !== null);
    $assert->equals('usage: lists remaining owner', 'Slab B (B)', Media::usage($aid)[0]['label'] ?? null);
    $assert->that('delete: refused while in use', $threw(fn() => Media::delete($aid)) !== null);

    // Deferred purge inside a transaction
    DB::connection()->pdo()->beginTransaction();
    Media::detachAll('product', 2);
    $assert->that('deferred: file still there inside transaction', is_file($fileA));
    DB::connection()->pdo()->commit();
    Media::flushPending();
    $assert->that('deferred: purged after commit', !is_file($fileA) && Media::find($aid) === null);

    // replaceRole (logo) + delete purges thumb too
    $logo2 = Media::storeFile($tmp . '/small.png', 'logo.png');
    Media::replaceRole('brand', 1, (int) $logo2['id'], 'logo');
    Media::replaceRole('brand', 1, $bid, 'logo');
    $assert->that('replaceRole: old logo purged', Media::find((int) $logo2['id']) === null);
    $bigFile = Media::absolutePath($big);
    $bigThumb = Media::absolutePath($big, true);
    Media::detachAll('brand', 1);
    Media::detachAll('product', 1);
    $assert->that('purge: file and thumb removed', !is_file($bigFile) && !is_file($bigThumb));
    $assert->equals('purge: library empty', 0, (int) DB::table('media')->count());

    // Private disk
    $p = Media::storeFile($tmp . '/small.png', 'proof.png', ['disk' => 'private']);
    $assert->that('private: stored under storage/media', str_starts_with(Media::absolutePath($p), app()->storagePath('media')));
    $assert->that('private: url goes through admin route', str_contains((string) $p['url'], '/admin/media/' . $p['id'] . '/file'));
    Media::delete((int) $p['id']);
    $assert->that('private: deleted', !is_file(Media::absolutePath($p)));

    array_map('unlink', glob($tmp . '/*') ?: []);
    @rmdir($tmp);
};
