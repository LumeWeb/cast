<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use InvalidArgumentException;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\ProbeResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The persisted serialization of the probe runtime state: strict and lossless,
 * preserving the exact origin scheme/host/port (the effective port stays
 * derivable from the scheme default when the explicit port was omitted) plus
 * the final URL and the probe metrics.
 */
final class ProbeResultTest extends TestCase
{
    public function testSerializesLosslesslyPreservingOriginPartsAndHostCase(): void
    {
        $probe = new ProbeResult(
            Origin::fromParts('https', 'Blog.Example.test', 8443),
            'https://Blog.Example.test:8443/',
            20480,
            42,
        );

        $restored = ProbeResult::fromArray($probe->toArray());

        self::assertSame('https', $restored->origin->scheme());
        self::assertSame('Blog.Example.test', $restored->origin->host());
        self::assertSame(8443, $restored->origin->port());
        self::assertSame('https://Blog.Example.test:8443/', $restored->finalUrl);
        self::assertSame(20480, $restored->bytes);
        self::assertSame(42, $restored->durationMs);
        // round-trip is stable: reserializing the restored value changes nothing.
        self::assertSame($probe->toArray(), $restored->toArray());
    }

    public function testRoundTripsOmittedDefaultPortAsNull(): void
    {
        $probe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            1024,
            1,
        );

        self::assertNull($probe->toArray()['origin_port']);

        $restored = ProbeResult::fromArray($probe->toArray());

        self::assertNull($restored->origin->port());
        // The effective port remains derivable from the scheme default.
        self::assertSame('https://blog.example.test', (string) $restored->origin);

        // The shared fixture state round-trips losslessly as well.
        self::assertSame($this->validState(), ProbeResult::fromArray($this->validState())->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function validState(): array
    {
        return [
            'origin_scheme' => 'https',
            'origin_host' => 'blog.example.test',
            'origin_port' => null,
            'final_url' => 'https://blog.example.test/',
            'bytes' => 1024,
            'duration_ms' => 12,
        ];
    }

    #[DataProvider('malformedProbeStateProvider')]
    public function testFromArrayRejectsMalformedState(mixed $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProbeResult::fromArray($data);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedProbeStateProvider(): iterable
    {
        yield 'not an array' => ['probe'];
        yield 'missing scheme' => [['origin_host' => 'h', 'final_url' => 'u', 'bytes' => 1, 'duration_ms' => 1]];
        yield 'missing host' => [['origin_scheme' => 'https', 'final_url' => 'u', 'bytes' => 1, 'duration_ms' => 1]];
        yield 'missing final url' => [['origin_scheme' => 'https', 'origin_host' => 'h', 'bytes' => 1, 'duration_ms' => 1]];
        yield 'non-string scheme' => [['origin_scheme' => 5, 'origin_host' => 'h', 'final_url' => 'u', 'bytes' => 1, 'duration_ms' => 1]];
        yield 'string port' => [['origin_scheme' => 'https', 'origin_host' => 'h', 'origin_port' => '443', 'final_url' => 'u', 'bytes' => 1, 'duration_ms' => 1]];
        yield 'negative bytes' => [['origin_scheme' => 'https', 'origin_host' => 'h', 'final_url' => 'u', 'bytes' => -1, 'duration_ms' => 1]];
        yield 'string bytes' => [['origin_scheme' => 'https', 'origin_host' => 'h', 'final_url' => 'u', 'bytes' => '1', 'duration_ms' => 1]];
    }
}
