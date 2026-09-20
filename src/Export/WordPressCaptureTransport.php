<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The real capture transport: wraps wp_remote_get() through the injected
 * {@see CaptureHttp} interface with the exact public-side policy — 30s timeout
 * (clamped 5-60), zero redirects, blocking, decompress on, a dedicated
 * user-agent, `Accept-Encoding: identity`, no cookies ever, TLS verified
 * except for obvious local origins, and strictly anonymous (never Basic Auth,
 * exactly like the probe — the deployment's Caddy bypasses Basic Auth on the
 * plugin's own loopback requests). Bodies are streamed into a temporary file
 * and surfaced as a typed {@see CaptureResponse}.
 */
final class WordPressCaptureTransport implements CaptureTransport
{
    public const USER_AGENT = 'Cast/1.0 (+https://github.com/lumeweb/cast; static site exporter)';

    public const TIMEOUT_SECONDS = 30.0;
    public const TIMEOUT_MIN = 5.0;
    public const TIMEOUT_MAX = 60.0;
    public const REDIRECTIONS = 0;

    private readonly LocalHostPolicy $localHosts;

    public function __construct(
        private readonly CaptureHttp $http,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
        private readonly ?string $userAgent = null,
        ?LocalHostPolicy $localHosts = null,
    ) {
        $this->localHosts = $localHosts ?? new LocalHostPolicy();
    }

    public function fetch(CaptureRequest $request): CaptureResponse
    {
        $headers = ['Accept-Encoding' => 'identity'];

        $agent = $this->userAgent ?? self::USER_AGENT;
        if ($agent !== '') {
            $headers['User-Agent'] = $agent;
        }

        $host = parse_url($request->url, PHP_URL_HOST);
        $sslVerify = !($this->localHosts->isLocal((string) $host) || $this->http->isLocalEnvironment());

        $args = [
            'timeout' => $this->clampedTimeout(),
            'redirection' => self::REDIRECTIONS,
            'blocking' => true,
            'decompress' => true,
            'headers' => $headers,
            'sslverify' => $sslVerify,
        ];

        $temp = tempnam(sys_get_temp_dir(), 'cast-cap-');
        if ($temp === false) {
            throw new CaptureTransportException('Unable to allocate a temporary capture file');
        }
        $args['stream'] = true;
        $args['filename'] = $temp;

        try {
            $response = $this->http->get($request->url, $args);
        } catch (\Throwable $exception) {
            @unlink($temp);
            throw new CaptureTransportException('WordPress HTTP failure: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->isError()) {
            @unlink($temp);
            throw new CaptureTransportException(sprintf(
                'WordPress HTTP error %s: %s',
                $response->errorCode(),
                $response->errorMessage(),
            ));
        }

        return $this->toCaptureResponse($response, $temp);
    }

    /**
     * Maps the streamed temporary file (or the plain body) into the typed
     * capture response, always copying into php://temp so the body outlives the
     * request scratch file, which is removed here. The streamed file is used
     * only when the response actually announces it (its filename matches the
     * requested scratch path); otherwise the plain body string is used — the
     * scratch file exists from tempnam() even for non-streamed responses, so
     * its mere presence must not be mistaken for a streamed body.
     */
    private function toCaptureResponse(WpRemoteResponse $response, string $temp): CaptureResponse
    {
        try {
            $filename = $response->filename();
            if (is_string($filename) && $filename !== '' && is_file($filename)) {
                $handle = @fopen($filename, 'rb');
                if ($handle !== false) {
                    $copy = fopen('php://temp', 'wb+');
                    if ($copy !== false) {
                        stream_copy_to_stream($handle, $copy);
                        rewind($copy);
                        fclose($handle);

                        return CaptureResponse::withBody($response->status(), $response->headers(), CaptureBody::fromStream($copy));
                    }
                    fclose($handle);
                }
            }

            return CaptureResponse::withString($response->status(), $response->headers(), $response->body());
        } finally {
            @unlink($temp);
        }
    }

    private function clampedTimeout(): float
    {
        return (float) max(self::TIMEOUT_MIN, min(self::TIMEOUT_MAX, $this->timeout));
    }
}
