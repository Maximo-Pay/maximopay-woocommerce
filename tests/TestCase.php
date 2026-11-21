<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var list<array{string, string}> */
    protected array $notices = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\stubs([
            'add_action' => true,
            'rest_url' => static fn (string $path = '') => 'https://loja.test/wp-json/' . $path,
            'get_woocommerce_currency' => 'BRL',
            'wpautop' => static fn (string $t) => "<p>$t</p>",
            'wp_kses_post' => static fn (string $t) => $t,
            'sanitize_text_field' => static fn (string $t) => trim(strip_tags($t)),
            'wp_unslash' => static fn ($v) => $v,
            'is_wp_error' => static fn ($v) => $v instanceof \WP_Error,
        ]);
        Functions\when('wc_add_notice')->alias(function (string $message, string $type = 'success'): void {
            $this->notices[] = [$type, $message];
        });
        $_POST = [];
        \WC_Payment_Gateway::$savedSettings = [
            'enabled' => 'yes',
            'base_url' => 'https://api.test/v1',
            'app_id' => 'app',
            'app_secret' => 'sec',
            'webhook_secret' => self::SECRET,
            'expiration_minutes' => '30',
        ];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public const SECRET = 'whsec_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
}
