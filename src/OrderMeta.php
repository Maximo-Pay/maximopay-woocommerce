<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce;

/** Chaves de meta do pedido (compatível com HPOS: sempre via get_meta/update_meta_data). */
final class OrderMeta
{
    public const CHARGE_ID = '_maximopay_charge_id';
    /** Todas as cobranças do pedido: id => valor em centavos. */
    public const CHARGES = '_maximopay_charges';
    public const PIX_CODE = '_maximopay_pix_code';
    public const CHECKOUT_URL = '_maximopay_checkout_url';
    public const EXPIRES_AT = '_maximopay_expires_at';
    public const ATTEMPT = '_maximopay_attempt';
    /** Últimos X-Delivery-Id processados. */
    public const DELIVERIES = '_maximopay_deliveries';
    /** Centavos devolvidos confirmados pelo webhook. */
    public const REFUNDED_CENTS = '_maximopay_refunded_cents';

    /** @return array<string, int> */
    public static function charges(\WC_Order $order): array
    {
        $value = $order->get_meta(self::CHARGES);
        return is_array($value) ? $value : [];
    }
}
