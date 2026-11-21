=== Máximo Pay Pix para WooCommerce ===
Tags: pix, pagamento, woocommerce, brasil
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: Proprietary

Receba por Pix com o Máximo Pay. O pedido é confirmado sozinho pelo webhook assinado.

== Description ==

* Gera o Pix na finalização do pedido e mostra QR Code, copia e cola e link da página de pagamento.
* Confirma o pedido quando o Pix é pago, cancela quando expira (o estoque volta) e registra devoluções.
* Devolução total ou parcial pelo botão "Reembolsar" do pedido.
* Compatível com HPOS. O checkout em blocos ainda não é suportado: use o checkout clássico.

== Installation ==

1. Envie o .zip em Plugins › Adicionar novo › Enviar plugin e ative.
2. Em WooCommerce › Configurações › Pagamentos › Máximo Pay Pix, preencha a URL da API, App ID e App Secret.
3. Cadastre o webhook no painel do Máximo Pay com a URL e os eventos mostrados na tela de configuração e cole o mesmo segredo.
