<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

/**
 * The server answered a multipart POST upload with a 307/308 redirect that
 * could not be carried out (a Location header was missing). Adapter policy
 * errors (hop budget exhausted, loop detected) are typed subclasses.
 */
class UploadRedirectException extends UploadException
{
}
