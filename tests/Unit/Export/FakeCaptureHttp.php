<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureHttp;
use LumeWeb\Cast\Export\WpRemoteResponse;

/**
 * Scripted {@see CaptureHttp} fake. Records every {url, args} pair so the
 * WordPress transport's exact request arguments (timeout, redirection,
 * blocking, decompress, headers, sslverify, stream) can be asserted; script
 * entries may be a static response or a callable that sees the arguments and
 * may write the streamed temporary file before returning.
 */
final class FakeCaptureHttp implements CaptureHttp
{
    /**
     * @param list<WpRemoteResponse|callable(string, array<string, mixed>): WpRemoteResponse> $script
     */
    public function __construct(
        private readonly array $script = [],
    ) {
    }

    /**
     * @var list<array{url: string, args: array<string, mixed>}>
     */
    public array $calls = [];

    public bool $localEnvironment = false;

    private int $index = 0;

    /**
     * @param array<string, mixed> $args
     */
    public function get(string $url, array $args): WpRemoteResponse
    {
        $this->calls[] = ['url' => $url, 'args' => $args];

        if ($this->index >= count($this->script)) {
            return WpRemoteResponse::success(200, []);
        }

        $next = $this->script[$this->index++];
        if (is_callable($next)) {
            return $next($url, $args);
        }

        return $next;
    }

    public function isLocalEnvironment(): bool
    {
        return $this->localEnvironment;
    }
}
