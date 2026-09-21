<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ExclusionOptions;
use LumeWeb\Cast\Export\ExclusionPolicy;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;

final class ExclusionPolicyTest extends TestCase
{
    private UrlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->canonicalizer = new UrlCanonicalizer();
    }

    public function testWpAdminPrefixIsExcluded(): void
    {
        $policy = new ExclusionPolicy();

        self::assertTrue($policy->isExcluded($this->url('https://example.com/wp-admin/')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/wp-admin/post.php')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/wp-admin')));
    }

    public function testWpAdminLookalikeIsNotMistakenForAdmin(): void
    {
        $policy = new ExclusionPolicy();

        self::assertFalse($policy->isExcluded($this->url('https://example.com/wp-adminson/awp-admin/')));
        self::assertFalse($policy->isExcluded($this->url('https://example.com/about/wp-admin-notes/')));
    }

    public function testLoginAndXmlRpcAreExcludedByExactBasename(): void
    {
        $policy = new ExclusionPolicy();

        self::assertTrue($policy->isExcluded($this->url('https://example.com/wp-login.php')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/wp-login.php?action=logout')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/xmlrpc.php')));

        self::assertFalse($policy->isExcluded($this->url('https://example.com/wp-login-helper.css')));
    }

    public function testRestIsExcludedByDefaultAndConfigurable(): void
    {
        $default = new ExclusionPolicy();
        self::assertTrue($default->isExcluded($this->url('https://example.com/wp-json/wp/v2/posts')));
        self::assertTrue($default->isExcluded($this->url('https://example.com/?rest_route=/wp/v2/posts')));

        $enabled = new ExclusionPolicy(new ExclusionOptions(excludeRests: false));
        self::assertFalse($enabled->isExcluded($this->url('https://example.com/wp-json/wp/v2/posts')));
        self::assertFalse($enabled->isExcluded($this->url('https://example.com/?rest_route=/wp/v2/posts')));
    }

    public function testFeedsAreExcludedWithTypedSegmentRules(): void
    {
        $policy = new ExclusionPolicy();

        self::assertTrue($policy->isExcluded($this->url('https://example.com/feed/')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/feed')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/comments/feed/')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/feed/atom/')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/feed/rss2/')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/?feed=rss2')));

        // Typed matching: "feed" inside a larger word must not be rejected.
        self::assertFalse($policy->isExcluded($this->url('https://example.com/feedback/')));
        self::assertFalse($policy->isExcluded($this->url('https://example.com/category/faithful-feedbacks/')));
        self::assertFalse($policy->isExcluded($this->url('https://example.com/feeding-the-cats/')));
    }

    public function testFeedsAreConfigurable(): void
    {
        $enabled = new ExclusionPolicy(new ExclusionOptions(excludeFeeds: false));

        self::assertFalse($enabled->isExcluded($this->url('https://example.com/feed/')));
        self::assertFalse($enabled->isExcluded($this->url('https://example.com/?feed=rss2')));
    }

    public function testStatefulWordPressUrlsAreExcluded(): void
    {
        $policy = new ExclusionPolicy();

        self::assertTrue($policy->isExcluded($this->url('https://example.com/?p=42&preview=true')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/?customize_changeset_uuid=abc')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/?wp_customize=on')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/?_wpnonce=abc123')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/?nonce=abc123')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/?add-to-cart=42')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/?wc-ajax=get_refreshed_fragments')));
    }

    public function testOrdinaryPagesAreNotExcluded(): void
    {
        $policy = new ExclusionPolicy();

        self::assertFalse($policy->isExcluded($this->url('https://example.com/')));
        self::assertFalse($policy->isExcluded($this->url('https://example.com/about/')));
        self::assertFalse($policy->isExcluded($this->url('https://example.com/products/?page=2')));
    }

    public function testWildcardPseudoUrlsAreExcluded(): void
    {
        // Defense-in-depth at the discovery boundary: a glob pattern
        // (`/wp-*.php`, `/wp-admin/*`) never names one real resource and is
        // excluded even if a future entry point skips the normalizer's
        // InvalidUrl check. The
        // percent-encoded `%2A` is a real escape and stays included.
        $policy = new ExclusionPolicy();

        self::assertTrue($policy->isExcluded($this->url('https://example.com/wp-*.php')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/wp-admin/*')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/wp-content/uploads/*.png')));
        self::assertTrue($policy->isExcluded($this->url('https://example.com/?s=*')));
        self::assertFalse($policy->isExcluded($this->url('https://example.com/wp-content/%2A-about/')));
    }

    private function url(string $raw): Url
    {
        return $this->canonicalizer->canonicalize($raw);
    }
}
