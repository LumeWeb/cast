<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use LumeWeb\Cast\Http\HttpTransport;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds a real HttpTransport on a Guzzle MockHandler core while recording every
 * PSR-7 request the transport sends, so SDK adapter tests can assert the exact
 * method, path, query and headers without any network.
 */
final class RecordingTransport
{
    /**
     * @var list<RequestInterface> Requests in the order the client received them.
     */
    private array $requests = [];

    private MockHandler $mockHandler;

    private HttpTransport $transport;

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue MockHandler queue.
     */
    public static function withResponses(array $queue): self
    {
        $instance = new self();
        $instance->mockHandler = new MockHandler($queue);
        $stack = HandlerStack::create($instance->mockHandler);
        $stack->push(function (callable $handler) use ($instance): callable {
            return function (RequestInterface $request, array $options) use ($handler, $instance) {
                $instance->requests[] = $request;

                return $handler($request, $options);
            };
        });

        // allow_redirects => false keeps 30x responses visible to the adapter
        // under test (e.g. PostUploader owns the 307/308 policy) instead of
        // letting Guzzle transparently follow them against the MockHandler queue.
        $psr17 = new HttpFactory();
        $instance->transport = new HttpTransport(new Client(['handler' => $stack, 'allow_redirects' => false]), $psr17, $psr17);

        return $instance;
    }

    public function transport(): HttpTransport
    {
        return $this->transport;
    }

    /**
     * Top up the MockHandler queue after the transport was built, so flow tests
     * can serve follow-up requests (e.g. a status poll after a create call)
     * without building a second transport. The whole batch is validated before
     * anything is enqueued, so a bad entry can never leave the queue half-mutated.
     *
     * @param list<ResponseInterface|ClientExceptionInterface> $responses MockHandler queue entries to append.
     */
    public function appendResponses(array $responses): void
    {
        foreach ($responses as $response) {
            if (!$response instanceof ResponseInterface && !$response instanceof ClientExceptionInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Expected a %s or %s queue entry, got %s.',
                    ResponseInterface::class,
                    ClientExceptionInterface::class,
                    get_debug_type($response),
                ));
            }
        }

        $this->mockHandler->append(...$responses);
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): RequestInterface
    {
        if ($this->requests === []) {
            throw new \RuntimeException('Expected at least one request to have been sent.');
        }

        return $this->requests[count($this->requests) - 1];
    }
}
