<?php

declare(strict_types=1);

namespace MaximoPay\WooCommerce\Tests;

use MaximoPay\WooCommerce\ChargeRequest;
use MaximoPay\WooCommerce\Document;
use MaximoPay\WooCommerce\Money;

final class HelpersTest extends TestCase
{
    public function testDocumentoAceitaCpfECnpjComMascara(): void
    {
        self::assertSame('12345678909', Document::normalize('123.456.789-09'));
        self::assertSame('11222333000181', Document::normalize('11.222.333/0001-81'));
    }

    public function testDocumentoRecusaDigitoErradoERepetido(): void
    {
        foreach (['123.456.789-00', '111.111.111-11', '11.222.333/0001-82', '00000000000000', '1234', '', null] as $bad) {
            self::assertNull(Document::normalize($bad), (string) $bad);
        }
    }

    public function testCentavosSemErroDePontoFlutuante(): void
    {
        self::assertSame(1590, Money::toCents('15.90'));
        self::assertSame(1, Money::toCents('0.01'));
        self::assertSame(1000, Money::toCents('10'));
        self::assertSame(1999, Money::toCents('19.99'));
        self::assertSame(29, Money::toCents(0.29));
        self::assertSame(1001, Money::toCents('10.005'));
        self::assertSame('15.90', Money::fromCents(1590));
    }

    public function testCorpoDaCobrancaAPartirDoPedido(): void
    {
        $params = ChargeRequest::fromOrder(new \WC_Order(42, '15.90'), '12345678909', 30);
        self::assertSame([
            'amount' => 1590,
            'paymentMethod' => 'PIX',
            'referenceId' => '42',
            'customer' => ['name' => 'João Silva', 'document' => '12345678909', 'email' => 'joao@email.com', 'phone' => '11999990000'],
            'paymentDetails' => ['expirationMinutes' => 30],
        ], $params);
    }

    public function testCnpjUsaARazaoSocialETelefoneInvalidoFicaDeFora(): void
    {
        $order = new \WC_Order();
        $order->billing['company'] = 'Loja Ltda';
        $order->billing['phone'] = '123';
        $customer = ChargeRequest::fromOrder($order, '11222333000181', 30)['customer'];
        self::assertSame('Loja Ltda', $customer['name']);
        self::assertArrayNotHasKey('phone', $customer);
    }
}
