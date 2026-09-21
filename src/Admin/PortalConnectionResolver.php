<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\PortalIdentity;
use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Persistence\TransientGateway;
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
 *
 * The resolved identity is additionally memoized across requests through the
 * optional {@see TransientGateway} (short TTL), because surfaces like the
 * always-rendering admin bar read status on every page load: without the
 * cross-request memo each page view would pay the full three-call exchange.
 * Only a fully resolved (healthy) identification is written to the transient
 * — error states are recomputed on demand instead of being pinned for the
 * whole TTL — so a transient failure surfaces once the next request refetches.
 * The memo carries the deployment identity's value-free {@see EnvIdentity
 * signature}: a memo written under a rotated credential or re-pointed portal
 * base is a cache miss, never silently served to a request with a changed
 * identity.
 */
final class PortalConnectionResolver implements ConnectionResolver
{
    /**
     * Public so the uninstaller can remove the memo row; a surviving
     * transient would be plugin-owned residue in the options database.
     */
    public const CACHE_KEY = 'cast_self_identification';

    private const CACHE_TTL_SECONDS = 300;

    private ?SelfIdentification $cached = null;

    private bool $resolved = false;

    public function __construct(
        private readonly EnvIdentity $identity,
        private readonly HttpTransport $transport,
        private readonly ?TransientGateway $cache = null,
    ) {
    }

    public function current(): ?SelfIdentification
    {
        if ($this->resolved) {
            return $this->cached;
        }

        $signature = $this->identity->signature();
        if ($this->cache !== null && $signature !== null) {
            $hit = $this->cache->get(self::CACHE_KEY, null);
            if (
                is_array($hit)
                && ($hit['signature'] ?? null) === $signature
                && $hit['self'] instanceof SelfIdentification
            ) {
                $this->cached = $hit['self'];
                $this->resolved = true;

                return $this->cached;
            }
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

        if ($this->cache !== null && $self->isResolved() && $signature !== null) {
            $this->cache->set(
                self::CACHE_KEY,
                ['signature' => $signature, 'self' => $self],
                self::CACHE_TTL_SECONDS,
            );
        }

        $this->cached = $self;
        $this->resolved = true;

        return $this->cached;
    }
}
