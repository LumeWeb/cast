<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

/**
 * The server kept answering with 307/308 redirects until the configured hop
 * budget ran out (no loop was detected, the endpoint simply never settled).
 */
final class TooManyRedirectsException extends UploadRedirectException
{
    public function __construct(int $maxHops)
    {
        parent::__construct(sprintf('Upload exceeded %d redirect hops.', $maxHops));
    }
}
