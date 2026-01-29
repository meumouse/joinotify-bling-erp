<?php

namespace MeuMouse\Joinotify\Bling\Core;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Exception;
use ReflectionClass;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Plugin class to initialize integration components.
 *
 * @since 1.0.0
 * @version 1.0.4
 * @package MeuMouse\Joinotify\Bling\Core
 * @author MeuMouse.com
 */
class Plugin {
    
    /**
     * Plugin version.
     * 
     * @since 1.0.0
     * @return string
     */
    private $plugin_version;

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
     * Cache for instantiated classes to prevent duplicate instantiation.
     * 
     * @since 1.0.4
     * @var array
     */
    private $instantiated_classes = array();

    /**
     * Deferred classes: hook => class list.
     *
     * @since 1.0.4
     * @var array
     */
    private $deferred_classes = array(
        'woocommerce_loaded' => array(
            'MeuMouse\\Joinotify\\Bling\\Integrations\\Woocommerce',
        ),
        'joinotify_init' => array(
            'MeuMouse\\Joinotify\\Bling\\Integrations\\Joinotify',
        ),
    );


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
     * @version 1.0.4
     * @param string $plugin_version | Plugin version
     * @return void
     */
    public function init( $plugin_version ) {
        $this->plugin_version = $plugin_version;

        // hook before plugin init
        do_action('Joinotify_Bling/Before_Init');

        $this->define_constants();
        
        // load text domain
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // set compatibility with WooCommerce HPOS (High-Performance Order Storage)
        add_action( 'before_woocommerce_init', array( $this, 'setup_hpos_compatibility' ) );

        // instance classes
        $this->instance_classes();

        // hook after plugin init
        do_action('Joinotify_Bling/After_Init');
    }


    /**
     * Define plugin constants used across modules.
     *
     * @since 1.0.0
     * @version 1.0.4
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
            'JOINOTIFY_BLING_VERSION'    => $this->plugin_version,
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
     * @version 1.0.4
     * @return void
     */
    public function instance_classes() {
        $this->register_deferred_classes();

        // Joinotify dependency
        if ( ! class_exists('MeuMouse\Joinotify\Core\Init') ) {
            add_action( 'admin_notices', array( $this, 'joinotify_missing_notice' ) );
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

        // Filter and instance classes (excluding deferred ones)
        $this->instance_filtered_classes( $classmap );
    }

    
    /**
     * Filter and instance classes
     * 
     * @since 1.0.0
     * @version 1.0.4
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

            $deferred = $this->get_deferred_class_list();

            // Skip deferred classes (they will be instantiated on their hook)
            if ( in_array( $class, $deferred, true ) ) {
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
     * @version 1.0.4
     * @param string $class | Class name
     * @return void
     */
    private function safe_instance_class( $class ) {
        if ( ! is_string( $class ) || empty( trim( $class ) ) ) {
            return null;
        }

        if ( isset( $this->instantiated_classes[ $class ] ) ) {
            return $this->instantiated_classes[ $class ];
        }

        if ( ! class_exists( $class ) ) {
            return null;
        }

        try {
            $reflection = new ReflectionClass( $class );

            if ( ! $reflection->isInstantiable() ) {
                return null;
            }

            $constructor = $reflection->getConstructor();

            // Only instance classes without required constructor parameters
            if ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
                return null;
            }

            $instance = $reflection->newInstance();

            $this->instantiated_classes[ $class ] = $instance;

            // Call init method if exists
            if ( method_exists( $instance, 'init' ) ) {
                $init_method = $reflection->getMethod( 'init' );

                if ( $init_method->isPublic() && ! $init_method->isStatic() ) {
                    $instance->init();
                }
            }

            return $instance;

        } catch ( Exception $e ) {
            if ( defined('JOINOTIFY_BLING_DEBUG_MODE') && JOINOTIFY_BLING_DEBUG_MODE ) {
                error_log( 'Joinotify Bling: Error instancing class ' . $class . ' - ' . $e->getMessage() );
            }

            return null;
        }
    }


    /**
     * Register deferred class instantiation by hook.
     *
     * @since 1.0.4
     * @return void
     */
    private function register_deferred_classes() {
        /**
         * Allow third-parties to add deferred classes.
         *
         * Format:
         * array(
         *   'hook/name' => array( 'Full\\ClassName', ... ),
         * )
         *
         * @since 1.0.4
         */
        $map = apply_filters( 'Joinotify_Bling/Init/Deferred_Classes', $this->deferred_classes );

        if ( ! is_array( $map ) || empty( $map ) ) {
            return;
        }

        foreach ( $map as $hook => $classes ) {
            if ( ! is_string( $hook ) || empty( trim( $hook ) ) ) {
                continue;
            }

            if ( ! is_array( $classes ) || empty( $classes ) ) {
                continue;
            }

            $callback = function() use ( $classes ) {
                foreach ( $classes as $class ) {
                    $this->safe_instance_class( $class );
                }
            };

            // If the hook already fired, instantiate immediately.
            if ( did_action( $hook ) ) {
                $callback();

                continue;
            }

            add_action( $hook, $callback, 10, 0 );
        }
    }


    /**
     * Get a flat list of deferred classes.
     *
     * @since 1.0.4
     * @return array
     */
    private function get_deferred_class_list() {
        $map = apply_filters( 'Joinotify_Bling/Init/Deferred_Classes', $this->deferred_classes );

        if ( ! is_array( $map ) || empty( $map ) ) {
            return array();
        }

        $all = array();

        foreach ( $map as $classes ) {
            if ( ! is_array( $classes ) ) {
                continue;
            }

            foreach ( $classes as $class ) {
                if ( is_string( $class ) && ! empty( trim( $class ) ) ) {
                    $all[] = $class;
                }
            }
        }

        return array_values( array_unique( $all ) );
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