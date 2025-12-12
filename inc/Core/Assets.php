<?php

namespace MeuMouse\Joinotify\Bling\Core;

use MeuMouse\Joinotify\Bling\Integrations\Woocommerce;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Asset management for Bling integration.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Assets {
    
    /**
     * Constructor
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        // Admin assets
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
    }
    

    /**
     * Enqueue admin assets.
     *
     * @since 1.0.0
     * @param string $hook | Current admin page.
     * @return void
     */
    public static function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        if (!self::is_bling_page($hook)) {
            return;
        }
        
        // Enqueue admin CSS
        self::enqueue_admin_css();
        
        // Enqueue admin JavaScript
        self::enqueue_admin_js();
        
        // Localize script data
        self::localize_admin_scripts();
    }


    /**
     * Enqueue admin CSS files.
     *
     * @since 1.0.0
     * @return void
     */
    private static function enqueue_admin_css() {
        // Main admin CSS
        wp_enqueue_style(
            'joinotify-bling-admin',
            self::asset_url('css/admin.css'),
            array(),
            self::get_version(),
            'all'
        );
        
        // Select2 CSS (if needed)
        if (self::needs_select2()) {
            wp_enqueue_style('select2');
        }
        
        // WooCommerce admin CSS (if needed)
        if (self::needs_woocommerce_css()) {
            wp_enqueue_style('woocommerce_admin_styles');
        }
    }

    
    /**
     * Enqueue admin JavaScript files.
     *
     * @since 1.0.0
     * @return void
     */
    private static function enqueue_admin_js() {
        // Main admin JavaScript
        wp_enqueue_script(
            'joinotify-bling-admin',
            self::asset_url('js/admin.js'),
            array('jquery', 'wp-util'),
            self::get_version(),
            true
        );
        
        // Select2 JavaScript (if needed)
        if (self::needs_select2()) {
            wp_enqueue_script('select2');
        }
        
        // WooCommerce admin JavaScript (if needed)
        if (self::needs_woocommerce_js()) {
            wp_enqueue_script('wc-admin-meta-boxes');
        }
    }
    

    /**
     * Localize admin script data.
     *
     * @since 1.0.0
     * @return void
     */
    private static function localize_admin_scripts() {
        wp_localize_script(
            'joinotify-bling-admin',
            'bling_admin',
            self::get_admin_localization_data()
        );
    }


    /**
     * Get admin localization data.
     *
     * @return array Localization data.
     */
    private static function get_admin_localization_data() {
        return array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bling_admin_nonce'),
            'rest_url' => rest_url('bling/v1/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'current_tab' => isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'credentials',
            'strings' => array(
                // General
                'loading' => __('Carregando...', 'joinotify-bling-erp'),
                'saving' => __('Salvando...', 'joinotify-bling-erp'),
                'success' => __('Sucesso!', 'joinotify-bling-erp'),
                'error' => __('Erro!', 'joinotify-bling-erp'),
                'confirm' => __('Tem certeza?', 'joinotify-bling-erp'),
                
                // Actions
                'confirm_delete' => __('Tem certeza que deseja excluir este item?', 'joinotify-bling-erp'),
                'confirm_sync' => __('Isso pode levar algum tempo. Deseja continuar?', 'joinotify-bling-erp'),
                'confirm_cache_clear' => __('Isso irá limpar todo o cache de sincronização. Continuar?', 'joinotify-bling-erp'),
                
                // Status messages
                'no_results' => __('Nenhum resultado encontrado.', 'joinotify-bling-erp'),
                'connection_testing' => __('Testando conexão...', 'joinotify-bling-erp'),
                'connection_success' => __('Conexão estabelecida com sucesso!', 'joinotify-bling-erp'),
                'connection_error' => __('Falha na conexão.', 'joinotify-bling-erp'),
                'cache_clearing' => __('Limpando cache...', 'joinotify-bling-erp'),
                'cache_cleared' => __('Cache limpo com sucesso!', 'joinotify-bling-erp'),
                'sync_starting' => __('Iniciando sincronização...', 'joinotify-bling-erp'),
                'sync_complete' => __('Sincronização concluída!', 'joinotify-bling-erp'),
                'sync_error' => __('Erro na sincronização.', 'joinotify-bling-erp'),
                
                // Webhooks
                'webhook_creating' => __('Criando webhook...', 'joinotify-bling-erp'),
                'webhook_created' => __('Webhook criado com sucesso!', 'joinotify-bling-erp'),
                'webhook_deleting' => __('Excluindo webhook...', 'joinotify-bling-erp'),
                'webhook_deleted' => __('Webhook excluído com sucesso!', 'joinotify-bling-erp'),
                'webhook_loading' => __('Carregando webhooks...', 'joinotify-bling-erp'),
                
                // Invoices
                'invoice_creating' => __('Criando nota fiscal...', 'joinotify-bling-erp'),
                'invoice_created' => __('Nota fiscal criada com sucesso!', 'joinotify-bling-erp'),
                'invoice_checking' => __('Verificando status da nota fiscal...', 'joinotify-bling-erp'),
                
                // Products
                'product_syncing' => __('Sincronizando produto...', 'joinotify-bling-erp'),
                'product_synced' => __('Produto sincronizado com sucesso!', 'joinotify-bling-erp'),
                'product_checking' => __('Verificando status do produto...', 'joinotify-bling-erp'),
                
                // Settings
                'settings_saving' => __('Salvando configurações...', 'joinotify-bling-erp'),
                'settings_saved' => __('Configurações salvas com sucesso!', 'joinotify-bling-erp'),
            ),
            'urls' => array(
                'admin_url' => admin_url(),
                'plugin_url' => plugin_dir_url(dirname(dirname(__FILE__))),
                'bling_dashboard' => 'https://www.bling.com.br',
                'bling_api_docs' => 'https://ajuda.bling.com.br/hc/pt-br/categories/360002186394-API-para-Desenvolvedores',
            ),
            'settings' => array(
                'auto_create_invoice' => get_option('bling_auto_create_invoice', 'yes'),
                'trigger_statuses' => get_option('bling_invoice_trigger_statuses', array('completed')),
                'sync_products' => get_option('bling_sync_products', 'no'),
                'sync_customers' => get_option('bling_sync_customers', 'no'),
            ),
            'status' => array(
                'is_connected' => !empty(get_option('bling_access_token')),
                'woocommerce_active' => class_exists('WooCommerce'),
                'debug_mode' => self::is_debug(),
            ),
        );
    }


    /**
     * Check if current page is a Bling admin page.
     *
     * @since 1.0.0
     * @param string $hook | Current admin page.
     * @return bool
     */
    private static function is_bling_page( $hook ) {
        if ( $hook === 'tools_page_joinotify-bling' ) {
            return true;
        }
        
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'joinotify-bling' ) {
            return true;
        }
        
        // Check for product or order edit pages with Bling meta boxes
        if ( self::is_product_page( $hook ) || $hook === Woocommerce::get_orders_page() ) {
            return true;
        }
        
        return false;
    }

    
    /**
     * Check if current page is a product page.
     *
     * @since 1.0.0
     * @param string $hook Current admin page.
     * @return bool
     */
    private static function is_product_page( $hook ) {
        return in_array( $hook, array( 'post.php', 'post-new.php' ) ) && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'product';
    }
    

    /**
     * Check if current page is an order page.
     *
     * @since 1.0.0
     * @param string $hook | Current admin page.
     * @return bool
     */
    private static function is_order_page( $hook ) {
        return in_array( $hook, array( 'post.php', 'post-new.php' ) ) && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'shop_order';
    }
    

    /**
     * Check if page needs Select2.
     *
     * @since 1.0.0
     * @return bool
     */
    private static function needs_select2() {
        // Check if we're on a Bling settings page
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'joinotify-bling' ) {
            return true;
        }
        
        // Check if we're on product or order pages
        global $pagenow, $post_type;

        return in_array( $pagenow, array( 'post.php', 'post-new.php' ) ) && in_array( $post_type, array( 'product', 'shop_order' ) );
    }
    

    /**
     * Check if page needs WooCommerce CSS.
     *
     * @since 1.0.0
     * @return bool
     */
    private static function needs_woocommerce_css() {
        return self::is_product_page($GLOBALS['pagenow'] ?? '') || $GLOBALS['pagenow'] === Woocommerce::get_orders_page();
    }
    

    /**
     * Check if page needs WooCommerce JavaScript.
     *
     * @since 1.0.0
     * @return bool
     */
    private static function needs_woocommerce_js() {
        return self::is_product_page( $GLOBALS['pagenow'] ?? '' ) || $GLOBALS['pagenow'] === Woocommerce::get_orders_page();
    }
    
    
    /**
     * Get plugin version for cache busting.
     *
     * @since 1.0.0
     * @return string Plugin version.
     */
    private static function get_version() {
        static $version = null;
        
        if ( $version === null ) {
            if ( defined('JOINOTIFY_BLING_VERSION') ) {
                $version = JOINOTIFY_BLING_VERSION;
            } else {
                $plugin_file = dirname( dirname( dirname( dirname(__FILE__) ) ) ) . '/joinotify-bling-erp.php';
                
                if ( function_exists('get_plugin_data') && file_exists( $plugin_file ) ) {
                    $plugin_data = get_plugin_data( $plugin_file );
                    $version = $plugin_data['Version'] ?? '1.0.0';
                } else {
                    $version = '1.0.0';
                }
            }
        }
        
        return $version;
    }
    

    /**
     * Generate asset URL.
     *
     * @since 1.0.0
     * @param string $path | Asset path relative to assets directory.
     * @return string Full URL to asset.
     */
    public static function asset_url( $path ) {
        if ( defined('JOINOTIFY_BLING_ASSETS') ) {
            return JOINOTIFY_BLING_ASSETS . ltrim( $path, '/' );
        }
        
        $plugin_url = plugin_dir_url( dirname( dirname( dirname(__FILE__) ) ) );
        
        return $plugin_url . 'assets/' . ltrim( $path, '/' );
    }
    

    /**
     * Check if debug mode is enabled.
     *
     * @since 1.0.0
     * @return bool
     */
    public static function is_debug() {
        return defined('WP_DEBUG') && WP_DEBUG;
    }
    

    /**
     * Get all WooCommerce order statuses for JavaScript.
     *
     * @since 1.0.0
     * @return array Formatted statuses.
     */
    public static function get_wc_statuses_for_js() {
        $statuses = wc_get_order_statuses();
        $formatted = array();
        
        foreach ( $statuses as $key => $label ) {
            $clean_key = str_replace( 'wc-', '', $key );
            $formatted[] = array(
                'value' => $clean_key,
                'label' => $label,
                'selected' => in_array( $clean_key, (array) get_option('bling_invoice_trigger_statuses', array('completed') ) ),
            );
        }
        
        return $formatted;
    }
    
    
    /**
     * Add inline CSS for immediate styling needs.
     * Note: Use sparingly, prefer external CSS files.
     *
     * @since 1.0.0
     * @return void
     */
    public static function add_critical_css() {
        if ( ! self::is_bling_page( $GLOBALS['pagenow'] ?? '' ) ) {
            return;
        }
        
        ?>
        <style type="text/css">
            /* Critical CSS for immediate rendering */
            .bling-loading {
                opacity: 0.7;
                cursor: not-allowed;
            }
            
            .bling-status-active {
                color: #00a32a;
                font-weight: 600;
            }
            
            .bling-status-inactive {
                color: #d63638;
                font-weight: 600;
            }
        </style>
        <?php
    }
}