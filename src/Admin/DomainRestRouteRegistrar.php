<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use ComposePress\Core\HookSubscriber;
use ComposePress\Core\Hooks;

/**
 * REST route registrar for the admin domain-setup surface.
 *
 * Registers the stable routes under the `cast/v1` namespace:
 *
 *   GET  /cast/v1/domains/list         — domains bound to the website
 *   POST /cast/v1/domains/bind         — bind an ICANN/HNS domain
 *   GET  /cast/v1/domains/dns          — delegation DNS requirements
 *   POST /cast/v1/domains/verify       — (re)verify a binding
 *   POST /cast/v1/domains/validate     — validate the website's DNS (once)
 *   POST /cast/v1/domains/delete       — unbind a domain
 *   GET  /cast/v1/domains/platform     — catalog of platform domains
 *   GET  /cast/v1/domains/availability — availability for a chosen label
 *   GET  /cast/v1/domains/ssl          — SSL status for a domain
 *
 * Every route is gated by {@see RestAuth}: the manage_options capability and a
 * valid wp_rest REST nonce. The callbacks delegate to {@see DomainRestHandler}
 * (a thin no-wait adapter over {@see DomainSetupService}), so no route does
 * long request-time work and the DTO payloads are plain JSON-safe arrays that
 * never carry credentials. Only the core `rest_api_init` action is registered
 * — no front-end content hooks.
 */
final class DomainRestRouteRegistrar implements HookSubscriber
{
    public const NAMESPACE = 'cast/v1';

    public const LIST_ROUTE = '/domains/list';

    public const BIND_ROUTE = '/domains/bind';

    public const DNS_ROUTE = '/domains/dns';

    public const VERIFY_ROUTE = '/domains/verify';

    public const VALIDATE_ROUTE = '/domains/validate';

    public const DELETE_ROUTE = '/domains/delete';

    public const PLATFORM_ROUTE = '/domains/platform';

    public const AVAILABILITY_ROUTE = '/domains/availability';

    public const SSL_ROUTE = '/domains/ssl';

    public const REST_NONCE_ACTION = 'wp_rest';

    private const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly DomainRestHandler $handler,
        private readonly RestAuth $auth,
    ) {
    }

    public function subscribe(Hooks $hooks): void
    {
        $hooks->action('rest_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::LIST_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this->handler, 'list'],
            'permission_callback' => [$this, 'canViewDomains'],
        ]);

        register_rest_route(self::NAMESPACE, self::BIND_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'bindDomain'],
            'permission_callback' => [$this, 'canBindDomain'],
        ]);

        register_rest_route(self::NAMESPACE, self::DNS_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'dnsRequirements'],
            'permission_callback' => [$this, 'canReadDns'],
        ]);

        register_rest_route(self::NAMESPACE, self::VERIFY_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'verifyDomain'],
            'permission_callback' => [$this, 'canVerifyDomain'],
        ]);

        register_rest_route(self::NAMESPACE, self::VALIDATE_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'validateDomain'],
            'permission_callback' => [$this, 'canValidateDomain'],
        ]);

        register_rest_route(self::NAMESPACE, self::DELETE_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'deleteDomain'],
            'permission_callback' => [$this, 'canDeleteDomain'],
        ]);

        register_rest_route(self::NAMESPACE, self::PLATFORM_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this->handler, 'listPlatformDomains'],
            'permission_callback' => [$this, 'canReadPlatform'],
        ]);

        register_rest_route(self::NAMESPACE, self::AVAILABILITY_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'checkAvailability'],
            'permission_callback' => [$this, 'canCheckAvailability'],
        ]);

        register_rest_route(self::NAMESPACE, self::SSL_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'sslStatus'],
            'permission_callback' => [$this, 'canReadSsl'],
        ]);
    }

    /**
     * Binds a domain. WordPress passes a \WP_REST_Request; the param is read
     * defensively so the callback is unit-testable with a minimal double and
     * an absent/invalid param degrades to the service's validation refusal.
     *
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function bindDomain(mixed $request): array
    {
        return $this->handler->bind(
            $this->stringParam($request, 'domain'),
            $this->stringParam($request, 'namespace'),
        );
    }

    /**
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function dnsRequirements(mixed $request): array
    {
        return $this->handler->dnsRequirements($this->stringParam($request, 'domain_id'));
    }

    /**
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function verifyDomain(mixed $request): array
    {
        return $this->handler->verify($this->stringParam($request, 'domain_id'));
    }

    /**
     * Runs the website DNS validation. The website id is derived server-side
     * from the identity gateway, so no client param is read.
     *
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function validateDomain(mixed $request): array
    {
        return $this->handler->validate();
    }

    /**
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function deleteDomain(mixed $request): array
    {
        return $this->handler->delete($this->stringParam($request, 'domain_id'));
    }

    /**
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function checkAvailability(mixed $request): array
    {
        return $this->handler->checkAvailability($this->stringParam($request, 'label'));
    }

    /**
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function sslStatus(mixed $request): array
    {
        return $this->handler->sslStatus($this->stringParam($request, 'domain'));
    }

    public function canViewDomains(): bool
    {
        return $this->isAuthorized();
    }

    public function canBindDomain(): bool
    {
        return $this->isAuthorized();
    }

    public function canReadDns(): bool
    {
        return $this->isAuthorized();
    }

    public function canVerifyDomain(): bool
    {
        return $this->isAuthorized();
    }

    public function canValidateDomain(): bool
    {
        return $this->isAuthorized();
    }

    public function canDeleteDomain(): bool
    {
        return $this->isAuthorized();
    }

    public function canReadPlatform(): bool
    {
        return $this->isAuthorized();
    }

    public function canCheckAvailability(): bool
    {
        return $this->isAuthorized();
    }

    public function canReadSsl(): bool
    {
        return $this->isAuthorized();
    }

    /**
     * @param mixed $request
     */
    private function stringParam(mixed $request, string $name): string
    {
        if (is_object($request) && method_exists($request, 'get_param')) {
            $value = $request->get_param($name);

            return is_string($value) ? $value : '';
        }

        return '';
    }

    private function isAuthorized(): bool
    {
        return $this->auth->currentUserCan(self::CAPABILITY)
            && $this->auth->hasValidRestNonce(self::REST_NONCE_ACTION);
    }
}
