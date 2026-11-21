<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce\Tests;

use MaximoPay\Exception\ConnectionException;
use MaximoPay\Http\Response;
use MaximoPay\Http\Transport;

/** Responde /auth/token sozinho e o resto da fila; guarda cada requisição. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    /** @var list<Response|ConnectionException> */
    private array $queue = [];

    /** @param array<string, mixed> $body */
    public function push(int $status, array $body = []): self
    {
        $this->queue[] = new Response($status, [], (string) json_encode($body));
        return $this;
    }

    public function fail(): self
    {
        $this->queue[] = new ConnectionException('ECONNRESET');
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response
    {
        if (str_ends_with($url, '/auth/token')) {
            return new Response(201, [], '{"accessToken":"tok","expiresIn":3600}');
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue) ?? throw new \LogicException("Sem resposta para $method $url");
        if ($next instanceof ConnectionException) {
            throw $next;
        }
        return $next;
    }

    /** @return array<string, mixed> */
    public function body(int $i): array
    {
        return json_decode((string) $this->requests[$i]['body'], true);
    }
}
