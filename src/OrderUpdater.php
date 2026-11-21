<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce;

use MaximoPay\WebhookEvent;

/** Aplica um webhook verificado ao pedido. Devolve o que fez (vai na resposta e nos testes). */
final class OrderUpdater
{
    private const MAX_DELIVERIES = 50;

    public function apply(WebhookEvent $event, ?\WC_Order $order): string
    {
        $chargeId = (string) ($event->data['id'] ?? '');
        if ($order === null || $order->get_payment_method() !== PixGateway::ID || $chargeId === '') {
            return 'ignored';
        }
        $charges = OrderMeta::charges($order);
        if (!array_key_exists($chargeId, $charges)) {
            // Cobrança de outro sistema com o mesmo referenceId: não é nossa.
            return 'ignored';
        }
        $deliveries = $order->get_meta(OrderMeta::DELIVERIES);
        $deliveries = is_array($deliveries) ? $deliveries : [];
        if ($event->id !== '' && in_array($event->id, $deliveries, true)) {
            return 'duplicate';
        }

        $outcome = match ($event->type) {
            'charge.paid' => $this->paid($order, $chargeId, $charges[$chargeId], (string) ($event->data['amount'] ?? '')),
            'charge.expired', 'charge.cancelled' => $this->closed($order, $chargeId, 'cancelled', $event->type === 'charge.expired'
                ? __('O Pix expirou sem pagamento.', 'maximopay-woocommerce')
                : __('A cobrança Pix foi cancelada.', 'maximopay-woocommerce')),
            'charge.failed' => $this->closed($order, $chargeId, 'failed', __('A cobrança Pix falhou.', 'maximopay-woocommerce')),
            'charge.refunded' => $this->refunded($order, $chargeId, (int) ($event->data['refundAmount'] ?? 0), (string) ($event->data['status'] ?? '')),
            default => 'ignored',
        };

        if ($event->id !== '') {
            $deliveries[] = $event->id;
            $order->update_meta_data(OrderMeta::DELIVERIES, array_slice($deliveries, -self::MAX_DELIVERIES));
            $order->save();
        }
        return $outcome;
    }

    private function paid(\WC_Order $order, string $chargeId, int $expectedCents, string $amount): string
    {
        if ($order->is_paid()) {
            if ($order->get_transaction_id() !== $chargeId) {
                $order->add_order_note(sprintf(
                    /* translators: %s: id da cobrança */
                    __('Pagamento em duplicidade: a cobrança Pix %s também foi paga. Devolva pelo painel do Máximo Pay.', 'maximopay-woocommerce'),
                    $chargeId,
                ));
                return 'duplicate_payment';
            }
            return 'already_paid';
        }
        if ($amount !== (string) $expectedCents) {
            $order->add_order_note(sprintf(
                /* translators: 1: valor pago em centavos, 2: valor esperado em centavos */
                __('Pix pago com valor diferente do pedido (%1$s centavos, esperado %2$s). Confira antes de enviar.', 'maximopay-woocommerce'),
                $amount,
                (string) $expectedCents,
            ));
            $order->update_status('on-hold');
            return 'amount_mismatch';
        }
        $order->update_meta_data(OrderMeta::CHARGE_ID, $chargeId);
        $order->payment_complete($chargeId);
        $order->add_order_note(sprintf(
            /* translators: %s: id da cobrança */
            __('Pix pago (cobrança %s).', 'maximopay-woocommerce'),
            $chargeId,
        ));
        return 'paid';
    }

    private function closed(\WC_Order $order, string $chargeId, string $status, string $note): string
    {
        // Só a cobrança atual fecha o pedido: uma tentativa antiga expirando não cancela a nova.
        if ($order->get_meta(OrderMeta::CHARGE_ID) !== $chargeId || !$order->has_status(['pending', 'on-hold'])) {
            return 'ignored';
        }
        $order->update_status($status, $note);
        return $status;
    }

    private function refunded(\WC_Order $order, string $chargeId, int $refundCents, string $status): string
    {
        if ($refundCents <= 0) {
            return 'ignored';
        }
        $confirmed = (int) $order->get_meta(OrderMeta::REFUNDED_CENTS) + $refundCents;
        $order->update_meta_data(OrderMeta::REFUNDED_CENTS, $confirmed);
        $order->add_order_note(sprintf(
            /* translators: %s: valor devolvido */
            __('Devolução Pix de R$ %s confirmada pelo Máximo Pay.', 'maximopay-woocommerce'),
            number_format($refundCents / 100, 2, ',', '.'),
        ));
        $order->save();
        // Devolução feita fora da loja (painel do Máximo Pay): registra no pedido.
        $missing = $confirmed - Money::toCents((string) $order->get_total_refunded());
        if ($missing > 0) {
            $refund = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => Money::fromCents($missing),
                'reason' => __('Devolução feita no Máximo Pay', 'maximopay-woocommerce'),
                'refund_payment' => false,
            ]);
            if (is_wp_error($refund)) {
                $order->add_order_note(sprintf(
                    /* translators: %s: mensagem de erro */
                    __('Não foi possível registrar a devolução no pedido: %s', 'maximopay-woocommerce'),
                    $refund->get_error_message(),
                ));
            }
        }
        return $status === 'REFUNDED' ? 'refunded' : 'partially_refunded';
    }
}
