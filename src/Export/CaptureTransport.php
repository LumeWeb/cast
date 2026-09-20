<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The transport boundary capture runs against. Fakes in unit tests exercise
 * the real CaptureService; a WordPress adapter (wp_remote_get) is added later
 * without changing any caller here.
 */
interface CaptureTransport
{
    public function fetch(CaptureRequest $request): CaptureResponse;
}
