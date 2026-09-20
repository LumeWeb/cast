<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * The response of the ipfs-sdk CheckPlatformDomainAvailability call: the
 * queried label (when one was supplied) plus one availability answer per
 * enabled platform (free-subdomain) root.
 */
final class PlatformAvailability
{
    /**
     * @param list<PlatformAvailabilityResult> $results
     */
    private function __construct(
        private readonly ?string $label,
        private readonly array $results,
    ) {
    }

    /**
     * @throws ResponseDecodingException when the payload is not a JSON object or a result is malformed.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Platform availability response is not a JSON object.');
        }

        $results = [];
        if (array_key_exists('results', $data) && $data['results'] !== null) {
            if (!is_array($data['results'])) {
                throw new ResponseDecodingException('Platform availability response field "results" is not an array.');
            }
            foreach ($data['results'] as $result) {
                $results[] = PlatformAvailabilityResult::fromArray($result);
            }
        }

        $label = null;
        if (array_key_exists('label', $data) && $data['label'] !== null) {
            if (!is_string($data['label'])) {
                throw new ResponseDecodingException('Platform availability response field "label" is not a string.');
            }
            $label = $data['label'];
        }

        return new self($label, $results);
    }

    public function label(): ?string
    {
        return $this->label;
    }

    /**
     * @return list<PlatformAvailabilityResult>
     */
    public function results(): array
    {
        return $this->results;
    }
}
