<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use ComposePress\Core\HookSubscriber;
use ComposePress\Core\Hooks;

/**
 * REST route registrar for the publish UX surface.
 *
 * Registers the stable routes under the `cast/v1` namespace:
 *
 *   GET  /cast/v1/publish/status  — the JSON-safe readiness report (+ mode)
 *   POST /cast/v1/publish/start   — explicitly queue the first publish
 *   POST /cast/v1/publish/now     — manual-run-wins "publish now" (re-publish)
 *   POST /cast/v1/publish/mode    — persist the selected publish trigger mode
 *   POST /cast/v1/publish/cancel  — cancel the live run + clear its events
 *   POST /cast/v1/publish/artifact — publish an intact existing artifact
 *   POST /cast/v1/website          — explicit create (guided path a)
 *   GET  /cast/v1/website/available — picker list for the link path
 *   POST /cast/v1/website/link     — link an existing website (guided path b)
 *
 * Every route is gated by {@see RestAuth}: the manage_options capability and a
 * valid wp_rest REST nonce. The callbacks delegate to {@see PublishRestHandler}
 * (pure read / queue-only / single option write / typed website actions), so
 * no route does long request-time work. Only the core `rest_api_init` action
 * is registered — no front-end content hooks.
 */
final class PublishRestRouteRegistrar implements HookSubscriber
{
    public const NAMESPACE = 'cast/v1';

    public const STATUS_ROUTE = '/publish/status';

    public const START_ROUTE = '/publish/start';

    public const NOW_ROUTE = '/publish/now';

    public const MODE_ROUTE = '/publish/mode';

    public const CANCEL_ROUTE = '/publish/cancel';

    public const ARTIFACT_ROUTE = '/publish/artifact';

    public const WEBSITE_CREATE_ROUTE = '/website';

    public const WEBSITE_AVAILABLE_ROUTE = '/website/available';

    public const WEBSITE_LINK_ROUTE = '/website/link';

    public const REST_NONCE_ACTION = 'wp_rest';

    private const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly PublishRestHandler $handler,
        private readonly RestAuth $auth,
    ) {
    }

    public function subscribe(Hooks $hooks): void
    {
        $hooks->action('rest_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::STATUS_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this->handler, 'status'],
            'permission_callback' => [$this, 'canViewStatus'],
        ]);

        register_rest_route(self::NAMESPACE, self::START_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this->handler, 'start'],
            'permission_callback' => [$this, 'canStart'],
        ]);

        register_rest_route(self::NAMESPACE, self::NOW_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this->handler, 'now'],
            'permission_callback' => [$this, 'canPublishNow'],
        ]);

        register_rest_route(self::NAMESPACE, self::MODE_ROUTE, [
            'methods' => 'POST',
            'callback' => function (\WP_REST_Request $request): array {
                $mode = $request->get_param('mode');

                return $this->handler->setMode(is_string($mode) ? $mode : '');
            },
            'permission_callback' => [$this, 'canChangeMode'],
        ]);

        register_rest_route(self::NAMESPACE, self::CANCEL_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this->handler, 'cancel'],
            'permission_callback' => [$this, 'canCancel'],
        ]);

        register_rest_route(self::NAMESPACE, self::ARTIFACT_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this->handler, 'publishExisting'],
            'permission_callback' => [$this, 'canPublishExisting'],
        ]);

        register_rest_route(self::NAMESPACE, self::WEBSITE_CREATE_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'createWebsite'],
            'permission_callback' => [$this, 'canCreateWebsite'],
        ]);

        register_rest_route(self::NAMESPACE, self::WEBSITE_AVAILABLE_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this->handler, 'availableWebsites'],
            'permission_callback' => [$this, 'canListWebsites'],
        ]);

        register_rest_route(self::NAMESPACE, self::WEBSITE_LINK_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'linkWebsite'],
            'permission_callback' => [$this, 'canLinkWebsite'],
        ]);
    }

    /**
     * Explicit create (guided path a): the hostname is optional and the
     * confirm flag is only shape-validated — the "no silent auto-create"
     * contract is enforced by the UI (this card is the only caller) and by
     * the service's own awaiting-website check, never by trusting the flag.
     *
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function createWebsite(mixed $request): array
    {
        return $this->handler->createWebsite($this->stringParam($request, 'hostname'));
    }

    /**
     * Link existing (guided path b): the website id must be a positive
     * integer; anything else is refused before the service is reached.
     *
     * @param mixed $request
     *
     * @return array<string, mixed>
     */
    public function linkWebsite(mixed $request): array
    {
        $websiteId = $this->intParam($request, 'website_id');
        if ($websiteId === null) {
            return (new PublishWebsiteLinkResult(false, PublishWebsiteRefusal::InvalidWebsiteId))->toArray();
        }

        return $this->handler->linkWebsite($websiteId);
    }

    public function canViewStatus(): bool
    {
        return $this->isAuthorized();
    }

    public function canStart(): bool
    {
        return $this->isAuthorized();
    }

    public function canPublishNow(): bool
    {
        return $this->isAuthorized();
    }

    public function canChangeMode(): bool
    {
        return $this->isAuthorized();
    }

    public function canCancel(): bool
    {
        return $this->isAuthorized();
    }

    public function canPublishExisting(): bool
    {
        return $this->isAuthorized();
    }

    public function canCreateWebsite(): bool
    {
        return $this->isAuthorized();
    }

    public function canListWebsites(): bool
    {
        return $this->isAuthorized();
    }

    public function canLinkWebsite(): bool
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

    /**
     * A positive integer param, or null when the param is absent or not a
     * positive integer (so the caller can refuse invalid shapes explicitly).
     *
     * @param mixed $request
     */
    private function intParam(mixed $request, string $name): ?int
    {
        if (is_object($request) && method_exists($request, 'get_param')) {
            $value = $request->get_param($name);
            if (is_int($value)) {
                return $value >= 1 ? $value : null;
            }
            if (is_string($value) && $value !== '' && ctype_digit($value)) {
                $parsed = (int) $value;
                if ($parsed >= 1) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    private function isAuthorized(): bool
    {
        return $this->auth->currentUserCan(self::CAPABILITY)
            && $this->auth->hasValidRestNonce(self::REST_NONCE_ACTION);
    }
}
