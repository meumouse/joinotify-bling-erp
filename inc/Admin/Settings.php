<?php

namespace MeuMouse\Joinotify\Bling\Admin;

use MeuMouse\Joinotify\Bling\API\Client;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * NFe Automation Settings.
 *
 * Handles automation settings for Bling NFe integration.
 *
 * @since 1.0.0
 * @version 1.0.2
 * @package MeuMouse\Joinotify\Bling\Admin
 * @author MeuMouse.com
 */
class Settings {
    
    /**
     * Settings group name
     *
     * @since 1.0.2
     * @var string
     */
    const SETTINGS_GROUP = 'bling-automation-group';
    
    /**
     * Default settings values
     *
     * @since 1.0.2
     * @var array
     */
    private static $default_settings = array(
        'bling_invoice_trigger_statuses'    => array( 'completed' ),
        'bling_default_nature_operation'    => 1,
        'bling_sync_products'               => 'no',
        'bling_sync_customers'              => 'no',
        'bling_auto_create_invoice'         => 'yes',
        'bling_send_invoice_email'          => 'yes',
        'bling_invoice_series'              => '1',
        'bling_invoice_purpose'             => '1',
        'bling_sales_channel_id'            => '',
    );
    
    /**
     * Sales channels cache
     *
     * @since 1.0.2
     * @var array|null
     */
    private static $sales_channels_cache = null;
    
    /**
     * Constructor
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_filter( 'bling_settings_tabs', array( __CLASS__, 'add_settings_tab' ) );
        add_action( 'bling_settings_tab_content', array( __CLASS__, 'render_settings_tab' ) );
        
        // Add AJAX handlers for sales channels
        add_action( 'wp_ajax_bling_refresh_sales_channels', array( __CLASS__, 'ajax_refresh_sales_channels' ) );
        add_action( 'wp_ajax_bling_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_bling_clear_cache', array( __CLASS__, 'ajax_clear_cache' ) );
    }
    
    /**
     * Register automation settings.
     *
     * @since 1.0.0
     * @version 1.0.2
     * @return void
     */
    public static function register_settings() {
        // Register all settings with sanitization callbacks
        $settings = array(
            'bling_invoice_trigger_statuses'    => array( __CLASS__, 'sanitize_trigger_statuses' ),
            'bling_default_nature_operation'    => 'intval',
            'bling_sync_products'               => array( __CLASS__, 'sanitize_yes_no' ),
            'bling_sync_customers'              => array( __CLASS__, 'sanitize_yes_no' ),
            'bling_auto_create_invoice'         => array( __CLASS__, 'sanitize_yes_no' ),
            'bling_send_invoice_email'          => array( __CLASS__, 'sanitize_yes_no' ),
            'bling_invoice_series'              => 'sanitize_text_field',
            'bling_invoice_purpose'             => array( __CLASS__, 'sanitize_invoice_purpose' ),
            'bling_sales_channel_id'            => 'intval',
        );
        
        foreach ( $settings as $option_name => $sanitize_callback ) {
            register_setting(
                self::SETTINGS_GROUP,
                $option_name,
                array(
                    'sanitize_callback' => $sanitize_callback,
                    'default'           => isset( self::$default_settings[ $option_name ] ) ? self::$default_settings[ $option_name ] : '',
                )
            );
        }
    }
    
    /**
     * Add automation settings tab.
     *
     * @since 1.0.0
     * @param array $tabs Existing tabs.
     * @return array Modified tabs.
     */
    public static function add_settings_tab( $tabs ) {
        $tabs['automation'] = __( 'Automação NFe', 'joinotify-bling-erp' );
        return $tabs;
    }
    
    /**
     * Render automation settings tab.
     *
     * @since 1.0.0
     * @version 1.0.2
     * @param string $tab Current tab.
     * @return void
     */
    public static function render_settings_tab( $tab ) {
        if ( 'automation' !== $tab ) {
            return;
        }
        
        // Get all option values
        $options = self::get_all_options();
        
        // Try to get sales channels
        $sales_channels = self::get_sales_channels();
        $has_sales_channels = ! is_wp_error( $sales_channels ) && ! empty( $sales_channels );
        
        // Enqueue scripts and styles
        self::enqueue_scripts(); ?>
        
        <form method="post" action="options.php">
            <?php settings_fields( self::SETTINGS_GROUP ); ?>
            
            <table class="form-table">
                <?php self::render_auto_create_field( $options['auto_create'] ); ?>
                <?php self::render_trigger_statuses_field( $options['trigger_statuses'] ); ?>
                <?php self::render_sales_channel_field( $options['sales_channel_id'], $sales_channels, $has_sales_channels ); ?>
                <?php self::render_nature_operation_field( $options['default_nature'] ); ?>
                <?php self::render_invoice_series_field( $options['invoice_series'] ); ?>
                <?php self::render_invoice_purpose_field( $options['invoice_purpose'] ); ?>
                <?php self::render_send_email_field( $options['send_email'] ); ?>
                <?php self::render_sync_products_field( $options['sync_products'] ); ?>
                <?php self::render_sync_customers_field( $options['sync_customers'] ); ?>
            </table>
            
            <?php submit_button( __( 'Salvar Configurações de Automação', 'joinotify-bling-erp' ) ); ?>
        </form>
        <?php
    }
    
    /**
     * Get all option values with defaults
     *
     * @since 1.0.2
     * @return array
     */
    private static function get_all_options() {
        return array(
            'trigger_statuses'    => get_option( 'bling_invoice_trigger_statuses', self::$default_settings['bling_invoice_trigger_statuses'] ),
            'default_nature'      => get_option( 'bling_default_nature_operation', self::$default_settings['bling_default_nature_operation'] ),
            'sync_products'       => get_option( 'bling_sync_products', self::$default_settings['bling_sync_products'] ),
            'sync_customers'      => get_option( 'bling_sync_customers', self::$default_settings['bling_sync_customers'] ),
            'auto_create'         => get_option( 'bling_auto_create_invoice', self::$default_settings['bling_auto_create_invoice'] ),
            'send_email'          => get_option( 'bling_send_invoice_email', self::$default_settings['bling_send_invoice_email'] ),
            'invoice_series'      => get_option( 'bling_invoice_series', self::$default_settings['bling_invoice_series'] ),
            'invoice_purpose'     => get_option( 'bling_invoice_purpose', self::$default_settings['bling_invoice_purpose'] ),
            'sales_channel_id'    => get_option( 'bling_sales_channel_id', self::$default_settings['bling_sales_channel_id'] ),
        );
    }
    
    /**
     * Get sales channels from cache or API
     *
     * @since 1.0.2
     * @return array|WP_Error
     */
    private static function get_sales_channels() {
        if ( null !== self::$sales_channels_cache ) {
            return self::$sales_channels_cache;
        }
        
        // Try to get from transient cache first
        $cached_channels = get_transient( 'bling_sales_channels_list' );
        
        if ( false !== $cached_channels ) {
            self::$sales_channels_cache = $cached_channels;
            return $cached_channels;
        }
        
        // Fetch from API
        self::$sales_channels_cache = Client::get_sales_channels_from_bling();
        
        // Cache for 1 hour if successful
        if ( ! is_wp_error( self::$sales_channels_cache ) && ! empty( self::$sales_channels_cache ) ) {
            set_transient( 'bling_sales_channels_list', self::$sales_channels_cache, HOUR_IN_SECONDS );
        }
        
        return self::$sales_channels_cache;
    }
    
    /**
     * Render auto create invoice field
     *
     * @since 1.0.2
     * @param string $current_value Current option value.
     * @return void
     */
    private static function render_auto_create_field( $current_value ) {
        ?>
        <tr>
            <th scope="row">
                <label for="bling_auto_create_invoice">
                    <?php echo esc_html__( 'Criar NFe automaticamente', 'joinotify-bling-erp' ); ?>
                </label>
            </th>
            <td>
                <select name="bling_auto_create_invoice" id="bling_auto_create_invoice" class="regular-text">
                    <option value="yes" <?php selected( $current_value, 'yes' ); ?>>
                        <?php echo esc_html__( 'Sim', 'joinotify-bling-erp' ); ?>
                    </option>
                    <option value="no" <?php selected( $current_value, 'no' ); ?>>
                        <?php echo esc_html__( 'Não', 'joinotify-bling-erp' ); ?>
                    </option>
                </select>
                <p class="description">
                    <?php echo esc_html__( 'Criar nota fiscal automaticamente quando o pedido atingir determinado status.', 'joinotify-bling-erp' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render trigger statuses field
     *
     * @since 1.0.2
     * @param array $current_value Current option value.
     * @return void
     */
    private static function render_trigger_statuses_field( $current_value ) {
        $statuses = wc_get_order_statuses();
        ?>
        <tr>
            <th scope="row">
                <label>
                    <?php echo esc_html__( 'Status que disparam NFe', 'joinotify-bling-erp' ); ?>
                </label>
            </th>
            <td>
                <div class="bling-checkbox-container">
                    <?php if ( empty( $statuses ) ) : ?>
                        <p class="description">
                            <?php echo esc_html__( 'Nenhum status de pedido encontrado. Certifique-se de que o WooCommerce está ativo.', 'joinotify-bling-erp' ); ?>
                        </p>
                    <?php else : ?>
                        <?php foreach ( $statuses as $status_key => $status_label ) : ?>
                            <?php
                            $clean_key = str_replace( 'wc-', '', $status_key );
                            $checked = in_array( $clean_key, (array) $current_value ) ? 'checked' : '';
                            $field_id = 'bling_status_' . sanitize_title( $clean_key );
                            ?>
                            <p>
                                <input type="checkbox" 
                                       id="<?php echo esc_attr( $field_id ); ?>" 
                                       name="bling_invoice_trigger_statuses[]" 
                                       value="<?php echo esc_attr( $clean_key ); ?>" 
                                       <?php echo esc_attr( $checked ); ?> />
                                <label for="<?php echo esc_attr( $field_id ); ?>">
                                    <?php echo esc_html( $status_label ); ?>
                                </label>
                            </p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <p class="description">
                    <?php echo esc_html__( 'Selecione os status de pedido que devem disparar a criação de NFe.', 'joinotify-bling-erp' ); ?>
                </p>
                <div class="bling-tool-buttons">
                    <button type="button" id="bling-select-all-statuses" class="button button-small">
                        <?php echo esc_html__( 'Selecionar todos', 'joinotify-bling-erp' ); ?>
                    </button>
                    <button type="button" id="bling-deselect-all-statuses" class="button button-small">
                        <?php echo esc_html__( 'Desmarcar todos', 'joinotify-bling-erp' ); ?>
                    </button>
                </div>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render sales channel field
     *
     * @since 1.0.2
     * @param string $current_value Current option value.
     * @param mixed $sales_channels Sales channels data.
     * @param bool $has_sales_channels Whether sales channels are available.
     * @return void
     */
    private static function render_sales_channel_field( $current_value, $sales_channels, $has_sales_channels ) {
        ?>
        <tr>
            <th scope="row">
                <label for="bling_sales_channel_id">
                    <?php echo esc_html__( 'Canal de Venda', 'joinotify-bling-erp' ); ?>
                </label>
            </th>
            <td>
                <div id="bling-sales-channel-container">
                    <?php if ( is_wp_error( $sales_channels ) ) : ?>
                        <div class="notice notice-error inline">
                            <p>
                                <?php echo esc_html__( 'Erro ao carregar canais de venda:', 'joinotify-bling-erp' ); ?> 
                                <?php echo esc_html( $sales_channels->get_error_message() ); ?>
                            </p>
                        </div>
                        <button type="button" id="bling-retry-load-channels" class="button button-small">
                            <?php echo esc_html__( 'Tentar novamente', 'joinotify-bling-erp' ); ?>
                        </button>
                    <?php elseif ( ! $has_sales_channels ) : ?>
                        <div class="notice notice-warning inline">
                            <p><?php echo esc_html__( 'Nenhum canal de venda encontrado ou não foi possível carregar.', 'joinotify-bling-erp' ); ?></p>
                        </div>
                        <button type="button" id="bling-retry-load-channels" class="button button-small">
                            <?php echo esc_html__( 'Tentar novamente', 'joinotify-bling-erp' ); ?>
                        </button>
                    <?php else : ?>
                        <select name="bling_sales_channel_id" id="bling_sales_channel_id" class="regular-text">
                            <option value=""><?php echo esc_html__( '-- Selecione um canal --', 'joinotify-bling-erp' ); ?></option>
                            <?php foreach ( $sales_channels as $channel ) : ?>
                                <option value="<?php echo esc_attr( $channel['id'] ); ?>" 
                                        <?php selected( $current_value, $channel['id'] ); ?>
                                        data-type="<?php echo esc_attr( $channel['tipo'] ); ?>">
                                    <?php echo esc_html( $channel['descricao'] ); ?>
                                    <?php if ( $channel['tipo'] ) : ?>
                                        (<?php echo esc_html( $channel['tipo'] ); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <p class="description">
                    <?php echo esc_html__( 'Selecione o canal de venda que será usado nas notas fiscais. Este campo é opcional.', 'joinotify-bling-erp' ); ?>
                </p>
                <button type="button" id="bling-refresh-channels" class="button button-small" style="margin-top: 5px;">
                    <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                    <?php echo esc_html__( 'Atualizar lista', 'joinotify-bling-erp' ); ?>
                </button>
                <span id="bling-refresh-spinner" class="spinner" style="float: none; margin-left: 5px;"></span>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render nature operation field
     *
     * @since 1.0.2
     * @param string $current_value Current option value.
     * @return void
     */
    private static function render_nature_operation_field( $current_value ) {
        ?>
        <tr>
            <th scope="row">
                <label for="bling_default_nature_operation">
                    <?php echo esc_html__( 'Natureza da Operação Padrão', 'joinotify-bling-erp' ); ?>
                </label>
            </th>
            <td>
                <input type="number" 
                       name="bling_default_nature_operation" 
                       id="bling_default_nature_operation" 
                       value="<?php echo esc_attr( $current_value ); ?>" 
                       class="regular-text" 
                       min="1" />
                <p class="description">
                    <?php echo esc_html__( 'ID da natureza da operação padrão no Bling.', 'joinotify-bling-erp' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render invoice series field
     *
     * @since 1.0.2
     * @param string $current_value Current option value.
     * @return void
     */
    private static function render_invoice_series_field( $current_value ) {
        ?>
        <tr>
            <th scope="row">
                <label for="bling_invoice_series">
                    <?php echo esc_html__( 'Série da Nota Fiscal', 'joinotify-bling-erp' ); ?>
                </label>
            </th>
            <td>
                <input type="text" 
                       name="bling_invoice_series" 
                       id="bling_invoice_series" 
                       value="<?php echo esc_attr( $current_value ); ?>" 
                       class="regular-text" 
                       maxlength="3" />
                <p class="description">
                    <?php echo esc_html__( 'Série utilizada para as notas fiscais (ex: 1, 2, 3).', 'joinotify-bling-erp' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render invoice purpose field
     *
     * @since 1.0.2
     * @param string $current_value Current option value.
     * @return void
     */
    private static function render_invoice_purpose_field( $current_value ) {
        ?>
        <tr>
            <th scope="row">
                <label for="bling_invoice_purpose">
                    <?php echo esc_html__( 'Finalidade da NF-e', 'joinotify-bling-erp' ); ?>
                </label>
            </th>
            <td>
                <select name="bling_invoice_purpose" id="bling_invoice_purpose" class="regular-text">
                    <option value="1" <?php selected( $current_value, '1' ); ?>>
                        <?php echo esc_html__( '1 - NF-e normal', 'joinotify-bling-erp' ); ?>
                    </option>
                    <option value="2" <?php selected( $current_value, '2' ); ?>>
                        <?php echo esc_html__( '2 - NF-e complementar', 'joinotify-bling-erp' ); ?>
                    </option>
                    <option value="3" <?php selected( $current_value, '3' ); ?>>
                        <?php echo esc_html__( '3 - NF-e de ajuste', 'joinotify-bling-erp' ); ?>
                    </option>
                    <option value="4" <?php selected( $current_value, '4' ); ?>>
                        <?php echo esc_html__( '4 - Devolução de mercadoria', 'joinotify-bling-erp' ); ?>
                    </option>
                </select>
                <p class="description">
                    <?php echo esc_html__( 'Selecione a finalidade das notas fiscais emitidas.', 'joinotify-bling-erp' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render send email field
     *
     * @since 1.0.2
     * @param string $current_value Current option value.
     * @return void
     */
    private static function render_send_email_field( $current_value ) {
        ?>
        <tr>
            <th scope="row">
                <label for="bling_send_invoice_email">
                    <?php echo esc_html__( 'Enviar e-mail da NFe', 'joinotify-bling-erp' ); ?>
                </label>
            </th>
            <td>
                <select name="bling_send_invoice_email" id="bling_send_invoice_email" class="regular-text">
                    <option value="yes" <?php selected( $current_value, 'yes' ); ?>>
                        <?php echo esc_html__( 'Sim', 'joinotify-bling-erp' ); ?>
                    </option>
                    <option value="no" <?php selected( $current_value, 'no' ); ?>>
                        <?php echo esc_html__( 'Não', 'joinotify-bling-erp' ); ?>
                    </option>
                </select>
                <p class="description">
                    <?php echo esc_html__( 'Enviar e-mail com a NFe para o cliente após emissão.', 'joinotify-bling-erp' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render sync products field
     *
     * @since 1.0.2
     * @param string $current_value Current option value.
     * @return void
     */
    private static function render_sync_products_field( $current_value ) {
        ?>
        <tr>
            <th scope="row">
                <label for="bling_sync_products">
                    <?php echo esc_html__( 'Sincronizar Produtos', 'joinotify-bling-erp' ); ?>
                </label>
            </th>
            <td>
                <select name="bling_sync_products" id="bling_sync_products" class="regular-text">
                    <option value="yes" <?php selected( $current_value, 'yes' ); ?>>
                        <?php echo esc_html__( 'Sim', 'joinotify-bling-erp' ); ?>
                    </option>
                    <option value="no" <?php selected( $current_value, 'no' ); ?>>
                        <?php echo esc_html__( 'Não', 'joinotify-bling-erp' ); ?>
                    </option>
                </select>
                <p class="description">
                    <?php echo esc_html__( 'Sincronizar produtos do WooCommerce com o Bling automaticamente.', 'joinotify-bling-erp' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render sync customers field
     *
     * @since 1.0.2
     * @param string $current_value Current option value.
     * @return void
     */
    private static function render_sync_customers_field( $current_value ) {
        ?>
        <tr>
            <th scope="row">
                <label for="bling_sync_customers">
                    <?php echo esc_html__( 'Sincronizar Clientes', 'joinotify-bling-erp' ); ?>
                </label>
            </th>
            <td>
                <select name="bling_sync_customers" id="bling_sync_customers" class="regular-text">
                    <option value="yes" <?php selected( $current_value, 'yes' ); ?>>
                        <?php echo esc_html__( 'Sim', 'joinotify-bling-erp' ); ?>
                    </option>
                    <option value="no" <?php selected( $current_value, 'no' ); ?>>
                        <?php echo esc_html__( 'Não', 'joinotify-bling-erp' ); ?>
                    </option>
                </select>
                <p class="description">
                    <?php echo esc_html__( 'Sincronizar clientes do WooCommerce com o Bling automaticamente.', 'joinotify-bling-erp' ); ?>
                </p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Enqueue scripts and styles
     *
     * @since 1.0.2
     * @return void
     */
    private static function enqueue_scripts() {
        wp_enqueue_script( 'bling-admin-settings' );
        wp_enqueue_style( 'bling-admin-style' );
        
        // Localize script for AJAX
        wp_localize_script( 'bling-admin-settings', 'blingSettings', array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'bling_settings_nonce' ),
            'loading'    => __( 'Carregando...', 'joinotify-bling-erp' ),
            'success'    => __( 'Sucesso!', 'joinotify-bling-erp' ),
            'error'      => __( 'Erro!', 'joinotify-bling-erp' ),
        ) );
    }
    
    /**
     * AJAX handler: Refresh sales channels
     *
     * @since 1.0.2
     * @return void
     */
    public static function ajax_refresh_sales_channels() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'bling_settings_nonce' ) ) {
            wp_die( esc_html__( 'Requisição inválida.', 'joinotify-bling-erp' ) );
        }
        
        // Clear cache
        delete_transient( 'bling_sales_channels_list' );
        self::$sales_channels_cache = null;
        
        // Get fresh data
        $sales_channels = self::get_sales_channels();
        
        if ( is_wp_error( $sales_channels ) ) {
            wp_send_json_error( array(
                'message' => $sales_channels->get_error_message(),
            ) );
        }
        
        if ( empty( $sales_channels ) ) {
            wp_send_json_error( array(
                'message' => __( 'Nenhum canal de venda encontrado.', 'joinotify-bling-erp' ),
            ) );
        }
        
        // Generate options HTML
        $options_html = '<option value="">' . esc_html__( '-- Selecione um canal --', 'joinotify-bling-erp' ) . '</option>';
        foreach ( $sales_channels as $channel ) {
            $selected = '';
            $type_html = $channel['tipo'] ? ' (' . esc_html( $channel['tipo'] ) . ')' : '';
            $options_html .= sprintf(
                '<option value="%s" data-type="%s">%s%s</option>',
                esc_attr( $channel['id'] ),
                esc_attr( $channel['tipo'] ),
                esc_html( $channel['descricao'] ),
                $type_html
            );
        }
        
        wp_send_json_success( array(
            'message'     => __( 'Lista de canais atualizada com sucesso!', 'joinotify-bling-erp' ),
            'options_html' => $options_html,
        ) );
    }
    
    /**
     * AJAX handler: Test connection
     *
     * @since 1.0.2
     * @return void
     */
    public static function ajax_test_connection() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'bling_settings_nonce' ) ) {
            wp_die( esc_html__( 'Requisição inválida.', 'joinotify-bling-erp' ) );
        }
        
        try {
            // Simple API call to test connection
            $response = Client::get_sales_channels();
            
            if ( is_wp_error( $response ) ) {
                wp_send_json_error( array(
                    'message' => sprintf(
                        __( 'Erro na conexão: %s', 'joinotify-bling-erp' ),
                        $response->get_error_message()
                    ),
                ) );
            }
            
            wp_send_json_success( array(
                'message' => __( 'Conexão com a API do Bling estabelecida com sucesso!', 'joinotify-bling-erp' ),
            ) );
            
        } catch ( \Exception $e ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( 'Erro na conexão: %s', 'joinotify-bling-erp' ),
                    $e->getMessage()
                ),
            ) );
        }
    }
    
    /**
     * AJAX handler: Clear cache
     *
     * @since 1.0.2
     * @return void
     */
    public static function ajax_clear_cache() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'bling_settings_nonce' ) ) {
            wp_die( esc_html__( 'Requisição inválida.', 'joinotify-bling-erp' ) );
        }
        
        $cleared_items = array();
        
        // Clear sales channels cache
        delete_transient( 'bling_sales_channels_list' );
        self::$sales_channels_cache = null;
        $cleared_items[] = __( 'Canais de venda', 'joinotify-bling-erp' );
        
        // Clear invoice status cache
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bling_invoice_status_%'" );
        $cleared_items[] = __( 'Status de notas fiscais', 'joinotify-bling-erp' );
        
        // Clear general API cache
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bling_%'" );
        $cleared_items[] = __( 'Cache geral da API', 'joinotify-bling-erp' );
        
        wp_send_json_success( array(
            'message' => sprintf(
                __( 'Cache limpo com sucesso! Itens limpos: %s', 'joinotify-bling-erp' ),
                implode( ', ', $cleared_items )
            ),
        ) );
    }
    
    /**
     * Sanitize trigger statuses array
     *
     * @since 1.0.2
     * @param mixed $input Input value.
     * @return array Sanitized array.
     */
    public static function sanitize_trigger_statuses( $input ) {
        if ( ! is_array( $input ) ) {
            return self::$default_settings['bling_invoice_trigger_statuses'];
        }
        
        $valid_statuses = array_keys( wc_get_order_statuses() );
        $valid_statuses = array_map( function( $status ) {
            return str_replace( 'wc-', '', $status );
        }, $valid_statuses );
        
        $sanitized = array();
        foreach ( $input as $status ) {
            $clean_status = sanitize_text_field( $status );
            if ( in_array( $clean_status, $valid_statuses ) ) {
                $sanitized[] = $clean_status;
            }
        }
        
        return ! empty( $sanitized ) ? $sanitized : self::$default_settings['bling_invoice_trigger_statuses'];
    }
    
    /**
     * Sanitize yes/no option
     *
     * @since 1.0.2
     * @param mixed $input Input value.
     * @return string Sanitized value.
     */
    public static function sanitize_yes_no( $input ) {
        $input = sanitize_text_field( $input );

        return in_array( $input, array( 'yes', 'no' ) ) ? $input : 'no';
    }
    
    /**
     * Sanitize invoice purpose option
     *
     * @since 1.0.2
     * @param mixed $input Input value.
     * @return string Sanitized value.
     */
    public static function sanitize_invoice_purpose( $input ) {
        $input = sanitize_text_field( $input );

        return in_array( $input, array( '1', '2', '3', '4' ) ) ? $input : '1';
    }
}