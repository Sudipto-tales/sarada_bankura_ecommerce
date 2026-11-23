<?php

namespace App\Controllers\Admin;

use App\Services\Media\MediaException;
use App\Services\Media\MediaService as Media;
use Vayu\Database\DB;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Media library (/admin/media) + the JSON endpoints the shared uploader and "pick from library"
 * dialog talk to. All writes go through MediaService.
 */
class MediaController
{
    private const PER_PAGE = 48;

    /** Owner tables, so an upload can't attach to something that doesn't exist. */
    private const OWNER_TABLES = [
        'product' => 'products', 'variant' => 'product_variants', 'brand' => 'brands', 'category' => 'product_categories',
    ];

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $kind = (string) $request->query('kind', '');
        $owner = (string) $request->query('owner', '');
        $page = max(1, (int) $request->query('page', 1));

        $where = [];
        $bind = [];
        if ($q !== '') {
            $where[] = '(LOWER(m.media_no) LIKE ? OR LOWER(m.original_name) LIKE ? OR LOWER(COALESCE(m.title, \'\')) LIKE ? OR LOWER(COALESCE(m.alt_text, \'\')) LIKE ?)';
            $like = '%' . mb_strtolower($q) . '%';
            array_push($bind, $like, $like, $like, $like);
        }
        if (in_array($kind, ['image', 'video', 'document'], true)) {
            $where[] = 'm.kind = ?';
            $bind[] = $kind;
        }
        if ($owner === 'unused') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM media_links l WHERE l.media_id = m.id)';
        } elseif (in_array($owner, Media::OWNER_TYPES, true)) {
            $where[] = 'EXISTS (SELECT 1 FROM media_links l WHERE l.media_id = m.id AND l.owner_type = ?)';
            $bind[] = $owner;
        }
        $disk = (string) $request->query('disk', '');
        if (in_array($disk, ['public', 'private'], true)) {
            $where[] = 'm.disk = ?';
            $bind[] = $disk;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM media m' . $sqlWhere, $bind)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = DB::select(
            'SELECT m.*, (SELECT COUNT(*) FROM media_links l WHERE l.media_id = m.id) AS used_count
             FROM media m' . $sqlWhere . ' ORDER BY m.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $bind
        );
        $rows = array_map(static fn($r) => Media::present($r) + ['used_count' => (int) $r['used_count']], $rows);

        if ($request->wantsJson()) {
            return json([
                'ok' => true,
                'items' => array_map([$this, 'brief'], $rows),
                'page' => $page, 'pages' => $pages, 'total' => $total,
            ]);
        }

        $stats = DB::selectOne(
            "SELECT COUNT(*) AS files,
                    COALESCE(SUM(size_bytes), 0) AS bytes,
                    SUM(CASE WHEN kind = 'image' THEN 1 ELSE 0 END) AS images,
                    SUM(CASE WHEN kind = 'video' THEN 1 ELSE 0 END) AS videos,
                    SUM(CASE WHEN kind = 'document' THEN 1 ELSE 0 END) AS documents,
                    SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM media_links l WHERE l.media_id = media.id) THEN 1 ELSE 0 END) AS unused
             FROM media"
        ) ?? [];

        return view('admin.media.index', [
            'active' => 'media',
            'rows' => $rows,
            'filters' => ['q' => $q, 'kind' => $kind, 'owner' => $owner, 'disk' => $disk],
            'page' => $page, 'pages' => $pages, 'total' => $total,
            'stats' => array_map('intval', $stats),
            'limits' => ['image' => Media::uploadLimit('image'), 'video' => Media::uploadLimit('video'), 'document' => Media::uploadLimit('document')],
            'success' => session()->get('success'),
            'error' => session()->get('error'),
        ]);
    }

    public function show(string $id): Response
    {
        $m = Media::find((int) $id);
        if ($m === null) {
            return json(['ok' => false, 'error' => 'File not found.'], 404);
        }

        return json(['ok' => true, 'media' => $this->brief($m) + [
            'alt_text' => $m['alt_text'], 'title' => $m['title'], 'mime' => $m['mime'], 'checksum' => $m['checksum'],
            'created_at' => $m['created_at'], 'width' => $m['width'], 'height' => $m['height'],
        ], 'usage' => Media::usage((int) $id)]);
    }

    /**
     * One file per request (Vayu parses single-file uploads). Optional owner_type/owner_id/role
     * attach it straight away; without them it just lands in the library.
     */
    public function upload(Request $request): Response
    {
        $ownerType = (string) $request->input('owner_type', '');
        $ownerId = (int) $request->input('owner_id', 0);
        $role = (string) $request->input('role', '');
        try {
            if ($ownerType !== '') {
                $this->assertOwner($ownerType, $ownerId);
            }
            $kinds = array_values(array_intersect(
                array_filter(explode(',', (string) $request->input('kinds', 'image,video'))),
                ['image', 'video', 'document']
            )) ?: ['image', 'video'];
            $disk = $request->input('disk') === 'private' ? 'private' : 'public';
            $media = Media::storeUpload($request->file('file'), [
                'disk' => $disk, 'kinds' => $kinds,
                'alt' => $request->input('alt_text'), 'title' => $request->input('title'),
            ]);
            $linkId = null;
            if ($ownerType !== '') {
                if ($role === '') {
                    $role = $media['kind'] === 'video' ? 'video' : ($ownerType === 'brand' ? 'logo' : 'gallery');
                }
                if (!in_array($role, Media::ROLES, true)) {
                    throw new MediaException('Unknown role.');
                }
                $linkId = in_array($role, ['logo', 'cover'], true)
                    ? Media::replaceRole($ownerType, $ownerId, (int) $media['id'], $role)
                    : Media::attach($ownerType, $ownerId, (int) $media['id'], $role);
                if (in_array($ownerType, ['product', 'variant'], true)) {
                    Media::ensureCover($ownerType, $ownerId);
                }
                $media['role'] = DB::table('media_links')->where('id', $linkId)->value('role') ?? $role;
            }

            return json(['ok' => true, 'media' => $this->brief($media) + ['role' => $media['role'] ?? null], 'duplicate' => (bool) $media['duplicate'], 'link_id' => $linkId]);
        } catch (MediaException $e) {
            return json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, string $id): Response
    {
        $mid = (int) $id;
        if (Media::find($mid) === null) {
            return $this->reply($request, false, 'File not found.', 404);
        }
        $alt = $request->input('alt_text');
        $title = $request->input('title');
        Media::updateMeta($mid, is_string($alt) ? $alt : null, is_string($title) ? $title : null);

        return $this->reply($request, true, 'Details saved.');
    }

    public function destroy(Request $request, string $id): Response
    {
        try {
            Media::delete((int) $id);

            return $this->reply($request, true, 'File deleted.');
        } catch (MediaException $e) {
            return $this->reply($request, false, $e->getMessage(), 422);
        }
    }

    /** Bulk delete — unused files only; anything still linked is skipped and reported. */
    public function bulkDelete(Request $request): Response
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        $done = 0;
        $skipped = 0;
        foreach ($ids as $mid) {
            try {
                Media::delete($mid);
                $done++;
            } catch (MediaException $e) {
                $skipped++;
            }
        }
        $msg = $done . ' file' . ($done === 1 ? '' : 's') . ' deleted.' . ($skipped ? ' ' . $skipped . ' skipped because they are still in use.' : '');

        return $this->reply($request, $done > 0 || !$ids, $ids ? $msg : 'Select files first.');
    }

    /** Stream a file (private disk, or any file by media id). Admin area only. */
    public function file(Request $request, string $id): Response
    {
        $m = DB::selectOne('SELECT * FROM media WHERE id = ?', [(int) $id]);
        if (!$m) {
            return response('Not found', 404);
        }
        $thumb = (bool) $request->query('thumb') && !empty($m['thumb_path']);
        $abs = Media::absolutePath($m, $thumb);
        if (!is_file($abs)) {
            return response('Not found', 404);
        }
        $mime = $thumb ? (mime_content_type($abs) ?: 'application/octet-stream') : ((string) $m['mime'] ?: 'application/octet-stream');
        $name = str_replace(['"', "\r", "\n"], '', (string) ($m['original_name'] ?: basename($abs)));

        return response((string) file_get_contents($abs), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($request->query('download') ? 'attachment' : 'inline') . '; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** @param array<string,mixed> $m @return array<string,mixed> */
    private function brief(array $m): array
    {
        return [
            'id' => (int) $m['id'], 'media_no' => $m['media_no'], 'kind' => $m['kind'], 'disk' => $m['disk'],
            'name' => $m['original_name'], 'title' => $m['title'] ?? null, 'alt_text' => $m['alt_text'] ?? null,
            'url' => $m['url'], 'thumb_url' => $m['thumb_url'], 'size' => $m['size_label'],
            'width' => isset($m['width']) ? (int) $m['width'] : null, 'height' => isset($m['height']) ? (int) $m['height'] : null,
            'used_count' => $m['used_count'] ?? null,
        ];
    }

    private function assertOwner(string $type, int $id): void
    {
        $table = self::OWNER_TABLES[$type] ?? null;
        if ($table === null || $id <= 0 || !DB::table($table)->where('id', $id)->exists()) {
            throw new MediaException('The item this file should be attached to was not found.');
        }
    }

    private function reply(Request $request, bool $ok, string $message, int $status = 200): Response
    {
        if ($request->wantsJson()) {
            return json(['ok' => $ok, $ok ? 'message' : 'error' => $message], $ok ? 200 : $status);
        }
        session()->flash($ok ? 'success' : 'error', $message);

        return redirect(route('admin.media'));
    }
}
