<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Upload\PostUploader;
use LumeWeb\Cast\Upload\TusUploader;
use LumeWeb\Cast\Upload\UploadException;
use LumeWeb\Cast\Upload\UploadResultClient;

/**
 * Publish UploadClient adapter over the real ipfs-sdk uploaders. upload() picks
 * the transport from the route the UploadRouter already decided: at/under the
 * 100 MiB limit the PostUploader streams a multipart POST whose response
 * already carries the terminal result — that result is retained keyed by a
 * local identifier and replayed on later poll() calls so the orchestration's
 * wait loop sees a terminal status immediately (there is no result endpoint for
 * a small POST upload). Above the limit the TusUploader runs a resumable
 * chunked session whose Upload-Key becomes the UploadIdentifier that poll()
 * delegates to the UploadResultClient. resume(), cancel() and offset() surface
 * the TUS resumption primitives the durable runner needs after a crash. Every
 * typed Upload/Http failure is rethrown as a secret-safe UploadClientException
 * carrying the original as $previous; the bearer token only ever appears on the
 * wire inside the wrapped clients, never in an adapter message.
 */
final class IpfsUploadClient implements UploadClient
{
    /**
     * Terminal results retained for POST uploads, keyed by the local identifier
     * upload() returned so poll() replays them without any result endpoint.
     *
     * @var array<string, UploadResult>
     */
    private array $postResults = [];

    private int $postCounter = 0;

    public function __construct(
        private readonly PostUploader $post,
        private readonly TusUploader $tus,
        private readonly UploadResultClient $results,
    ) {
    }

    public function upload(UploadSpec $spec): UploadIdentifier
    {
        if ($spec->route === UploadRoute::Post) {
            return $this->uploadByPost($spec);
        }

        return $this->uploadByTus($spec);
    }

    public function poll(UploadIdentifier $identifier): UploadResult
    {
        if (isset($this->postResults[$identifier->value])) {
            return $this->postResults[$identifier->value];
        }

        return $this->pollResult($identifier);
    }

    /**
     * Resume an interrupted TUS upload under its existing Upload-Key: the
     * server's current offset is HEADed and only the remaining bytes are
     * PATCHed (the session is re-created when the server lost it). Returns the
     * same identifier so the durable run row keeps polling it.
     */
    public function resume(UploadSpec $spec, UploadIdentifier $existingKey): UploadIdentifier
    {
        try {
            return $this->tus->uploadSession($spec, $existingKey)->identifier();
        } catch (UploadException $exception) {
            throw new UploadClientException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * DELETE the server-side TUS upload resource tracked for this identifier.
     */
    public function cancel(UploadIdentifier $identifier): void
    {
        try {
            $this->tus->cancel($identifier);
        } catch (UploadException $exception) {
            throw new UploadClientException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * The server's current byte offset for a known TUS upload (HEAD), or null
     * when it no longer tracks it — the durable runner uses this to decide
     * whether to resume or start over.
     */
    public function offset(UploadIdentifier $identifier): ?int
    {
        try {
            return $this->tus->offset($identifier);
        } catch (UploadException $exception) {
            throw new UploadClientException($exception->getMessage(), 0, $exception);
        }
    }

    private function uploadByPost(UploadSpec $spec): UploadIdentifier
    {
        try {
            $result = $this->post->upload($spec);
        } catch (UploadException | HttpException $exception) {
            throw new UploadClientException($exception->getMessage(), 0, $exception);
        }

        $identifier = $this->nextPostIdentifier();
        $this->postResults[$identifier->value] = $result;

        return $identifier;
    }

    private function uploadByTus(UploadSpec $spec): UploadIdentifier
    {
        try {
            return $this->tus->uploadSession($spec)->identifier();
        } catch (UploadException $exception) {
            throw new UploadClientException($exception->getMessage(), 0, $exception);
        }
    }

    private function pollResult(UploadIdentifier $identifier): UploadResult
    {
        try {
            return $this->results->poll($identifier);
        } catch (HttpException $exception) {
            throw new UploadClientException($exception->getMessage(), 0, $exception);
        }
    }

    private function nextPostIdentifier(): UploadIdentifier
    {
        ++$this->postCounter;

        return new UploadIdentifier('post-' . $this->postCounter);
    }
}
