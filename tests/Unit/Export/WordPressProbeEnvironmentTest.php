<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\WordPressProbeEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress {@see ProbeEnvironment} adapter: reads the same facts the pure
 * {@see ProbeStage} prechecks through the real home_url()/get_option()/
 * extension_loaded()/wp_upload_dir() surfaces, so the loopback probe reflects
 * actual runtime WordPress state — without ever touching the network itself or
 * reading the incoming request's authorization headers.
 */
final class WordPressProbeEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_home_url'] = 'https://blog.example.test/';
        unset($GLOBALS['lumeweb_cast_options']['permalink_structure']);
        $GLOBALS['lumeweb_cast_upload_dir'] = [
            'path' => sys_get_temp_dir(),
            'url' => 'http://example.test/wp-content/uploads',
            'subdir' => '',
            'basedir' => sys_get_temp_dir(),
            'baseurl' => 'http://example.test/wp-content/uploads',
            'error' => false,
        ];
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    public function testHomeUrlComesFromWordPressHomeUrlWithTrailingSlash(): void
    {
        $GLOBALS['lumeweb_cast_home_url'] = 'https://blog.example.test';
        self::assertSame('https://blog.example.test/', (new WordPressProbeEnvironment())->homeUrl());

        $GLOBALS['lumeweb_cast_home_url'] = 'https://blog.example.test/';
        self::assertSame('https://blog.example.test/', (new WordPressProbeEnvironment())->homeUrl());
    }

    public function testPermalinkStructureReadsTheConfiguredOption(): void
    {
        $GLOBALS['lumeweb_cast_options']['permalink_structure'] = '/%postname%/';

        self::assertSame('/%postname%/', (new WordPressProbeEnvironment())->permalinkStructure());
    }

    public function testPermalinkStructureIsEmptyForPlainPermalinks(): void
    {
        unset($GLOBALS['lumeweb_cast_options']['permalink_structure']);

        self::assertSame('', (new WordPressProbeEnvironment())->permalinkStructure());
    }

    public function testPhpExtensionFactsReflectTheRunningRuntime(): void
    {
        $env = new WordPressProbeEnvironment();

        self::assertSame(extension_loaded('xml'), $env->xmlLoaded());
        self::assertSame(extension_loaded('dom'), $env->domLoaded());
        self::assertSame(extension_loaded('zip'), $env->zipLoaded());
    }

    public function testUploadsWritableFollowsTheWordPressUploadDir(): void
    {
        $env = new WordPressProbeEnvironment();

        $GLOBALS['lumeweb_cast_upload_dir'] = ['basedir' => sys_get_temp_dir(), 'error' => false];
        self::assertTrue($env->uploadsWritable());

        $GLOBALS['lumeweb_cast_upload_dir'] = ['basedir' => '/nonexistent-cast-uploads-' . uniqid(), 'error' => true];
        self::assertFalse($env->uploadsWritable());

        $GLOBALS['lumeweb_cast_upload_dir'] = ['error' => true];
        self::assertFalse($env->uploadsWritable());
    }

    public function testProbeEnvironmentIsUnawareOfIncomingRequestAuthorizationHeaders(): void
    {
        // The WordPress probe environment reads live site runtime facts only —
        // never the incoming request's authorization surface. An admin request
        // that happens to carry HTTP Basic Auth therefore cannot influence or
        // block loopback probing: the facts it exposes are the same either way.
        $_SERVER['PHP_AUTH_USER'] = 'alice';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:s3cret');
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:s3cret');

        $env = new WordPressProbeEnvironment();

        self::assertSame('https://blog.example.test/', $env->homeUrl());
        self::assertTrue($env->uploadsWritable());
    }
}
