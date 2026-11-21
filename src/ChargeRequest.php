<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce;

/** Monta o corpo de POST /charges a partir de um pedido. */
final class ChargeRequest
{
    /** @return array<string, mixed> */
    public static function fromOrder(\WC_Order $order, string $document, int $expirationMinutes): array
    {
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if (strlen($document) === 14 && $order->get_billing_company() !== '') {
            $name = $order->get_billing_company();
        }
        $customer = [
            'name' => $name !== '' ? $name : $order->get_billing_email(),
            'document' => $document,
            'email' => $order->get_billing_email(),
        ];
        $phone = self::phone($order->get_billing_phone());
        if ($phone !== null) {
            $customer['phone'] = $phone;
        }
        return [
            'amount' => Money::toCents($order->get_total()),
            'paymentMethod' => 'PIX',
            'referenceId' => (string) $order->get_id(),
            'customer' => $customer,
            'paymentDetails' => ['expirationMinutes' => $expirationMinutes],
        ];
    }

    /** Só manda o telefone num formato que a API aceita; senão, nada. */
    private static function phone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        return preg_match('/^(55)?\d{10,11}$/', $digits) === 1 ? $digits : null;
    }
}
