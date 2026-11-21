<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use MaximoPay\WooCommerce\OrderMeta;
use MaximoPay\WooCommerce\OrderUpdater;
use MaximoPay\WooCommerce\WebhookHandler;

final class WebhookTest extends TestCase
{
    private const NOW = 1800000000;
    private \WC_Order $order;
    /** @var list<array<string, mixed>> */
    private array $createdRefunds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->order = new \WC_Order(42, '15.90');
        $this->order->status = 'on-hold';
        $this->order->meta[OrderMeta::CHARGE_ID] = 'ch_2';
        $this->order->meta[OrderMeta::CHARGES] = ['ch_1' => 1590, 'ch_2' => 1590];
        Functions\when('wc_create_refund')->alias(function (array $args) {
            $this->createdRefunds[] = $args;
            $this->order->totalRefunded = $args['amount'];
            return new \stdClass();
        });
    }

    /** @param array<string, mixed> $data */
    private function deliver(string $event, array $data, string $deliveryId = 'd1', ?string $secret = null, ?string $body = null): array
    {
        $body ??= (string) json_encode($data + ['referenceId' => '42']);
        $signature = hash_hmac('sha256', self::NOW . '.' . $body, self::SECRET);
        $handler = new WebhookHandler(
            $secret ?? self::SECRET,
            new OrderUpdater(),
            fn (string $id) => $id === '42' ? $this->order : null,
            self::NOW,
        );
        return $handler->handle($body, ['X-Signature' => 't=' . self::NOW . ',v1=' . $signature, 'X-Event' => $event, 'X-Delivery-Id' => $deliveryId]);
    }

    public function testPagoConfirmaOPedido(): void
    {
        self::assertSame([200, 'paid'], $this->deliver('charge.paid', ['id' => 'ch_2', 'amount' => '1590', 'status' => 'PAID']));
        self::assertTrue($this->order->paid);
        self::assertSame('processing', $this->order->status);
        self::assertSame('ch_2', $this->order->get_transaction_id());
    }

    public function testEntregaRepetidaEIgnorada(): void
    {
        $this->deliver('charge.paid', ['id' => 'ch_2', 'amount' => '1590']);
        self::assertSame([200, 'duplicate'], $this->deliver('charge.paid', ['id' => 'ch_2', 'amount' => '1590']));
    }

    public function testPixAntigoPagoTambemConfirma(): void
    {
        self::assertSame([200, 'paid'], $this->deliver('charge.paid', ['id' => 'ch_1', 'amount' => '1590']));
        self::assertSame('ch_1', $this->order->meta[OrderMeta::CHARGE_ID]);
    }

    public function testSegundoPixPagoViraNotaDeDuplicidade(): void
    {
        $this->deliver('charge.paid', ['id' => 'ch_2', 'amount' => '1590'], 'd1');
        self::assertSame([200, 'duplicate_payment'], $this->deliver('charge.paid', ['id' => 'ch_1', 'amount' => '1590'], 'd2'));
        self::assertStringContainsString('duplicidade', end($this->order->notes));
    }

    public function testValorDiferenteNaoConfirma(): void
    {
        self::assertSame([200, 'amount_mismatch'], $this->deliver('charge.paid', ['id' => 'ch_2', 'amount' => '100']));
        self::assertFalse($this->order->paid);
        self::assertSame('on-hold', $this->order->status);
    }

    public function testExpiradoCancelaSoACobrancaAtual(): void
    {
        self::assertSame([200, 'ignored'], $this->deliver('charge.expired', ['id' => 'ch_1'], 'd1'));
        self::assertSame('on-hold', $this->order->status);
        self::assertSame([200, 'cancelled'], $this->deliver('charge.expired', ['id' => 'ch_2'], 'd2'));
        self::assertSame('cancelled', $this->order->status);
    }

    public function testExpiradoDepoisDePagoNaoMexe(): void
    {
        $this->deliver('charge.paid', ['id' => 'ch_2', 'amount' => '1590'], 'd1');
        self::assertSame([200, 'ignored'], $this->deliver('charge.expired', ['id' => 'ch_2'], 'd2'));
        self::assertSame('processing', $this->order->status);
    }

    public function testFalhaMarcaFalhou(): void
    {
        self::assertSame([200, 'failed'], $this->deliver('charge.failed', ['id' => 'ch_2']));
        self::assertSame('failed', $this->order->status);
    }

    public function testDevolucaoFeitaNaLojaSoGanhaNota(): void
    {
        $this->order->totalRefunded = '5.50';
        self::assertSame([200, 'partially_refunded'], $this->deliver('charge.refunded', ['id' => 'ch_2', 'refundAmount' => '550', 'status' => 'PARTIALLY_REFUNDED']));
        self::assertSame([], $this->createdRefunds);
        self::assertSame(550, $this->order->meta[OrderMeta::REFUNDED_CENTS]);
    }

    public function testDevolucaoFeitaNoPainelERegistradaNoPedido(): void
    {
        self::assertSame([200, 'refunded'], $this->deliver('charge.refunded', ['id' => 'ch_2', 'refundAmount' => '1590', 'status' => 'REFUNDED']));
        self::assertCount(1, $this->createdRefunds);
        self::assertSame('15.90', $this->createdRefunds[0]['amount']);
        self::assertFalse($this->createdRefunds[0]['refund_payment']);
    }

    public function testAssinaturaErradaE400(): void
    {
        $body = (string) json_encode(['id' => 'ch_2', 'amount' => '1590', 'referenceId' => '42']);
        $handler = new WebhookHandler(self::SECRET, new OrderUpdater(), fn () => $this->order, self::NOW);
        self::assertSame([400, 'invalid_signature'], $handler->handle($body, ['X-Signature' => 't=' . self::NOW . ',v1=00', 'X-Event' => 'charge.paid']));
        self::assertSame([400, 'invalid_signature'], $handler->handle($body, []));
        self::assertFalse($this->order->paid);
    }

    public function testSemSegredoE503(): void
    {
        self::assertSame([503, 'not_configured'], $this->deliver('charge.paid', ['id' => 'ch_2', 'amount' => '1590'], secret: ''));
    }

    public function testPedidoDeOutroSistemaOuOutroGatewayE200Ignorado(): void
    {
        self::assertSame([200, 'ignored'], $this->deliver('charge.paid', ['id' => 'ch_9', 'amount' => '1590']));
        self::assertSame([200, 'ignored'], $this->deliver('charge.paid', ['id' => 'x', 'amount' => '1', 'referenceId' => 'pedido-abc']));
        self::assertSame([200, 'ignored'], $this->deliver('withdrawal.completed', ['id' => 'w1']));
        $this->order->paymentMethod = 'bacs';
        self::assertSame([200, 'ignored'], $this->deliver('charge.paid', ['id' => 'ch_2', 'amount' => '1590']));
        self::assertFalse($this->order->paid);
    }
}
