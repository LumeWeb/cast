<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\PublishListener;
use LumeWeb\Cast\Publish\PublishProgress;

/**
 * Captures progress events so tests can assert the stage sequence and that no
 * credential ever leaks into a progress message.
 */
final class RecordingPublishListener implements PublishListener
{
    /**
     * @var list<PublishProgress> Events in emission order.
     */
    public array $progress = [];

    public function onProgress(PublishProgress $progress): void
    {
        $this->progress[] = $progress;
    }
}
