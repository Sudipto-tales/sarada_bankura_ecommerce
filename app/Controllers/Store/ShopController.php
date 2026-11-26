<?php

namespace App\Controllers\Store;

use App\Services\Store\Catalog;
use App\Services\Store\Listing;
use App\Services\Store\View;
use Vayu\Http\Request;
use Vayu\Http\Response;

/**
 * Shop listing (docs/storefront.md): /shop, /shop/{space}, /shop/{space}/{room}.
 * Filters come from the query string (Listing::fromQuery whitelists them); the page works without
 * JS: facets are a GET form, chips and pages are links.
 */
class ShopController
{
    public function index(Request $request): Response
    {
        // Stone finder without JS posts ?cat=<slug>: move it into the path (one URL per listing).
        $cat = $request->query('cat');
        if (is_string($cat) && $cat !== '') {
            $found = self::find($cat);
            $rest = $request->query;
            unset($rest['cat']);
            if ($found !== null) {
                return redirect(base_url(self::path($found[0], $found[1])) . Listing::query(Listing::fromQuery($rest)), 302);
            }
        }

        return $this->render($request, null, null);
    }

    public function category(Request $request, string $cat): Response
    {
        $found = self::find($cat);
        if ($found === null) {
            abort(404);
        }
        // A room reached by its own slug (/shop/flooring-bathroom) → canonical /shop/flooring/flooring-bathroom.
        if ($found[1] !== null) {
            return redirect(base_url(self::path($found[0], $found[1])) . Listing::query(Listing::fromQuery($request->query)), 301);
        }

        return $this->render($request, $found[0], null);
    }

    public function room(Request $request, string $cat, string $sub): Response
    {
        $found = self::find($sub);
        if ($found === null || $found[1] === null || $found[0]['slug'] !== mb_strtolower($cat)) {
            abort(404);
        }

        return $this->render($request, $found[0], $found[1]);
    }

    /* ---------------------------------------------------------------- */

    private function render(Request $request, ?array $top, ?array $room): Response
    {
        View::loadHelpers();
        $current = $room ?? $top;
        $state = Listing::fromQuery($request->query, $current);
        $result = Listing::run($state);
        $cards = Catalog::cards($result['ids']);
        $chrome = Catalog::chrome();
        $path = $current ? self::path($top, $room) : '/shop';

        $hero = self::hero($state, $top, $room, $chrome);
        $chips = self::subChips($chrome, $top, $room);

        $others = [];
        foreach ($chrome['spaces'] as $s) {
            foreach ($s['children'] as $c) {
                if (!$current || $c['id'] !== $current['id']) {
                    $others[] = $c + ['parent' => $s['name'], 'url' => self::path($s, $c)];
                }
            }
        }


        return View::page('store.shop', [
            'state' => $state,
            'result' => $result,
            'cards' => $cards,
            'path' => $path,
            'top' => $top,
            'room' => $room,
            'hero' => $hero,
            'subChips' => $chips,
            'tree' => $chrome['spaces'],
            'origins' => $chrome['origins'],
            'total' => $chrome['total'],
            'others' => array_slice($others, 0, 6),
        ], [
            'title' => $hero['title_plain'] . ($result['page'] > 1 ? ' · page ' . $result['page'] : ''),
            'description' => $hero['copy'],
            'active' => 'shop',
            'canonical' => base_url($path) . Listing::query($state, ['page' => $result['page']]),
            // Search results and deep filter combinations are not pages for search engines.
            'head' => $state['q'] !== '' || $result['filters'] > 1 ? '<meta name="robots" content="noindex, follow">' : '',
        ]);
    }

    /** Visible category by slug → [top-level row, room row|null], or null. */
    public static function find(string $slug): ?array
    {
        $slug = mb_strtolower(trim($slug));
        foreach (Catalog::chrome()['spaces'] as $top) {
            if ($top['slug'] === $slug) {
                return [$top, null];
            }
            foreach ($top['children'] as $c) {
                if ($c['slug'] === $slug) {
                    return [$top, $c];
                }
            }
        }
        foreach (Catalog::chrome()['origins'] as $top) {
            if ($top['slug'] === $slug) {
                return [$top, null];
            }
        }

        return null;
    }

    public static function path(?array $top, ?array $room): string
    {
        if ($top === null) {
            return '/shop';
        }

        return '/shop/' . rawurlencode($top['slug']) . ($room ? '/' . rawurlencode($room['slug']) : '');
    }

    /** Title, kicker, copy and picture for the dark header block. @return array<string,mixed> */
    private static function hero(array $state, ?array $top, ?array $room, array $chrome): array
    {
        $current = $room ?? $top;
        $tag = count($state['tag']) === 1 && $current === null ? self::findBy($chrome['needs'], 'slug', $state['tag'][0]) : null;
        $brand = null;
        if (count($state['brand']) === 1 && $current === null) {
            foreach (Catalog::brands() as $b) {
                if ($b['slug'] === mb_strtolower($state['brand'][0])) {
                    $brand = $b;
                }
            }
        }
        $isOrigin = $top !== null && $top['children'] === [];

        if ($current !== null) {
            $title = $current['name'];
            $kicker = $room ? $top['name'] : ($isOrigin ? 'By origin' : 'Shop by space');
            $copy = $current['description'] !== '' ? $current['description']
                : ($room ? $top['name'] . ' stone chosen for the ' . mb_strtolower($room['name']) . '.'
                    : ($isOrigin ? $current['name'] . ' marble, granite and onyx.' : 'Stone for ' . mb_strtolower($current['name']) . ', every room.'));
            $image = $current['image'];
        } elseif ($state['q'] !== '') {
            $title = 'Results for “' . $state['q'] . '”';
            $kicker = 'Search';
            $copy = 'Can’t find it? We source to order — ask us.';
            $image = null;
        } elseif ($state['offer']) {
            $title = 'Offers';
            $kicker = 'Shop';
            $copy = 'Stones priced below their usual rate, while this lot lasts.';
            $image = null;
        } elseif ($tag) {
            $title = $tag['name'];
            $kicker = 'Good for';
            $copy = $tag['copy'] !== '' ? $tag['copy'] : 'Stones our customers pick for this.';
            $image = null;
        } elseif ($brand) {
            $title = $brand['name'];
            $kicker = 'Brand' . ($brand['country'] !== '' ? ' · ' . $brand['country'] : '');
            $copy = $brand['description'] !== '' ? $brand['description'] : 'Every stone we stock from ' . $brand['name'] . '.';
            $image = $brand['is_logo'] ? null : $brand['image'];
        } else {
            $title = 'All stone';
            $kicker = 'Shop';
            $copy = 'Marble, granite and onyx for every room. Delivery included in every price.';
            $image = $chrome['spaces'][0]['image'] ?? null;
        }

        return ['title' => $title, 'title_plain' => $current ? $title : ($state['q'] !== '' ? 'Search: ' . $state['q'] : ($title === 'All stone' ? 'Shop stone' : $title)),
            'kicker' => $kicker, 'copy' => $copy, 'image' => $image ?? \App\Services\Store\DefaultImages::get($state['q'] !== '' || $tag ? 'interior' : 'showroom')];
    }

    /** Chips under the header: rooms of the space, siblings of the room, or every space. @return list<array> */
    private static function subChips(array $chrome, ?array $top, ?array $room): array
    {
        if ($top !== null && $top['children'] !== []) {
            $chips = [['label' => 'All ' . mb_strtolower($top['name']), 'url' => self::path($top, null), 'on' => $room === null, 'count' => $top['count'], 'image' => null]];
            foreach ($top['children'] as $c) {
                $chips[] = ['label' => $c['name'], 'url' => self::path($top, $c), 'on' => $room !== null && $room['id'] === $c['id'], 'count' => $c['count'], 'image' => $c['image']];
            }

            return $chips;
        }
        $chips = [];
        foreach (array_merge($chrome['spaces'], $chrome['origins']) as $t) {
            $chips[] = ['label' => $t['name'], 'url' => self::path($t, null), 'on' => $top !== null && $t['id'] === $top['id'], 'count' => $t['count'], 'image' => $t['image']];
        }

        return $chips;
    }

    private static function findBy(array $rows, string $key, string $value): ?array
    {
        foreach ($rows as $r) {
            if (mb_strtolower((string) $r[$key]) === mb_strtolower($value)) {
                return $r;
            }
        }

        return null;
    }
}
