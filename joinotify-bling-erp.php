<?php
/**
 * Plugin Name:             Joinotify Bling ERP Integration
 * Description:             Integração do Joinotify com o Bling ERP para envio de notificações de NFe emitidas.
 * Author:                  MeuMouse.com
 * Version:                 1.0.0
 * Text Domain:             joinotify-bling-erp
 * Domain Path:             /languages
 */
 
// Exit if accessed directly.
defined('ABSPATH') || exit;

// Autoload classes via Composer.
if ( file_exists(__DIR__ . '/vendor/autoload.php') ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Main plugin initializer.
 */
add_action('plugins_loaded', function() {
    // Check if Joinotify plugin is active
    if ( ! class_exists('MeuMouse\\Joinotify\\Integrations\\Integrations_Base') ) {
        // Show admin notice if Joinotify is not active
        add_action('admin_notices', function() {
            $class = 'notice notice-error';
            $message = esc_html__( 'O plugin Joinotify Bling ERP requer que o Joinotify esteja instalado e ativo.', 'joinotify-bling-erp' );
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
        });

        return;
    }
    
    // Initialize the plugin components
    MeuMouse\Joinotify\Bling\Core\Plugin::init();
});