<?php

namespace App\Services\Store;

use Vayu\Http\Response;

/**
 * Renders a storefront page inside a layout (docs/storefront.md):
 *
 *   View::page('store.home', $data, ['title' => …, 'description' => …, 'active' => 'home'])
 *
 * The page file is rendered first (so it can set $page['…'] values such as extra <head> tags),
 * then wrapped by resources/views/store/layouts/{app|minimal}.php with $content.
 * View helpers (store_card(), store_img(), …) live in helpers.php and are loaded once here.
 */
final class View
{
    private static bool $helpers = false;

    /** @param array<string,mixed> $data @param array<string,mixed> $page */
    public static function page(string $view, array $data = [], array $page = [], int $status = 200): Response
    {
        return Response::make(self::html($view, $data, $page), $status);
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $page */
    public static function html(string $view, array $data = [], array $page = []): string
    {
        self::loadHelpers();
        $page += [
            'title' => null,
            'description' => null,
            'active' => '',
            'layout' => 'app',
            'canonical' => null,
            'head' => '',
            'scripts' => '',
            'chrome' => true,
            'tabbar' => true,
        ];
        $base = app()->basePath('resources/views/');
        $content = self::renderFile($base . str_replace('.', '/', $view) . '.php', $data + ['page' => &$page]);

        $chrome = null;
        if ($page['chrome']) {
            try {
                $chrome = Catalog::chrome();
            } catch (\Throwable) {
                $chrome = null; // DB down: still render the page with a bare header
            }
        }

        return self::renderFile($base . 'store/layouts/' . $page['layout'] . '.php', [
            'content' => $content,
            'page' => $page,
            'chrome' => $chrome,
        ]);
    }

    public static function loadHelpers(): void
    {
        if (!self::$helpers) {
            require_once __DIR__ . '/helpers.php';
            self::$helpers = true;
        }
    }

    /** @param array<string,mixed> $__data */
    private static function renderFile(string $__file, array $__data): string
    {
        if (!is_file($__file)) {
            throw new \RuntimeException('Storefront view not found: ' . $__file);
        }
        extract($__data, EXTR_SKIP | EXTR_REFS);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
