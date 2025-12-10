<?php

/**
 * Plugin Name:             Joinotify Bling ERP Integration
 * Description:             Integração do Joinotify com o Bling ERP para envio de notificações de NFe emitidas e sincronização com WooCommerce.
 * Author:                  MeuMouse.com
 * Version:                 1.0.0
 * Text Domain:             joinotify-bling-erp
 * Domain Path:             /languages
 * Requires at least:       5.6
 * Requires PHP:            7.4
 * WC requires at least:    5.0
 */

// Exit if accessed directly.
defined('ABSPATH') || exit;

// Autoload classes via Composer.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Main plugin initializer.
 */
add_action( 'plugins_loaded', function() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Check if Joinotify plugin is active
    if ( ! is_plugin_active('joinotify/joinotify.php') ) {
        // Show admin notice if Joinotify is not active
        add_action( 'admin_notices', function() {
            $class = 'notice notice-error';
            $message = esc_html__( 'O plugin Joinotify Bling ERP requer que o Joinotify esteja instalado e ativo.', 'joinotify-bling-erp' );
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
        });

        return;
    }
    
    // Check for WooCommerce if needed
    if ( ! class_exists('WooCommerce') ) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning">';
            echo '<p>' . esc_html__('Para usar todas as funcionalidades do Bling ERP, o WooCommerce precisa estar instalado e ativo.', 'joinotify-bling-erp') . '</p>';
            echo '</div>';
        });
    }
    
    // Initialize the plugin components
    MeuMouse\Joinotify\Bling\Core\Plugin::init();
    
    // Initialize webhook controller
    add_action('rest_api_init', array('MeuMouse\Joinotify\Bling\API\Webhook_Controller', 'register_routes'));
}, 999);