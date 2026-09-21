<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use LumeWeb\Cast\Http\HttpResponse;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Publish\Contract;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadSpec;
use LumeWeb\Cast\Publish\UploadStatus;

/**
 * Real small-upload POST adapter against the ipfs-sdk contract. A single
 * multipart field exactly named "file" is streamed (never buffered) to
 * POST /api/upload?archive=true|false&name=..., the PostUploadResponse CID is
 * mapped onto a Publish UploadResult, and the adapter owns the 307/308
 * redirect policy: up to Contract::MAX_REDIRECT_HOPS hops with loop detection,
 * always re-sending the request body and bearer on each hop, never following
 * other redirect statuses, and never leaking the bearer token into errors.
 *
 * Input/output reuse the existing Publish DTOs (UploadSpec / UploadResult) so
 * a future adapter can bridge this onto the Publish UploadClient boundary
 * (which additionally requires result polling) without reshaping its types.
 */
final class PostUploader
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $baseUrl,
        private readonly string $bearerToken,
    ) {
    }

    /**
     * POST a small artifact (at/under the 100 MiB limit) as a multipart
     * upload. Returns the terminal result the POST response already carries
     * (the CID); no result polling is required for small POST uploads.
     *
     * @throws UploadFileException        when the artifact path is not a readable non-empty file
     * @throws UploadRedirectException    on a 307/308 that cannot be followed (missing Location)
     * @throws RedirectLoopException      when a redirect revisits an endpoint
     * @throws TooManyRedirectsException  when the hop budget is exhausted
     * @throws \LumeWeb\Cast\Http\UnexpectedStatusCodeException when any non-2xx (including
     *                                     non-307/308 redirects) is returned
     * @throws \LumeWeb\Cast\Http\ResponseDecodingException      when the 2xx body does not map to PostUploadResponse
     * @throws \LumeWeb\Cast\Http\TransportException             when the request never completes
     */
    public function upload(UploadSpec $spec): UploadResult
    {
        $path = $spec->artifactPath;
        $this->assertUsableFile($path);

        $endpoint = $this->endpoint($spec);
        $visited = [];

        $hops = 0;
        while ($hops < Contract::MAX_REDIRECT_HOPS) {
            if (isset($visited[$endpoint])) {
                throw new RedirectLoopException();
            }
            $visited[$endpoint] = true;

            $body = $this->multipart($path, $spec->name);
            $response = $this->transport->send('POST', $endpoint, [
                'Authorization' => 'Bearer ' . $this->bearerToken,
                'Accept' => 'application/json',
                'Content-Type' => 'multipart/form-data; boundary=' . $body->getBoundary(),
            ], $body);
            ++$hops;

            if ($response->status() === 307 || $response->status() === 308) {
                $endpoint = $this->followRedirect($response, $endpoint);
                continue;
            }

            // Any other status — including 301/302/303 redirects that must NOT
            // be followed — is rejected with the shared typed status error.
            $response->requireSuccess();

            return $this->mapResult($response, $spec);
        }

        throw new TooManyRedirectsException(Contract::MAX_REDIRECT_HOPS);
    }

    /**
     * @throws UploadFileException when the path is not a readable, non-empty file
     */
    private function assertUsableFile(string $path): void
    {
        if (!is_file($path)) {
            throw new UploadFileException($path, UploadFileProblem::NotFound);
        }
        if (!is_readable($path)) {
            throw new UploadFileException($path, UploadFileProblem::Unreadable);
        }
        $size = filesize($path);
        if ($size === false) {
            throw new UploadFileException($path, UploadFileProblem::Unreadable);
        }
        if ($size === 0) {
            throw new UploadFileException($path, UploadFileProblem::Empty);
        }
    }

    /**
     * The initial endpoint with the archive flag and optional pin name on the
     * query (POST /upload DTO: query "archive" and query "name"). The name is
     * only sent when non-empty, mirroring upload.go's postUpload.
     */
    private function endpoint(UploadSpec $spec): string
    {
        $query = ['archive' => $spec->archive ? 'true' : 'false'];
        if ($spec->name !== '') {
            $query['name'] = $spec->name;
        }

        return $this->baseUrl . '/api/upload?' . http_build_query($query);
    }

    /**
     * A fresh multipart body per hop: the file is streamed from disk under the
     * exact form field the contract requires, so a re-sent body (after a
     * 307/308) is identical on every hop without buffering or rewinding state.
     */
    private function multipart(string $path, string $name): MultipartStream
    {
        return new MultipartStream([
            [
                'name' => 'file',
                'contents' => Utils::streamFor(Utils::tryFopen($path, 'rb')),
                'filename' => $name,
            ],
        ]);
    }

    /**
     * Resolve a 307/308 Location header (absolute or relative) against the
     * endpoint that produced it, treating it as the next upload endpoint.
     *
     * @throws UploadRedirectException when no Location header was provided
     */
    private function followRedirect(HttpResponse $response, string $currentEndpoint): string
    {
        $location = $response->header('Location');
        if ($location === null || $location === '') {
            throw new UploadRedirectException(sprintf(
                'Upload redirect (HTTP %d) with no Location header.',
                $response->status(),
            ));
        }

        return (string) UriResolver::resolve(new Uri($currentEndpoint), new Uri($location));
    }

    /**
     * Map the PostUploadResponse ({"CID": string}) onto a terminal upload
     * result. The size is the logical file size the caller declared; DAG size
     * and location are unknown for a non-CAR data upload (upload.go semantics).
     *
     * @throws ResponseDecodingException when the 2xx body is not a valid PostUploadResponse
     */
    private function mapResult(HttpResponse $response, UploadSpec $spec): UploadResult
    {
        $data = $response->json();
        if (!isset($data['CID']) || !is_string($data['CID'])) {
            throw new ResponseDecodingException('Upload response is missing string field "CID".');
        }

        return new UploadResult(
            status: UploadStatus::Completed,
            cid: $data['CID'],
            size: $spec->sizeBytes,
        );
    }
}
