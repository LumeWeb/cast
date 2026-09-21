<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * REST request adapter over {@see PublishSetupService}.
 *
 * The route callbacks delegate to the typed service and return the DTOs'
 * JSON-safe arrays, which WordPress' REST server serializes to JSON. No long
 * work ever runs in a request: status() is a pure read, start()/now() only
 * queue/schedule, and setMode() is a single option write — none wait on
 * exports or create a website.
 */
final class PublishRestHandler
{
    public function __construct(private readonly PublishSetupService $service)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->service->status()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function start(): array
    {
        return $this->service->startFirstPublish()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function now(): array
    {
        return $this->service->startPublishNow()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function setMode(string $mode): array
    {
        return $this->service->setMode($mode)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(): array
    {
        return $this->service->cancelRun()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function publishExisting(): array
    {
        return $this->service->publishExisting()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function createWebsite(string $hostname): array
    {
        return $this->service->createWebsite($hostname)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function linkWebsite(int $websiteId): array
    {
        return $this->service->linkWebsite($websiteId)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function availableWebsites(): array
    {
        return $this->service->availableWebsites()->toArray();
    }
}
