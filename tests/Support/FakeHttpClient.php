<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal PSR-18 stub for driving a real SDK {@see \CronMonitor\Api\MonitorApiClient}
 * over canned responses, mirroring the SDK's own `RecordingHttpClient`.
 *
 * Records each request (for asserting verb / path / body) and dispenses
 * queued responses in order; a queued {@see \Throwable} is thrown instead of
 * returned (it must implement {@see \Psr\Http\Client\ClientExceptionInterface}
 * for the SDK to treat it as a transport failure rather than let it escape).
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> */
    public array $bodies = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $queue;

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();

        if ([] === $this->queue) {
            throw new \LogicException('FakeHttpClient queue is empty — an unexpected extra request was made.');
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
