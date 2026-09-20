<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * Concrete {@see CaptureHttp} that funnels the transport's request arguments
 * through the real WordPress HTTP stack. Maps WP_Error to a typed error
 * response and normalizes the raw response array (status, lowercased headers,
 * body, streamed filename) into {@see WpRemoteResponse}. WordPress globals live
 * here and nowhere in the pure transport.
 */
final class WordPressCaptureHttp implements CaptureHttp
{
    public function get(string $url, array $args): WpRemoteResponse
    {
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return WpRemoteResponse::error(
                (string) $response->get_error_code(),
                (string) $response->get_error_message(),
            );
        }

        if (!is_array($response)) {
            return WpRemoteResponse::error('http_error', 'wp_remote_get returned an unexpected result');
        }

        $filename = $response['filename'] ?? null;

        return WpRemoteResponse::success(
            (int) wp_remote_retrieve_response_code($response),
            $this->headersToArray(wp_remote_retrieve_headers($response)),
            (string) wp_remote_retrieve_body($response),
            is_string($filename) ? $filename : null,
        );
    }

    public function isLocalEnvironment(): bool
    {
        return wp_get_environment_type() === 'local';
    }

    /**
     * @param CaseInsensitiveDictionary|array<array-key, mixed> $headers
     * @return array<string, string|list<string>>
     */
    private function headersToArray(CaseInsensitiveDictionary|array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            $out[strtolower((string) $key)] = is_array($value)
                ? array_map('strval', array_values($value))
                : (string) $value;
        }

        return $out;
    }
}
