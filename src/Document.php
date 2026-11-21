<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce;

/** CPF/CNPJ: só dígitos, com dígitos verificadores conferidos. */
final class Document
{
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if (strlen($digits) === 11 && self::validCpf($digits)) {
            return $digits;
        }
        if (strlen($digits) === 14 && self::validCnpj($digits)) {
            return $digits;
        }
        return null;
    }

    private static function validCpf(string $d): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $d) === 1) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * (($t + 1) - $i);
            }
            $check = ((10 * $sum) % 11) % 10;
            if ((int) $d[$t] !== $check) {
                return false;
            }
        }
        return true;
    }

    private static function validCnpj(string $d): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $d) === 1) {
            return false;
        }
        foreach ([12, 13] as $length) {
            $weights = $length === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $d[$i] * $weights[$i];
            }
            $rest = $sum % 11;
            $check = $rest < 2 ? 0 : 11 - $rest;
            if ((int) $d[$length] !== $check) {
                return false;
            }
        }
        return true;
    }
}
