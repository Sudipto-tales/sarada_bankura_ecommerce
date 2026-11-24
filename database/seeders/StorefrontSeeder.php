<?php

use Vayu\Database\DB;
use Vayu\Database\Seeder;

/**
 * Storefront defaults (docs/storefront.md S1). Idempotent: only fills values that are still NULL,
 * so anything set in the admin later is never overwritten.
 *
 *   tags.icon                 Lucide icons + short copy for the "Shop by need" tiles (discovery tags)
 *   attribute_values.family   colour families for the shop's colour facet (owner default §6 Q2: yes)
 *
 * Run: php vayu db:seed StorefrontSeeder
 */
return new class extends Seeder {
    public function run(): void
    {
        $tags = [
            'imported' => ['globe', 'Italy, Spain, Turkey and beyond'],
            'white' => ['circle', 'Bright, light-filled rooms'],
            'book-matched' => ['book-open', 'Mirrored slabs for feature walls'],
            'heat-resistant' => ['flame', 'Hot pans, cooking zones'],
            'low-maintenance' => ['sparkles', 'Easy daily cleaning'],
            'outdoor-safe' => ['sun', 'Sun, rain, anti-slip finishes'],
            'vastu-friendly' => ['compass', 'Colours chosen by direction'],
        ];
        $n = 0;
        foreach ($tags as $slug => [$icon, $copy]) {
            $n += DB::table('tags')->where('slug', $slug)->whereNull('icon')->update(['icon' => $icon]);
            DB::table('tags')->where('slug', $slug)->whereNull('description')->update(['description' => $copy]);
        }
        echo "Tag icons set: {$n}.\n";

        $families = [
            'Statuario White' => 'white', 'Carrara Grey' => 'grey', 'Botticino Beige' => 'beige', 'Nero Black' => 'black',
            'Emperador Brown' => 'brown', 'Verde Green' => 'green', 'Rosso Red' => 'red', 'Onyx Honey' => 'gold',
            'Makrana Pink' => 'pink', 'Ivory Cream' => 'beige',
        ];
        $colour = DB::table('attributes')->where('code', 'colour')->first();
        $m = 0;
        if ($colour) {
            foreach ($families as $label => $family) {
                $m += DB::table('attribute_values')->where('attribute_id', $colour['id'])->where('label', $label)->whereNull('family')->update(['family' => $family]);
            }
        }
        echo "Colour families set: {$m}.\n";
    }
};
