<?php

namespace App\Controllers\Admin;

use App\Http\FileResponse;
use App\Services\AdminAccess\Guard;
use App\Services\AppReleases;
use Vayu\Exceptions\HttpException;
use Vayu\Http\Request;
use Vayu\Http\Response;
use Vayu\Validation\ValidationException;

/**
 * Admin → Settings → Android app (docs/mobile-app.md phase 3). Every action needs
 * app_releases.manage (Owner by default).
 *
 *   GET  /admin/app-releases                     list + upload form
 *   POST /admin/app-releases                     upload an APK → Draft
 *   POST /admin/app-releases/{id}/update         changelog, minimum supported code
 *   POST /admin/app-releases/{id}/publish        make it the public release
 *   POST /admin/app-releases/{id}/archive        take the public release down
 *   POST /admin/app-releases/{id}/delete         Draft / archived only
 *   GET  /admin/app-releases/{id}/download       any status (test a Draft on a phone); not counted
 */
class AppReleaseController
{
    public function index(): Response
    {
        $this->actor();

        return view('admin.app-releases.index', [
            'rows' => AppReleases::all(),
            'playUrl' => AppReleases::playUrl(),
            'maxMb' => AppReleases::MAX_BYTES >> 20,
            'serverMax' => (string) ini_get('upload_max_filesize'),
        ]);
    }

    public function store(Request $request): Response
    {
        $actor = $this->actor();
        try {
            $id = AppReleases::upload($request->file('apk'), (array) $request->input(), $actor);
        } catch (ValidationException $e) {
            return $this->back($e, true);
        }
        $r = AppReleases::find($id);
        session()->flash('success', 'Version ' . $r['version_name'] . ' uploaded as a draft. Download it on a phone to test, then publish.');

        return redirect(route('admin.app-releases'));
    }

    public function update(Request $request, string $id): Response
    {
        $actor = $this->actor();
        try {
            AppReleases::update((int) $id, (array) $request->input(), $actor);
        } catch (ValidationException $e) {
            return $this->back($e);
        }
        session()->flash('success', 'Release saved.');

        return redirect(route('admin.app-releases'));
    }

    public function publish(string $id): Response
    {
        $actor = $this->actor();
        try {
            AppReleases::publish((int) $id, $actor);
        } catch (ValidationException $e) {
            return $this->back($e);
        }
        session()->flash('success', 'Published. /app and the update check now offer version ' . (AppReleases::find((int) $id)['version_name'] ?? '') . '.');

        return redirect(route('admin.app-releases'));
    }

    public function archive(string $id): Response
    {
        $actor = $this->actor();
        AppReleases::archive((int) $id, $actor);
        session()->flash('warning', 'Taken down. /app offers no direct download until you publish a release.');

        return redirect(route('admin.app-releases'));
    }

    public function destroy(string $id): Response
    {
        $actor = $this->actor();
        try {
            AppReleases::delete((int) $id, $actor);
        } catch (ValidationException $e) {
            return $this->back($e);
        }
        session()->flash('success', 'Release deleted.');

        return redirect(route('admin.app-releases'));
    }

    public function download(string $id): Response
    {
        $this->actor();
        $r = AppReleases::find((int) $id);
        if ($r === null || !is_file(AppReleases::path($r))) {
            throw new HttpException(404, 'That APK isn’t on the server.');
        }

        return new FileResponse(AppReleases::path($r), AppReleases::fileName($r), AppReleases::MIME, ['Cache-Control' => 'no-store']);
    }

    private function back(ValidationException $e, bool $keep = false): Response
    {
        session()->flash('error', implode(' ', array_merge(...array_values($e->errors()))));
        if ($keep) {
            $in = request()?->input() ?? [];
            session()->flashInput(array_intersect_key(is_array($in) ? $in : [], array_flip(['version_name', 'version_code', 'min_supported_code', 'changelog'])));
        }

        return redirect(route('admin.app-releases'));
    }

    /** The logged-in admin's id; 403 without app_releases.manage. */
    private function actor(): int
    {
        if (!Guard::can('app_releases.manage')) {
            throw new HttpException(403, 'You don’t have access to app releases.');
        }

        return (int) Guard::id();
    }
}
