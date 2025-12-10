<?php

namespace MeuMouse\Joinotify\Bling\Core;

use MeuMouse\Joinotify\Bling\Integrations\WooCommerce as WooCommerce_Integration;
use MeuMouse\Joinotify\Bling\Integrations\Joinotify as Joinotify_Integration;
use MeuMouse\Joinotify\Bling\Admin\Admin;
use MeuMouse\Joinotify\Bling\Admin\Settings;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Plugin class to initialize integration components.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Plugin {

    /**
     * Initialize the integration plugin.
     *
     * Set up default settings and load all necessary components.
     *
     * @since 1.0.0
     * @return void
     */
    public static function init() {
        // Ensure default integration setting exists in Joinotify settings
        $options = get_option('joinotify_settings', array());

        if ( ! isset( $options['enable_bling_integration'] ) ) {
            $options['enable_bling_integration'] = 'no';

            update_option( 'joinotify_settings', $options );
        }

        // Initialize admin panel and settings
        Admin::init();
        
        // Initialize automation settings
        Settings::init();
        
        // Initialize WooCommerce integration if WooCommerce is active
        if ( class_exists('WooCommerce') ) {
            WooCommerce_Integration::init();
        }

        // Register REST API endpoints
        add_action( 'rest_api_init', array( 'MeuMouse\Joinotify\Bling\API\Controller', 'register_routes' ) );
        
        // Register AJAX handlers
        add_action( 'admin_init', array( 'MeuMouse\Joinotify\Bling\Core\Settings', 'handle_ajax_requests' ) );
        
        // Initialize integration (triggers and placeholders)
        if ( class_exists('MeuMouse\Joinotify\Integrations\Integrations_Base') ) {
            new Joinotify_Integration();
        }
    }
}