<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ProbeEnvironment;

/**
 * Scripted {@see ProbeEnvironment} fake so the pure probe stage is exercised
 * without ever loading WordPress. Every WordPress fact is a mutable property a
 * test can flip between scenarios.
 */
final class FakeProbeEnvironment implements ProbeEnvironment
{
    public function __construct(
        public string $homeUrl = 'https://blog.example.test/',
        public string $permalinkStructure = '/%postname%/',
        public bool $xml = true,
        public bool $dom = true,
        public bool $zip = true,
        public bool $uploadsWritable = true,
    ) {
    }

    public function homeUrl(): string
    {
        return $this->homeUrl;
    }

    public function permalinkStructure(): string
    {
        return $this->permalinkStructure;
    }

    public function xmlLoaded(): bool
    {
        return $this->xml;
    }

    public function domLoaded(): bool
    {
        return $this->dom;
    }

    public function zipLoaded(): bool
    {
        return $this->zip;
    }

    public function uploadsWritable(): bool
    {
        return $this->uploadsWritable;
    }
}
