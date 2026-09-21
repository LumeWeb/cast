<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use Closure;
use LumeWeb\Cast\Export\PipelineStage;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\StageResult;

/**
 * Recording {@see PipelineStage} double: remembers every execute call (stage
 * key + cursor), and produces a configured {@see StageResult}: either a fixed
 * sequence, whatever a callback yields, or `more($cursor)` forever so a unit
 * never finishes by accident.
 */
final class FakePipelineStage implements PipelineStage
{
    /**
     * @var list<array{key: PipelineStageKey, cursor: string}>
     */
    public array $calls = [];

    /**
     * @var list<StageResult>|null
     */
    public ?array $sequence = null;

    /**
     * @var Closure(string):StageResult|null
     */
    public ?Closure $respond = null;

    private int $sequenceIndex = 0;

    public function __construct(private readonly PipelineStageKey $stageKey)
    {
    }

    public function key(): PipelineStageKey
    {
        return $this->stageKey;
    }

    public function execute(string $cursor): StageResult
    {
        $this->calls[] = ['key' => $this->stageKey, 'cursor' => $cursor];

        if ($this->respond !== null) {
            return ($this->respond)($cursor);
        }

        if ($this->sequence !== null) {
            $result = $this->sequence[$this->sequenceIndex] ?? StageResult::more($cursor);
            ++$this->sequenceIndex;

            return $result;
        }

        return StageResult::more($cursor);
    }
}
