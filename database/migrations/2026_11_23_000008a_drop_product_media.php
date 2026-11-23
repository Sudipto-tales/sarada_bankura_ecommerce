<?php

use Vayu\Database\DB;
use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Stage 1 (end): every reader now uses media + media_links (MediaService), so the old
 * per-product table goes. down() rebuilds it from the product links.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('product_media')) {
            return;
        }
        // Safety net: refuse to drop if 000008 somehow didn't copy every row.
        $old = (int) DB::table('product_media')->count();
        $copied = (int) (DB::select(
            "SELECT COUNT(*) AS n FROM media_links WHERE owner_type = 'product' AND role IN ('cover','gallery','video')"
        )[0]['n'] ?? 0);
        if ($copied < $old) {
            throw new \RuntimeException("product_media has {$old} rows but only {$copied} were copied to media_links; not dropping.");
        }
        Schema::dropIfExists('product_media');
    }

    public function down(): void
    {
        Schema::create('product_media', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('product_id')->index();
            $t->string('path');
            $t->string('type', 16)->default('image');
            $t->boolean('is_cover')->default(false);
            $t->integer('sort')->default(0);
        });
        $rows = DB::select(
            "SELECT l.owner_id, l.role, l.sort, m.path, m.kind FROM media_links l JOIN media m ON m.id = l.media_id
             WHERE l.owner_type = 'product' AND l.role IN ('cover','gallery','video') AND m.disk = 'public'
             ORDER BY l.owner_id, l.sort"
        );
        foreach ($rows as $r) {
            DB::table('product_media')->insert([
                'product_id' => (int) $r['owner_id'], 'path' => $r['path'],
                'type' => $r['kind'] === 'video' ? 'video' : 'image',
                'is_cover' => $r['role'] === 'cover' ? 1 : 0, 'sort' => (int) $r['sort'],
            ]);
        }
    }
};
