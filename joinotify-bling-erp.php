<?php

/**
 * Plugin Name:             Joinotify: Integração Bling ERP
 * Description:             Integração do Joinotify com o Bling ERP para envio de notificações de NFe emitidas e sincronização com WooCommerce.
 * Requires Plugins: 		woocommerce, joinotify
 * Author:                  MeuMouse.com
 * Author URI: 				https://meumouse.com/?utm_source=wordpress&utm_medium=plugins_list&utm_campaign=joinotify_bling_erp
 * Version:                 1.0.1
 * Requires at least:       5.6
 * Requires PHP:            7.4
 * Tested up to:      		6.9
 * WC requires at least:    5.0
 * Text Domain:             joinotify-bling-erp
 * Domain Path:             /languages
 * 
 * @author					MeuMouse.com
 * @copyright 				2025 MeuMouse.com
 * @license 				Proprietary - See license.md for details
 */

use MeuMouse\Joinotify\Bling\Core\Plugin;

// Exit if accessed directly.
defined('ABSPATH') || exit;

$autoload = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

if ( ! class_exists( Plugin::class ) ) {
    return;
}

Plugin::get_instance()->init();