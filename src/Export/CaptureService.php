<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The pure capture orchestrator for one work item.
 *
 * It enforces the exact-origin check before any request, prefers a jailed disk
 * copy for assets, fetches the rest through the injected transport, validates
 * the response (empty/ghost/403/404 never reach the work directory), writes
 * accepted bodies atomically to the deterministic output path and returns a
 * status + content hash for the crawler. No cookies, credentials, WordPress,
 * SQL or sleeping live here.
 */
final class CaptureService
{
    public function __construct(
        private readonly CaptureTransport $transport,
        private readonly Origin $origin,
        private readonly OutputFileSystem $files,
        private readonly DiskAssetSource $disk,
        private readonly OriginPolicy $originPolicy = new OriginPolicy(),
        private readonly OutputPathResolver $paths = new OutputPathResolver(),
        private readonly LocationResolver $locationResolver = new LocationResolver(),
        private readonly NotFoundPolicy $notFoundPolicy = new DefaultNotFoundPolicy(),
        private readonly RetryPolicy $retry = new RetryPolicy(),
    ) {
    }

    public function capture(WorkItem $item): CaptureResult
    {
        $this->originPolicy->assertAllowed($item->url(), $this->origin);

        // The first attempt always runs; further attempts are gated by the
        // bounded retry policy. The first stable retryable outcome (empty body,
        // 5xx, transport failure) is remembered so a late transport hiccup
        // after the budget is exhausted never mislabels what the resource kept
        // producing; a terminal success always wins and overwrites it.
        $attempts = 1;
        $last = $this->attempt($item);
        $stableRetryable = $last->outcome->isRetryable() ? $last->outcome : null;
        while ($this->retry->shouldRetry($last->outcome, $attempts)) {
            ++$attempts;
            $last = $this->attempt($item);
            $stableRetryable = $last->outcome->isRetryable() ? ($stableRetryable ?? $last->outcome) : null;
        }

        $outcome = $last->outcome->isRetryable() && $stableRetryable !== null ? $stableRetryable : $last->outcome;

        return new CaptureResult(
            $outcome,
            outputPath: $last->outputPath,
            contentHash: $last->contentHash,
            redirectTarget: $last->redirectTarget,
            attempts: $attempts,
            retryDelaySeconds: $outcome->isRetryable() ? $this->retry->delaySeconds($attempts) : 0,
            detail: $last->detail,
        );
    }

    private function attempt(WorkItem $item): CaptureResult
    {
        if ($item->kind() === WorkItemKind::Asset) {
            $local = $this->disk->read($item);
            if ($local !== null) {
                return $this->acceptBody($item, $local, CaptureOutcome::Copied);
            }
        }

        try {
            $response = $this->transport->fetch(new CaptureRequest((string) $item->url()));
        } catch (CaptureTransportException $exception) {
            return $this->reject($item, CaptureOutcome::Failed, $exception->getMessage());
        }

        return $this->classify($item, $response);
    }

    private function classify(WorkItem $item, CaptureResponse $response): CaptureResult
    {
        $status = $response->status();

        if ($status === 200) {
            return $this->accept($item, $response);
        }

        if ($status === 404) {
            return $this->notFound($item, $response);
        }

        if ($status === 403) {
            return $this->reject($item, CaptureOutcome::Forbidden, 'HTTP 403');
        }

        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            return $this->redirect($item, $response);
        }

        return $this->reject($item, CaptureOutcome::Failed, sprintf('HTTP %d', $status));
    }

    private function redirect(WorkItem $item, CaptureResponse $response): CaptureResult
    {
        $location = $response->location();
        if ($location === null || trim($location) === '') {
            return $this->reject($item, CaptureOutcome::Failed, 'HTTP redirect without Location');
        }

        try {
            $target = $this->locationResolver->resolve($location, $item->url());
        } catch (InvalidUrl $exception) {
            return $this->reject($item, CaptureOutcome::Failed, $exception->getMessage());
        }

        // A canonical slash/scheme twin lands in the same output file as the
        // source; writing a stub would clobber the canonical page, so nothing
        // is written and the twin is reported before the origin check.
        $targetPath = $this->paths->resolve($target, $item->kind());
        if ($targetPath === $item->outputPath()) {
            return new CaptureResult(CaptureOutcome::CanonicalTwin, redirectTarget: (string) $target);
        }

        if (!$this->origin->matches($target)) {
            return new CaptureResult(CaptureOutcome::OffOrigin, redirectTarget: (string) $target);
        }

        if ($item->kind() === WorkItemKind::Page) {
            $stub = RedirectPage::render((string) $target);

            return $this->acceptBody(
                $item,
                CaptureBody::fromString($stub),
                CaptureOutcome::Redirected,
                null,
                (string) $target,
            );
        }

        return new CaptureResult(CaptureOutcome::Redirected, redirectTarget: (string) $target);
    }

    private function accept(WorkItem $item, CaptureResponse $response): CaptureResult
    {
        $body = $response->body() ?? CaptureBody::empty();

        if ($body->size() === 0) {
            return new CaptureResult(CaptureOutcome::Empty, outputPath: $item->outputPath());
        }

        if ($item->kind() === WorkItemKind::Page && $this->isGhost($body)) {
            return $this->reject($item, CaptureOutcome::Ghost, 'HTML under 1024 bytes or missing <html');
        }

        return $this->acceptBody($item, $body, CaptureOutcome::Fetched);
    }

    private function isGhost(CaptureBody $body): bool
    {
        return $body->size() < 1024 || !str_contains(strtolower($body->contents()), '<html');
    }

    private function notFound(WorkItem $item, CaptureResponse $response): CaptureResult
    {
        $dedicated = $this->notFoundPolicy->outputPathForDedicated404($item);
        if ($dedicated !== null) {
            return $this->acceptBody($item, $response->body() ?? CaptureBody::empty(), CaptureOutcome::Fetched, $dedicated);
        }

        return $this->reject($item, CaptureOutcome::NotFound, 'HTTP 404');
    }

    private function acceptBody(
        WorkItem $item,
        CaptureBody $body,
        CaptureOutcome $outcome,
        ?string $pathOverride = null,
        ?string $redirectTarget = null,
    ): CaptureResult {
        $contents = $body->contents();
        $path = $pathOverride ?? $item->outputPath();
        $this->files->put($path, $contents);

        return new CaptureResult(
            $outcome,
            outputPath: $path,
            contentHash: md5($contents),
            redirectTarget: $redirectTarget,
        );
    }

    private function reject(WorkItem $item, CaptureOutcome $outcome, string $detail): CaptureResult
    {
        $this->files->delete($item->outputPath());

        return new CaptureResult($outcome, outputPath: $item->outputPath(), detail: $detail);
    }
}
