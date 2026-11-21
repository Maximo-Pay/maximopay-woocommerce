<?php
/**
 * Plugin Name: Máximo Pay Pix para WooCommerce
 * Description: Receba por Pix com o Máximo Pay. O pedido é confirmado sozinho pelo webhook assinado.
 * Version: 0.1.0
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.4
 * Text Domain: maximopay-woocommerce
 * Domain Path: /languages
 * License: Proprietary
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$maximopayAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($maximopayAutoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>Máximo Pay Pix: falta a pasta vendor. Instale pelo .zip gerado em bin/build-zip.sh.</p></div>';
    });
    return;
}
require_once $maximopayAutoload;

MaximoPay\WooCommerce\Plugin::boot(__FILE__);
