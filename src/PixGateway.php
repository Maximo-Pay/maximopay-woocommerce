<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce;

use MaximoPay\Client;
use MaximoPay\Exception\ConnectionException;
use MaximoPay\Exception\MaximoPayException;

/** Forma de pagamento "Pix" do WooCommerce, cobrando pelo Máximo Pay. */
class PixGateway extends \WC_Payment_Gateway
{
    public const ID = 'maximopay_pix';
    /** Eventos que a loja precisa receber no webhook. */
    public const EVENTS = ['charge.paid', 'charge.expired', 'charge.cancelled', 'charge.failed', 'charge.refunded'];

    private ?Client $client;

    public function __construct(?Client $client = null)
    {
        $this->id = self::ID;
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = 'Máximo Pay Pix';
        $this->method_description = __('Receba por Pix com o Máximo Pay. O pedido é confirmado sozinho quando o Pix é pago.', 'maximopay-woocommerce');
        $this->supports = ['products', 'refunds'];
        $this->client = $client;

        $this->init_form_fields();
        $this->init_settings();
        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $webhookUrl = function_exists('rest_url') ? rest_url('maximopay/v1/webhook') : '/wp-json/maximopay/v1/webhook';
        $this->form_fields = [
            'enabled' => [
                'title' => __('Ativar', 'maximopay-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Receber por Pix com o Máximo Pay', 'maximopay-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Título', 'maximopay-woocommerce'),
                'type' => 'text',
                'default' => 'Pix',
            ],
            'description' => [
                'title' => __('Descrição', 'maximopay-woocommerce'),
                'type' => 'textarea',
                'default' => __('Pague com Pix. O código aparece depois de finalizar o pedido e a confirmação é imediata.', 'maximopay-woocommerce'),
            ],
            'base_url' => [
                'title' => __('URL da API', 'maximopay-woocommerce'),
                'type' => 'text',
                'description' => __('Com /v1 no fim, ex. https://api.seudominio.com.br/v1. Use a URL do sandbox para testar.', 'maximopay-woocommerce'),
                'default' => '',
            ],
            'app_id' => [
                'title' => __('App ID', 'maximopay-woocommerce'),
                'type' => 'text',
                'description' => __('Criado no painel do Máximo Pay, em Chaves de API, com permissão de depósito.', 'maximopay-woocommerce'),
                'default' => '',
            ],
            'app_secret' => [
                'title' => __('App Secret', 'maximopay-woocommerce'),
                'type' => 'password',
                'default' => '',
            ],
            'webhook_secret' => [
                'title' => __('Segredo do webhook', 'maximopay-woocommerce'),
                'type' => 'password',
                'description' => sprintf(
                    /* translators: 1: URL do webhook, 2: lista de eventos */
                    __('Cadastre no painel do Máximo Pay um webhook para %1$s com os eventos %2$s e cole aqui o mesmo segredo (32 a 256 caracteres).', 'maximopay-woocommerce'),
                    '<code>' . esc_html($webhookUrl) . '</code>',
                    '<code>' . esc_html(implode(', ', self::EVENTS)) . '</code>',
                ),
                'default' => '',
            ],
            'expiration_minutes' => [
                'title' => __('Validade do Pix (minutos)', 'maximopay-woocommerce'),
                'type' => 'number',
                'description' => __('Depois disso o pedido é cancelado e o estoque volta.', 'maximopay-woocommerce'),
                'default' => '30',
                'custom_attributes' => ['min' => '5', 'max' => '1440', 'step' => '1'],
            ],
        ];
    }

    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }
        foreach (['base_url', 'app_id', 'app_secret', 'webhook_secret'] as $key) {
            if (trim((string) $this->get_option($key)) === '') {
                return false;
            }
        }
        return get_woocommerce_currency() === 'BRL';
    }

    /** Campo de CPF/CNPJ, a menos que a loja já tenha um no checkout (Brazilian Market). */
    public function payment_fields(): void
    {
        if ($this->description !== '') {
            echo wpautop(wp_kses_post($this->description));
        }
        if (self::hasBrazilianMarketFields()) {
            return;
        }
        printf(
            '<p class="form-row form-row-wide"><label for="maximopay_document">%s <span class="required">*</span></label><input id="maximopay_document" name="maximopay_document" type="text" inputmode="numeric" autocomplete="off" class="input-text" /></p>',
            esc_html__('CPF ou CNPJ do pagador', 'maximopay-woocommerce'),
        );
    }

    public function validate_fields(): bool
    {
        if (self::documentFromRequest() === null) {
            wc_add_notice(__('Informe um CPF ou CNPJ válido para pagar com Pix.', 'maximopay-woocommerce'), 'error');
            return false;
        }
        return true;
    }

    /** @return array{result: string, redirect?: string} */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return ['result' => 'failure'];
        }
        $amount = Money::toCents($order->get_total());

        // Clique duplo ou volta do navegador: reaproveita o Pix ainda válido do mesmo valor.
        $current = (string) $order->get_meta(OrderMeta::CHARGE_ID);
        $charges = OrderMeta::charges($order);
        $expiresAt = (int) $order->get_meta(OrderMeta::EXPIRES_AT);
        if ($current !== '' && ($charges[$current] ?? null) === $amount && $expiresAt > time() + 60 && $order->has_status(['pending', 'on-hold'])) {
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        $document = self::documentFromRequest() ?? Document::normalize((string) $order->get_meta('_billing_cpf')) ?? Document::normalize((string) $order->get_meta('_billing_cnpj'));
        if ($document === null) {
            wc_add_notice(__('Informe um CPF ou CNPJ válido para pagar com Pix.', 'maximopay-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        $attempt = (int) $order->get_meta(OrderMeta::ATTEMPT) + 1;
        $order->update_meta_data(OrderMeta::ATTEMPT, $attempt);
        $order->save();
        $params = ChargeRequest::fromOrder($order, $document, $this->expirationMinutes());

        try {
            // Mesma chave nas repetições automáticas; outra tentativa do cliente gera outra cobrança.
            $charge = $this->client()->charges->create($params, sprintf('wc-%d-%d', $order->get_id(), $attempt));
        } catch (MaximoPayException $e) {
            $this->log('error', sprintf('Pedido %d: %s %s (%s)', $order->get_id(), $e->statusCode, $e->errorCode, $e->correlationId ?? '-'));
            $order->add_order_note(sprintf(
                /* translators: 1: código do erro, 2: mensagem */
                __('Máximo Pay recusou a cobrança Pix: %1$s (%2$s).', 'maximopay-woocommerce'),
                $e->errorCode,
                $e->getMessage(),
            ));
            wc_add_notice(self::customerMessage($e), 'error');
            return ['result' => 'failure'];
        } catch (ConnectionException $e) {
            $this->log('error', sprintf('Pedido %d: %s', $order->get_id(), $e->getMessage()));
            wc_add_notice(__('Não conseguimos gerar o Pix agora. Tente de novo em alguns instantes.', 'maximopay-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        $chargeId = (string) ($charge['id'] ?? '');
        $metadata = is_array($charge['metadata'] ?? null) ? $charge['metadata'] : [];
        $charges[$chargeId] = $amount;
        $order->update_meta_data(OrderMeta::CHARGE_ID, $chargeId);
        $order->update_meta_data(OrderMeta::CHARGES, $charges);
        $order->update_meta_data(OrderMeta::PIX_CODE, (string) ($metadata['pixCopiaECola'] ?? ''));
        $order->update_meta_data(OrderMeta::CHECKOUT_URL, (string) ($charge['checkoutUrl'] ?? ''));
        $expiration = isset($charge['expirationDate']) ? strtotime((string) $charge['expirationDate']) : false;
        $order->update_meta_data(OrderMeta::EXPIRES_AT, $expiration !== false ? $expiration : time() + 60 * $this->expirationMinutes());
        $order->set_transaction_id($chargeId);
        // on-hold baixa o estoque; o cancelamento por Pix expirado devolve.
        $order->update_status('on-hold', sprintf(
            /* translators: %s: id da cobrança */
            __('Aguardando pagamento do Pix (cobrança %s).', 'maximopay-woocommerce'),
            $chargeId,
        ));
        if (function_exists('WC') && WC()->cart !== null) {
            WC()->cart->empty_cart();
        }
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    /**
     * @param int $order_id
     * @param float|string|null $amount
     * @param string $reason
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        $chargeId = $order instanceof \WC_Order ? (string) $order->get_transaction_id() : '';
        if ($chargeId === '') {
            return new \WP_Error('maximopay_refund', __('Pedido sem cobrança Pix do Máximo Pay.', 'maximopay-woocommerce'));
        }
        $params = [];
        if ($amount !== null && $amount !== '') {
            $params['amount'] = Money::toCents((string) $amount);
        }
        if ($reason !== '') {
            $params['reason'] = mb_substr((string) $reason, 0, 140);
        }
        // A devolução já existe no pedido quando o Woo chama este método: a contagem fica estável numa repetição.
        $key = sprintf('wc-refund-%d-%d', $order->get_id(), count($order->get_refunds()));
        try {
            $this->client()->charges->refund($chargeId, $params, $key);
        } catch (MaximoPayException $e) {
            return new \WP_Error('maximopay_refund', sprintf('%s (%s)', $e->getMessage(), $e->errorCode));
        } catch (ConnectionException $e) {
            return new \WP_Error('maximopay_refund', $e->getMessage());
        }
        $order->add_order_note(__('Devolução Pix pedida ao Máximo Pay; a confirmação chega pelo webhook.', 'maximopay-woocommerce'));
        return true;
    }

    public function webhookSecret(): string
    {
        return (string) $this->get_option('webhook_secret');
    }

    private function expirationMinutes(): int
    {
        return max(5, min(1440, (int) $this->get_option('expiration_minutes', '30')));
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            (string) $this->get_option('app_id'),
            (string) $this->get_option('app_secret'),
            (string) $this->get_option('base_url'),
            ['timeout' => 20.0],
        );
    }

    private static function customerMessage(MaximoPayException $e): string
    {
        return match (true) {
            $e->errorCode === 'VALIDATION_ERROR' => __('Confira seus dados (nome, e-mail e CPF/CNPJ) e tente de novo.', 'maximopay-woocommerce'),
            $e->statusCode >= 500, $e->statusCode === 429 => __('Não conseguimos gerar o Pix agora. Tente de novo em alguns instantes.', 'maximopay-woocommerce'),
            default => __('O Pix não está disponível para este pedido. Escolha outra forma de pagamento.', 'maximopay-woocommerce'),
        };
    }

    private static function documentFromRequest(): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- o checkout do Woo já confere o nonce.
        foreach (['maximopay_document', 'billing_cpf', 'billing_cnpj'] as $field) {
            if (isset($_POST[$field])) {
                $document = Document::normalize(sanitize_text_field(wp_unslash((string) $_POST[$field])));
                if ($document !== null) {
                    return $document;
                }
            }
        }
        return null;
    }

    private static function hasBrazilianMarketFields(): bool
    {
        return class_exists('Extra_Checkout_Fields_For_Brazil');
    }

    private function log(string $level, string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'maximopay']);
        }
    }
}
