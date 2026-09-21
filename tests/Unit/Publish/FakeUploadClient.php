<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\UploadClient;
use LumeWeb\Cast\Publish\UploadClientException;
use LumeWeb\Cast\Publish\UploadIdentifier;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadSpec;
use LumeWeb\Cast\Publish\UploadStatus;

/**
 * Scripted upload client. upload() records every spec and returns a fresh
 * identifier (or throws when an upload error is scripted); poll() consumes the
 * script in order and falls back to a repeatable response once exhausted so a
 * time-out scenario can serve the same pending status forever.
 */
final class FakeUploadClient implements UploadClient
{
    /**
     * @var list<UploadSpec> Every spec upload() was called with, in order.
     */
    public array $specs = [];

    /**
     * @var list<UploadIdentifier> Every identifier poll() was called with, in order.
     */
    public array $polled = [];

    /**
     * @param list<UploadResult> $pollScript   Responses consumed in order.
     * @param string|null        $uploadError  When set, upload() throws.
     * @param UploadResult|null  $loopResponse Repeated response once the script is exhausted.
     */
    public function __construct(
        array $pollScript = [],
        ?string $uploadError = null,
        ?UploadResult $loopResponse = null,
    ) {
        $this->pollScript = $pollScript;
        $this->uploadError = $uploadError;
        $this->loopResponse = $loopResponse;
    }

    /**
     * @var list<UploadResult> Responses consumed in order.
     */
    public array $pollScript = [];

    public ?string $uploadError = null;

    public ?UploadResult $loopResponse = null;

    private int $index = 0;

    public function upload(UploadSpec $spec): UploadIdentifier
    {
        if ($this->uploadError !== null) {
            throw new UploadClientException($this->uploadError);
        }

        $this->specs[] = $spec;

        return new UploadIdentifier('id-' . count($this->specs));
    }

    public function poll(UploadIdentifier $identifier): UploadResult
    {
        $this->polled[] = $identifier;

        if (isset($this->pollScript[$this->index])) {
            return $this->pollScript[$this->index++];
        }

        return $this->loopResponse ?? new UploadResult(UploadStatus::Completed, cid: 'QmDone');
    }
}
