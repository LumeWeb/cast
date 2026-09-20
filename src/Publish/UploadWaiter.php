<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The upload + result-polling gateway: upload() delegates to the transport
 * client, wait() then polls until a terminal status arrives (with bounded
 * backoff between polls) so a slow server never blocks one request for its
 * full deadline. The terminal interpretation (completed/duplicate = success,
 * failed = failure, pending/processing = keep polling) lives on UploadStatus.
 */
final class UploadWaiter
{
    public function __construct(
        private readonly UploadClient $uploads,
        private readonly PublishClock $clock,
        private readonly PollPolicy $policy = new PollPolicy(),
    ) {
    }

    public function upload(UploadSpec $spec): UploadIdentifier
    {
        return $this->uploads->upload($spec);
    }

    /**
     * Poll until the result status is terminal, sleeping between polls with
     * backoff. When the poll budget is exhausted the last non-terminal result
     * is returned as-is; the caller interprets `pending`/`processing` as a
     * processing timeout.
     */
    public function wait(UploadIdentifier $identifier): UploadResult
    {
        $polls = 0;
        do {
            $result = $this->uploads->poll($identifier);
            ++$polls;
            if ($result->isTerminal() || $polls >= $this->policy->maxPolls()) {
                return $result;
            }
            $this->clock->sleep($this->policy->delayFor($polls));
        } while (true);
    }
}
