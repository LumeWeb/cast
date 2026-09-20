<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\InvalidUrl;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\UrlRejection;
use PHPUnit\Framework\TestCase;

final class UrlCanonicalizerRejectionTest extends TestCase
{
    public function testRejectsEmptyUrl(): void
    {
        $this->expectException(InvalidUrl::class);
        $this->expectExceptionMessage('empty');

        (new UrlCanonicalizer())->canonicalize('');
    }

    public function testRejectsNonHttpSchemes(): void
    {
        foreach (['mailto:hi@example.com', 'javascript:alert(1)', 'data:text/plain,x', 'tel:+123', 'ftp://example.com/a'] as $url) {
            try {
                (new UrlCanonicalizer())->canonicalize($url);
                self::fail("expected rejection for {$url}");
            } catch (InvalidUrl $e) {
                self::assertSame(UrlRejection::NonHttpScheme, $e->rejection, $url);
            }
        }
    }

    public function testRejectsUserInfo(): void
    {
        try {
            (new UrlCanonicalizer())->canonicalize('https://user:pass@example.com/about/');
            self::fail('expected userinfo rejection');
        } catch (InvalidUrl $e) {
            self::assertSame(UrlRejection::UserInfo, $e->rejection);
        }
    }

    public function testRejectsNulByteBackslashAndControlCharacters(): void
    {
        $cases = [
            "https://example.com/a\0b" => UrlRejection::NulByte,
            "https://example.com/a\x01b" => UrlRejection::ControlCharacter,
            'https://example.com\\about' => UrlRejection::Backslash,
        ];
        foreach ($cases as $url => $expected) {
            try {
                (new UrlCanonicalizer())->canonicalize($url);
                self::fail("expected rejection for {$url}");
            } catch (InvalidUrl $e) {
                self::assertSame($expected, $e->rejection, $url);
            }
        }
    }
}
