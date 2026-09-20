<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

use InvalidArgumentException;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\RunRepository;

/**
 * WordPress option-backed RunRepository.
 *
 * The single global, non-autoloaded option {@see self::OPTION_KEY} holds the
 * serialized export run — one run per install, mirroring the onboarding
 * wizard's single-option persistence style. All WordPress option access goes
 * through the injected {@see OptionGateway}, so this adapter never touches
 * loose global functions and stays unit-testable through a fake gateway.
 *
 * The aggregate's explicit toArray()/fromArray() typed serialization is the
 * only sanitization: option keys are a constant and values round-trip through
 * the schema version without lossy field handling. Credentials, capabilities
 * and nonces are never persistence concerns and never stored.
 */
final class WordPressRunRepository implements RunRepository
{
    public const OPTION_KEY = 'cast_export_run';

    public function __construct(private readonly OptionGateway $options)
    {
    }

    public function find(string $runId): ?ExportRun
    {
        $run = $this->read();
        if ($run === null || $run->runId !== $runId) {
            return null;
        }

        return $run;
    }

    public function create(ExportRun $run): bool
    {
        // add_option is atomic: it refuses to store when the option already
        // exists, so a racing tick cannot double-start a run.
        return $this->options->add(self::OPTION_KEY, $run->toArray(), false);
    }

    public function save(ExportRun $run): void
    {
        $this->options->update(self::OPTION_KEY, $run->toArray(), false);
    }

    public function delete(string $runId): void
    {
        $run = $this->read();
        if ($run === null || $run->runId !== $runId) {
            return;
        }

        $this->options->delete(self::OPTION_KEY);
    }

    public function latest(): ?ExportRun
    {
        return $this->read();
    }

    /**
     * @return list<ExportRun>
     */
    public function list(): array
    {
        $run = $this->read();

        return $run === null ? [] : [$run];
    }

    /**
     * Deserialize the stored option: a missing option means "no run", while
     * any value the aggregate refuses to read surfaces as a typed persistence
     * error instead of silently wedging the caller.
     */
    private function read(): ?ExportRun
    {
        $value = $this->options->get(self::OPTION_KEY, null);
        if ($value === null) {
            return null;
        }

        try {
            return ExportRun::fromArray($value);
        } catch (InvalidArgumentException $e) {
            throw new RunPersistenceException('The stored export run could not be read back.', 0, $e);
        }
    }
}
