<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Pre-pack artifact validation: composes the fixed-point completion
 * check, the root index.html ghost requirement, output-path integrity (no
 * traversal, no duplicates), leftover-origin scanning and broken local
 * reference detection into a single deterministic ValidationReport. Pure and
 * stateless: the tree and work-item state are supplied through interfaces, the
 * tree is read-only, and findings carry the samples the manifest needs.
 */
final class ArtifactValidator
{
    /**
     * @param list<string> $intentionalPaths staged paths that may legitimately
     *                                       stay unresolved (redirects,
     *                                       intentionally skipped/failed)
     */
    public function __construct(
        private readonly ArtifactTree $tree,
        private readonly WorkItemStateProvider $state,
        private readonly Origin $origin,
        private readonly ArtifactValidationMode $mode = ArtifactValidationMode::Warning,
        private readonly array $intentionalPaths = [],
    ) {
    }

    public function validate(): ValidationReport
    {
        $findings = [];
        $paths = $this->tree->paths();

        // 1. Fixed point: the crawler must be terminal before anything packs.
        if ($this->state->hasPending()) {
            $findings[] = new ValidationFinding(
                'pending_items',
                ValidationSeverity::Hard,
                'Crawler has not reached its fixed point: work items are still queued or processing',
            );
        }

        // 2. Root index.html must exist and be real HTML (never a ghost page).
        $rootFindings = $this->rootIndexFindings();
        array_push($findings, ...$rootFindings);

        // 3. Output paths must be clean relative paths inside the jail.
        foreach ($paths as $path) {
            $violation = ArtifactPath::violation($path);
            if ($violation !== null) {
                $findings[] = new ValidationFinding(
                    'unsafe_output_path',
                    ValidationSeverity::Hard,
                    sprintf('Output path is unsafe (%s): "%s"', $violation, $path),
                    $path,
                );
            }
        }

        // 4. No two work items may have written the same output path.
        foreach (ArtifactPath::duplicates($paths) as $duplicate) {
            $findings[] = new ValidationFinding(
                'duplicate_output_path',
                ValidationSeverity::Hard,
                sprintf('Duplicate output path written by more than one item: "%s"', $duplicate),
                $duplicate,
            );
        }

        // 5. Leftover origin URLs are warnings, escalated to hard in strict mode.
        array_push($findings, ...$this->leftoverOriginFindings());

        // 6. Local references that do not resolve to a staged file.
        array_push(
            $findings,
            ...(new LocalReferenceValidator($this->tree, $this->origin, null, $this->intentionalPaths))->brokenReferences(),
        );

        return ValidationReport::summarize($findings, $this->counts($paths));
    }

    /**
     * @return list<ValidationFinding>
     */
    private function rootIndexFindings(): array
    {
        if (!$this->tree->exists('index.html')) {
            return [new ValidationFinding(
                'missing_root_index',
                ValidationSeverity::Hard,
                'Work tree has no root index.html',
                'index.html',
            )];
        }

        $content = $this->tree->readText('index.html');
        if ($content === null || !HtmlLike::isNonGhost($content)) {
            return [new ValidationFinding(
                'ghost_root_index',
                ValidationSeverity::Hard,
                'Root index.html is missing, empty or does not look like a real HTML document',
                'index.html',
            )];
        }

        return [];
    }

    /**
     * @return list<ValidationFinding>
     */
    private function leftoverOriginFindings(): array
    {
        $findings = [];
        $scanner = new OriginLeftoverScanner($this->origin);

        foreach ($this->tree->paths() as $path) {
            if (!$this->tree->isTextLike($path)) {
                continue;
            }
            $content = $this->tree->readText($path);
            if ($content === null) {
                continue;
            }
            if (!$scanner->hasLeftover($content)) {
                continue;
            }

            $finding = new ValidationFinding(
                'leftover_origin',
                ValidationSeverity::Warning,
                sprintf('Origin URL is still present in staged output (not rewritten): "%s"', $path),
                $path,
            );
            $findings[] = $this->mode === ArtifactValidationMode::Strict ? $finding->escalated() : $finding;
        }

        return $findings;
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, int>
     */
    private function counts(array $paths): array
    {
        return [
            'files' => count($paths),
            'queued' => $this->state->countByStatus(WorkItemStatus::Queued),
            'processing' => $this->state->countByStatus(WorkItemStatus::Processing),
            'done' => $this->state->countByStatus(WorkItemStatus::Done),
            'failed' => $this->state->countByStatus(WorkItemStatus::Failed),
            'skipped' => $this->state->countByStatus(WorkItemStatus::Skipped),
        ];
    }
}
