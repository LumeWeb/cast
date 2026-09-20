<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Creates the jailed, web-denied directory used by one export run.
 */
final class SetupStage implements PipelineStage
{
    private const WORK_ROOT = 'cast-work';
    private const INDEX_CONTENT = "<?php\n// Silence is golden.\n";
    private const HTACCESS_CONTENT = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";

    public function __construct(
        private readonly SetupEnvironment $environment,
        private readonly PipelineState $state,
        private readonly string $runId,
    ) {
    }

    public function key(): PipelineStageKey
    {
        return PipelineStageKey::Setup;
    }

    public function execute(string $cursor): StageResult
    {
        if (!$this->validRunId()) {
            return StageResult::fail('Setup received an unsafe run identifier.');
        }

        try {
            $uploads = realpath($this->environment->uploadsDirectory());
            if ($uploads === false || !is_dir($uploads) || !is_writable($uploads)) {
                return StageResult::fail('Setup requires a writable WordPress uploads directory.');
            }

            $workRoot = $uploads . DIRECTORY_SEPARATOR . self::WORK_ROOT;
            if (!$this->ensureDirectory($workRoot)) {
                return StageResult::fail('Setup could not create its uploads work directory.');
            }

            $workRootReal = realpath($workRoot);
            if ($workRootReal === false || !$this->isInside($workRootReal, $uploads)) {
                return StageResult::fail('Setup work directory escaped the uploads jail.');
            }

            $workDir = $workRoot . DIRECTORY_SEPARATOR . $this->runId;
            if (!$this->ensureDirectory($workDir)) {
                return StageResult::fail('Setup could not create the run work directory.');
            }

            $workDirReal = realpath($workDir);
            if ($workDirReal === false || !$this->isInside($workDirReal, $uploads)) {
                return StageResult::fail('Setup work directory escaped the uploads jail.');
            }

            $this->writeFile($workDirReal . DIRECTORY_SEPARATOR . 'index.php', self::INDEX_CONTENT);
            $this->writeFile($workDirReal . DIRECTORY_SEPARATOR . '.htaccess', self::HTACCESS_CONTENT);
            $this->state->setup = new SetupResult($workDirReal);

            return StageResult::done($this->runId);
        } catch (\Throwable) {
            return StageResult::fail('Setup could not prepare a safe export work directory.');
        }
    }

    private function validRunId(): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/D', $this->runId) === 1;
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_link($path)) {
            return false;
        }

        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, 0777, true) || is_dir($path);
    }

    private function writeFile(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.cast-setup-');
        if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new \RuntimeException('Unable to write setup protection file.');
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to install setup protection file.');
        }
    }

    private function isInside(string $path, string $root): bool
    {
        return $path !== $root && str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
