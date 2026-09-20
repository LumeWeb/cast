<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Turns a raw or already-canonical URL into the single value object every
 * discovery route inserts and every later stage consumes. This is the one
 * place identity, kind, hash, and output path are derived together, so all
 * producers share identical rules.
 */
final class WorkItemFactory
{
    public function __construct(
        private readonly UrlCanonicalizer $canonicalizer = new UrlCanonicalizer(),
        private readonly UrlClassifier $classifier = new UrlClassifier(),
        private readonly OutputPathResolver $pathResolver = new OutputPathResolver(),
        private readonly AssetExtensions $assetExtensions = new AssetExtensions(),
    ) {
    }

    public function fromString(string $url): WorkItem
    {
        return $this->fromUrl($this->canonicalizer->canonicalize($url));
    }

    public function fromUrl(Url $url): WorkItem
    {
        $kind = $this->classifier->classify($url, $this->assetExtensions);
        $identity = $this->identityFor($url, $kind);

        return new WorkItem(
            $url,
            $kind,
            $identity,
            md5($identity),
            $this->pathResolver->resolve($url, $kind),
        );
    }

    private function identityFor(Url $url, WorkItemKind $kind): string
    {
        $base = $url->base();

        if (($kind === WorkItemKind::Asset || $kind === WorkItemKind::Text) || !$url->hasQuery()) {
            return $base;
        }

        return $base . '?' . $url->query();
    }
}
