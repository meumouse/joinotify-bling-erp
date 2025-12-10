<?php

namespace MeuMouse\Joinotify\Bling\Core;

use MeuMouse\Joinotify\Integrations\Integrations_Base;
use MeuMouse\Joinotify\Admin\Admin as JoinotifyAdmin;
use MeuMouse\Joinotify\Core\Workflow_Processor;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

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

        // Register REST API endpoints
        add_action('rest_api_init', array(__NAMESPACE__ . '\\API', 'register_routes'));
        
        // Initialize integration (triggers and placeholders)
        new Integration();
    }
}