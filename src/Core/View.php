<?php

declare(strict_types=1);

namespace Mailing\Core;

/** Plain-PHP templates under views/, optionally wrapped in a layout. */
final class View
{
    /** @var array<string, string> extra HTML for the layout ("head", "foot"), set from inside a view */
    private static array $sections = [];

    public static function section(string $name, string $html): void
    {
        self::$sections[$name] = (self::$sections[$name] ?? '') . $html;
    }

    public static function get(string $name): string
    {
        return self::$sections[$name] ?? '';
    }

    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $view, array $data = []): string
    {
        $file = BASE_PATH . '/views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Vista inexistent: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function show(string $view, array $data = [], ?string $layout = 'layouts/app'): never
    {
        header('Content-Type: text/html; charset=utf-8');
        echo self::render($view, $data, $layout);
        exit;
    }
}
