<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
use LumeWeb\Cast\Persistence\OptionGateway;

/**
 * WordPress {@see PublishRegistry} adapter.
 *
 * Persists the site's portal identity (website + IPNS key) into the same
 * non-autoloaded `cast_publish_identity` option the {@see WordPressIdentityGateway}
 * reads, following the PublishIdentity schema/value conventions. It is written
 * incrementally — website id + name first, IPNS key name + id second — so a
 * crash or failure between the two never loses what already exists, and the
 * stored identity only reports ready (and only becomes visible through
 * hasIdentity()/current()) once both halves are complete. Partial records still
 * surface through current() once the website half exists (what the site already
 * owns), so a resumed publish never re-creates a website or key that already
 * exists; the stored identity itself stays not-ready until the IPNS half is
 * complete too.
 */
final class WordPressPublishRegistry implements PublishRegistry
{
    public const OPTION_KEY = WordPressIdentityGateway::OPTION_KEY;

    public function __construct(private readonly OptionGateway $options)
    {
    }

    public function current(): ?SitePublishState
    {
        $value = $this->read();
        if ($value === null) {
            return null;
        }

        // The incremental write order is website first, IPNS key second, so the
        // website half is the meaningful partial state: an IPNS key without a
        // website is not yet something the site owns.
        $websiteId = $this->websiteId($value);
        if ($websiteId === null) {
            return null;
        }

        return new SitePublishState($websiteId, $this->ipnsKeyName($value), $this->ipnsKeyId($value));
    }

    public function recordWebsite(string $websiteId, string $websiteName): void
    {
        $value = $this->read() ?? $this->blank();
        $value['website'] = ['id' => $websiteId, 'name' => $websiteName];

        $this->persist($value);
    }

    public function recordIpnsKey(string $ipnsKeyName, ?string $ipnsKeyId): void
    {
        $value = $this->read() ?? $this->blank();
        $value['ipns_key'] = ['id' => $ipnsKeyId, 'name' => $ipnsKeyName];

        $this->persist($value);
    }

    /**
     * @return array{
     *     schema_version: int,
     *     ready: false
     * }
     */
    private function blank(): array
    {
        return [
            'schema_version' => PublishIdentity::SCHEMA_VERSION,
            'ready' => false,
        ];
    }

    /**
     * The stored option value when it carries the identity schema, or null when
     * the option is missing, unknown-schema, or corrupt.
     *
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        $value = $this->options->get(self::OPTION_KEY, null);
        // The identity option is always an associative record (never a list);
        // reject a list-shaped value so the narrowed type is the string-keyed
        // schema record phpstan can reason about.
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        if (($value['schema_version'] ?? null) !== PublishIdentity::SCHEMA_VERSION) {
            return null;
        }

        return $value;
    }

    /**
     * Persist the merged value, adding it atomically when the option does not
     * exist yet and updating it otherwise, always with the ready flag derived
     * from the two identity halves.
     *
     * @param array<string, mixed> $value
     */
    private function persist(array $value): void
    {
        $value['ready'] = $this->isReady($value);

        if (!$this->options->add(self::OPTION_KEY, $value, false)) {
            $this->options->update(self::OPTION_KEY, $value, false);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function isReady(array $value): bool
    {
        return $this->isCompleteRecord($value['website'] ?? null)
            && $this->isCompleteRecord($value['ipns_key'] ?? null);
    }

    /**
     * @param mixed $record
     */
    private function isCompleteRecord(mixed $record): bool
    {
        return is_array($record)
            && is_string($record['id'] ?? null) && $record['id'] !== ''
            && is_string($record['name'] ?? null) && $record['name'] !== '';
    }

    /**
     * @param array<string, mixed> $value
     */
    private function websiteId(array $value): ?string
    {
        $website = $value['website'] ?? null;
        if (!is_array($website) || !is_string($website['id'] ?? null) || $website['id'] === '') {
            return null;
        }

        return $website['id'];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function ipnsKeyName(array $value): ?string
    {
        $ipns = $value['ipns_key'] ?? null;
        if (!is_array($ipns) || !is_string($ipns['name'] ?? null) || $ipns['name'] === '') {
            return null;
        }

        return $ipns['name'];
    }

    /**
     * The numeric portal key id recorded with the key, or null when the IPNS
     * half is missing or its id was never recorded. The value is preserved as
     * the string the option schema stores; the adapter casts it back when it
     * needs the id the publish endpoint accepts.
     *
     * @param array<string, mixed> $value
     */
    private function ipnsKeyId(array $value): ?string
    {
        $ipns = $value['ipns_key'] ?? null;
        if (!is_array($ipns) || !is_string($ipns['id'] ?? null) || $ipns['id'] === '') {
            return null;
        }

        return $ipns['id'];
    }
}
