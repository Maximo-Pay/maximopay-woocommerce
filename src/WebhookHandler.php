<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce;

use MaximoPay\Exception\SignatureVerificationException;
use MaximoPay\Webhook;

/** Recebe a entrega crua, verifica e aplica. Devolve [status HTTP, resultado]. */
final class WebhookHandler
{
    /** @param callable(string): ?\WC_Order $findOrder */
    public function __construct(
        private readonly string $secret,
        private readonly OrderUpdater $updater,
        private $findOrder,
        private readonly ?int $now = null,
    ) {
    }

    /**
     * @param array<string, string|null> $headers
     * @return array{int, string}
     */
    public function handle(string $rawBody, array $headers): array
    {
        if ($this->secret === '') {
            // Sem segredo não dá para confiar em nada; 503 faz o Máximo Pay tentar de novo.
            return [503, 'not_configured'];
        }
        try {
            $event = Webhook::constructEvent($rawBody, array_filter($headers, 'is_string'), $this->secret, now: $this->now);
        } catch (SignatureVerificationException) {
            return [400, 'invalid_signature'];
        }
        $reference = $event->data['referenceId'] ?? null;
        if (!str_starts_with($event->type, 'charge.') || !is_string($reference) || !ctype_digit($reference)) {
            // Responde 2xx para o que não é desta loja: senão o webhook seria pausado.
            return [200, 'ignored'];
        }
        $order = ($this->findOrder)($reference);
        return [200, $this->updater->apply($event, $order instanceof \WC_Order ? $order : null)];
    }
}
