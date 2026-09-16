<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use RuntimeException;

/**
 * Deliberately tiny loader for the admin view templates.
 *
 * Includes a PHP template file (default base: templates/admin) with an explicit
 * $view array bound into the template scope. Production rendering never buffers
 * output — the template echoes directly — and templates hold no business logic
 * beyond iteration and escaping.
 */
final class ViewRenderer
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? dirname(__DIR__, 2) . '/templates/admin', '/');
    }

    /**
     * @param string $template Template file name relative to the base path,
     *                         e.g. 'page.php'.
     * @param array<string, mixed> $view Explicit, already-computed view data.
     *
     * @throws RuntimeException When the template file does not exist.
     */
    public function render(string $template, array $view): void
    {
        $file = $this->basePath . '/' . ltrim($template, '/');

        if (!is_file($file)) {
            throw new RuntimeException("Admin template not found: {$file}");
        }

        include $file;
    }
}
