<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The discovery stage: several finite producer cursors feed one
 * deduplicated {@see WorkItemRepository}.
 *
 * Producers run in a fixed order and each performs exactly one bounded unit
 * per tick, resuming from an opaque cursor token:
 *
 *   seed:{index}                                static seeders, one literal
 *                                               per tick;
 *   posts:{PostIdCursor token}                  published-content keyset, one
 *                                               page per tick;
 *   sitemap:{base64 frontier}                   origin-only sitemap crawl, one
 *                                               document per tick; the cursor
 *                                               carries the remaining frontier
 *                                               of `[url, depth]` pairs so a
 *                                               fresh stage can resume.
 *
 * Every candidate URL passes the same single check before insertion — typed
 * origin check via {@see UrlCandidateNormalizer} and the typed exclusion
 * policy — and insertion funnels through
 * {@see WorkItemRepository::insertCanonical()}, so first-seen wins and lower
 * numeric priority may replace. The stage is done only after every producer
 * cursor has drained; the optional hard item cap stops seeding early with a
 * warning instead of growing without limit.
 */
final class DiscoverStage implements PipelineStage
{
    public const SITEMAP_TIMEOUT = 10.0;
    public const SITEMAP_UA = 'Cast/1.0 (+https://github.com/lumeweb/cast; static site exporter)';

    /**
     * Hard cap on distinct queued items before discovery stops seeding early.
     */
    public const DEFAULT_MAX_ITEMS = 200_000;

    private const PHASE_SEED = 'seed';
    private const PHASE_POSTS = 'posts';
    private const PHASE_SITEMAP = 'sitemap';
    private const SITEMAP_INDEX = 'sitemapindex';
    private const URLSET = 'urlset';

    private readonly UrlCandidateNormalizer $normalizer;
    private readonly ExclusionPolicy $exclusionPolicy;
    private readonly SitemapLocFilter $locFilter;
    private readonly LocalHostPolicy $localHosts;

    public function __construct(
        private readonly DiscoverEnvironment $environment,
        private readonly PipelineState $state,
        private readonly WorkItemRepository $repository,
        private readonly CaptureHttp $http,
        private readonly SitemapLimits $sitemapLimits = new SitemapLimits(),
        private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
        if ($maxItems < 1) {
            throw new \InvalidArgumentException('DiscoverStage maxItems must be >= 1');
        }

        $this->normalizer = new UrlCandidateNormalizer();
        $this->exclusionPolicy = new ExclusionPolicy();
        $this->locFilter = new SitemapLocFilter($this->sitemapLimits);
        $this->localHosts = new LocalHostPolicy();
    }

    public function key(): PipelineStageKey
    {
        return PipelineStageKey::Discover;
    }

    public function execute(string $cursor): StageResult
    {
        $origin = $this->state->probe?->origin;
        if ($origin === null) {
            return StageResult::fail('Discover requires a successful probe first.');
        }

        if ($cursor === '' || str_starts_with($cursor, self::PHASE_SEED . ':')) {
            return $this->runSeedUnit($this->seedIndex($cursor), $origin);
        }

        if (str_starts_with($cursor, self::PHASE_POSTS . ':')) {
            return $this->runPostUnit($cursor, $origin);
        }

        if (str_starts_with($cursor, self::PHASE_SITEMAP . ':')) {
            return $this->runResumedSitemapUnit($cursor, $origin);
        }

        return StageResult::fail('Discover received an unparseable cursor.');
    }

    private function runSeedUnit(int $index, Origin $origin): StageResult
    {
        $seeds = $this->environment->staticSeeds();

        if ($index >= count($seeds)) {
            return $this->runPostUnit(self::PHASE_POSTS . ':' . (new PostIdCursor())->toToken(), $origin);
        }

        if ($this->capReached()) {
            return $this->capDone(0);
        }

        $created = $this->enqueueCandidate($seeds[$index], $origin);

        return $this->capReached()
            ? $this->capDone($created)
            : StageResult::more(self::PHASE_SEED . ':' . ($index + 1), $created);
    }

    private function runPostUnit(string $cursor, Origin $origin): StageResult
    {
        $token = substr($cursor, strlen(self::PHASE_POSTS) + 1);
        $postCursor = PostIdCursor::fromToken($token);
        $page = $this->environment->publishedPage($postCursor->lastId(), $postCursor->limit());

        $created = 0;
        foreach ($page->urls as $url) {
            $created += $this->enqueueCandidate($url, $origin);
        }

        if ($this->capReached()) {
            return $this->capDone($created);
        }

        $nextId = $page->ids === [] ? $postCursor->lastId() : max($page->ids);
        if ($this->hasMorePosts($page, $postCursor, $nextId)) {
            return StageResult::more(
                self::PHASE_POSTS . ':' . (new PostIdCursor($nextId, $postCursor->limit()))->toToken(),
                $created,
            );
        }

        return $this->firstSitemapUnit($created, $origin);
    }

    /**
     * The keyset only advances when the page was full (a short page is
     * definitively drained) and produced an id strictly after the cursor.
     */
    private function hasMorePosts(PostIdPage $page, PostIdCursor $cursor, int $nextId): bool
    {
        return count($page->urls) >= $cursor->limit() && $page->ids !== [] && $nextId > $cursor->lastId();
    }

    private function firstSitemapUnit(int $created, Origin $origin): StageResult
    {
        $queue = [];
        foreach ($this->environment->sitemapCandidates() as $candidate) {
            $queue[] = [$candidate, 0];
        }

        if ($queue === []) {
            $this->finish($created);

            return StageResult::done('', $created);
        }

        return StageResult::more(self::PHASE_SITEMAP . ':' . $this->queueToToken($queue), $created);
    }

    private function runResumedSitemapUnit(string $cursor, Origin $origin): StageResult
    {
        $queue = $this->tokenToQueue(substr($cursor, strlen(self::PHASE_SITEMAP) + 1));
        if ($queue === []) {
            return StageResult::fail('Discover received an unparseable sitemap cursor.');
        }

        [$docUrl, $depth] = array_shift($queue);

        $created = 0;
        $failure = $this->consumeSitemapDocument($docUrl, (int) $depth, $origin, $created, $queue);
        if ($failure !== null) {
            return StageResult::fail($failure);
        }

        if ($this->capReached()) {
            return $this->capDone($created);
        }

        if ($queue === []) {
            $this->finish($created);

            return StageResult::done('', $created);
        }

        return StageResult::more(self::PHASE_SITEMAP . ':' . $this->queueToToken($queue), $created);
    }

    /**
     * Fetches and consumes one sitemap document: enqueue the document itself
     * (the roadmap's parse-then-discard fix), then enqueue a local `<loc>` and
     * any nested sitemap documents accepted by the depth/body/URL caps onto the
     * resumable frontier. Returns an actionable failure sentence, or null when
     * the document was consumed.
     *
     * @param list<array{0: string, 1: int}> $frontier The remaining frontier;
     *                                                 nested documents are appended.
     */
    private function consumeSitemapDocument(
        string $docUrl,
        int $depth,
        Origin $origin,
        int &$created,
        array &$frontier,
    ): ?string {
        try {
            $response = $this->http->get($docUrl, $this->sitemapArgs($origin));
        } catch (\Throwable $exception) {
            return sprintf('Discover could not reach the sitemap at %s: %s.', $docUrl, $exception->getMessage());
        }

        if ($response->isError()) {
            return sprintf('Discover could not reach the sitemap at %s: %s.', $docUrl, $response->errorMessage());
        }

        if ($response->status() !== 200) {
            return sprintf('Discover could not fetch the sitemap at %s: HTTP %d.', $docUrl, $response->status());
        }

        $body = $response->body();
        if (strlen($body) > $this->sitemapLimits->maxBodyBytes) {
            return sprintf('Discover refused the sitemap body at %s: it exceeds the configured body limit.', $docUrl);
        }

        if ($this->declaresDoctypeOrEntity($body)) {
            return sprintf('Discover refused the sitemap body at %s: it declares a DOCTYPE or entity.', $docUrl);
        }

        $document = $this->parseSitemap($body);
        if ($document === null) {
            return sprintf('Discover refused the sitemap body at %s: malformed XML.', $docUrl);
        }

        $root = (string) $document->getName();
        if ($root !== self::SITEMAP_INDEX && $root !== self::URLSET) {
            return sprintf('Discover refused the sitemap body at %s: the XML root is unsupported.', $docUrl);
        }

        $created += $this->enqueueCandidate($docUrl, $origin);
        $this->enqueueSitemapChildren($document, $depth, $origin, $created, $frontier);

        return null;
    }

    /**
     * Enqueues every local `<loc>` of the document and pushes nested sitemap
     * documents onto the frontier, both bounded by the shared caps.
     *
     * @param list<array{0: string, 1: int}> $frontier
     */
    private function enqueueSitemapChildren(
        \SimpleXMLElement $document,
        int $depth,
        Origin $origin,
        int &$created,
        array &$frontier,
    ): void {
        $isIndex = (string) $document->getName() === self::SITEMAP_INDEX;
        $childElement = $isIndex ? 'sitemap' : 'url';
        $childDepth = $isIndex ? $depth + 1 : $depth;

        $documents = [];
        foreach ($this->childLocations($document, $childElement) as $loc) {
            $documents[] = new SitemapDocument($loc, $childDepth);
        }

        foreach ($this->locFilter->filterDocuments($documents, $origin) as $accepted) {
            $created += $this->enqueueCandidate($accepted->loc, $origin);
            if ($isIndex) {
                $frontier[] = [$accepted->loc, $childDepth];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function childLocations(\SimpleXMLElement $document, string $childElement): array
    {
        $locations = [];
        foreach ($document->children() as $child) {
            if ($child->getName() !== $childElement) {
                continue;
            }
            $loc = trim((string) $child->loc);
            if ($loc !== '') {
                $locations[] = $loc;
            }
        }

        return $locations;
    }

    private function parseSitemap(string $body): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document === false ? null : $document;
    }

    private function declaresDoctypeOrEntity(string $body): bool
    {
        return stripos($body, '<!DOCTYPE') !== false
            || stripos($body, '<!ENTITY') !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function sitemapArgs(Origin $origin): array
    {
        return [
            'timeout' => self::SITEMAP_TIMEOUT,
            'redirection' => 0,
            'blocking' => true,
            'decompress' => true,
            'stream' => false,
            'headers' => [
                'Accept-Encoding' => 'identity',
                'User-Agent' => self::SITEMAP_UA,
            ],
            'sslverify' => !$this->isLocalSitemapHost($origin->host()) && !$this->http->isLocalEnvironment(),
        ];
    }

    private function isLocalSitemapHost(string $host): bool
    {
        $host = strtolower($host);
        if (str_ends_with($host, '.test')) {
            return substr_count($host, '.') === 1;
        }

        return $this->localHosts->isLocal($host);
    }

    /**
     * @param list<array{0: string, 1: int}> $queue
     */
    private function queueToToken(array $queue): string
    {
        return base64_encode((string) json_encode($queue));
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function tokenToQueue(string $payload): array
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return [];
        }

        try {
            $queue = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($queue)) {
            return [];
        }

        $normalized = [];
        foreach ($queue as $entry) {
            if (!is_array($entry) || !array_key_exists(0, $entry) || !array_key_exists(1, $entry)) {
                return [];
            }
            $normalized[] = [(string) $entry[0], (int) $entry[1]];
        }

        return $normalized;
    }

    /**
     * The one place a raw candidate becomes a queued work item: canonicalize
     * (throws InvalidUrl/OffOriginUrl for malformed or off-origin values), run
     * the typed exclusion policy, then insert through the canonical repository.
     * Returns 1 when a brand-new row was created, else 0 (duplicate or
     * excluded), keeping the running progress delta bounded to actual work.
     */
    private function enqueueCandidate(string $raw, Origin $origin): int
    {
        try {
            $url = $this->normalizer->normalizeUrl($raw, $origin);
        } catch (InvalidUrl | OffOriginUrl) {
            return 0;
        }

        if ($this->exclusionPolicy->isExcluded($url)) {
            return 0;
        }

        $outcome = $this->repository->insertCanonical($this->normalizer->workItem($url));

        return $outcome->inserted() ? 1 : 0;
    }

    /**
     * Reads live repository state, so repeated calls within one tick may
     * return different results as candidates are enqueued between them.
     *
     * @phpstan-impure
     */
    private function capReached(): bool
    {
        return $this->repository->countByStatus(WorkItemStatus::Queued) >= $this->maxItems;
    }

    private function capDone(int $progress): StageResult
    {
        $this->finish($progress);

        return StageResult::done('', $progress, [
            sprintf('Discovery stopped at the configured item cap of %d.', $this->maxItems),
        ]);
    }

    private function finish(int $progress): void
    {
        $this->state->discover = new DiscoverResult(
            $this->repository->countByStatus(WorkItemStatus::Queued),
        );
    }

    private function seedIndex(string $cursor): int
    {
        if ($cursor === '') {
            return 0;
        }

        $index = (int) substr($cursor, strlen(self::PHASE_SEED) + 1);

        return max(0, $index);
    }
}
