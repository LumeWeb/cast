<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\ViewRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Behavioral tests for the explicit admin template loader.
 *
 * The loader is the "new template boundary": production HTML rendering must be
 * produced by PHP templates included with require/include and explicit view
 * data, never by output buffering. These tests pin that boundary — the output
 * has to come from the template file, and missing templates must fail loudly
 * instead of silently buffering or echoing nothing.
 */
final class ViewRendererTest extends TestCase
{
    public function testRenderIncludesTemplateAndBindsExplicitViewData(): void
    {
        $renderer = new ViewRenderer($this->fixturesPath());

        ob_start();
        $renderer->render('hello.php', ['name' => 'World']);
        $output = (string) ob_get_clean();

        // The template file produced the output; the value travelled through
        // the explicit $view array (escaped by the template itself).
        self::assertStringContainsString('Hello World', $output);
        self::assertStringContainsString('<', $output, 'The fixture echoes raw open tags, not pre-escaped data.');
    }

    public function testTemplateOwnsEscapingOfRawViewData(): void
    {
        $renderer = new ViewRenderer($this->fixturesPath());

        ob_start();
        $renderer->render('hello.php', ['name' => '<script>alert(1)</script>']);
        $output = (string) ob_get_clean();

        // The template is responsible for escaping; the renderer must not
        // pre-escape (double escaping would corrupt output) and must not leak
        // raw data.
        self::assertStringContainsString('&lt;script&gt;', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    public function testRenderRequiresTemplateFileNotJustDirectory(): void
    {
        $renderer = new ViewRenderer($this->fixturesPath());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does-not-exist.php');

        $renderer->render('does-not-exist.php', []);
    }

    /**
     * The default loader must point at the real production template tree
     * (project-root templates/admin), never at a nonexistent src/templates/admin
     * fallback. Production admin rendering is template-driven, so the boundary
     * has to resolve to files that actually exist.
     */
    public function testDefaultBasePathResolvesProductionTemplateRoot(): void
    {
        $renderer = new ViewRenderer();
        $property = new \ReflectionProperty(ViewRenderer::class, 'basePath');
        $basePath = rtrim((string) $property->getValue($renderer), '/');

        self::assertStringEndsWith('/templates/admin', $basePath);
        // The default base MUST be the project-root tree, not a src/ fallback.
        self::assertStringNotContainsString('/src/templates/admin', $basePath);
        // The real production page template must exist on the default path.
        self::assertFileExists($basePath . '/page.php');
    }

    private function fixturesPath(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Admin/templates';
    }
}
