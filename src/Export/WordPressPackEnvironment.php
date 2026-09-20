<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * WordPress adapter for the pack {@see PackEnvironment} interface: resolves the
 * per-run artifact ZIP into the denied `cast-exports` sibling of the WordPress
 * uploads root (never inside the run's work directory) and reads the pre-pack
 * validation strictness from the `cast_artifact_validation` option.
 *
 * The validation-mode option is defensive by default: any unset or unparseable
 * value falls back to the non-blocking {@see ArtifactValidationMode::Warning}
 * so a corrupt setting can never silently escalate to the strict validation that
 * blocks packing. The artifact path genuinely needs the uploads root, so an
 * absent one fails loudly exactly like {@see WordPressSetupEnvironment}.
 */
final class WordPressPackEnvironment implements PackEnvironment
{
    /**
     * The option holding the pre-pack validation strictness; `strict` maps to
     * {@see ArtifactValidationMode::Strict}, anything else to Warning.
     */
    public const VALIDATION_MODE_OPTION = 'cast_artifact_validation';

    public function artifactPath(string $runId): string
    {
        $uploads = wp_upload_dir();
        if (!is_array($uploads) || !isset($uploads['basedir']) || !is_string($uploads['basedir'])) {
            throw new \RuntimeException('WordPress did not provide an uploads directory.');
        }

        return rtrim($uploads['basedir'], '/\\') . '/cast-exports/' . $runId . '.zip';
    }

    public function validationMode(): ArtifactValidationMode
    {
        $stored = get_option(self::VALIDATION_MODE_OPTION, null);

        return $stored === ArtifactValidationMode::Strict->value
            ? ArtifactValidationMode::Strict
            : ArtifactValidationMode::Warning;
    }
}
