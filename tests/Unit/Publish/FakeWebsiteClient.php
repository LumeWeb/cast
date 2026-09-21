<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\CreateWebsiteRequest;
use LumeWeb\Cast\Publish\Website;
use LumeWeb\Cast\Publish\WebsiteClient;
use LumeWeb\Cast\Publish\WebsiteClientException;

/**
 * Records every create()/update() call and can be scripted to throw, so tests
 * assert exactly when (and whether) a website is created vs target-updated.
 * get() consumes a website status script in order (falling back to a repeatable
 * loop site once exhausted) so readiness polling can be scripted the same way
 * upload result polling is. The fallback serves the last target hash the fake
 * created or updated as a live site, so a publish completes readiness once the
 * deploy it just made is what get() reports serving.
 */
final class FakeWebsiteClient implements WebsiteClient
{
    /**
     * @var list<CreateWebsiteRequest> Requests create() was called with, in order.
     */
    public array $created = [];

    /**
     * @var list<array{0: string, 1: string, 2: string}> [websiteId, targetHash, targetType]
     */
    public array $updated = [];

    /**
     * @var list<string> Website ids get() was called with, in order.
     */
    public array $fetched = [];

    /**
     * @var list<Website> Sites get() returns, consumed in order.
     */
    public array $getScript = [];

    public ?Website $loopResponse = null;

    public ?string $createError = null;

    public ?string $updateError = null;

    public ?string $getError = null;

    private int $getIndex = 0;

    /**
     * The CID the fake's get() loop reports as being actively served; each
     * create()/update() advances it to the target hash just deployed so a
     * readiness poll confirms the deploy.
     */
    public string $servedCid = 'QmServed';

    public function create(CreateWebsiteRequest $request): Website
    {
        if ($this->createError !== null) {
            throw new WebsiteClientException($this->createError);
        }

        $this->created[] = $request;
        $this->servedCid = $request->targetHash;

        return new Website('website-1', $request->label ?? '', $request->targetHash, $request->targetType, $request->domain);
    }

    public function update(string $websiteId, string $targetHash, string $targetType): Website
    {
        if ($this->updateError !== null) {
            throw new WebsiteClientException($this->updateError);
        }

        $this->updated[] = [$websiteId, $targetHash, $targetType];
        $this->servedCid = $targetHash;

        return new Website($websiteId, '', $targetHash, $targetType);
    }

    public function get(string $websiteId): Website
    {
        if ($this->getError !== null) {
            throw new WebsiteClientException($this->getError);
        }

        $this->fetched[] = $websiteId;

        if (isset($this->getScript[$this->getIndex])) {
            return $this->getScript[$this->getIndex++];
        }

        if ($this->loopResponse !== null) {
            return $this->loopResponse;
        }

        $servedCid = $this->servedCid;

        return new Website($websiteId, '', $servedCid, 'car', null, Website::STATUS_LIVE, $servedCid);
    }
}
