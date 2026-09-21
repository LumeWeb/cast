<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * One progress event: the current stage and a plain-language message. This
 * payload is deliberately credential-free — it carries no tokens, keys or URLs
 * with secrets, only stage + a fixed human message.
 */
final class PublishProgress
{
    public function __construct(
        public readonly PublishStage $stage,
        public readonly string $message,
    ) {
    }
}
