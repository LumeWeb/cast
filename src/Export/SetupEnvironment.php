<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Supplies the WordPress uploads root used by one export run.
 */
interface SetupEnvironment
{
    /**
     * @return string Absolute uploads directory.
     */
    public function uploadsDirectory(): string;
}
