<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The IPNS surface the orchestration needs: create one key per site on first
 * publish, then publish/republish the newest CID to that same key on every
 * publish. Throwing IpnsClientException signals a failure that must not lose
 * the already-created website or the upload CID.
 */
interface IpnsClient
{
    public function createKey(string $name): IpnsKey;

    public function publish(string $keyName, string $cid): IpnsPublication;
}
