<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Rewrite\WorkItemQueueCollector;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

final class RewriteContextTest extends TestCase
{
    public function testExposesDocumentOriginAndQueue(): void
    {
        $document = new Url('https', 'example.com', null, '/wp-content/themes/x/style.css', '');
        $origin = Origin::fromUrl($document);
        $queue = new WorkItemQueueCollector(new InMemoryWorkItemRepository());

        $context = new RewriteContext($document, $origin, $queue);

        self::assertSame($document, $context->document());
        self::assertSame($origin, $context->origin());
        self::assertSame($queue, $context->queue());
        self::assertSame('https', $context->document()->scheme());
        self::assertSame('/wp-content/themes/x/style.css', $context->document()->path());
    }
}
