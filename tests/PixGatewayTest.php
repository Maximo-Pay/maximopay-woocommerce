<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce\Tests;

use Brain\Monkey\Functions;
use MaximoPay\Client;
use MaximoPay\WooCommerce\OrderMeta;
use MaximoPay\WooCommerce\PixGateway;

final class PixGatewayTest extends TestCase
{
    private FakeTransport $transport;
    private \WC_Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
        $this->order = new \WC_Order(42, '15.90');
        Functions\when('wc_get_order')->justReturn($this->order);
    }

    private function gateway(): PixGateway
    {
        return new PixGateway(new Client('app', 'sec', 'https://api.test/v1', [
            'transport' => $this->transport,
            'sleep' => static function (): void {
            },
        ]));
    }

    private static function charge(string $id = 'ch_1'): array
    {
        return [
            'id' => $id,
            'status' => 'PENDING',
            'expirationDate' => '2099-01-01T00:30:00.000Z',
            'checkoutUrl' => 'https://pay.test/c/' . $id,
            'metadata' => ['pixCopiaECola' => '00020126pix-' . $id],
        ];
    }

    public function testCriaOPixEDeixaOPedidoAguardando(): void
    {
        $_POST['maximopay_document'] = '123.456.789-09';
        $this->transport->push(201, self::charge());

        $result = $this->gateway()->process_payment(42);

        self::assertSame(['result' => 'success', 'redirect' => 'https://loja.test/obrigado/42'], $result);
        self::assertSame('on-hold', $this->order->status);
        self::assertSame('ch_1', $this->order->get_transaction_id());
        self::assertSame(['ch_1' => 1590], $this->order->meta[OrderMeta::CHARGES]);
        self::assertSame('00020126pix-ch_1', $this->order->meta[OrderMeta::PIX_CODE]);
        self::assertSame('https://pay.test/c/ch_1', $this->order->meta[OrderMeta::CHECKOUT_URL]);
        self::assertSame(strtotime('2099-01-01T00:30:00Z'), $this->order->meta[OrderMeta::EXPIRES_AT]);
        $req = $this->transport->requests[0];
        self::assertSame('https://api.test/v1/charges', $req['url']);
        self::assertSame('wc-42-1', $req['headers']['Idempotency-Key']);
        self::assertSame('12345678909', $this->transport->body(0)['customer']['document']);
        self::assertSame('BRL', $this->transport->body(0)['currencyCode']);
    }

    public function testCliqueDuploReaproveitaOPixValido(): void
    {
        $_POST['maximopay_document'] = '12345678909';
        $this->transport->push(201, self::charge());
        $gateway = $this->gateway();
        $gateway->process_payment(42);
        self::assertSame('success', $gateway->process_payment(42)['result']);
        self::assertCount(1, $this->transport->requests);
    }

    public function testValorMudouGeraOutraCobrancaComOutraChave(): void
    {
        $_POST['maximopay_document'] = '12345678909';
        $this->transport->push(201, self::charge('ch_1'))->push(201, self::charge('ch_2'));
        $gateway = $this->gateway();
        $gateway->process_payment(42);
        $this->order->total = '20.00';
        $gateway->process_payment(42);
        self::assertSame('wc-42-2', $this->transport->requests[1]['headers']['Idempotency-Key']);
        self::assertSame(['ch_1' => 1590, 'ch_2' => 2000], $this->order->meta[OrderMeta::CHARGES]);
        self::assertSame('ch_2', $this->order->meta[OrderMeta::CHARGE_ID]);
    }

    public function testUsaOCpfDoBrazilianMarket(): void
    {
        $_POST['billing_cpf'] = '123.456.789-09';
        $this->transport->push(201, self::charge());
        $this->gateway()->process_payment(42);
        self::assertSame('12345678909', $this->transport->body(0)['customer']['document']);
    }

    public function testDocumentoInvalidoNaoChamaAApi(): void
    {
        $_POST['maximopay_document'] = '111.111.111-11';
        $gateway = $this->gateway();
        self::assertFalse($gateway->validate_fields());
        self::assertSame('failure', $gateway->process_payment(42)['result']);
        self::assertCount(0, $this->transport->requests);
        self::assertSame('error', $this->notices[0][0]);
    }

    public function testRecusaDaApiViraAvisoENotaSemTrocarOStatus(): void
    {
        $_POST['maximopay_document'] = '12345678909';
        $this->transport->push(422, ['statusCode' => 422, 'errorCode' => 'KYC_LIMIT_EXCEEDED', 'message' => 'Limite diário']);

        self::assertSame(['result' => 'failure'], $this->gateway()->process_payment(42));
        self::assertSame('pending', $this->order->status);
        self::assertStringContainsString('KYC_LIMIT_EXCEEDED', $this->order->notes[0]);
        self::assertStringContainsString('outra forma de pagamento', $this->notices[0][1]);
    }

    public function testFalhaDeRedeRepeteComAMesmaChaveEDepoisAvisa(): void
    {
        $_POST['maximopay_document'] = '12345678909';
        $this->transport->fail()->fail()->fail();
        self::assertSame(['result' => 'failure'], $this->gateway()->process_payment(42));
        self::assertCount(3, $this->transport->requests);
        self::assertCount(1, array_unique(array_map(static fn ($r) => $r['headers']['Idempotency-Key'], $this->transport->requests)));
        self::assertStringContainsString('Tente de novo', $this->notices[0][1]);
    }

    public function testDevolucaoParcialChamaRefundComCentavos(): void
    {
        $this->order->transactionId = 'ch_1';
        $this->order->refunds = [new \stdClass()];
        $this->transport->push(200, ['id' => 'ch_1', 'status' => 'REFUND_PENDING']);

        self::assertTrue($this->gateway()->process_refund(42, '5.50', 'Produto com defeito'));
        $req = $this->transport->requests[0];
        self::assertSame('https://api.test/v1/charges/ch_1/refund', $req['url']);
        self::assertSame('wc-refund-42-1', $req['headers']['Idempotency-Key']);
        self::assertSame(['amount' => 550, 'reason' => 'Produto com defeito'], $this->transport->body(0));
    }

    public function testDevolucaoRecusadaVoltaWpError(): void
    {
        $this->order->transactionId = 'ch_1';
        $this->transport->push(422, ['errorCode' => 'SPLIT_PARTY_INSUFFICIENT_FUNDS', 'message' => 'Sem saldo']);
        $result = $this->gateway()->process_refund(42, '5.50');
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertStringContainsString('SPLIT_PARTY_INSUFFICIENT_FUNDS', $result->get_error_message());
    }

    public function testSoAparecePronto(): void
    {
        self::assertTrue($this->gateway()->is_available());
        \WC_Payment_Gateway::$savedSettings['webhook_secret'] = '';
        self::assertFalse($this->gateway()->is_available());
        \WC_Payment_Gateway::$savedSettings['webhook_secret'] = self::SECRET;
        Functions\when('get_woocommerce_currency')->justReturn('USD');
        self::assertFalse($this->gateway()->is_available());
    }
}
