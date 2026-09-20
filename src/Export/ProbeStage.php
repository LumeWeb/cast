<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Performs the bounded home-page probe before export work reaches the queue.
 * Prechecks are deliberately local so a known-invalid WordPress setup never
 * causes an HTTP request.
 */
final class ProbeStage implements PipelineStage
{
    public const PROBE_TIMEOUT = 10.0;
    public const PROBE_UA = 'Cast/1.0 (+https://github.com/lumeweb/cast; static site exporter)';

    /**
     * The permalink structure Cast enforces ('Day and name') and expects on
     * the origins it captures. The admin {@see \LumeWeb\Cast\Admin\PermalinkGuard}
     * applies this when permalinks are plain, so the probe's pretty-permalink
     * precheck is the last-line safety net. Keeping the canonical value here
     * (near the probe that consumes it) is the single source of truth for
     * "what structure a Cast-ready site uses".
     */
    public const CANONICAL_PERMALINK_STRUCTURE = '/%year%/%monthnum%/%day%/%postname%/';

    private readonly LocalHostPolicy $localHosts;
    private readonly UrlCanonicalizer $canonicalizer;

    public function __construct(
        private readonly CaptureHttp $http,
        private readonly ProbeEnvironment $environment,
        private readonly PipelineState $state,
    ) {
        $this->localHosts = new LocalHostPolicy();
        $this->canonicalizer = new UrlCanonicalizer();
    }

    public function key(): PipelineStageKey
    {
        return PipelineStageKey::Probe;
    }

    public function execute(string $cursor): StageResult
    {
        $precheckFailure = $this->precheckFailure();
        if ($precheckFailure !== null) {
            return StageResult::fail($precheckFailure);
        }

        try {
            $home = $this->canonicalizer->canonicalize($this->environment->homeUrl());
        } catch (InvalidUrl) {
            return StageResult::fail('Probe cannot use the configured home URL.');
        }

        $origin = Origin::fromUrl($home);
        $headers = [
            'Accept-Encoding' => 'identity',
            'User-Agent' => self::PROBE_UA,
        ];

        $args = [
            'timeout' => self::PROBE_TIMEOUT,
            'redirection' => 0,
            'blocking' => true,
            'decompress' => true,
            'stream' => false,
            'headers' => $headers,
            'sslverify' => !$this->isLocalProbeHost($home->host()) && !$this->http->isLocalEnvironment(),
        ];

        $started = microtime(true);
        try {
            $response = $this->http->get((string) $home, $args);
        } catch (\Throwable $exception) {
            return StageResult::fail($this->transportFailure($home->host(), $exception->getMessage()));
        }
        $durationMs = max(0, (int) round((microtime(true) - $started) * 1000));

        if ($response->isError()) {
            return StageResult::fail($this->transportFailure($home->host(), $response->errorMessage()));
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            return $this->handleRedirect($home, $origin, $response, $durationMs);
        }

        if ($response->status() !== 200) {
            if (in_array($response->status(), [401, 403], true)) {
                // A 401/403 loopback access failure is surfaced safely and
                // anonymously: probe and capture never carry credentials (the
                // deployment's Caddy bypasses Basic Auth on the plugin's own
                // loopback requests), so there is no credential advice to give.
                return StageResult::fail(sprintf(
                    'Probe cannot access the site over loopback (HTTP %d).',
                    $response->status(),
                ));
            }

            return StageResult::fail(sprintf('Probe received HTTP %d from the home page.', $response->status()));
        }

        $body = $response->body();
        $bytes = strlen($body);
        if ($bytes < 1024 || !$this->containsHtmlMarker($body)) {
            return StageResult::fail(sprintf('Probe did not return HTML of plausible size (%d bytes).', $bytes));
        }

        $this->state->probe = new ProbeResult($origin, (string) $home, $bytes, $durationMs);

        return StageResult::done();
    }

    private function isLocalProbeHost(string $host): bool
    {
        $host = strtolower($host);
        if (str_ends_with($host, '.test')) {
            return substr_count($host, '.') === 1;
        }

        return $this->localHosts->isLocal($host);
    }

    private function precheckFailure(): ?string
    {
        if ($this->environment->permalinkStructure() === '') {
            return sprintf(
                'Probe requires pretty permalinks; plain permalinks are not supported (Cast expects %s).',
                self::CANONICAL_PERMALINK_STRUCTURE,
            );
        }

        if (!$this->environment->xmlLoaded()) {
            return 'Probe requires the PHP XML extension.';
        }

        if (!$this->environment->domLoaded()) {
            return 'Probe requires the PHP DOM extension.';
        }

        if (!$this->environment->zipLoaded()) {
            return 'Probe requires the PHP zip extension.';
        }

        if (!$this->environment->uploadsWritable()) {
            return 'Probe requires a writable WordPress uploads directory.';
        }

        return null;
    }

    private function handleRedirect(
        Url $home,
        Origin $homeOrigin,
        WpRemoteResponse $response,
        int $durationMs,
    ): StageResult {
        $location = $response->headers()['location'] ?? null;
        if (is_array($location)) {
            $location = reset($location);
        }
        if (!is_string($location) || $location === '') {
            return StageResult::fail(sprintf('Probe received HTTP %d without a redirect location.', $response->status()));
        }

        try {
            $target = $this->canonicalizer->canonicalize($location);
        } catch (InvalidUrl) {
            return StageResult::fail('Probe redirect location is invalid.');
        }

        if (!$this->isAcceptedRedirect($home, $homeOrigin, $target)) {
            if ($target->host() !== $home->host()) {
                return StageResult::fail(sprintf('Probe redirect is off-site: %s.', $target->host()));
            }

            return StageResult::fail(sprintf('Probe redirect did not land on the canonical home page: %s.', (string) $target));
        }

        $this->state->probe = new ProbeResult(
            Origin::fromUrl($target),
            (string) $target,
            strlen($response->body()),
            $durationMs,
        );

        return StageResult::done();
    }

    private function isAcceptedRedirect(Url $home, Origin $homeOrigin, Url $target): bool
    {
        if ($homeOrigin->matches($target)) {
            return (string) $home === (string) $target;
        }

        return $home->scheme() === 'http'
            && $target->scheme() === 'https'
            && $home->host() === $target->host()
            && $home->path() === $target->path()
            && $home->query() === $target->query()
            && $home->effectivePort() === 80
            && $target->effectivePort() === 443;
    }

    private function containsHtmlMarker(string $body): bool
    {
        return preg_match('/<html\b/i', $body) === 1
            || preg_match('/<!doctype\s+html\b/i', $body) === 1;
    }

    private function transportFailure(string $host, string $message): string
    {
        $lower = strtolower($message);
        if (
            str_contains($lower, 'ssl')
            || str_contains($lower, 'tls')
            || str_contains($lower, 'certificate')
        ) {
            return sprintf('Probe TLS failure for %s: certificate verification failed.', $host);
        }

        if (
            str_contains($lower, 'dns')
            || str_contains($lower, 'resolve')
            || str_contains($lower, 'getaddrinfo')
            || str_contains($lower, 'failed to connect')
            || str_contains($lower, 'curl error 6')
            || str_contains($lower, 'curl error 7')
        ) {
            return sprintf('Probe cannot reach %s; check DNS and network access.', $host);
        }

        return sprintf('Probe cannot reach %s; check DNS and network access.', $host);
    }
}
