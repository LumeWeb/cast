<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Ipfs\Website;
use LumeWeb\Cast\Ipfs\WebsiteRegistry;
use LumeWeb\Cast\Publish\CreateWebsiteRequest;

/**
 * In-memory WebsiteRegistry fake so the awaiting-website derivation and the
 * guided create action are testable without a transport. Tracks how many reads
 * actually hit the "registry" so a test can prove an ordinary status poll never
 * consults the list, and records the last create request so a test can pin the
 * exact payload the guided action sent.
 */
final class FakeWebsiteList implements WebsiteRegistry
{
    public int $calls = 0;

    /** @var list<Website> */
    public array $websites = [];

    public ?CreateWebsiteRequest $lastCreate = null;

    public ?\LumeWeb\Cast\Http\HttpException $createException = null;

    /**
     * @param list<Website> $websites
     */
    public function __construct(array $websites = [])
    {
        $this->websites = $websites;
    }

    /**
     * @return list<Website>
     */
    public function list(): array
    {
        ++$this->calls;

        return $this->websites;
    }

    public function create(CreateWebsiteRequest $request): Website
    {
        $this->lastCreate = $request;

        if ($this->createException !== null) {
            throw $this->createException;
        }

        $website = Website::fromArray([
            'id' => 99,
            'status' => 'pending',
            'domain' => '',
            'target_hash' => $request->targetHash,
            'target_type' => $request->targetType,
        ]);

        // A created website exists in the account, so later reads see it —
        // exactly like the real portal once Websites.Create returns. This is
        // what lets the attach-conflict tolerance recognize a website minted
        // by an earlier attempt of this same create/attach flow.
        $this->websites[] = $website;

        return $website;
    }
}
