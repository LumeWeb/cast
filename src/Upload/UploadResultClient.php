<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

use LumeWeb\Cast\Http\HttpResponse;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Publish\UploadIdentifier;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadStatus;

/**
 * Real upload-result adapter against the ipfs-sdk contract: GET
 * /api/upload/result/{identifier} with bearer auth and the identifier
 * URL-path-escaped (rawurlencode, matching upload.go's url.PathEscape; a TUS
 * upload id or a numeric request id both fit). The UploadResultResponse
 * {status, cid?, error?} is mapped onto the Publish UploadResult /
 * UploadStatus DTOs: the status vocabulary is mirrored exactly, the error
 * field on a failed result is preserved as the message (never silently
 * dropped), and a success without a CID or an unknown/malformed payload fails
 * loudly through ResponseDecodingException. The bearer token only ever appears
 * on the wire; the typed HttpException family is preserved.
 */
final class UploadResultClient
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $baseUrl,
        private readonly string $bearerToken,
    ) {
    }

    /**
     * Poll the upload result endpoint once for an identifier.
     *
     * A failed terminal status is returned as an UploadResult (status Failed,
     * message from the server's "error" field) so the caller surfaces the
     * failure detail; transport, non-2xx and decoding problems raise the typed
     * HttpException family.
     *
     * @throws \LumeWeb\Cast\Http\UnexpectedStatusCodeException when the endpoint returns a non-2xx status
     * @throws \LumeWeb\Cast\Http\ResponseDecodingException      when the body maps to no valid UploadResultResponse
     * @throws \LumeWeb\Cast\Http\TransportException             when the request never completes
     */
    public function poll(UploadIdentifier $identifier): UploadResult
    {
        $uri = $this->baseUrl . '/api/upload/result/' . rawurlencode($identifier->value);

        $response = $this->transport->send('GET', $uri, $this->headers());
        $response->requireSuccess();

        return $this->mapResult($response);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->bearerToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @throws ResponseDecodingException when the response is not a valid UploadResultResponse
     */
    private function mapResult(HttpResponse $response): UploadResult
    {
        $data = $response->json();

        if (!isset($data['status']) || !is_string($data['status'])) {
            throw new ResponseDecodingException('Upload result is missing string field "status".');
        }

        $status = UploadStatus::tryFrom($data['status']);
        if ($status === null) {
            throw new ResponseDecodingException(sprintf('Upload result has unknown status "%s".', $data['status']));
        }

        $cid = $data['cid'] ?? null;
        if ($cid !== null && !is_string($cid)) {
            throw new ResponseDecodingException('Upload result field "cid" must be a string.');
        }

        $message = $data['error'] ?? null;
        if ($message !== null && !is_string($message)) {
            throw new ResponseDecodingException('Upload result field "error" must be a string.');
        }

        if ($status->isSuccess() && ($cid === null || $cid === '')) {
            throw new ResponseDecodingException('Upload result is missing string field "cid".');
        }

        return new UploadResult(
            status: $status,
            cid: $cid,
            message: $message,
        );
    }
}
