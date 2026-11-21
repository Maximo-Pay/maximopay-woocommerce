<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce;

final class Money
{
    /** "15.90" (WooCommerce) → 1590 centavos, sem erro de ponto flutuante. */
    public static function toCents(string|float|int $amount): int
    {
        $text = is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', '');
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $text, $m) !== 1) {
            throw new \InvalidArgumentException("Valor inválido: $text");
        }
        $fraction = str_pad(substr($m[3] ?? '', 0, 3), 3, '0');
        $cents = (int) $m[2] * 100 + intdiv((int) $fraction + 5, 10);
        return $m[1] === '-' ? -$cents : $cents;
    }

    /** 1590 → "15.90", o formato que wc_create_refund e wc_price aceitam. */
    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
