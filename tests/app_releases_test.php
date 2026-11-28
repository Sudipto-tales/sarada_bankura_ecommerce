<?php

use App\Http\FileResponse;
use App\Services\AdminAccess\Guard;
use App\Services\AdminAccess\Passwords;
use App\Services\AdminAccess\Roles;
use App\Services\AppReleases;
use App\Services\Store\AppMode;
use App\Services\Store\Cart;
use App\Services\Store\View;
use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\UploadedFile;
use Vayu\Validation\ValidationException;

/** Android app phase 3 (docs/mobile-app.md): release management, /app, /app/download, /app/version. */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');
    $app->config()->set('cache.default', 'array');
    $app->config()->set('store.cache_ttl', 0);
    $app->config()->set('store.enabled', true);
    $app->config()->set('store.app_play_url', '');
    $router = $app->router();
    $router->aliasMiddleware('throttle', \Vayu\Middleware\ThrottleRequests::class);
    require dirname(__DIR__) . '/routes/web.php';
    $files = glob(dirname(__DIR__) . '/database/migrations/2026_*.php');
    sort($files);
    foreach ($files as $f) {
        ob_start();
        (require $f)->up();
        ob_end_clean();
    }
    View::loadHelpers();
    Cart::$sendCookie = false;
    $_COOKIE = [];
    Cart::reset();
    session()->start();
    AppMode::force(false);
    $req = static fn(string $method, string $path, array $headers = []): Request => new Request($method, $path, [], [], $headers, ['REMOTE_ADDR' => '10.0.6.1'], [], null);
    $errs = static function (callable $fn): array {
        try { $fn(); return []; } catch (ValidationException $e) { return $e->errors(); }
    };
    $tmp = sys_get_temp_dir() . '/ms-apk-test-' . bin2hex(random_bytes(4));
    @mkdir($tmp);
    $apk = static function (string $name = 'app-release.apk', string $magic = "PK\x03\x04", int $size = 4096) use ($tmp): UploadedFile {
        $p = $tmp . '/' . bin2hex(random_bytes(6));
        file_put_contents($p, $magic . random_bytes(max(0, $size - strlen($magic))));

        return new UploadedFile($name, 'application/vnd.android.package-archive', $p, UPLOAD_ERR_OK, $size);
    };
    $stored = [];

    // ---------------------------------------------------------------- nothing yet
    $assert->that('permission: app_releases.manage is in the catalogue', isset(config('permissions.sections')['app']['permissions']['app_releases.manage']));
    $v = json_decode($router->dispatch($req('GET', '/app/version'))->getContent(), true);
    $assert->that('/app/version: no release → latest_code 0, points at /app', $v['latest_code'] === 0 && $v['min_code'] === 0 && str_ends_with($v['url'], '/app'));
    $html = $router->dispatch($req('GET', '/app'))->getContent();
    $assert->that('/app: coming soon, no download button, no QR', str_contains($html, 'Coming soon') && !str_contains($html, '/app/download') && !str_contains($html, 'id="appQr"'));
    $html = $router->dispatch($req('GET', '/brands'))->getContent();
    $assert->that('site: no banner or footer link while there is nothing to offer', !str_contains($html, 'id="appBanner"') && !str_contains($html, 'Get the Android app'));
    $res = $router->dispatch($req('GET', '/app/download'));
    $assert->that('/app/download: nothing published → back to /app', $res->getStatus() === 302 && str_ends_with((string) ($res->getHeaders()['Location'] ?? ''), '/app'));

    // ---------------------------------------------------------------- upload validation
    $ok = ['version_name' => '1.0.0', 'version_code' => '10', 'min_supported_code' => '0', 'changelog' => "- First release\n- Works offline"];
    $assert->that('upload: no file refused', isset($errs(fn() => AppReleases::upload(null, $ok))['apk']));
    $assert->that('upload: .aab refused', isset($errs(fn() => AppReleases::upload($apk('app.aab'), $ok))['apk']));
    $assert->that('upload: not a zip refused', isset($errs(fn() => AppReleases::upload($apk('app.apk', '%PDF'), $ok))['apk']));
    $assert->that('upload: tiny file refused', isset($errs(fn() => AppReleases::upload($apk('app.apk', "PK\x03\x04", 200), $ok))['apk']));
    $e = $errs(fn() => AppReleases::upload($apk(), ['version_name' => 'v one', 'version_code' => '1.5', 'min_supported_code' => '99']));
    $assert->that('upload: bad version name / code / min code refused', isset($e['version_name'], $e['version_code']));
    $assert->that('upload: min code above the version code refused', isset($errs(fn() => AppReleases::upload($apk(), ['min_supported_code' => '11'] + $ok))['min_supported_code']));
    $assert->that('upload: nothing stored after refusals', DB::table('app_releases')->count() === 0);

    // ---------------------------------------------------------------- upload → draft
    $f1 = $apk();
    $hash = hash_file('sha256', $f1->getPathname());
    $id1 = AppReleases::upload($f1, $ok, 1);
    $r1 = AppReleases::find($id1);
    $stored[] = AppReleases::path($r1);
    $assert->that('upload: draft row with size + sha256, file under storage/uploads/app', $r1['status'] === 'draft' && (int) $r1['size_bytes'] === 4096
        && $r1['sha256'] === $hash && str_starts_with($r1['file_path'], 'uploads/app/') && is_file(AppReleases::path($r1)));
    $assert->that('upload: the APK is not under public/', !str_contains(AppReleases::path($r1), '/public/'));
    $assert->that('upload: the same or a lower code is refused', isset($errs(fn() => AppReleases::upload($apk(), $ok))['version_code'])
        && isset($errs(fn() => AppReleases::upload($apk(), ['version_code' => '9'] + $ok))['version_code']));
    $assert->that('draft: not public (/app/version, /app, banner)', json_decode($router->dispatch($req('GET', '/app/version'))->getContent(), true)['latest_code'] === 0
        && !AppReleases::available());

    // ---------------------------------------------------------------- publish
    AppReleases::publish($id1, 1);
    $v = json_decode($router->dispatch($req('GET', '/app/version'))->getContent(), true);
    $assert->that('publish: /app/version shows it', $v['latest_code'] === 10 && $v['name'] === '1.0.0' && $v['sha256'] === $hash && str_contains($v['notes'], 'Works offline'));
    $res = $router->dispatch($req('GET', '/app/version'));
    $assert->that('/app/version: never cached', ($res->getHeaders()['Cache-Control'] ?? '') === 'no-store');
    $html = $router->dispatch($req('GET', '/app'))->getContent();
    $assert->that('/app: download button with size, changelog, SHA-256, install steps, QR', str_contains($html, '/app/download"') && str_contains($html, '0.0 MB')
        && str_contains($html, 'Works offline') && str_contains($html, $hash) && str_contains($html, 'Install unknown apps') && str_contains($html, 'id="appQr"')
        && str_contains($html, 'qrcode.min.js'));
    $assert->that('/app: no Play badge without APP_PLAY_URL', !str_contains($html, 'Google Play</span>'));
    $html = $router->dispatch($req('GET', '/brands'))->getContent();
    $assert->that('site: banner (hidden until JS) + footer link', str_contains($html, '<div id="appBanner" class="lg:hidden" hidden>') && str_contains($html, 'Get the Android app'));
    AppMode::force(true);
    $html = $router->dispatch($req('GET', '/brands'))->getContent();
    $assert->that('app mode: no banner, footer link hidden', !str_contains($html, 'id="appBanner"'));
    AppMode::force(false);

    $res = $router->dispatch($req('GET', '/app/download'));
    $h = $res->getHeaders();
    $assert->that('/app/download: streams the APK file with the right type and name', $res instanceof FileResponse && $res->path() === AppReleases::path($r1)
        && $h['Content-Type'] === AppReleases::MIME && str_contains($h['Content-Disposition'], 'maa-sarada-1.0.0.apk') && $h['Content-Length'] === '4096');
    $assert->equals('/app/download: counted', 1, (int) DB::table('app_releases')->where('id', $id1)->value('downloads'));

    // ---------------------------------------------------------------- next version, archive, delete
    $id2 = AppReleases::upload($apk(), ['version_name' => '1.1.0', 'version_code' => '11', 'min_supported_code' => '10', 'changelog' => ''], 1);
    $stored[] = AppReleases::path(AppReleases::find($id2));
    AppReleases::publish($id2, 1);
    $assert->that('publish v2: v1 archived, one public release', DB::table('app_releases')->where('id', $id1)->value('status') === 'archived'
        && DB::table('app_releases')->where('status', 'published')->count() === 1 && (int) AppReleases::latest()['id'] === $id2);
    $assert->equals('/app/version: min_code forces older builds', 10, json_decode($router->dispatch($req('GET', '/app/version'))->getContent(), true)['min_code']);
    $assert->that('publish: an older code over a newer public one is refused', isset($errs(fn() => AppReleases::publish($id1))['release']));
    $assert->that('delete: the public release can\'t be deleted', isset($errs(fn() => AppReleases::delete($id2))['release']));
    $p1 = AppReleases::path(AppReleases::find($id1));
    AppReleases::delete($id1, 1);
    $assert->that('delete: archived release and its file removed', AppReleases::find($id1) === null && !is_file($p1));
    AppReleases::update($id2, ['min_supported_code' => '0', 'changelog' => 'Faster search'], 1);
    $assert->that('update: notes + min code edited, version unchanged', AppReleases::find($id2)['changelog'] === 'Faster search'
        && (int) AppReleases::find($id2)['min_supported_code'] === 0 && (int) AppReleases::find($id2)['version_code'] === 11);
    $assert->that('audit: create / publish / delete logged', DB::table('audit_logs')->where('entity_type', 'app_release')->count() >= 5);

    $app->config()->set('store.app_play_url', 'https://play.google.com/store/apps/details?id=com.maasarada.store');
    $html = $router->dispatch($req('GET', '/app'))->getContent();
    $assert->that('/app: Play badge first, APK second when both exist', str_contains($html, 'Google Play</span>') && strpos($html, 'play.google.com') < strpos($html, '/app/download"'));
    $app->config()->set('store.app_play_url', 'http://evil.example/x');
    $assert->that('play url: only https://play.google.com links are used', AppReleases::playUrl() === '');

    AppReleases::archive($id2, 1);
    $assert->that('take down: nothing public, download goes back to /app', AppReleases::latest() === null
        && $router->dispatch($req('GET', '/app/download'))->getStatus() === 302);

    // ---------------------------------------------------------------- admin access
    Roles::sync();
    $now = date('Y-m-d H:i:s');
    $mk = static function (string $email, string $roleSlug) use ($now): int {
        $id = (int) DB::table('admin_users')->insertGetId(['name' => $email, 'email' => $email, 'password' => Passwords::hash('admin-pass-1'), 'status' => 'active',
            'must_change_password' => 0, 'session_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('admin_user_roles')->insert(['admin_user_id' => $id, 'role_id' => (int) DB::table('roles')->where('slug', $roleSlug)->value('id')]);

        return $id;
    };
    $mk('owner@shop.in', 'owner');
    $mk('cat@shop.in', 'catalogue-manager');
    Guard::attempt('cat@shop.in', 'admin-pass-1');
    $res = $router->dispatch($req('GET', '/admin/app-releases'));
    $assert->that('admin: staff without app_releases.manage get 403', $res->getStatus() === 403);
    Guard::forget();
    session()->forget('admin');
    Guard::attempt('owner@shop.in', 'admin-pass-1');
    $html = $router->dispatch($req('GET', '/admin/app-releases'))->getContent();
    $assert->that('admin: owner sees the upload form and the release list', str_contains($html, 'name="apk"') && str_contains($html, 'enctype="multipart/form-data"')
        && str_contains($html, '1.1.0') && str_contains($html, 'Higher than 11'));
    $res = $router->dispatch($req('GET', '/admin/app-releases/' . $id2 . '/download'));
    $assert->that('admin: staff can download any release (not counted)', $res instanceof FileResponse && (int) AppReleases::find($id2)['downloads'] === 0);

    // ---------------------------------------------------------------- CI publish (php vayu app:release)
    $cmd = new class ($app) extends \App\Console\Commands\AppReleaseCommand {
        public string $out = '';
        protected function line(string $message = ''): void { $this->out .= $message . "\n"; }
        protected function info(string $message): void { $this->line($message); }
        protected function error(string $message): void { $this->line($message); }
    };
    $file = $tmp . '/' . bin2hex(random_bytes(4)) . '.apk'; rename($apk()->getPathname(), $file);
    file_put_contents($tmp . '/notes.txt', "- New logo\n");
    $rc = $cmd->handle(['--apk=' . $file, '--name=2.0.0', '--code=20000', '--notes-file=' . $tmp . '/notes.txt']);
    $r = DB::selectOne('SELECT * FROM app_releases WHERE version_code = 20000');
    $assert->that('cli: uploads and publishes the CI build with its notes', $rc === 0 && $r && $r['status'] === 'published' && $r['changelog'] === '- New logo' && !is_file($file)
        && AppReleases::latest()['version_code'] == 20000);
    if ($r) {
        $stored[] = AppReleases::path($r);
    }
    $file = $tmp . '/' . bin2hex(random_bytes(4)) . '.apk'; rename($apk()->getPathname(), $file);
    $assert->that('cli: a re-run for the same code is a no-op', $cmd->handle(['--apk=' . $file, '--name=2.0.0', '--code=20000']) === 0
        && (int) DB::selectOne('SELECT COUNT(*) AS n FROM app_releases WHERE version_code = 20000')['n'] === 1);
    $file = $tmp . '/' . bin2hex(random_bytes(4)) . '.apk'; rename($apk()->getPathname(), $file);
    $assert->that('cli: a lower code is refused', $cmd->handle(['--apk=' . $file, '--name=1.9.0', '--code=19000']) === 1 && str_contains($cmd->out, 'version_code'));
    $assert->that('cli: missing args → usage', $cmd->handle([]) === 1 && str_contains($cmd->out, 'Usage'));

    foreach ($stored as $p) {
        @unlink($p);
    }
    array_map('unlink', glob($tmp . '/*') ?: []);
    @rmdir($tmp);
};
