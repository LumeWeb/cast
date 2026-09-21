<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureRequest;
use LumeWeb\Cast\Export\CaptureResponse;
use LumeWeb\Cast\Export\CaptureTransport;
use LumeWeb\Cast\Export\CaptureTransportException;

/**
 * Scripted transport whose responses are consumed in order. The ERROR marker
 * makes fetch() throw CaptureTransportException, simulating a network failure.
 * CaptureService runs unmodified against it, exercising real service behavior.
 */
final class FakeCaptureTransport implements CaptureTransport
{
    public const ERROR = 'transport-error';

    /**
     * @param list<CaptureResponse|self::ERROR> $script
     */
    public function __construct(
        private readonly array $script,
    ) {
    }

    /**
     * @var list<string> URLs in the order fetch() was called.
     */
    public array $requested = [];

    private int $index = 0;

    public function fetch(CaptureRequest $request): CaptureResponse
    {
        $this->requested[] = $request->url;

        if ($this->index >= count($this->script)) {
            throw new CaptureTransportException('No more scripted responses');
        }

        $next = $this->script[$this->index++];
        if ($next === self::ERROR) {
            throw new CaptureTransportException('Simulated transport failure');
        }

        return $next;
    }
}
