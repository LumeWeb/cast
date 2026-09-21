<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

use GuzzleHttp\Exception\GuzzleException;
use LumeWeb\Cast\Publish\UploadIdentifier;
use LumeWeb\Cast\Publish\UploadSpec;
use Ramsey\Uuid\Uuid;
use TusPhp\Cache\Cacheable;
use TusPhp\Exception\ConnectionException;
use TusPhp\Exception\FileException;
use TusPhp\Exception\TusException;
use TusPhp\Tus\Client;

/**
 * Real resumable TUS upload adapter against the ipfs-sdk contract: POST
 * /api/upload/tus with bearer auth, archive + name metadata, and bounded
 * memory chunking (default 16 MiB) — the first chunk rides the
 * creation-with-upload POST, later chunks are explicit upload() PATCHes, and
 * upload(-1) is never used so a large file is never buffered whole. Resumption
 * HEADs the server offset for a known Upload-Key, cancellation DELETEs the
 * server resource. All failures are wrapped in typed errors whose messages
 * never include the bearer token, request headers or raw server bodies.
 *
 * The underlying tus-php client hard-wires Guzzle and is only injectable
 * through its constructor options, so the transport adapter is a small client
 * factory: given the base URL and the per-request header options (bearer), the
 * factory returns a configured collect that tests wire up to an offline
 * MockHandler while production builds the default Guzzle-backed client. The
 * upload state (TUS Upload-Key -> server location) is cached in the injected
 * tus-php store so a later request can resume/cancel without re-creating.
 */
final class TusUploader
{
    /** The ipfs-sdk TUS endpoint relative to the portal base URL. */
    public const API_PATH = '/api/upload/tus';

    /** Bounded memory chunk size for every PATCH (16 MiB). */
    public const DEFAULT_CHUNK_BYTES = 16 * 1024 * 1024;

    private const CHECKSUM_ALGORITHM = 'sha256';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $bearerToken,
        private readonly Cacheable $cache,
        private readonly ?\Closure $clientFactory = null,
        private readonly int $chunkBytes = self::DEFAULT_CHUNK_BYTES,
    ) {
    }

    /**
     * Upload the artifact in bounded chunks and return the resulting TUS
     * session. With an $existingKey the upload is resumed from the server's
     * current HEAD offset (re-creating with the same key only when the server
     * knows nothing about it); without one a fresh Upload-Key is minted and
     * sent with the creation-with-upload first chunk.
     *
     * @throws UploadFileException  when the artifact path is not a readable non-empty file
     * @throws TusUploadException   when the server/library rejects the upload
     */
    public function uploadSession(UploadSpec $spec, ?UploadIdentifier $existingKey = null): TusUploadSession
    {
        $this->assertUsableFile($spec->artifactPath);

        $key = $existingKey->value ?? Uuid::uuid4()->toString();

        try {
            $client = $this->makeClient();
            $this->configure($client, $spec, $key);

            if ($existingKey !== null) {
                // tus-php reports "gone" as a false-y offset; resume when the
                // server still tracks it, otherwise re-create with the same key.
                $headOffset = $client->getOffset();
                $offset = is_int($headOffset)
                    ? $headOffset
                    : $this->createWithFirstChunk($client, $key, $spec->sizeBytes);
            } else {
                $offset = $this->createWithFirstChunk($client, $key, $spec->sizeBytes);
            }

            while ($offset < $spec->sizeBytes) {
                $offset = $client->upload(min($this->chunkBytes, $spec->sizeBytes - $offset));
            }

            $uploadUrl = $client->getUrl();
            if ($uploadUrl === null) {
                throw new TusUploadException('TUS upload finished without a server upload URL.');
            }

            return new TusUploadSession(
                completed: $offset >= $spec->sizeBytes,
                uploadUrl: $uploadUrl,
                offsetBytes: $offset,
                sizeBytes: $spec->sizeBytes,
                identifier: new UploadIdentifier($key),
            );
        } catch (ConnectionException | FileException | TusException | GuzzleException $e) {
            throw new TusUploadException('TUS upload failed.', 0, $e);
        }
    }

    /**
     * Query the server's current byte offset for a known upload (HEAD). Null
     * when the server no longer tracks it (or the local resume record is
     * gone), signalling the caller it has to start over.
     *
     * @throws TusUploadException when the HEAD itself cannot be carried out
     */
    public function offset(UploadIdentifier $identifier): ?int
    {
        $client = $this->makeClient();
        $client->setCache($this->cache);
        $client->setKey($identifier->value);

        try {
            $offset = $client->getOffset();
        } catch (ConnectionException | FileException | TusException | GuzzleException $e) {
            throw new TusUploadException('Could not query TUS upload offset.', 0, $e);
        }

        return is_int($offset) ? $offset : null;
    }

    /**
     * DELETE the server-side upload resource tracked for this key.
     *
     * @throws TusUploadException when the upload is not known or the DELETE fails
     */
    public function cancel(UploadIdentifier $identifier): void
    {
        $client = $this->makeClient();
        $client->setCache($this->cache);
        $client->setKey($identifier->value);

        try {
            $client->delete();
        } catch (ConnectionException | FileException | TusException | GuzzleException $e) {
            throw new TusUploadException('Could not cancel TUS upload.', 0, $e);
        }
    }

    /**
     * POST the creation-with-upload request carrying the first bounded chunk.
     *
     * @return int the offset the server reported after the first chunk
     */
    private function createWithFirstChunk(Client $client, string $key, int $size): int
    {
        $result = $client->createWithUpload($key, min($this->chunkBytes, $size));

        return (int) $result['offset'];
    }

    private function makeClient(): Client
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->bearerToken]];

        if ($this->clientFactory instanceof \Closure) {
            return ($this->clientFactory)($this->baseUrl, $options);
        }

        return new Client($this->baseUrl, $options);
    }

    private function configure(Client $client, UploadSpec $spec, string $key): void
    {
        $client->setApiPath(self::API_PATH);
        $client->setCache($this->cache);

        // file() registers a "filename" metadata entry the ipfs-sdk contract
        // does not expect; drop it and send only archive + name.
        $client->file($spec->artifactPath, $spec->name);
        $client->removeMetadata('filename');
        $client->addMetadata('archive', $spec->archive ? 'true' : 'false');
        $client->addMetadata('name', $spec->name);

        $client->setKey($key);

        // tus-php's default getChecksum() base64-encodes the hex digest, but
        // the contract (and the tus spec) wants the raw binary digest
        // base64-encoded ("sha256 <base64>"), so hand the client raw bytes.
        $checksum = hash_file(self::CHECKSUM_ALGORITHM, $spec->artifactPath, true);
        if ($checksum === false) {
            throw new UploadFileException($spec->artifactPath, UploadFileProblem::Unreadable);
        }
        $client->setChecksum($checksum);
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
}
