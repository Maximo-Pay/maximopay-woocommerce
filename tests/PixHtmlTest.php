<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use MaximoPay\WooCommerce\OrderMeta;
use MaximoPay\WooCommerce\Plugin;

final class PixHtmlTest extends TestCase
{
    /** @var list<string> */
    private array $scripts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubs([
            'esc_textarea' => static fn (string $t) => htmlspecialchars($t),
            'get_option' => static fn (string $k) => $k === 'date_format' ? 'd/m/Y' : 'H:i',
            'wp_date' => static fn (string $f, int $t) => gmdate($f, $t),
            'wp_json_encode' => static fn ($v) => json_encode($v),
            'add_query_arg' => static fn (string $k, string $v, string $url) => "$url?$k=$v",
        ]);
        Functions\when('wp_enqueue_script')->alias(function (string $handle): void {
            $this->scripts[] = $handle;
        });
        Functions\when('wp_add_inline_script')->alias(function (string $handle, string $js): void {
            $this->scripts[] = $js;
        });
    }

    private static function order(): \WC_Order
    {
        $order = new \WC_Order(42);
        $order->status = 'on-hold';
        $order->meta[OrderMeta::PIX_CODE] = '00020126<pix>';
        $order->meta[OrderMeta::CHECKOUT_URL] = 'https://pay.test/c/ch_1';
        $order->meta[OrderMeta::EXPIRES_AT] = 1800000000;
        return $order;
    }

    public function testPaginaDeObrigadoMostraQrCodigoELink(): void
    {
        $html = Plugin::pixHtml(self::order(), true);
        self::assertStringContainsString('src="data:image/svg+xml;base64,', $html);
        self::assertStringContainsString('00020126&lt;pix&gt;', $html);
        self::assertStringContainsString('https://pay.test/c/ch_1', $html);
        self::assertStringContainsString('15/01/2027', $html);
        self::assertSame('maximopay-pix', $this->scripts[0]);
        self::assertStringContainsString('maximopay\/v1\/orders\/42\/status?key=wc_order_abc', $this->scripts[1]);
    }

    public function testEmailNaoLevaQrNemScript(): void
    {
        $html = Plugin::pixHtml(self::order(), false);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('00020126&lt;pix&gt;', $html);
        self::assertSame([], $this->scripts);
    }

    public function testPagoMostraConfirmacaoECanceladoNada(): void
    {
        $order = self::order();
        $order->paid = true;
        self::assertStringContainsString('Pagamento confirmado', Plugin::pixHtml($order, true));
        $order = self::order();
        $order->status = 'cancelled';
        self::assertSame('', Plugin::pixHtml($order, true));
    }
}
