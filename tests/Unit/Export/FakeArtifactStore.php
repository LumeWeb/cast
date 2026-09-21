<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactFile;
use LumeWeb\Cast\Export\ArtifactStore;

/**
 * Scripted in-memory ArtifactStore for the pure service tests: holds a
 * name-index of artifacts, reports a (possibly null) jail root and applies
 * idempotent deletion while recording every delete call + refusal for
 * assertions. Deterministic; no real filesystem involved.
 */
final class FakeArtifactStore implements ArtifactStore
{
    /** @var array<string, ArtifactFile> */
    private array $files = [];

    public ?string $jail = '/jail/cast-exports';

    /** @var array<string, true> */
    private array $deleteRefusals = [];

    /** @var list<string> */
    public array $deletedPaths = [];

    public int $deleteCalls = 0;

    public function add(string $name, int $modifiedAt): void
    {
        $this->files[$name] = new ArtifactFile(
            $name,
            '/jail/cast-exports/' . $name,
            $modifiedAt,
        );
    }

    public function refuseDelete(string $name): void
    {
        $this->deleteRefusals[$name] = true;
    }

    public function jailRoot(): ?string
    {
        return $this->jail;
    }

    public function listZipArtifacts(): array
    {
        $artifacts = array_values($this->files);
        usort($artifacts, static fn (ArtifactFile $a, ArtifactFile $b): int => strcmp($a->name, $b->name));

        return $artifacts;
    }

    public function delete(string $path): bool
    {
        ++$this->deleteCalls;
        $this->deletedPaths[] = $path;

        $name = basename($path);
        if (isset($this->deleteRefusals[$name]) || !isset($this->files[$name])) {
            return false;
        }

        unset($this->files[$name]);

        return true;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->files);
    }
}
