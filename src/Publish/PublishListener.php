<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Receives stage/status progress from the publish orchestration. A recorder
 * fake asserts the sequence in tests; a later runner adapts it to the run row /
 * dashboard without the orchestration ever exposing credentials.
 */
interface PublishListener
{
    public function onProgress(PublishProgress $progress): void;
}
