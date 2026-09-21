<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

/**
 * A 307/308 redirect led back to an endpoint already visited in this upload,
 * so following it could not make progress; the request body was not re-sent
 * into the cycle.
 */
final class RedirectLoopException extends UploadRedirectException
{
    public function __construct()
    {
        parent::__construct('Upload redirect loop detected; aborting instead of revisiting an endpoint.');
    }
}
