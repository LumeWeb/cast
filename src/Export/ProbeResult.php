<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use InvalidArgumentException;

/**
 * The metrics the loopback probe records on success: the canonical origin
 * capture must target, the final URL the home page answered from, the size of
 * the public home body in bytes, and how long the probe took in milliseconds.
 *
 * Deliberately carries no credentials: the Basic Auth pair lives only in the
 * injected deployment config and never in pipeline state or results.
 *
 * The value also knows its persisted shape ({@see toArray()} / {@see
 * fromArray()}) so ExportRun can carry the probe across WP-Cron requests and
 * rehydrate the shared PipelineState without re-running the probe. The origin
 * is serialized as its three parts so host case and the explicit-vs-default
 * port distinction survive losslessly.
 */
final class ProbeResult
{
    public function __construct(
        public readonly Origin $origin,
        public readonly string $finalUrl,
        public readonly int $bytes,
        public readonly int $durationMs,
    ) {
    }

    /**
     * @return array{
     *     origin_scheme: string,
     *     origin_host: string,
     *     origin_port: int|null,
     *     final_url: string,
     *     bytes: int,
     *     duration_ms: int
     * }
     */
    public function toArray(): array
    {
        return [
            'origin_scheme' => $this->origin->scheme(),
            'origin_host' => $this->origin->host(),
            'origin_port' => $this->origin->port(),
            'final_url' => $this->finalUrl,
            'bytes' => $this->bytes,
            'duration_ms' => $this->durationMs,
        ];
    }

    /**
     * Strict, lossless read-back of a persisted probe. Any malformed part is
     * rejected loudly so a corrupt run row is never silently reinterpreted.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Probe state is not an array.');
        }

        $scheme = $data['origin_scheme'] ?? null;
        $host = $data['origin_host'] ?? null;
        $port = $data['origin_port'] ?? null;
        $finalUrl = $data['final_url'] ?? null;
        $bytes = $data['bytes'] ?? null;
        $durationMs = $data['duration_ms'] ?? null;

        if (
            !is_string($scheme) || $scheme === ''
            || !is_string($host) || $host === ''
            || ($port !== null && !is_int($port))
            || !is_string($finalUrl) || $finalUrl === ''
            || !is_int($bytes) || $bytes < 0
            || !is_int($durationMs) || $durationMs < 0
        ) {
            throw new InvalidArgumentException('Probe state is malformed.');
        }

        return new self(
            Origin::fromParts($scheme, $host, $port),
            $finalUrl,
            $bytes,
            $durationMs,
        );
    }
}
