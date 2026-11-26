<?php

use Vayu\Database\DB;
use Vayu\Database\Migrations\Migration;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;

/**
 * Stage 1: one central media system (docs/README.md).
 *
 *   media        one row per physical file (its own MED-000123 number)
 *   media_links  who uses it: owner_type/owner_id + role (cover|gallery|video|logo|photo|proof|document)
 *
 * Existing product_media rows and brands.logo paths are copied in. product_media itself is dropped
 * by the next migration (…000008a), once every reader has moved to these tables.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('media', function (Blueprint $t): void {
            $t->id();
            $t->string('media_no', 20)->unique();
            $t->string('disk', 16)->default('public');      // public | private
            $t->string('path', 255);                         // public: relative to public/, private: relative to storage/media/
            $t->string('thumb_path', 255)->nullable();
            $t->string('original_name', 255)->nullable();
            $t->string('mime', 100)->nullable();
            $t->string('kind', 16)->default('image');        // image | video | document
            $t->string('extension', 16)->nullable();
            $t->bigInteger('size_bytes')->nullable();
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->string('checksum', 40)->nullable()->index(); // sha1 of the stored bytes → duplicate detection
            $t->string('alt_text', 255)->nullable();
            $t->string('title', 255)->nullable();
            $t->bigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });

        Schema::create('media_links', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('media_id')->index();
            $t->string('owner_type', 32);
            $t->bigInteger('owner_id')->index();
            $t->string('role', 16)->default('gallery');
            $t->integer('sort')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->uniqueIndex(['owner_type', 'owner_id', 'media_id', 'role']);
            $t->addIndex(['owner_type', 'owner_id']);
        });

        $this->copyExisting();
    }

    public function down(): void
    {
        Schema::dropIfExists('media_links');
        Schema::dropIfExists('media');
    }

    /** Copy product_media rows and brand logos into media + media_links. */
    private function copyExisting(): void
    {
        $now = date('Y-m-d H:i:s');
        $byPath = [];

        $make = function (string $path, string $kind) use (&$byPath, $now): int {
            if (isset($byPath[$path])) {
                return $byPath[$path];
            }
            $full = app()->publicPath($path);
            $exists = is_file($full);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $size = $exists ? getimagesize($full) : false;
            $id = (int) DB::table('media')->insertGetId([
                'media_no' => 'TMP-' . bin2hex(random_bytes(6)),
                'disk' => 'public',
                'path' => $path,
                'original_name' => basename($path),
                'mime' => $exists ? (mime_content_type($full) ?: null) : null,
                'kind' => $kind,
                'extension' => $ext ?: null,
                'size_bytes' => $exists ? filesize($full) : null,
                'width' => $size ? $size[0] : null,
                'height' => $size ? $size[1] : null,
                'checksum' => $exists ? sha1_file($full) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('media')->where('id', $id)->update(['media_no' => sprintf('MED-%06d', $id)]);

            return $byPath[$path] = $id;
        };

        if (Schema::hasTable('product_media')) {
            foreach (DB::select('SELECT * FROM product_media ORDER BY product_id, sort, id') as $r) {
                $kind = ($r['type'] ?? 'image') === 'video' ? 'video' : 'image';
                $role = $kind === 'video' ? 'video' : ((int) $r['is_cover'] ? 'cover' : 'gallery');
                $mid = $make((string) $r['path'], $kind);
                $exists = DB::table('media_links')->where('owner_type', 'product')->where('owner_id', (int) $r['product_id'])
                    ->where('media_id', $mid)->where('role', $role)->exists();
                if (!$exists) {
                    DB::table('media_links')->insert([
                        'media_id' => $mid, 'owner_type' => 'product', 'owner_id' => (int) $r['product_id'],
                        'role' => $role, 'sort' => (int) $r['sort'], 'created_at' => $now,
                    ]);
                }
            }
        }

        $logos = Schema::hasTable('brands') ? DB::select("SELECT id, logo FROM brands WHERE logo IS NOT NULL AND logo <> ''") : [];
        foreach ($logos as $b) {
            $mid = $make((string) $b['logo'], 'image');
            DB::table('media_links')->insert([
                'media_id' => $mid, 'owner_type' => 'brand', 'owner_id' => (int) $b['id'],
                'role' => 'logo', 'sort' => 0, 'created_at' => $now,
            ]);
        }
    }
};
