<?php

namespace MeuMouse\Joinotify\Bling\Core;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

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
     * Plugin version.
     * 
     * @since 1.0.0
     * @return string
     */
    public const VERSION = '1.0.1';

    /**
     * Plugin slug.
     * 
     * @since 1.0.0
     * @version 1.5.0
     * @return string
     */
    public const SLUG = 'joinotify-bling-erp';

    /**
     * Plugin instance.
     * 
     * @since 1.0.0
     * @var Plugin
     */
    private static $instance = null;


    /**
     * Get plugin instance
     * 
     * @since 1.0.0
     * @version 1.0.0
     * @return Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }


    /**
     * Initialize the plugin.
     *
     * @since 1.0.0
     * @return void
     */
    public function init() {
        // hook before plugin init
        do_action('Joinotify_Bling/Before_Init');

        $this->define_constants();
        
        // load text domain
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // set compatibility with WooCommerce HPOS (High-Performance Order Storage)
        add_action( 'before_woocommerce_init', array( $this, 'setup_hpos_compatibility' ) );

        // check if WooCommerce is active
        add_action( 'woocommerce_loaded', array( $this, 'check_dependencies' ) );

        // instance classes after Joinotify is loaded
        add_action( 'joinotify_init', array( $this, 'instance_classes' ) );

        // hook after plugin init
        do_action('Joinotify_Bling/After_Init');
    }


    /**
     * Define plugin constants used across modules.
     *
     * @since 1.0.0
     * @return void
     */
    private function define_constants() {
        $base_file = dirname( __DIR__, 2 ) . '/joinotify-bling-erp.php';
        $base_dir = plugin_dir_path( $base_file );
        $base_url = plugin_dir_url( $base_file );

        $constants = array(
            'JOINOTIFY_BLING_BASENAME'   => plugin_basename( $base_file ),
            'JOINOTIFY_BLING_FILE'       => $base_file,
            'JOINOTIFY_BLING_PATH'       => $base_dir,
            'JOINOTIFY_BLING_INC_PATH'   => $base_dir . 'inc/',
            'JOINOTIFY_BLING_URL'        => $base_url,
            'JOINOTIFY_BLING_ASSETS'     => $base_url . 'assets/',
            'JOINOTIFY_BLING_ABSPATH'    => dirname( $base_file ) . '/',
            'JOINOTIFY_BLING_SLUG'       => self::SLUG,
            'JOINOTIFY_BLING_VERSION'    => self::VERSION,
            'JOINOTIFY_BLING_DEBUG_MODE' => defined('WP_DEBUG') && WP_DEBUG,
            'JOINOTIFY_BLING_DEV_MODE'   => true,
        );

        foreach ( $constants as $key => $value ) {
            if ( ! defined( $key ) ) {
                define( $key, $value );
            }
        }
    }


    /**
     * Check plugin dependencies
     * 
     * @since 1.0.0
     * @return void
     */
    public function check_dependencies() {
        // WooCommerce dependency
        if ( ! class_exists('WooCommerce') ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        // Joinotify dependency
        if ( ! class_exists('MeuMouse\Joinotify\Joinotify') ) {
            add_action( 'admin_notices', array( $this, 'joinotify_missing_notice' ) );
            return;
        }
    }


    /**
     * WooCommerce missing notice
     * 
     * @since 1.0.0
     * @return void
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php 
                printf(
                    esc_html__( 'O plugin %1$s requer %2$s para funcionar. Por favor, instale e ative o WooCommerce.', 'joinotify-bling' ),
                    '<strong>Joinotify: Integração Bling ERP</strong>',
                    '<strong>WooCommerce</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }


    /**
     * Joinotify missing notice
     * 
     * @since 1.0.0
     * @return void
     */
    public function joinotify_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php 
                printf(
                    esc_html__( 'O plugin %1$s requer %2$s para funcionar. Por favor, instale e ative o Joinotify.', 'joinotify-bling' ),
                    '<strong>Joinotify: Integração Bling ERP</strong>',
                    '<strong>Joinotify</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }


    /**
     * Load plugin text domain
     * 
     * @since 1.0.0
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'joinotify-bling', false, dirname( JOINOTIFY_BLING_BASENAME ) . '/languages' );
    }


    /**
     * Instance classes after load Composer
     * 
     * @since 1.0.0
     * @return void
     */
    public function instance_classes() {
        // Check dependencies
        if ( ! class_exists('WooCommerce') || ! class_exists('MeuMouse\Joinotify\Integrations\Integrations_Base') ) {
            return;
        }

        // Get classmap from Composer
        $classmap_file = JOINOTIFY_BLING_PATH . 'vendor/composer/autoload_classmap.php';

        if ( ! file_exists( $classmap_file ) ) {
            return;
        }

        $classmap = include_once $classmap_file;

        // Ensure classmap is an array
        if ( ! is_array( $classmap ) ) {
            $classmap = array();
        }

        // Filter and instance classes
        $this->instance_filtered_classes( $classmap );
    }

    
    /**
     * Filter and instance classes
     * 
     * @since 1.0.0
     * @param array $classmap
     * @return void
     */
    private function instance_filtered_classes( $classmap ) {
        $filtered_classes = array_filter( $classmap, function( $file, $class ) {
            // Skip if not in our namespace
            if ( strpos( $class, 'MeuMouse\\Joinotify\\Bling\\' ) !== 0 ) {
                return false;
            }

            // Skip abstract classes
            if ( strpos( $class, 'Abstract' ) !== false ) {
                return false;
            }
            
            // Skip interfaces
            if ( strpos( $class, 'Interface' ) !== false ) {
                return false;
            }
            
            // Skip traits
            if ( strpos( $class, 'Trait' ) !== false ) {
                return false;
            }
            
            // Skip Plugin class itself
            if ( $class === 'MeuMouse\\Joinotify\\Bling\\Core\\Plugin' ) {
                return false;
            }

            // Check if class exists
            if ( ! class_exists( $class ) ) {
                return false;
            }
            
            return true;
            
        }, ARRAY_FILTER_USE_BOTH );

        foreach ( array_keys( $filtered_classes ) as $class ) {
            $this->safe_instance_class( $class );
        }
    }


    /**
     * Safely instance a class
     * 
     * @since 1.0.0
     * @param string $class
     * @return void
     */
    private function safe_instance_class( $class ) {
        try {
            $reflection = new \ReflectionClass( $class );
            
            if ( ! $reflection->isInstantiable() ) {
                return;
            }

            $constructor = $reflection->getConstructor();
            
            // Only instance classes without required constructor parameters
            if ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
                return;
            }

            $instance = new $class();
            
            // Call init method if exists
            if ( method_exists( $instance, 'init' ) ) {
                $instance->init();
            }
            
        } catch ( \Exception $e ) {
            if ( defined('JOINOTIFY_BLING_DEBUG_MODE') && JOINOTIFY_BLING_DEBUG_MODE ) {
                error_log( 'Joinotify Bling: Error instancing class ' . $class . ' - ' . $e->getMessage() );
            }
        }
    }


    /**
     * Setup HPOS compatibility.
     *
     * @since 1.0.0
     * @return void
     */
    public function setup_hpos_compatibility() {
        if ( class_exists( FeaturesUtil::class ) ) {
            FeaturesUtil::declare_compatibility( 'custom_order_tables', JOINOTIFY_BLING_FILE, true );
        }
    }


    /**
     * Cloning is forbidden
     *
     * @since 1.0.0
     * @return void
     */
    public function __clone() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Trapaceando?', 'joinotify-bling' ), '1.0.0' );
    }


    /**
     * Unserializing instances of this class is forbidden
     *
     * @since 1.0.0
     * @return void
     */
    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Trapaceando?', 'joinotify-bling' ), '1.0.0' );
    }
}