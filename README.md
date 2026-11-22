# Máximo Pay Pix para WooCommerce

Plugin que adiciona **Pix pelo Máximo Pay** como forma de pagamento do WooCommerce, usando o [SDK PHP](https://github.com/Maximo-Pay/maximopay-sdk-php).

## Como funciona

1. **Finalização do pedido.** O cliente informa o CPF ou CNPJ, ou o plugin usa o campo do Brazilian Market quando ele está ativo. O plugin cria a cobrança Pix com `referenceId` = id do pedido e `Idempotency-Key` = `wc-<pedido>-<tentativa>`. O pedido fica **Aguardando** (`on-hold`), o que já baixa o estoque.
2. **Página de obrigado.** Mostra o QR Code, o copia e cola com botão de copiar, a validade e o link da página de pagamento do Máximo Pay. A página consulta o status a cada 5 s e recarrega quando o Pix é pago. O e-mail de pedido aguardando leva o copia e cola e o link.
3. **Webhook** em `POST /wp-json/maximopay/v1/webhook`. Confere a assinatura `X-Signature`, ignora entregas repetidas pelo `X-Delivery-Id` e:
   - `charge.paid` confirma o pagamento (`payment_complete`) se o valor bater. Com valor diferente, o pedido fica aguardando e ganha uma nota.
   - `charge.expired` / `charge.cancelled` cancela o pedido e o estoque volta. `charge.failed` marca como falhou. Só a cobrança atual do pedido fecha o pedido.
   - `charge.refunded` registra a nota. Uma devolução feita no painel do Máximo Pay também vira reembolso no pedido.
   - Um Pix antigo do mesmo pedido que for pago também confirma. Um segundo Pix pago vira nota de duplicidade para devolver.
   - Responde 2xx para o que não é desta loja, para o webhook não ser pausado. 400 para assinatura errada, 503 sem segredo configurado e 500 em erro interno, para o Máximo Pay tentar de novo.
4. **Devolução** pelo botão "Reembolsar" do pedido, total ou parcial (`POST /charges/:id/refund`). A confirmação chega pelo webhook.

O plugin é compatível com HPOS. O checkout em blocos fica declarado como incompatível por enquanto, porque ainda não tem o campo de CPF/CNPJ: a loja precisa usar o checkout clássico (shortcode `[woocommerce_checkout]`).

## Configuração

Em **WooCommerce › Configurações › Pagamentos › Máximo Pay Pix**:

| Campo | O que é |
|---|---|
| URL da API | a URL do seu operador com `/v1`. Use a do sandbox para testar |
| App ID / App Secret | chave de API criada no painel, com permissão de depósito |
| Segredo do webhook | o mesmo cadastrado no webhook do painel (32 a 256 caracteres) |
| Validade do Pix | minutos até o pedido ser cancelado (5 a 1440, padrão 30) |

No painel do Máximo Pay, cadastre o webhook com a URL que aparece na tela de configuração e os eventos `charge.paid`, `charge.expired`, `charge.cancelled`, `charge.failed` e `charge.refunded`. A forma de pagamento só aparece com tudo preenchido e com a loja em real (BRL).

## Desenvolvimento

```bash
composer install
composer test          # PHPUnit com Brain Monkey e imitações de WC_Order/WC_Payment_Gateway
bin/build-zip.sh       # build/maximopay-woocommerce.zip (~280 KB) com o vendor de produção
```

O SDK vem de `Maximo-Pay/maximopay-sdk-php` (`dev-main`) por repositório VCS, então o `composer install` precisa de acesso a ele no GitHub. Quando o SDK estiver no Packagist, basta trocar para uma versão.

Os testes não sobem um WordPress real. Antes de liberar para lojistas, vale uma rodada numa loja de teste contra o sandbox: pedido pago, Pix expirado e devolução parcial.

## Licença

MIT. Veja [LICENSE](LICENSE).
