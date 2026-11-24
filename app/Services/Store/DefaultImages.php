<?php

namespace App\Services\Store;

/**
 * Stock photos used when the catalogue has no picture yet (no products, no category cover,
 * no brand logo). Files live in public/assets/image/defaults/ — <key>.jpg (1600w) and
 * <key>-sm.jpg (600w); they come from the design mockups (html/storefront).
 *
 * Real media always wins: callers only use these where they would otherwise get null.
 */
final class DefaultImages
{
    private const DIR = 'assets/image/defaults/';

    /** Slug keyword → photo key, checked in order (first match wins). */
    private const BY_SLUG = [
        'living' => 'living-room', 'bath' => 'bathroom', 'vanity' => 'bathroom-hero', 'outdoor' => 'outdoor',
        'facade' => 'exterior-facade', 'exterior' => 'exterior-facade', 'interior' => 'interior',
        'kitchen' => 'kitchen', 'counter' => 'countertops', 'wall' => 'wall-cladding', 'clad' => 'wall-cladding',
        'floor' => 'flooring', 'import' => 'showroom', 'indian' => 'indian', 'bed' => 'bedroom',
    ];

    /** Rotation for anything the slug map does not cover (brands, unknown categories). */
    private const POOL = ['showroom', 'lounge', 'flooring', 'nero-bath', 'villa', 'living-room', 'indian', 'bedroom'];

    /** @return array{src:string, full:string, alt:string} */
    public static function get(string $key, string $alt = ''): array
    {
        return ['src' => self::DIR . $key . '-sm.jpg', 'full' => self::DIR . $key . '.jpg', 'alt' => $alt];
    }

    /** Best photo for a category / room slug, else a stable pick from the pool. */
    public static function forSlug(string $slug, string $alt = ''): array
    {
        $slug = mb_strtolower($slug);
        foreach (self::BY_SLUG as $needle => $key) {
            if (str_contains($slug, $needle)) {
                return self::get($key, $alt);
            }
        }

        return self::pool(crc32($slug), $alt);
    }

    /** @return array{src:string, full:string, alt:string} */
    public static function pool(int $n, string $alt = ''): array
    {
        return self::get(self::POOL[abs($n) % count(self::POOL)], $alt);
    }

    /** Hero slides shown when no featured product has a photo (same three as the mockup). @return list<array<string,mixed>> */
    public static function slides(string $city): array
    {
        return [
            ['image' => self::get('showroom'), 'kicker' => $city . ' showroom', 'title' => "Stone for the\nrooms you live in.",
                'copy' => 'Italian and Indian marble, granite and onyx for floors, walls and kitchens. See every slab in our showroom before it reaches your home.',
                'href' => '#collection', 'cta' => 'Explore the collection'],
            ['image' => self::get('countertops'), 'kicker' => 'Countertops · Kitchen', 'title' => "Kitchens that\ntake the heat.",
                'copy' => 'Granite and quartzite cut to your counter, with the edge profile you choose. Heat resistant, easy to clean.',
                'href' => '/shop', 'cta' => 'Explore the collection'],
            ['image' => self::get('bathroom-hero'), 'kicker' => 'Flooring · Bathroom', 'title' => "Calm, cool\nbathrooms.",
                'copy' => 'Honed and leather finishes that stay safe underfoot. Matching vanity tops from the same block.',
                'href' => '/shop', 'cta' => 'Explore the collection'],
        ];
    }
}
