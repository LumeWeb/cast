<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Local-reference validation against the staged work tree: scans text-like
 * staged files (HTML URL attributes plus CSS url() spans) and reports any
 * reference that resolves to a staged file that does not exist — unless it is
 * on the known redirect / intentionally skipped-or-failed list. External and
 * non-web references are ignored. Findings are warnings; the validation decides
 * whether they block.
 */
final class LocalReferenceValidator
{
    private const HTML_ATTRIBUTES = 'href|src|srcset|imagesrcset|poster|action|formaction|data[\\w-]*';

    private readonly StagedReferenceResolver $resolver;

    /**
     * @param list<string> $intentionalPaths staged paths that may apply to
     *                                       redirects / intentionally skipped
     *                                       or failed records and are not
     *                                       broken when otherwise unresolved
     */
    public function __construct(
        private readonly ArtifactTree $tree,
        private readonly Origin $origin,
        ?StagedReferenceResolver $resolver = null,
        private readonly array $intentionalPaths = [],
    ) {
        $this->resolver = $resolver ?? new StagedReferenceResolver($tree, $origin);
    }

    /**
     * @return list<ValidationFinding>
     */
    public function brokenReferences(): array
    {
        $findings = [];

        foreach ($this->tree->paths() as $document) {
            if (!$this->tree->isTextLike($document)) {
                continue;
            }
            $content = $this->tree->readText($document);
            if ($content === null) {
                continue;
            }

            foreach ($this->extractReferences($content) as $reference) {
                $resolved = $this->resolver->resolve($reference, $document);
                if ($resolved === null) {
                    continue;
                }
                if ($this->tree->exists($resolved) || in_array($resolved, $this->intentionalPaths, true)) {
                    continue;
                }

                $findings[] = new ValidationFinding(
                    'broken_local_reference',
                    ValidationSeverity::Warning,
                    sprintf('Local reference "%s" in %s does not resolve to a staged file', $reference, $document),
                    $document,
                    $reference,
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function extractReferences(string $content): array
    {
        $references = [];

        if (
            preg_match_all(
                '~\b(?:' . self::HTML_ATTRIBUTES . ')\s*=\s*(["\'])(.*?)\1~is',
                $content,
                $matches,
                PREG_SET_ORDER,
            )
        ) {
            foreach ($matches as $match) {
                foreach ($this->splitReferences($match[2], strtolower($match[0])) as $reference) {
                    $references[] = $reference;
                }
            }
        }

        if (preg_match_all('~url\(\s*([\'"]?)(.*?)\1\s*\)~is', $content, $cssMatches, PREG_SET_ORDER)) {
            foreach ($cssMatches as $match) {
                $references[] = $match[2];
            }
        }

        return $references;
    }

    /**
     * @return list<string>
     */
    private function splitReferences(string $value, string $attribute): array
    {
        if (str_contains($attribute, 'srcset')) {
            $parts = preg_split('/\s*,\s*/', $value) ?: [];

            return array_map(
                static function (string $part): string {
                    $head = preg_split('/\s+/', trim($part), 2) ?: [''];

                    return $head[0];
                },
                $parts,
            );
        }

        return [$value];
    }
}
