<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * One keyset page of published content from {@see DiscoverEnvironment}: the
 * post ids that were fetched (used to advance the PostIdCursor) and the
 * matching permalinks the discovery stage enqueues. ids and urls are parallel
 * lists produced together by the same WordPress query.
 */
final class PostIdPage
{
    /**
     * @param list<int> $ids
     * @param list<string> $urls
     */
    public function __construct(
        public readonly array $ids,
        public readonly array $urls,
    ) {
    }
}
