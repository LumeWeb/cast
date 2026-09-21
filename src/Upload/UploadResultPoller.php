<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

use LumeWeb\Cast\Publish\PollPolicy;
use LumeWeb\Cast\Publish\PublishClock;
use LumeWeb\Cast\Publish\UploadIdentifier;
use LumeWeb\Cast\Publish\UploadResult;

/**
 * The bounded result-polling service over the real UploadResultClient: it
 * polls until a terminal status arrives, reusing the Publish polling
 * vocabulary (UploadStatus terminal/success semantics) and the injected
 * PollPolicy + PublishClock for bounded backoff — completed and duplicate are
 * terminal success, failed is a terminal error (the caller reads the message
 * detail), and pending/processing are re-polled. When the poll budget is
 * exhausted the last non-terminal result is returned as-is for the caller to
 * interpret as a processing timeout, mirroring UploadWaiter. The clock is the
 * only wall-clock primitive touched here, so tests inject a fake that records
 * sleeps instead of blocking.
 */
final class UploadResultPoller
{
    public function __construct(
        private readonly UploadResultClient $results,
        private readonly PublishClock $clock,
        private readonly PollPolicy $policy = new PollPolicy(),
    ) {
    }

    public function wait(UploadIdentifier $identifier): UploadResult
    {
        $polls = 0;
        do {
            $result = $this->results->poll($identifier);
            ++$polls;
            if ($result->isTerminal() || $polls >= $this->policy->maxPolls()) {
                return $result;
            }
            $this->clock->sleep($this->policy->delayFor($polls));
        } while (true);
    }
}
