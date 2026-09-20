<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Portal;

use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Ipfs\Workspace;

/**
 * The safe runtime-identity value object mapped from the portal
 * Workspaces.Resolve (/api/workspaces/resolve) response: the resolved
 * workspace plus the optional attached Website publish relationship.
 *
 * It holds identifiers and lifecycle statuses only — never credentials — and
 * derives a safe publish state ({@see publishState()}) from the attached
 * website. A server-side `error` field (a non-empty string) maps to an
 * explicit not-resolved state instead of crashing the decoder, so the caller
 * can render a value-free failure.
 */
final class WorkspaceResolve
{
    public const PUBLISH_STATE_NONE = 'none';
    public const PUBLISH_STATE_PENDING = 'pending';
    public const PUBLISH_STATE_PUBLISHED = 'published';

    private function __construct(
        private readonly ?Workspace $workspace,
        private readonly ?WorkspaceResolveWebsite $website,
        private readonly ?string $error,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Workspace resolve response is not a JSON object.');
        }

        $error = self::optionalStringField($data, 'error');
        if ($error !== null && trim($error) !== '') {
            // A server-side resolution rejection (e.g. resource UUID mismatch):
            // the workspace could not be resolved. Represent it explicitly so
            // callers never guess from missing fields.
            return new self(null, null, $error);
        }

        $workspace = Workspace::fromArray($data);

        $website = null;
        if (isset($data['website']) && is_array($data['website'])) {
            $website = WorkspaceResolveWebsite::fromArray($data['website']);
        }

        return new self($workspace, $website, null);
    }

    public function isResolved(): bool
    {
        return $this->workspace !== null;
    }

    public function workspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function website(): ?WorkspaceResolveWebsite
    {
        return $this->website;
    }

    /**
     * The raw server-side rejection message, when the portal could not resolve
     * the workspace. Held internally for diagnosis only — operator-facing
     * surfaces must map it to a value-free message and never echo it raw.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * A safe, derived publish state from the attached Website publish
     * relationship: 'published' when the website is live, 'pending' when it
     * exists but is not yet serving, or 'none' when no website is attached.
     */
    public function publishState(): string
    {
        if ($this->website === null) {
            return self::PUBLISH_STATE_NONE;
        }

        return $this->website->isLive()
            ? self::PUBLISH_STATE_PUBLISHED
            : self::PUBLISH_STATE_PENDING;
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalStringField(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (!is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Workspace resolve response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }
}
