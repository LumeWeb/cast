<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\InvalidUrl;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\UrlRejection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Named canonicalization vectors (test-vector closure).
 *
 * Each provider maps to documented canonicalization rules:
 * long/page queries (sorted, preserved), asset version parameters, fragments,
 * schemes/userinfo, and over-encoded %25 collapse. Vectors are named so a
 * failing assertion names the exact rule it pins.
 */
final class UrlCanonicalizerK1VectorsTest extends TestCase
{
    private UrlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->canonicalizer = new UrlCanonicalizer();
    }

    #[DataProvider('pageQueryProvider')]
    public function testPageQueryPairsAreSortedAndPreserved(string $raw, string $expected): void
    {
        self::assertSame($expected, (string) $this->canonicalizer->canonicalize($raw));
    }

    /**
     * "Pages with query strings are first-class: identity keeps the query".
     *
     * @return array<string, array{string, string}>
     */
    public static function pageQueryProvider(): array
    {
        return [
            'single page query is kept' => [
                'https://example.com/?s=hello',
                'https://example.com/?s=hello',
            ],
            'reordered params share one canonical query' => [
                'https://example.com/?b=2&a=1',
                'https://example.com/?a=1&b=2',
            ],
            'repeated params survive sorting' => [
                'https://example.com/?x=2&x=1&a=1',
                'https://example.com/?a=1&x=1&x=2',
            ],
            'long page query is preserved whole' => [
                'https://example.com/search?' . str_repeat('q', 20) . '=' . str_repeat('x', 300),
                'https://example.com/search?' . str_repeat('q', 20) . '=' . str_repeat('x', 300),
            ],
            'empty query collapses to no query' => [
                'https://example.com/?',
                'https://example.com/',
            ],
        ];
    }

    #[DataProvider('assetVersionProvider')]
    public function testAssetVersionParamsAreKeptByCanonicalizerAndSorted(string $raw, string $expectedBase, string $expectedQuery): void
    {
        $url = $this->canonicalizer->canonicalize($raw);

        self::assertSame($expectedBase, $url->base());
        self::assertSame($expectedQuery, $url->query());
    }

    /**
     * "Asset extensions: query dropped for identity (`?ver=` is a
     * cache-buster)" — the canonicalizer still sorts it; WorkItemFactory drops
     * it for asset identity (covered by WorkItemFactoryK1ClosureTest).
     *
     * @return array<string, array{string, string, string}>
     */
    public static function assetVersionProvider(): array
    {
        return [
            'version param on stylesheet is kept and sorted' => [
                'https://example.com/wp-content/themes/x/style.css?ver=6.7.1',
                'https://example.com/wp-content/themes/x/style.css',
                'ver=6.7.1',
            ],
            'version and cache busters sort together' => [
                'https://example.com/wp-content/themes/x/app.js?v=3&ver=6.7.1',
                'https://example.com/wp-content/themes/x/app.js',
                'v=3&ver=6.7.1',
            ],
        ];
    }

    #[DataProvider('fragmentProvider')]
    public function testFragmentsAreStrippedForIdentity(string $raw, string $expected): void
    {
        self::assertSame($expected, (string) $this->canonicalizer->canonicalize($raw));
    }

    /**
     * "Strip fragment".
     *
     * @return array<string, array{string, string}>
     */
    public static function fragmentProvider(): array
    {
        return [
            'named anchor on pretty page is stripped' => [
                'https://example.com/about/#team',
                'https://example.com/about/',
            ],
            'fragment after query is stripped' => [
                'https://example.com/s?q=1#results',
                'https://example.com/s?q=1',
            ],
            'fragment with percent content is stripped not decoded' => [
                'https://example.com/a/%2520#x%2520y',
                'https://example.com/a/%20',
            ],
        ];
    }

    #[DataProvider('schemeAndUserInfoProvider')]
    public function testSchemesAndUserInfoNormalizeOrReject(string $raw, ?string $expected, ?UrlRejection $expectedRejection): void
    {
        if ($expectedRejection !== null) {
            try {
                $this->canonicalizer->canonicalize($raw);
                self::fail("expected {$expectedRejection->value} rejection for {$raw}");
            } catch (InvalidUrl $e) {
                self::assertSame($expectedRejection, $e->rejection, $raw);
            }

            return;
        }

        self::assertSame($expected, (string) $this->canonicalizer->canonicalize($raw));
    }

    /**
     * "Reject before insert: non-http(s) ... userinfo in URL", plus the
     * "lowercase scheme/host" and default-port rules.
     *
     * @return array<string, array{string, ?string, ?UrlRejection}>
     */
    public static function schemeAndUserInfoProvider(): array
    {
        return [
            'scheme and host are lowercased' => [
                'HTTPS://EXAMPLE.COM/About',
                'https://example.com/About',
                null,
            ],
            'http default port 80 is dropped' => [
                'http://example.com:80/a',
                'http://example.com/a',
                null,
            ],
            'https default port 443 is dropped' => [
                'https://example.com:443/a',
                'https://example.com/a',
                null,
            ],
            'non-default port participates in identity' => [
                'https://example.com:8443/a',
                'https://example.com:8443/a',
                null,
            ],
            'empty URL is rejected' => [
                '',
                null,
                UrlRejection::Empty,
            ],
            'relative URL without scheme is rejected' => [
                '/about/',
                null,
                UrlRejection::Relative,
            ],
            'mailto scheme is rejected' => [
                'mailto:hi@example.com',
                null,
                UrlRejection::NonHttpScheme,
            ],
            'javascript scheme is rejected' => [
                'javascript:alert(1)',
                null,
                UrlRejection::NonHttpScheme,
            ],
            'userinfo with credentials is rejected' => [
                'https://user:pass@example.com/about/',
                null,
                UrlRejection::UserInfo,
            ],
            'userinfo without password is rejected' => [
                'https://user@example.com/about/',
                null,
                UrlRejection::UserInfo,
            ],
        ];
    }

    #[DataProvider('overEncodedProvider')]
    public function testOverEncodedPercentCollapsesToCanonicalEscape(string $raw, string $expected): void
    {
        self::assertSame($expected, (string) $this->canonicalizer->canonicalize($raw));
    }

    /**
     * "decode over-encoded `%25`". Collapse `%25xx` to `%xx` so equal
     * decoded resources share one identity, without ever un-encoding `%20` to a
     * raw space and without touching a lone literal `%` (`%25` not followed by
     * hex stays).
     *
     * @return array<string, array{string, string}>
     */
    public static function overEncodedProvider(): array
    {
        return [
            'double-encoded space in path collapses' => [
                'https://example.com/a%2520b',
                'https://example.com/a%20b',
            ],
            'double-encoded slash in path collapses but stays encoded' => [
                'https://example.com/a%252Fb',
                'https://example.com/a%2Fb',
            ],
            'triple-encoded space converges to single escape' => [
                'https://example.com/a%25252520b',
                'https://example.com/a%20b',
            ],
            'single-encoded space is never un-encoded' => [
                'https://example.com/a%20b',
                'https://example.com/a%20b',
            ],
            'lone literal percent is preserved' => [
                'https://example.com/100%25',
                'https://example.com/100%25',
            ],
            'percent followed by non-hex is preserved' => [
                'https://example.com/?p=50%25off',
                'https://example.com/?p=50%25off',
            ],
            'double-encoded space in query collapses before sort' => [
                'https://example.com/?q=a%2520b',
                'https://example.com/?q=a%20b',
            ],
            'reordered params with over-encoded values converge' => [
                'https://example.com/?b=x%2520y&a=1',
                'https://example.com/?a=1&b=x%20y',
            ],
            'asset filename with over-encoded space collapses' => [
                'https://example.com/wp-content/themes/x/snow%2520flake.css?ver=6.7.1',
                'https://example.com/wp-content/themes/x/snow%20flake.css?ver=6.7.1',
            ],
            'fragment is stripped before percent collapse matters' => [
                'https://example.com/%2520#frag',
                'https://example.com/%20',
            ],
        ];
    }

    #[DataProvider('traversalPreservationProvider')]
    public function testPercentCollapseNeverWeakensJailTraversalProtection(string $raw, string $expected): void
    {
        self::assertSame($expected, (string) $this->canonicalizer->canonicalize($raw));
    }

    /**
     * Traversal safety: the canonicalizer keeps raw and encoded dot segments
     * as data — it never decodes `%2e%2e` into `..` and never introduces a raw
     * separator/backslash/NUL. JailedPath/ArtifactPath do the traversal
     * rejection, and this pins that their input form is unchanged.
     *
     * @return array<string, array{string, string}>
     */
    public static function traversalPreservationProvider(): array
    {
        return [
            'raw dot segments stay as data' => [
                'https://example.com/a/../b',
                'https://example.com/a/../b',
            ],
            'encoded dot-dot stays fully encoded' => [
                'https://example.com/a/%2e%2e/b',
                'https://example.com/a/%2e%2e/b',
            ],
            'over-encoded dot-dot collapses one layer only' => [
                'https://example.com/a/%252e%252e/b',
                'https://example.com/a/%2e%2e/b',
            ],
            'encoded backslash stays encoded' => [
                'https://example.com/a%5cb',
                'https://example.com/a%5cb',
            ],
        ];
    }
}
