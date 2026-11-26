<?php

namespace App\Services;

use App\Services\Payment\Audit;
use Vayu\Database\DB;
use Vayu\Http\UploadedFile;
use Vayu\Validation\ValidationException;

/**
 * Android app releases (docs/mobile-app.md phase 3). The only writer of app_releases and of the
 * APK files in storage/uploads/app/ (shared across deploys, never under public/).
 *
 * - Upload → Draft: only staff can download it (to test on a phone).
 * - Publish → the one public release; the previous published one is archived.
 * - version_code must be higher than every earlier upload: Android refuses to install a lower one
 *   over a higher one, so a "rollback" is a rebuild of the old code with a new, higher code.
 * - min_supported_code: installed builds below it are told to update (forced) via /app/version.
 */
final class AppReleases
{
    public const DIR = 'uploads/app';                 // under storage/
    public const MAX_BYTES = 60 * 1024 * 1024;        // the server's upload limit is 64M
    public const MAX_CODE = 2100000000;               // Google Play's versionCode ceiling
    public const MIME = 'application/vnd.android.package-archive';

    /* ================================================================ reads */

    /** @return list<array<string,mixed>> newest first */
    public static function all(): array
    {
        return DB::select('SELECT * FROM app_releases ORDER BY version_code DESC');
    }

    public static function find(int $id): ?array
    {
        return DB::selectOne('SELECT * FROM app_releases WHERE id = ?', [$id]);
    }

    /** The published release, or null. */
    public static function latest(): ?array
    {
        return DB::selectOne("SELECT * FROM app_releases WHERE status = 'published' ORDER BY version_code DESC LIMIT 1");
    }

    public static function path(array $r): string
    {
        return app()->storagePath((string) $r['file_path']);
    }

    /** Download file name, e.g. maa-sarada-1.2.0.apk */
    public static function fileName(array $r): string
    {
        return 'maa-sarada-' . preg_replace('/[^0-9A-Za-z.\-]+/', '-', (string) $r['version_name']) . '.apk';
    }

    /** Something to offer on /app (a public APK or a Play listing): drives the banner and footer link. */
    public static function available(): bool
    {
        return self::playUrl() !== '' || DB::selectOne("SELECT id FROM app_releases WHERE status = 'published' LIMIT 1") !== null;
    }

    public static function playUrl(): string
    {
        $u = trim((string) config('store.app_play_url', ''));

        return preg_match('#^https://play\.google\.com/#', $u) ? $u : '';
    }

    /**
     * /app/version for sideloaded installs (Play installs use Play's in-app updates).
     * @return array{latest_code: int, min_code: int, name: string, notes: string, url: string, size: int, sha256: string, play_url: string}
     */
    public static function versionInfo(): array
    {
        $r = self::latest();

        return [
            'latest_code' => $r ? (int) $r['version_code'] : 0,
            'min_code' => $r ? (int) $r['min_supported_code'] : 0,
            'name' => $r ? (string) $r['version_name'] : '',
            'notes' => $r ? (string) ($r['changelog'] ?? '') : '',
            'url' => base_url('/app'),
            'size' => $r ? (int) $r['size_bytes'] : 0,
            'sha256' => $r ? (string) $r['sha256'] : '',
            'play_url' => self::playUrl(),
        ];
    }

    public static function countDownload(int $id): void
    {
        DB::update('UPDATE app_releases SET downloads = downloads + 1 WHERE id = ?', [$id]);
    }

    /* ================================================================ writes */

    /**
     * Upload an APK as a new Draft. Throws ValidationException (field-keyed).
     * @param array<string,mixed> $in version_name, version_code, min_supported_code?, changelog?
     */
    public static function upload(?UploadedFile $file, array $in, ?int $actorId = null): int
    {
        $name = trim((string) ($in['version_name'] ?? ''));
        $codeRaw = trim((string) ($in['version_code'] ?? ''));
        $code = ctype_digit($codeRaw) ? (int) $codeRaw : 0;
        $err = self::checkFields($in, $code);
        if (!preg_match('/^\d{1,4}(\.\d{1,4}){0,3}([-+][0-9A-Za-z.]{1,20})?$/', $name)) {
            $err['version_name'][] = 'Use the app’s version name, e.g. 1.2.0.';
        }
        if ($code < 1 || $code > self::MAX_CODE) {
            $err['version_code'][] = 'Enter the whole-number version code from the build (1 or more).';
        } else {
            $max = (int) (DB::selectOne('SELECT MAX(version_code) AS m FROM app_releases')['m'] ?? 0);
            if ($code <= $max) {
                $err['version_code'][] = 'The version code must be higher than ' . $max . ' (the highest uploaded so far). Android won’t install a lower code over a higher one.';
            }
        }
        $err += self::checkFile($file);
        if ($err) {
            throw new ValidationException($err);
        }

        $stored = $file->storeAs(self::DIR, 'maa-sarada-' . $code . '-' . bin2hex(random_bytes(4)) . '.apk');
        if ($stored === null) {
            throw new ValidationException(['apk' => ['The APK could not be saved on the server. Try again.']]);
        }
        $abs = app()->storagePath($stored);
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('app_releases')->insertGetId([
            'version_name' => $name, 'version_code' => $code, 'file_path' => $stored, 'size_bytes' => (int) filesize($abs),
            'sha256' => (string) hash_file('sha256', $abs), 'changelog' => self::changelog($in), 'min_supported_code' => (int) ($in['min_supported_code'] ?? 0),
            'status' => 'draft', 'uploaded_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Audit::log('app_release', $id, 'create', ['version' => $name, 'code' => $code], $actorId);

        return $id;
    }

    /** Edit the changelog and minimum supported code (the APK and version never change). */
    public static function update(int $id, array $in, ?int $actorId = null): void
    {
        $r = self::find($id) ?? throw new ValidationException(['release' => ['That release no longer exists.']]);
        $err = self::checkFields($in, (int) $r['version_code']);
        if ($err) {
            throw new ValidationException($err);
        }
        DB::table('app_releases')->where('id', $id)->update([
            'changelog' => self::changelog($in), 'min_supported_code' => (int) ($in['min_supported_code'] ?? 0), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Audit::log('app_release', $id, 'update', ['min_supported_code' => (int) ($in['min_supported_code'] ?? 0)], $actorId);
    }

    /** Make a Draft (or archived) release the public one; the current public release is archived. */
    public static function publish(int $id, ?int $actorId = null): void
    {
        $r = self::find($id) ?? throw new ValidationException(['release' => ['That release no longer exists.']]);
        if ($r['status'] === 'published') {
            return;
        }
        $cur = self::latest();
        if ($cur !== null && (int) $cur['version_code'] > (int) $r['version_code']) {
            throw new ValidationException(['release' => ['Version ' . $cur['version_name'] . ' (code ' . $cur['version_code'] . ') is already public and has a higher code. Phones that have it can’t go back to a lower code: build the old version again with a new, higher code.']]);
        }
        if (!is_file(self::path($r))) {
            throw new ValidationException(['release' => ['The APK file for this release is missing on the server. Upload it again.']]);
        }
        $now = date('Y-m-d H:i:s');
        DB::transaction(static function () use ($id, $actorId, $now): void {
            DB::update("UPDATE app_releases SET status = 'archived', updated_at = ? WHERE status = 'published'", [$now]);
            DB::update("UPDATE app_releases SET status = 'published', published_at = ?, published_by = ?, updated_at = ? WHERE id = ?", [$now, $actorId, $now, $id]);
        });
        Audit::log('app_release', $id, 'publish', ['previous' => $cur['id'] ?? null], $actorId);
    }

    /** Take the public release down (no public APK until another is published). */
    public static function archive(int $id, ?int $actorId = null): void
    {
        DB::update("UPDATE app_releases SET status = 'archived', updated_at = ? WHERE id = ? AND status = 'published'", [date('Y-m-d H:i:s'), $id]);
        Audit::log('app_release', $id, 'archive', [], $actorId);
    }

    /** Delete a Draft or archived release and its file. The public release can't be deleted. */
    public static function delete(int $id, ?int $actorId = null): void
    {
        $r = self::find($id);
        if ($r === null) {
            return;
        }
        if ($r['status'] === 'published') {
            throw new ValidationException(['release' => ['This is the public release. Publish another one or take it down first.']]);
        }
        $abs = self::path($r);
        if (is_file($abs)) {
            @unlink($abs);
        }
        DB::delete('DELETE FROM app_releases WHERE id = ?', [$id]);
        Audit::log('app_release', $id, 'delete', ['version' => $r['version_name'], 'code' => (int) $r['version_code']], $actorId);
    }

    /* ================================================================ helpers */

    /** @return array<string, list<string>> */
    private static function checkFields(array $in, int $code): array
    {
        $err = [];
        $min = trim((string) ($in['min_supported_code'] ?? '0'));
        if ($min !== '' && (!ctype_digit($min) || ($code > 0 && (int) $min > $code))) {
            $err['min_supported_code'][] = 'Use 0 (no forced update) or a version code up to ' . max($code, 0) . '.';
        }
        if (mb_strlen((string) ($in['changelog'] ?? '')) > 2000) {
            $err['changelog'][] = 'Keep “What’s new” under 2,000 characters.';
        }

        return $err;
    }

    /** @return array<string, list<string>> */
    private static function checkFile(?UploadedFile $f): array
    {
        if ($f === null || $f->getError() === UPLOAD_ERR_NO_FILE) {
            return ['apk' => ['Choose the APK file to upload.']];
        }
        if (in_array($f->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return ['apk' => ['The APK is larger than the server allows. Release APKs are usually 10–30 MB.']];
        }
        if (!$f->isValid()) {
            return ['apk' => ['The upload didn’t finish (error ' . $f->getError() . '). Try again.']];
        }
        if (strtolower($f->getClientExtension()) !== 'apk') {
            return ['apk' => ['Upload the .apk file (an .aab bundle is for the Play Console only).']];
        }
        $size = (int) @filesize($f->getPathname());
        if ($size < 1024 || $size > self::MAX_BYTES) {
            return ['apk' => ['The APK must be between 1 KB and ' . (self::MAX_BYTES >> 20) . ' MB.']];
        }
        $h = @fopen($f->getPathname(), 'rb');
        $magic = $h ? (string) fread($h, 4) : '';
        if ($h) {
            fclose($h);
        }
        if ($magic !== "PK\x03\x04") {
            return ['apk' => ['That file isn’t an Android APK.']];
        }

        return [];
    }

    private static function changelog(array $in): ?string
    {
        $c = trim(str_replace("\r\n", "\n", (string) ($in['changelog'] ?? '')));

        return $c === '' ? null : $c;
    }
}
