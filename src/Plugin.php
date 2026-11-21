<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class Plugin
{
    public const VERSION = '0.1.0';

    public static function boot(string $pluginFile): void
    {
        add_action('before_woocommerce_init', static function () use ($pluginFile): void {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $pluginFile, true);
                // O checkout em blocos ainda não tem o campo de CPF/CNPJ: use o checkout clássico.
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $pluginFile, false);
            }
        });
        add_action('plugins_loaded', static function () use ($pluginFile): void {
            if (!class_exists(\WC_Payment_Gateway::class)) {
                add_action('admin_notices', static function (): void {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Máximo Pay Pix precisa do WooCommerce ativo.', 'maximopay-woocommerce') . '</p></div>';
                });
                return;
            }
            load_plugin_textdomain('maximopay-woocommerce', false, dirname(plugin_basename($pluginFile)) . '/languages');
            add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
                $gateways[] = PixGateway::class;
                return $gateways;
            });
            add_action('woocommerce_thankyou_' . PixGateway::ID, [self::class, 'renderPix']);
            add_action('woocommerce_view_order', [self::class, 'renderPixOnAccount']);
            add_action('woocommerce_email_before_order_table', [self::class, 'renderPixInEmail'], 10, 3);
            add_action('wp_enqueue_scripts', static function () use ($pluginFile): void {
                wp_register_script('maximopay-pix', plugins_url('assets/maximopay-pix.js', $pluginFile), [], self::VERSION, true);
            });
            add_filter('plugin_action_links_' . plugin_basename($pluginFile), static function (array $links): array {
                array_unshift($links, sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . PixGateway::ID)),
                    esc_html__('Configurar', 'maximopay-woocommerce'),
                ));
                return $links;
            });
        });
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route('maximopay/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'webhook'],
            // A autenticação é a assinatura X-Signature, conferida no callback.
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('maximopay/v1', '/orders/(?P<id>\d+)/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'orderStatus'],
            'permission_callback' => '__return_true',
            'args' => ['key' => ['required' => true, 'type' => 'string']],
        ]);
    }

    public static function webhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = self::gateway();
        $handler = new WebhookHandler(
            $gateway?->webhookSecret() ?? '',
            new OrderUpdater(),
            static fn (string $id) => wc_get_order((int) $id) ?: null,
        );
        try {
            [$status, $result] = $handler->handle($request->get_body(), [
                'X-Signature' => $request->get_header('x_signature'),
                'X-Event' => $request->get_header('x_event'),
                'X-Delivery-Id' => $request->get_header('x_delivery_id'),
            ]);
        } catch (\Throwable $e) {
            // Erro nosso (banco, plugin): 500 faz o Máximo Pay repetir a entrega.
            wc_get_logger()->error('Webhook: ' . $e->getMessage(), ['source' => 'maximopay']);
            return new \WP_REST_Response(['result' => 'error'], 500);
        }
        return new \WP_REST_Response(['result' => $result], $status);
    }

    /** Consultado pela página de obrigado para recarregar quando o Pix for pago. */
    public static function orderStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        $order = wc_get_order((int) $request['id']);
        if (!$order instanceof \WC_Order || !hash_equals($order->get_order_key(), (string) $request['key'])) {
            return new \WP_REST_Response(['code' => 'not_found'], 404);
        }
        return new \WP_REST_Response(['paid' => $order->is_paid(), 'status' => $order->get_status()], 200);
    }

    public static function renderPix(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== PixGateway::ID) {
            return;
        }
        echo self::pixHtml($order, true); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado em pixHtml
    }

    public static function renderPixOnAccount(int $orderId): void
    {
        self::renderPix($orderId);
    }

    /** @param mixed $sentToAdmin */
    public static function renderPixInEmail(\WC_Order $order, $sentToAdmin = false, bool $plainText = false): void
    {
        if ($sentToAdmin || $order->get_payment_method() !== PixGateway::ID || !$order->has_status('on-hold')) {
            return;
        }
        $code = (string) $order->get_meta(OrderMeta::PIX_CODE);
        $url = (string) $order->get_meta(OrderMeta::CHECKOUT_URL);
        if ($plainText) {
            echo esc_html__('Pix copia e cola:', 'maximopay-woocommerce') . "\n" . $code . "\n" . ($url !== '' ? $url . "\n\n" : "\n");
            return;
        }
        echo self::pixHtml($order, false); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado em pixHtml
    }

    public static function pixHtml(\WC_Order $order, bool $interactive): string
    {
        if ($order->is_paid()) {
            return '<p class="maximopay-pix-paid">' . esc_html__('Pagamento confirmado. Obrigado!', 'maximopay-woocommerce') . '</p>';
        }
        $code = (string) $order->get_meta(OrderMeta::PIX_CODE);
        if ($code === '' || !$order->has_status(['pending', 'on-hold'])) {
            return '';
        }
        $url = (string) $order->get_meta(OrderMeta::CHECKOUT_URL);
        $expiresAt = (int) $order->get_meta(OrderMeta::EXPIRES_AT);
        $html = '<section class="maximopay-pix">';
        $html .= '<h2>' . esc_html__('Pague com Pix', 'maximopay-woocommerce') . '</h2>';
        if ($interactive) {
            $html .= '<p><img class="maximopay-pix-qr" width="240" height="240" alt="' . esc_attr__('QR Code Pix', 'maximopay-woocommerce') . '" src="' . esc_attr(self::qrDataUri($code)) . '" /></p>';
        }
        $html .= '<p>' . esc_html__('Ou copie o código e cole no app do seu banco:', 'maximopay-woocommerce') . '</p>';
        $html .= '<p><textarea class="maximopay-pix-code" readonly rows="3" style="width:100%">' . esc_textarea($code) . '</textarea></p>';
        if ($interactive) {
            $html .= '<p><button type="button" class="button maximopay-pix-copy" data-copied="' . esc_attr__('Copiado!', 'maximopay-woocommerce') . '">' . esc_html__('Copiar código Pix', 'maximopay-woocommerce') . '</button></p>';
        }
        if ($expiresAt > 0) {
            $html .= '<p>' . esc_html(sprintf(
                /* translators: %s: data e hora */
                __('Válido até %s.', 'maximopay-woocommerce'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $expiresAt),
            )) . '</p>';
        }
        if ($url !== '') {
            $html .= '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('Abrir a página de pagamento', 'maximopay-woocommerce') . '</a></p>';
        }
        $html .= '</section>';
        if ($interactive) {
            wp_enqueue_script('maximopay-pix');
            wp_add_inline_script('maximopay-pix', 'window.maximopayPix = ' . wp_json_encode([
                'statusUrl' => add_query_arg('key', $order->get_order_key(), rest_url('maximopay/v1/orders/' . $order->get_id() . '/status')),
            ]) . ';', 'before');
        }
        return $html;
    }

    private static function qrDataUri(string $code): string
    {
        // Padrão da biblioteca: SVG em data URI, com margem.
        return (new QRCode(new QROptions()))->render($code);
    }

    private static function gateway(): ?PixGateway
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = $gateways[PixGateway::ID] ?? null;
        return $gateway instanceof PixGateway ? $gateway : null;
    }
}
