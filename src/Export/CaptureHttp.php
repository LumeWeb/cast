<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The WordPress HTTP interface behind {@see WordPressCaptureTransport}. The transport
 * builds the exact capture request arguments and consumes the typed {@see WpRemoteResponse};
 * a concrete {@see WordPressCaptureHttp} adapter funnels those through the real
 * wp_remote_get()/wp_remote_retrieve_*() functions, while unit tests inject a fake
 * so the pure transport is exercised without ever loading WordPress.
 */
interface CaptureHttp
{
    /**
     * @param array<string, mixed> $args wp_remote_get() request arguments.
     */
    public function get(string $url, array $args): WpRemoteResponse;

    /**
     * Whether the current WordPress environment type is 'local' (the TLS
     * local-exception arm of the capture policy).
     */
    public function isLocalEnvironment(): bool;
}
