<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

/**
 * The artifact file could not be uploaded (missing, unreadable or empty). The
 * message names the path and the failure class only — never any credential.
 */
final class UploadFileException extends UploadException
{
    public function __construct(
        private readonly string $path,
        private readonly UploadFileProblem $problem,
    ) {
        parent::__construct(sprintf('Cannot upload artifact at %s: %s', $this->path, $this->problem->value));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function problem(): UploadFileProblem
    {
        return $this->problem;
    }
}
