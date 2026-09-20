<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * A seekable response/disk body backed by a stream (usually php://temp), the
 * temporary-file abstraction both transports and the disk asset source speak.
 * Bodies stay on disk/streams, never in a run-sized array; read access is
 * offered as a whole-string contents() or a stream handle for streaming
 * consumers.
 */
final class CaptureBody
{
    /**
     * @param resource $stream Seekable, positioned at offset 0.
     */
    private function __construct(private $stream)
    {
    }

    public static function fromString(string $data): self
    {
        $stream = fopen('php://temp', 'wb+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open a temporary stream for a capture body');
        }
        fwrite($stream, $data);
        rewind($stream);

        return new self($stream);
    }

    /**
     * @param resource $stream
     */
    public static function fromStream($stream): self
    {
        rewind($stream);

        return new self($stream);
    }

    public static function empty(): self
    {
        return self::fromString('');
    }

    /**
     * @return resource
     */
    public function stream()
    {
        return $this->stream;
    }

    public function contents(): string
    {
        rewind($this->stream);
        $contents = stream_get_contents($this->stream);

        return $contents === false ? '' : $contents;
    }

    public function size(): int
    {
        rewind($this->stream);
        $stats = fstat($this->stream);

        return $stats === false ? 0 : (int) $stats['size'];
    }
}
