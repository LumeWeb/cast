<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\DiscoverEnvironment;
use LumeWeb\Cast\Export\PostIdPage;

/**
 * Scripted {@see DiscoverEnvironment} fake so the pure discovery stage is
 * exercised without ever loading WordPress. Static seeders, sitemap candidates
 * and the keyed pages are plain mutable properties; the fake serves one
 * published page per call in order, and an empty page once exhausted.
 */
final class FakeDiscoverEnvironment implements DiscoverEnvironment
{
    /**
     * @param list<string> $staticSeeds
     * @param list<string> $sitemapCandidates
     * @param list<array{ids: list<int>, urls: list<string>}> $postPages
     */
    public function __construct(
        public array $staticSeeds = [],
        public array $sitemapCandidates = [],
        public array $postPages = [],
    ) {
    }

    private int $postPageIndex = 0;

    /**
     * @return list<string>
     */
    public function staticSeeds(): array
    {
        return $this->staticSeeds;
    }

    /**
     * @return list<string>
     */
    public function sitemapCandidates(): array
    {
        return $this->sitemapCandidates;
    }

    public function publishedPage(int $afterId, int $limit): PostIdPage
    {
        $page = $this->postPages[$this->postPageIndex] ?? ['ids' => [], 'urls' => []];
        ++$this->postPageIndex;

        return new PostIdPage($page['ids'], $page['urls']);
    }
}
