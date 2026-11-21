#!/usr/bin/env bash
# Gera build/maximopay-woocommerce.zip pronto para "Plugins › Adicionar novo › Enviar plugin".
# Leva só o código de produção e o vendor sem as dependências de desenvolvimento.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build
mkdir -p build/maximopay-woocommerce
cp -r maximopay-woocommerce.php composer.json src assets readme.txt build/maximopay-woocommerce/
[ -f composer.lock ] && cp composer.lock build/maximopay-woocommerce/
(cd build/maximopay-woocommerce && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet && rm -f composer.json composer.lock \
  && find vendor -mindepth 3 -maxdepth 3 \( -name .git -o -name tests -o -name docs -o -name examples \) -exec rm -rf {} +)
(cd build && zip -qr maximopay-woocommerce.zip maximopay-woocommerce)
echo "build/maximopay-woocommerce.zip"
