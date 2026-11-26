<?php

namespace App\Services\Media;

use Vayu\Database\DB;
use Vayu\Http\UploadedFile;

/**
 * The single writer for media + media_links (docs/roadmap.md, Stage 1).
 *
 *   store*()          validate → (resize + thumbnail via GD) → checksum → one `media` row (MED-000123)
 *   attach()/detach() owners point at media through links; a file is deleted only when no links remain
 *   linksFor()/cover() readers; coverSql() is the subquery list pages use for their thumbnail column
 *
 * Disks: `public` files live under public/assets/uploads/media/, `private` ones under storage/media/
 * (never web-served; streamed by MediaController::file). Never opens its own transaction, so it is
 * safe to call inside one (the importer does): file deletes requested inside a transaction wait in
 * a queue until flushPending() runs after the commit.
 */
final class MediaService
{
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
    public const VIDEO_EXT = ['mp4', 'webm', 'mov', 'm4v'];
    public const DOC_EXT = ['pdf'];

    public const MAX_BYTES = ['image' => 20 * 1024 * 1024, 'video' => 100 * 1024 * 1024, 'document' => 10 * 1024 * 1024];

    /** Longest edge kept for stored images, and the thumbnail box. */
    public const MAX_EDGE = 2400;
    public const THUMB_EDGE = 480;

    public const OWNER_TYPES = ['product', 'variant', 'brand', 'category', 'review', 'payment_attempt', 'order'];
    public const ROLES = ['cover', 'gallery', 'room', 'video', 'logo', 'photo', 'proof', 'document']; // room = lifestyle shot (storefront hover + hero)

    private const PUBLIC_DIR = 'assets/uploads/media';

    /** @var list<int> media ids whose last link went away inside a transaction */
    private static array $pending = [];

    /* ------------------------------------------------------------------ store */

    /**
     * Store an uploaded file. Options: disk (public|private), kinds (allowed kinds), alt, title,
     * dedupe (default true: identical bytes on the same disk reuse the existing row).
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed> the media row (+ url, thumb_url, duplicate)
     */
    public static function storeUpload(?UploadedFile $file, array $opts = []): array
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new MediaException('Choose a file to upload.');
        }
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new MediaException('"' . $file->getClientOriginalName() . '" is larger than the server allows (' . self::humanBytes(self::serverLimit()) . ').');
        }
        if (!$file->isValid() || !is_uploaded_file($file->getPathname()) && !is_file($file->getPathname())) {
            throw new MediaException('The upload of "' . $file->getClientOriginalName() . '" did not complete. Please try again.');
        }

        return self::storeFile($file->getPathname(), $file->getClientOriginalName(), $opts);
    }

    /**
     * Store raw bytes (importer: ZIP entries and downloaded URLs).
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function storeBytes(string $bytes, string $originalName, array $opts = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'med');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new MediaException('Could not write a temporary file.');
        }
        try {
            return self::storeFile($tmp, $originalName, $opts);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function storeFile(string $source, string $originalName, array $opts = []): array
    {
        $disk = ($opts['disk'] ?? 'public') === 'private' ? 'private' : 'public';
        $originalName = self::cleanName($originalName);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = self::sniffMime($source);
        $kind = self::kindFor($ext, $mime);
        $allowedKinds = (array) ($opts['kinds'] ?? ['image', 'video', 'document']);

        if ($kind === null || !in_array($kind, $allowedKinds, true)) {
            $allowed = [];
            foreach ($allowedKinds as $k) {
                $allowed = array_merge($allowed, self::extensionsFor($k));
            }
            throw new MediaException('"' . $originalName . '" is not an allowed file type. Use ' . strtoupper(implode(', ', array_unique($allowed))) . '.');
        }
        $size = (int) filesize($source);
        if ($size <= 0) {
            throw new MediaException('"' . $originalName . '" is empty.');
        }
        if ($size > self::MAX_BYTES[$kind]) {
            throw new MediaException('"' . $originalName . '" is ' . self::humanBytes($size) . '; ' . $kind . 's can be up to ' . self::humanBytes(self::MAX_BYTES[$kind]) . '.');
        }
        $dims = null;
        if ($kind === 'image') {
            $dims = @getimagesize($source);
            if ($dims === false && $ext !== 'avif') {
                throw new MediaException('"' . $originalName . '" is not a readable image.');
            }
        }
        // Real extension from content when the name lies (e.g. a PNG named .jpg).
        $ext = self::extensionFor($kind, $mime, $ext);

        $checksum = sha1_file($source) ?: null;
        if (($opts['dedupe'] ?? true) && $checksum) {
            $existing = DB::selectOne('SELECT * FROM media WHERE checksum = ? AND disk = ? ORDER BY id LIMIT 1', [$checksum, $disk]);
            if ($existing && is_file(self::absolutePath($existing))) {
                return self::present($existing) + ['duplicate' => true];
            }
        }

        [$dirRel, $dirAbs] = self::targetDir($disk);
        $base = bin2hex(random_bytes(8));
        $fileRel = $dirRel . '/' . $base . '.' . $ext;
        $fileAbs = $dirAbs . '/' . $base . '.' . $ext;

        $width = $dims ? (int) $dims[0] : null;
        $height = $dims ? (int) $dims[1] : null;
        if ($kind === 'image' && $dims && self::exifOrientation($source, $ext) >= 5) {
            [$width, $height] = [$height, $width]; // shown rotated 90°
        }
        $written = false;
        if ($kind === 'image') {
            $res = self::processImage($source, $fileAbs, $ext, $dims ?: null);
            if ($res !== null) {
                [$width, $height] = $res;
                $written = true;
            }
        }
        if (!$written && !@copy($source, $fileAbs)) {
            throw new MediaException('Could not save "' . $originalName . '" (storage not writable).');
        }

        $thumbRel = null;
        if ($kind === 'image') {
            $thumbRel = self::makeThumb($fileAbs, $dirRel, $dirAbs, $base);
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('media')->insertGetId([
            'media_no' => 'TMP-' . bin2hex(random_bytes(6)),
            'disk' => $disk,
            'path' => $fileRel,
            'thumb_path' => $thumbRel,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime' => $mime,
            'kind' => $kind,
            'extension' => $ext,
            'size_bytes' => (int) filesize($fileAbs),
            'width' => $width,
            'height' => $height,
            'checksum' => $checksum,
            'alt_text' => blank_to_null($opts['alt'] ?? null),
            'title' => blank_to_null($opts['title'] ?? null) ?? pathinfo($originalName, PATHINFO_FILENAME),
            'uploaded_by' => isset($opts['uploaded_by']) ? (int) $opts['uploaded_by'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('media')->where('id', $id)->update(['media_no' => self::number($id)]);

        $row = self::find($id);
        $row['new_files'] = array_values(array_filter([$fileAbs, $thumbRel ? self::absolute($disk, $thumbRel) : null]));

        return $row + ['duplicate' => false];
    }

    /* ------------------------------------------------------------------ links */

    /** Link media to an owner. Idempotent; returns the link id. */
    public static function attach(string $ownerType, int $ownerId, int $mediaId, string $role = 'gallery', ?int $sort = null): int
    {
        self::assertOwner($ownerType, $role);
        if (!DB::table('media')->where('id', $mediaId)->exists()) {
            throw new MediaException('That file no longer exists in the media library.');
        }
        $found = DB::selectOne(
            'SELECT id FROM media_links WHERE owner_type = ? AND owner_id = ? AND media_id = ? AND role = ?',
            [$ownerType, $ownerId, $mediaId, $role]
        );
        if ($found) {
            return (int) $found['id'];
        }
        if ($sort === null) {
            $sort = (int) (DB::selectOne(
                'SELECT MAX(sort) AS s FROM media_links WHERE owner_type = ? AND owner_id = ?',
                [$ownerType, $ownerId]
            )['s'] ?? 0) + 1;
        }

        return (int) DB::table('media_links')->insertGetId([
            'media_id' => $mediaId, 'owner_type' => $ownerType, 'owner_id' => $ownerId,
            'role' => $role, 'sort' => $sort, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Replace whatever holds a single-slot role (logo, cover) with this media. */
    public static function replaceRole(string $ownerType, int $ownerId, int $mediaId, string $role): int
    {
        foreach (DB::select('SELECT media_id FROM media_links WHERE owner_type = ? AND owner_id = ? AND role = ? AND media_id <> ?', [$ownerType, $ownerId, $role, $mediaId]) as $old) {
            self::detach($ownerType, $ownerId, (int) $old['media_id'], $role);
        }

        return self::attach($ownerType, $ownerId, $mediaId, $role);
    }

    /** Remove one owner→media link (all roles when $role is null); purge the file if now unused. */
    public static function detach(string $ownerType, int $ownerId, int $mediaId, ?string $role = null): void
    {
        $q = DB::table('media_links')->where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('media_id', $mediaId);
        if ($role !== null) {
            $q->where('role', $role);
        }
        $q->delete();
        self::purgeIfUnused($mediaId);
    }

    /**
     * Remove every link an owner has (optionally only some roles). Used when an owner is deleted.
     *
     * @param list<string>|null $roles
     */
    public static function detachAll(string $ownerType, int $ownerId, ?array $roles = null): void
    {
        $sql = 'SELECT DISTINCT media_id FROM media_links WHERE owner_type = ? AND owner_id = ?';
        $bind = [$ownerType, $ownerId];
        if ($roles) {
            $sql .= ' AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')';
            $bind = array_merge($bind, $roles);
        }
        $ids = array_map(static fn($r) => (int) $r['media_id'], DB::select($sql, $bind));
        $del = DB::table('media_links')->where('owner_type', $ownerType)->where('owner_id', $ownerId);
        if ($roles) {
            $del->whereIn('role', $roles);
        }
        $del->delete();
        foreach ($ids as $id) {
            self::purgeIfUnused($id);
        }
    }

    /**
     * Make one of the owner's images the cover: the old cover becomes a gallery image.
     */
    public static function setCover(string $ownerType, int $ownerId, int $mediaId): void
    {
        $media = self::find($mediaId);
        if ($media === null || $media['kind'] !== 'image') {
            throw new MediaException('Only images can be the cover.');
        }
        $has = DB::table('media_links')->where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('media_id', $mediaId)->exists();
        if (!$has) {
            throw new MediaException('That image is not attached here.');
        }
        foreach (DB::select("SELECT id, media_id FROM media_links WHERE owner_type = ? AND owner_id = ? AND role = 'cover'", [$ownerType, $ownerId]) as $c) {
            if ((int) $c['media_id'] === $mediaId) {
                continue;
            }
            self::moveRole((int) $c['id'], $ownerType, $ownerId, (int) $c['media_id'], 'gallery');
        }
        $link = DB::selectOne("SELECT id, role FROM media_links WHERE owner_type = ? AND owner_id = ? AND media_id = ? AND role IN ('gallery','cover') ORDER BY role = 'cover' DESC LIMIT 1", [$ownerType, $ownerId, $mediaId]);
        if ($link && $link['role'] !== 'cover') {
            self::moveRole((int) $link['id'], $ownerType, $ownerId, $mediaId, 'cover');
        }
    }

    /** If the owner has images but no cover, the first image becomes the cover. */
    public static function ensureCover(string $ownerType, int $ownerId): void
    {
        if (DB::table('media_links')->where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('role', 'cover')->exists()) {
            return;
        }
        $first = DB::selectOne(
            "SELECT l.media_id FROM media_links l JOIN media m ON m.id = l.media_id
             WHERE l.owner_type = ? AND l.owner_id = ? AND l.role = 'gallery' AND m.kind = 'image'
             ORDER BY l.sort, l.id LIMIT 1",
            [$ownerType, $ownerId]
        );
        if ($first) {
            self::setCover($ownerType, $ownerId, (int) $first['media_id']);
        }
    }

    /**
     * Save a new order (media ids, first = left-most). Ids not listed keep their place after these.
     *
     * @param list<int> $mediaIds
     */
    public static function reorder(string $ownerType, int $ownerId, array $mediaIds): void
    {
        $sort = 1;
        foreach (array_values(array_unique(array_map('intval', $mediaIds))) as $mid) {
            DB::table('media_links')->where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('media_id', $mid)->update(['sort' => $sort++]);
        }
    }

    /* ------------------------------------------------------------------ read */

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = DB::selectOne('SELECT * FROM media WHERE id = ?', [$id]);

        return $row ? self::present($row) : null;
    }

    /**
     * The owner's media in display order: cover first, then by sort.
     *
     * @param list<string>|null $roles
     * @return list<array<string,mixed>> media rows + link_id, role, sort
     */
    public static function linksFor(string $ownerType, int $ownerId, ?array $roles = null): array
    {
        $sql = "SELECT m.*, l.id AS link_id, l.role, l.sort FROM media_links l JOIN media m ON m.id = l.media_id
                WHERE l.owner_type = ? AND l.owner_id = ?";
        $bind = [$ownerType, $ownerId];
        if ($roles) {
            $sql .= ' AND l.role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')';
            $bind = array_merge($bind, $roles);
        }
        $sql .= " ORDER BY CASE l.role WHEN 'cover' THEN 0 WHEN 'logo' THEN 0 ELSE 1 END, l.sort, l.id";

        return array_map([self::class, 'present'], DB::select($sql, $bind));
    }

    /** @return array<string,mixed>|null the cover (or logo) image, else the first image */
    public static function cover(string $ownerType, int $ownerId): ?array
    {
        foreach (self::linksFor($ownerType, $ownerId, ['cover', 'logo', 'gallery']) as $m) {
            if ($m['kind'] === 'image') {
                return $m;
            }
        }

        return null;
    }

    /**
     * SQL subquery returning the path of an owner's cover thumbnail, for list pages:
     *   'SELECT p.*, ' . MediaService::coverSql('product', 'p.id') . ' AS cover FROM products p'
     * Use with base_url(). Only public-disk files (private ones aren't web paths).
     */
    public static function coverSql(string $ownerType, string $idColumn, string $roles = "'cover'"): string
    {
        if (!preg_match('/^[a-z_]+$/', $ownerType) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $idColumn)) {
            throw new \InvalidArgumentException('Bad coverSql arguments.');
        }

        return "(SELECT COALESCE(m.thumb_path, m.path) FROM media_links l JOIN media m ON m.id = l.media_id
                 WHERE l.owner_type = '{$ownerType}' AND l.owner_id = {$idColumn} AND l.role IN ({$roles})
                   AND m.kind = 'image' AND m.disk = 'public'
                 ORDER BY l.sort, l.id LIMIT 1)";
    }

    /**
     * Where a file is used, with a readable label and admin link for each owner.
     *
     * @return list<array{owner_type:string, owner_id:int, role:string, label:string, url:?string}>
     */
    public static function usage(int $mediaId): array
    {
        $out = [];
        foreach (DB::select('SELECT owner_type, owner_id, role FROM media_links WHERE media_id = ? ORDER BY owner_type, owner_id', [$mediaId]) as $l) {
            [$label, $url] = self::ownerLabel((string) $l['owner_type'], (int) $l['owner_id']);
            $out[] = ['owner_type' => $l['owner_type'], 'owner_id' => (int) $l['owner_id'], 'role' => $l['role'], 'label' => $label, 'url' => $url];
        }

        return $out;
    }

    /* ------------------------------------------------------------------ edit / delete */

    /** Save alt text / title. Pass null to leave a field unchanged; '' clears it. */
    public static function updateMeta(int $mediaId, ?string $alt, ?string $title): void
    {
        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if ($alt !== null) {
            $data['alt_text'] = mb_substr(trim($alt), 0, 255) ?: null;
        }
        if ($title !== null) {
            $data['title'] = mb_substr(trim($title), 0, 255) ?: null;
        }
        DB::table('media')->where('id', $mediaId)->update($data);
    }

    /** Delete a file from the library. Refused while anything still uses it. */
    public static function delete(int $mediaId): void
    {
        $n = (int) DB::table('media_links')->where('media_id', $mediaId)->count();
        if ($n > 0) {
            throw new MediaException('This file is still used in ' . $n . ' place' . ($n === 1 ? '' : 's') . '. Remove it there first.');
        }
        self::purge($mediaId);
    }

    /** Remove a media row + its files if no links remain. Deferred while a transaction is open. */
    public static function purgeIfUnused(int $mediaId): bool
    {
        if (DB::table('media_links')->where('media_id', $mediaId)->exists()) {
            return false;
        }
        if (DB::connection()->pdo()->inTransaction()) {
            self::$pending[] = $mediaId;

            return false;
        }
        self::purge($mediaId);

        return true;
    }

    /** Run deferred purges (call after a transaction that detached media has committed). */
    public static function flushPending(): void
    {
        $ids = array_unique(self::$pending);
        self::$pending = [];
        foreach ($ids as $id) {
            self::purgeIfUnused($id);
        }
    }

    /** Forget deferred purges (the transaction rolled back, so the links are back). */
    public static function discardPending(): void
    {
        self::$pending = [];
    }

    /* ------------------------------------------------------------------ helpers */

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function present(array $row): array
    {
        $public = ($row['disk'] ?? 'public') === 'public';
        $id = (int) $row['id'];
        $fileUrl = route('admin.media.file', ['id' => $id]) ?? base_url('admin/media/' . $id . '/file');
        $row['url'] = $public ? base_url((string) $row['path']) : $fileUrl;
        $row['thumb_url'] = $row['kind'] === 'image'
            ? ($row['thumb_path']
                ? ($public ? base_url((string) $row['thumb_path']) : $fileUrl . '?thumb=1')
                : $row['url'])
            : null;
        $row['size_label'] = self::humanBytes((int) ($row['size_bytes'] ?? 0));

        return $row;
    }

    /** @param array<string,mixed> $row */
    public static function absolutePath(array $row, bool $thumb = false): string
    {
        $rel = (string) ($thumb && !empty($row['thumb_path']) ? $row['thumb_path'] : $row['path']);

        return self::absolute((string) $row['disk'], $rel);
    }

    public static function number(int $id): string
    {
        return sprintf('MED-%06d', $id);
    }

    /** Smallest of PHP's upload_max_filesize / post_max_size, in bytes. */
    public static function serverLimit(): int
    {
        $toBytes = static function (string $v): int {
            $v = trim($v);
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => $n,
            };
        };
        $limits = array_filter([$toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size'))]);

        return $limits ? min($limits) : 2 * 1024 * 1024;
    }

    /** Max bytes the uploader should accept for a kind (server limit and our own cap). */
    public static function uploadLimit(string $kind = 'image'): int
    {
        return min(self::serverLimit(), self::MAX_BYTES[$kind] ?? self::MAX_BYTES['image']);
    }

    public static function humanBytes(int $b): string
    {
        if ($b >= 1024 ** 2) {
            return rtrim(rtrim(number_format($b / 1024 ** 2, 1), '0'), '.') . ' MB';
        }
        if ($b >= 1024) {
            return (int) round($b / 1024) . ' KB';
        }

        return $b . ' B';
    }

    /** @return list<string> */
    public static function extensionsFor(string $kind): array
    {
        return match ($kind) {
            'image' => self::IMAGE_EXT,
            'video' => self::VIDEO_EXT,
            'document' => self::DOC_EXT,
            default => [],
        };
    }

    private static function kindFor(string $ext, string $mime): ?string
    {
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if ($mime === 'application/pdf') {
            return 'document';
        }
        // finfo can be vague (application/octet-stream) for some videos; fall back to the extension.
        if ($mime === 'application/octet-stream' || $mime === '') {
            if (in_array($ext, self::VIDEO_EXT, true)) {
                return 'video';
            }
            if ($ext === 'avif') {
                return 'image';
            }
        }

        return null;
    }

    private static function extensionFor(string $kind, string $mime, string $ext): string
    {
        $map = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/x-m4v' => 'm4v',
            'application/pdf' => 'pdf',
        ];
        if (isset($map[$mime])) {
            return $map[$mime];
        }

        return in_array($ext, self::extensionsFor($kind), true) ? $ext : ($kind === 'video' ? 'mp4' : ($kind === 'document' ? 'pdf' : 'jpg'));
    }

    private static function sniffMime(string $path): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = (string) finfo_file($f, $path);
                finfo_close($f);
            }
        } elseif (function_exists('mime_content_type')) {
            $mime = (string) mime_content_type($path);
        }

        return strtolower($mime);
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return $name !== '' ? $name : 'file';
    }

    /** @return array{0:string,1:string} [relative dir, absolute dir] */
    private static function targetDir(string $disk): array
    {
        $sub = date('Y/m');
        $rel = $disk === 'public' ? self::PUBLIC_DIR . '/' . $sub : $sub;
        $abs = self::absolute($disk, $rel);
        if (!is_dir($abs) && !@mkdir($abs, 0775, true) && !is_dir($abs)) {
            throw new MediaException('Could not create the upload folder (storage not writable).');
        }

        return [$rel, $abs];
    }

    private static function absolute(string $disk, string $rel): string
    {
        return $disk === 'private'
            ? app()->storagePath('media/' . ltrim($rel, '/'))
            : app()->publicPath(ltrim($rel, '/'));
    }

    /**
     * Resize to MAX_EDGE (and fix JPEG camera rotation) when GD can handle the format.
     * Returns [width, height] after writing $dest, or null to store the original bytes untouched.
     *
     * @param array<int|string,mixed>|null $dims
     * @return array{0:int,1:int}|null
     */
    /** JPEG/WebP quality for stored images that have to be re-encoded (downscaled). */
    public const IMAGE_QUALITY = 95;

    /**
     * Images within MAX_EDGE are kept byte-for-byte (browsers apply EXIF rotation themselves);
     * only larger ones are rotated upright, downscaled and re-encoded. Returns the stored
     * [width, height] as displayed, or null when the original was not rewritten.
     */
    private static function processImage(string $src, string $dest, string $ext, ?array $dims): ?array
    {
        if (!extension_loaded('gd') || $dims === null || $ext === 'gif' || $ext === 'avif') {
            return null; // GIFs may be animated; GD has no AVIF here. Keep as-is.
        }
        [$w, $h] = [(int) $dims[0], (int) $dims[1]];
        if (max($w, $h) <= self::MAX_EDGE) {
            return null;
        }
        $img = self::gdLoad($src, $ext);
        if (!$img) {
            return null;
        }
        $img = self::orient($img, self::exifOrientation($src, $ext));
        $img = self::fit($img, self::MAX_EDGE);
        $ok = self::gdSave($img, $dest, $ext, self::IMAGE_QUALITY);
        $out = [imagesx($img), imagesy($img)];
        imagedestroy($img);

        return $ok ? $out : null;
    }

    private static function exifOrientation(string $path, string $ext): int
    {
        if ($ext !== 'jpg' || !function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($path);

        return (int) ($exif['Orientation'] ?? 1);
    }

    private static function orient(\GdImage $img, int $orientation): \GdImage
    {
        $out = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
        if ($out !== $img && $out !== false) {
            imagedestroy($img);
            return $out;
        }

        return $img;
    }

    private static function makeThumb(string $fileAbs, string $dirRel, string $dirAbs, string $base): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        $info = @getimagesize($fileAbs);
        if ($info === false) {
            return null;
        }
        $ext = image_type_to_extension($info[2], false) ?: '';
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        $img = self::gdLoad($fileAbs, $ext);
        if (!$img) {
            return null;
        }
        // Kept originals may still carry an EXIF rotation flag; GD ignores it, so apply it here.
        $img = self::orient($img, self::exifOrientation($fileAbs, $ext));
        if (!is_dir($dirAbs . '/thumbs')) {
            @mkdir($dirAbs . '/thumbs', 0775, true);
        }
        $img = self::fit($img, self::THUMB_EDGE);
        $tExt = function_exists('imagewebp') ? 'webp' : 'jpg';
        $ok = self::gdSave($img, $dirAbs . '/thumbs/' . $base . '.' . $tExt, $tExt, 80);
        imagedestroy($img);

        return $ok ? $dirRel . '/thumbs/' . $base . '.' . $tExt : null;
    }

    /** @return \GdImage|null */
    private static function gdLoad(string $path, string $ext): ?\GdImage
    {
        $img = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'gif' => @imagecreatefromgif($path),
            default => false,
        };

        return $img instanceof \GdImage ? $img : null;
    }

    private static function fit(\GdImage $img, int $edge): \GdImage
    {
        [$w, $h] = [imagesx($img), imagesy($img)];
        if (max($w, $h) <= $edge) {
            return $img;
        }
        $r = $edge / max($w, $h);
        [$nw, $nh] = [max(1, (int) round($w * $r)), max(1, (int) round($h * $r))];
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        return $out;
    }

    private static function gdSave(\GdImage $img, string $dest, string $ext, int $quality): bool
    {
        return match ($ext) {
            'jpg', 'jpeg' => imagejpeg($img, $dest, $quality),
            'png' => imagepng($img, $dest, 7),
            'webp' => function_exists('imagewebp') && imagewebp($img, $dest, $quality),
            default => false,
        };
    }

    private static function purge(int $mediaId): void
    {
        $row = DB::selectOne('SELECT * FROM media WHERE id = ?', [$mediaId]);
        if (!$row) {
            return;
        }
        DB::table('media')->where('id', $mediaId)->delete();
        // Another row may point at the same path (legacy copies); only unlink when none does.
        foreach (array_filter([$row['path'], $row['thumb_path']]) as $rel) {
            $still = DB::selectOne('SELECT id FROM media WHERE disk = ? AND (path = ? OR thumb_path = ?) LIMIT 1', [$row['disk'], $rel, $rel]);
            $abs = self::absolute((string) $row['disk'], (string) $rel);
            if (!$still && is_file($abs) && self::isManaged((string) $row['disk'], $abs)) {
                @unlink($abs);
            }
        }
    }

    /** Never unlink outside the upload roots (defence against a bad path in the DB). */
    private static function isManaged(string $disk, string $abs): bool
    {
        $root = realpath($disk === 'private' ? app()->storagePath('media') : app()->publicPath('assets/uploads'));
        $real = realpath($abs);

        return $root !== false && $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    private static function moveRole(int $linkId, string $ownerType, int $ownerId, int $mediaId, string $role): void
    {
        $clash = DB::selectOne('SELECT id FROM media_links WHERE owner_type = ? AND owner_id = ? AND media_id = ? AND role = ?', [$ownerType, $ownerId, $mediaId, $role]);
        if ($clash) {
            DB::table('media_links')->where('id', $linkId)->delete();

            return;
        }
        DB::table('media_links')->where('id', $linkId)->update(['role' => $role]);
    }

    private static function assertOwner(string $ownerType, string $role): void
    {
        if (!in_array($ownerType, self::OWNER_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown media owner type "' . $ownerType . '".');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Unknown media role "' . $role . '".');
        }
    }

    /** @return array{0:string,1:?string} */
    private static function ownerLabel(string $type, int $id): array
    {
        try {
            switch ($type) {
                case 'product':
                    $p = DB::selectOne('SELECT name, sku FROM products WHERE id = ?', [$id]);
                    return $p ? [$p['name'] . ' (' . $p['sku'] . ')', route('admin.products.show', ['id' => $id])] : ['Deleted product #' . $id, null];
                case 'variant':
                    $v = DB::selectOne('SELECT v.title, v.sku, v.product_id FROM product_variants v WHERE v.id = ?', [$id]);
                    return $v ? ['Variant ' . $v['title'] . ' (' . $v['sku'] . ')', route('admin.products.show', ['id' => (int) $v['product_id']])] : ['Deleted variant #' . $id, null];
                case 'brand':
                    $b = DB::selectOne('SELECT name FROM brands WHERE id = ?', [$id]);
                    return $b ? ['Brand ' . $b['name'], route('admin.brands')] : ['Deleted brand #' . $id, null];
                case 'category':
                    $c = DB::selectOne('SELECT name FROM product_categories WHERE id = ?', [$id]);
                    return $c ? ['Category ' . $c['name'], route('admin.categories')] : ['Deleted category #' . $id, null];
            }
        } catch (\Throwable $e) {
        }

        return [ucfirst(str_replace('_', ' ', $type)) . ' #' . $id, null];
    }
}
