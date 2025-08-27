<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Traits;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

trait HttpClientMockTrait
{
    protected function mockHttpClient(array $queue): HttpClientInterface
    {
        return new class($queue) implements HttpClientInterface {
            private array $queue;

            public function __construct(array $queue)
            {
                $this->queue = $queue;
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                if (empty($this->queue)) {
                    throw new \RuntimeException('HTTP queue empty for URL: ' . $url);
                }
                $item   = array_shift($this->queue);
                $status = $item['status'] ?? 200;
                $json   = $item['json']   ?? '{}';
                $delay  = $item['delay']  ?? 0;

                return new class($status, $json, $delay) implements ResponseInterface {
                    private int $status;
                    private string $json;
                    private int $delay;

                    public function __construct(int $status, string $json, int $delay)
                    {
                        $this->status = $status;
                        $this->json   = $json;
                        $this->delay  = $delay;
                    }

                    public function getStatusCode(): int
                    {
                        return $this->status;
                    }

                    public function getHeaders(bool $throw = true): array
                    {
                        return [];
                    }

                    public function getContent(bool $throw = true): string
                    {
                        return $this->json;
                    }

                    public function toArray(bool $throw = true): array
                    {
                        return json_decode($this->json, true, 512, JSON_THROW_ON_ERROR);
                    }

                    public function cancel(): void
                    {
                    }

                    public function getInfo(?string $type = null): mixed
                    {
                        return null;
                    }
                };
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                $gen = (function () { if (false) { yield; } })();

                return new \Symfony\Component\HttpClient\Response\ResponseStream($gen);
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };
    }
}
