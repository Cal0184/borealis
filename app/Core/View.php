<?php
declare(strict_types=1);

namespace Borealis\Core;

final class View
{
    public static function render(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) throw new \RuntimeException("View not found: $template");
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    /** Render a page inside the application layout. */
    public static function page(string $template, array $data = [], int $status = 200): Response
    {
        $content = self::render('pages/' . $template, $data);
        $layout = $data['layout'] ?? 'app';
        return Response::html(self::render('layouts/' . $layout, $data + ['content' => $content]), $status);
    }
}
