<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * A domain bind/list/DNS/verify/delete/platform/SSL failure. The website and
 * the upload CID survive it, so the orchestration can retry the domain step
 * without re-creating what already exists.
 */
final class DomainClientException extends \RuntimeException
{
}
