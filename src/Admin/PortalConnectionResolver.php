<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\PortalIdentity;
use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Portal\PortalFacade;
use LumeWeb\Cast\Portal\SelfIdentification;

/**
 * Production {@see ConnectionResolver} over {@see PortalFacade}: resolves the
 * dashboard Connection card's self-identification ONLY when {@see current()}
 * is asked — never during plugin boot/activation, so a plain boot performs no
 * portal network call.
 *
 * Never throws: {@see PortalFacade} maps HttpException failures to a safe
 * SelfIdentification error state, and any unexpected (non-HttpException)
 * transport blow-up is absorbed into the same safe unreachable state. The
 * result is cached per instance, so a single request resolves the connection
 * at most once even when status is read repeatedly.
 */
final class PortalConnectionResolver implements ConnectionResolver
{
    private ?SelfIdentification $cached = null;

    private bool $resolved = false;

    public function __construct(
        private readonly EnvIdentity $identity,
        private readonly HttpTransport $transport,
    ) {
    }

    public function current(): ?SelfIdentification
    {
        if ($this->resolved) {
            return $this->cached;
        }

        try {
            $self = (new PortalFacade($this->identity, $this->transport))->selfIdentify();
        } catch (\Throwable) {
            // Incomplete env already short-circuits before the transport; a
            // non-HttpException here can only be an unexpected transport
            // blow-up. Map it to the same value-free unreachable state so a
            // status read can never throw or leak a credential.
            $identity = $this->identity->resolve()
                ?? new PortalIdentity('', '');
            $self = SelfIdentification::unreachable($identity);
        }

        $this->cached = $self;
        $this->resolved = true;

        return $self;
    }
}
