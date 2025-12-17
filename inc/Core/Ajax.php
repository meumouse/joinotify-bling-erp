<?php

namespace MeuMouse\Joinotify\Bling\Core;

use MeuMouse\Joinotify\Bling\API\Client;
use MeuMouse\Joinotify\Bling\Integrations\Woocommerce;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * AJAX handlers for Bling integration.
 *
 * @since 1.0.0
 * @version 1.0.1
 * @package MeuMouse\Joinotify\Bling\Core
 * @author MeuMouse.com
 */
class Ajax {
    
    /**
     * Constructor
     *
     * @since 1.0.0
     * @version 1.0.1
     * @return void
     */
    public function __construct() {
        // Bulk sync handlers
        add_action( 'wp_ajax_bling_bulk_sync_products', array( __CLASS__, 'bulk_sync_products' ) );
        add_action( 'wp_ajax_bling_bulk_sync_customers', array( __CLASS__, 'bulk_sync_customers' ) );
        
        // Utility handlers
        add_action( 'wp_ajax_bling_test_connection', array( __CLASS__, 'test_connection' ) );
        
        // Order handlers
        add_action( 'wp_ajax_bling_create_invoice_for_order', array( __CLASS__, 'create_invoice_for_order_callback' ) );
        add_action( 'wp_ajax_bling_get_invoice_status', array( __CLASS__, 'get_invoice_status' ) );
        
        // Product handlers
        add_action( 'wp_ajax_bling_sync_single_product', array( __CLASS__, 'sync_single_product' ) );
        add_action( 'wp_ajax_bling_get_product_status', array( __CLASS__, 'get_product_status' ) );

        add_action( 'wp_ajax_bling_create_invoice_ajax', array( $this, 'ajax_create_invoice' ) );
        add_action( 'wp_ajax_bling_check_invoice_status_ajax', array( $this, 'ajax_check_invoice_status' ) );

        // load sales channels
        add_action('wp_ajax_bling_get_sales_channels', array(__CLASS__, 'ajax_get_sales_channels'));
    }

    
    /**
     * Bulk sync products AJAX handler.
     *
     * @since 1.0.0
     * @return void
     */
    public static function bulk_sync_products() {
        check_ajax_referer('bling_bulk_sync', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Get all published products
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        );
        
        $products = get_posts($args);
        $synced = 0;
        $failed = 0;
        $errors = array();
        
        foreach ($products as $post) {
            $product = wc_get_product($post->ID);
            
            if ($product) {
                // Trigger product sync
                do_action('save_post_product', $post->ID, $post, true);
                $synced++;
            } else {
                $failed++;
                $errors[] = sprintf(
                    __('Produto ID %d não encontrado', 'joinotify-bling-erp'),
                    $post->ID
                );
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('%d produtos sincronizados com sucesso, %d falhas.', 'joinotify-bling-erp'),
                $synced,
                $failed
            ),
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors,
        ));
    }

    
    /**
     * Bulk sync customers AJAX handler.
     *
     * @since 1.0.0
     * @return void
     */
    public static function bulk_sync_customers() {
        check_ajax_referer('bling_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Get all customers
        $users = get_users(array('role' => 'customer'));
        $synced = 0;
        $failed = 0;
        $errors = array();
        
        foreach ($users as $user) {
            try {
                // Trigger customer sync
                do_action('profile_update', $user->ID, $user);
                $synced++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = sprintf(
                    __('Usuário %s: %s', 'joinotify-bling-erp'),
                    $user->user_email,
                    $e->getMessage()
                );
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('%d clientes sincronizados com sucesso, %d falhas.', 'joinotify-bling-erp'),
                $synced,
                $failed
            ),
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors,
        ));
    }
    

    /**
     * Test connection AJAX handler.
     *
     * @since 1.0.0
     * @return void
     */
    public static function test_connection() {
        check_ajax_referer('bling_admin_nonce', 'nonce');
        
        if ( ! current_user_can('manage_options') ) {
            wp_die('Unauthorized');
        }
        
        // Check if we have access token
        $access_token = get_option('bling_access_token');
        
        if ( empty( $access_token ) ) {
            wp_send_json_error(__('Token de acesso não configurado. Configure as credenciais primeiro.', 'joinotify-bling-erp'));
        }
        
        // Test connection by fetching categories
        try {
            $client = new Client();
            $response = $client::get_categories( 1, 1 );
            
            if ( is_wp_error( $response ) ) {
                wp_send_json_error( $response->get_error_message() );
            }
            
            if ( $response['status'] === 200 ) {
                wp_send_json_success( __('Conexão estabelecida com sucesso! A API do Bling está respondendo normalmente.', 'joinotify-bling-erp') );
            } else {
                wp_send_json_error( sprintf(
                    __('Falha na conexão. Status HTTP: %d', 'joinotify-bling-erp'),
                    $response['status']
                ));
            }
        } catch ( \Exception $e ) {
            wp_send_json_error( sprintf(
                __('Erro ao testar conexão: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }


    /**
     * Create invoice for order AJAX handler.
     *
     * @since 1.0.0
     * @return void
     */
    public static function create_invoice_for_order_callback() {
        check_ajax_referer('bling_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if (!$order_id) {
            wp_send_json_error(__('ID do pedido é obrigatório.', 'joinotify-bling-erp'));
        }
        
        try {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                wp_send_json_error(__('Pedido não encontrado.', 'joinotify-bling-erp'));
            }
            
            // Check if invoice already exists
            $existing_invoice_id = $order->get_meta('_bling_invoice_id');
            
            if ($existing_invoice_id) {
                wp_send_json_error(sprintf(
                    __('Nota fiscal já existe para este pedido (ID: %d).', 'joinotify-bling-erp'),
                    $existing_invoice_id
                ));
            }
            
            // Create invoice
            $woocommerce = new Woocommerce();
            $result = $woocommerce->create_invoice_for_order( $order );
            
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }
            
            wp_send_json_success(array(
                'message' => __('Nota fiscal criada com sucesso!', 'joinotify-bling-erp'),
                'invoice_id' => $result,
            ));
        } catch (\Exception $e) {
            wp_send_json_error(sprintf(
                __('Erro ao criar nota fiscal: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }

    
    /**
     * Get invoice status AJAX handler.
     *
     * @since 1.0.0
     * @return void
     */
    public static function get_invoice_status() {
        check_ajax_referer('bling_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if (!$order_id) {
            wp_send_json_error(__('ID do pedido é obrigatório.', 'joinotify-bling-erp'));
        }
        
        try {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                wp_send_json_error(__('Pedido não encontrado.', 'joinotify-bling-erp'));
            }
            
            $invoice_id = $order->get_meta('_bling_invoice_id');
            
            if (!$invoice_id) {
                wp_send_json_error(__('Nenhuma nota fiscal vinculada a este pedido.', 'joinotify-bling-erp'));
            }
            
            // Get invoice details
            $client = new Client();
            $response = $client::get_invoice($invoice_id);
            
            if (is_wp_error($response)) {
                wp_send_json_error($response->get_error_message());
            }
            
            $invoice_data = isset($response['data']['data'][0]) ? $response['data']['data'][0] : array();
            
            ob_start(); ?>

            <div class="bling-invoice-details">
                <h4><?php echo esc_html__('Detalhes da Nota Fiscal', 'joinotify-bling-erp'); ?></h4>
                
                <?php if (!empty($invoice_data)) : ?>
                    <table class="widefat">
                        <tr>
                            <th><?php echo esc_html__('ID Bling:', 'joinotify-bling-erp'); ?></th>
                            <td><?php echo esc_html($invoice_data['id'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Número:', 'joinotify-bling-erp'); ?></th>
                            <td><?php echo esc_html($invoice_data['numero'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Situação:', 'joinotify-bling-erp'); ?></th>
                            <td>
                                <?php 
                                $status = $invoice_data['situacao'] ?? 0;
                                echo WooCommerce::get_invoice_status_label($status);
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Chave de Acesso:', 'joinotify-bling-erp'); ?></th>
                            <td style="word-break: break-all;"><?php echo esc_html($invoice_data['chave'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Data de Emissão:', 'joinotify-bling-erp'); ?></th>
                            <td>
                                <?php 
                                if (isset($invoice_data['dataEmissao'])) {
                                    echo esc_html(date_i18n('d/m/Y H:i', strtotime($invoice_data['dataEmissao'])));
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Valor Total:', 'joinotify-bling-erp'); ?></th>
                            <td>
                                <?php 
                                if (isset($invoice_data['valor'])) {
                                    echo wc_price($invoice_data['valor']);
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                    
                    <p style="margin-top: 15px;">
                        <a href="https://www.bling.com.br/nfe/<?php echo esc_attr($invoice_id); ?>" 
                           target="_blank" class="button button-primary">
                            <?php echo esc_html__('Ver no Bling', 'joinotify-bling-erp'); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p><?php echo esc_html__('Detalhes da nota fiscal não disponíveis.', 'joinotify-bling-erp'); ?></p>
                <?php endif; ?>
            </div>
            <?php
            $html = ob_get_clean();
            
            wp_send_json_success(array(
                'html' => $html,
                'invoice_data' => $invoice_data,
            ));
        } catch (\Exception $e) {
            wp_send_json_error(sprintf(
                __('Erro ao obter status da nota fiscal: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }
    

    /**
     * Sync single product AJAX handler.
     *
     * @since 1.0.0
     * @return void
     */
    public static function sync_single_product() {
        check_ajax_referer('bling_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        
        if (!$product_id) {
            wp_send_json_error(__('ID do produto é obrigatório.', 'joinotify-bling-erp'));
        }
        
        try {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                wp_send_json_error(__('Produto não encontrado.', 'joinotify-bling-erp'));
            }
            
            // Trigger product sync
            $post = get_post($product_id);
            do_action('save_post_product', $product_id, $post, true);
            
            // Check if sync was successful
            $bling_id = $product->get_meta('_bling_product_id');
            
            if ($bling_id) {
                wp_send_json_success(array(
                    'message' => sprintf(
                        __('Produto sincronizado com sucesso! ID Bling: %d', 'joinotify-bling-erp'),
                        $bling_id
                    ),
                    'bling_id' => $bling_id,
                ));
            } else {
                wp_send_json_error(__('Produto sincronizado, mas ID do Bling não foi retornado.', 'joinotify-bling-erp'));
            }
        } catch (\Exception $e) {
            wp_send_json_error(sprintf(
                __('Erro ao sincronizar produto: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }
    
    
    /**
     * Get product sync status AJAX handler.
     *
     * @since 1.0.0
     * @return void
     */
    public static function get_product_status() {
        check_ajax_referer('bling_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        
        if (!$product_id) {
            wp_send_json_error(__('ID do produto é obrigatório.', 'joinotify-bling-erp'));
        }
        
        try {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                wp_send_json_error(__('Produto não encontrado.', 'joinotify-bling-erp'));
            }
            
            $bling_id = $product->get_meta('_bling_product_id');
            $last_sync = $product->get_meta('_bling_last_sync');
            $sync_error = $product->get_meta('_bling_sync_error');
            
            ob_start();
            ?>
            <div class="bling-product-status">
                <h4><?php echo esc_html__('Status de Sincronização', 'joinotify-bling-erp'); ?></h4>
                
                <table class="widefat">
                    <tr>
                        <th><?php echo esc_html__('ID Bling:', 'joinotify-bling-erp'); ?></th>
                        <td>
                            <?php if ($bling_id) : ?>
                                <?php echo esc_html($bling_id); ?>
                            <?php else : ?>
                                <span style="color: #d63638;"><?php echo esc_html__('Não sincronizado', 'joinotify-bling-erp'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Última Sincronização:', 'joinotify-bling-erp'); ?></th>
                        <td>
                            <?php if ($last_sync) : ?>
                                <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($last_sync))); ?>
                            <?php else : ?>
                                <?php echo esc_html__('Nunca sincronizado', 'joinotify-bling-erp'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__('Status:', 'joinotify-bling-erp'); ?></th>
                        <td>
                            <?php if ($sync_error) : ?>
                                <span style="color: #d63638;">
                                    <?php echo esc_html__('Erro na sincronização', 'joinotify-bling-erp'); ?>
                                </span>
                            <?php elseif ($bling_id) : ?>
                                <span style="color: #00a32a;">
                                    <?php echo esc_html__('Sincronizado', 'joinotify-bling-erp'); ?>
                                </span>
                            <?php else : ?>
                                <span style="color: #dba617;">
                                    <?php echo esc_html__('Pendente', 'joinotify-bling-erp'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($sync_error) : ?>
                        <tr>
                            <th><?php echo esc_html__('Erro:', 'joinotify-bling-erp'); ?></th>
                            <td style="color: #d63638;"><?php echo esc_html($sync_error); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
                
                <p style="margin-top: 15px;">
                    <button class="button button-secondary bling-sync-product" 
                            data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php echo esc_html__('Sincronizar Agora', 'joinotify-bling-erp'); ?>
                    </button>
                </p>
            </div>

            <?php $html = ob_get_clean();
            
            wp_send_json_success(array(
                'html' => $html,
                'status' => array(
                    'bling_id' => $bling_id,
                    'last_sync' => $last_sync,
                    'has_error' => !empty($sync_error),
                ),
            ));
        } catch (\Exception $e) {
            wp_send_json_error(sprintf(
                __('Erro ao obter status do produto: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }


    /**
     * Handle AJAX request to create invoice
     *
     * @since 1.0.1
     * @return void
     */
    public function ajax_create_invoice() {
        check_ajax_referer('bling_admin_nonce', 'nonce');
        
        if ( ! current_user_can('manage_woocommerce') ) {
            wp_die('Unauthorized');
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        
        if ( ! $order ) {
            wp_send_json_error('Pedido não encontrado.');
        }
        
        $woocommerce = new Woocommerce();
        $result = $woocommerce->create_invoice_for_order( $order );
        
        if ( is_wp_error($result) ) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(array(
                'invoice_id' => $result,
                'message' => 'Nota fiscal criada com sucesso!'
            ));
        }
    }

    /**
     * Handle AJAX request to check invoice status
     *
     * @since 1.0.1
     * @return void
     */
    public function ajax_check_invoice_status() {
        check_ajax_referer('bling_admin_nonce', 'nonce');
        
        if ( ! current_user_can('manage_woocommerce') ) {
            wp_die('Unauthorized');
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        
        if ( ! $order ) {
            wp_send_json_error('Pedido não encontrado.');
        }
        
        $invoice_id = $order->get_meta('_bling_invoice_id');
        
        if ( ! $invoice_id ) {
            wp_send_json_error('Nenhuma nota fiscal vinculada a este pedido.');
        }
        
        $response = Client::get_invoice($invoice_id);
        
        if ( is_wp_error($response) ) {
            wp_send_json_error('Erro ao verificar status: ' . $response->get_error_message());
        }
        
        if ( isset($response['data']['data']['situacao']) ) {
            $invoice_data = $response['data']['data'];
            $status = WooCommerce::get_invoice_status_label($invoice_data['situacao']);
            
            wp_send_json_success(array('status' => $status));
        } elseif ( isset($response['data']['situacao']) ) {
            $invoice_data = $response['data'];
            $status = WooCommerce::get_invoice_status_label($invoice_data['situacao']);

            wp_send_json_success(array('status' => $status));
        } elseif ( isset($response['data']['data'][0]['situacao']) ) {
            $invoice_data = $response['data']['data'][0];
            $status = WooCommerce::get_invoice_status_label($invoice_data['situacao']);

            wp_send_json_success(array('status' => $status));
        } else {
            error_log('Estrutura inesperada da resposta: ' . print_r($response, true));

            wp_send_json_error('Não foi possível obter o status da nota fiscal. Estrutura da resposta inesperada.');
        }
    }


    /**
     * AJAX handler to get sales channels
     *
     * @since 1.0.1
     * @return void
     */
    public static function ajax_get_sales_channels() {
        check_ajax_referer('bling_admin_nonce', 'nonce');
        
        if ( ! current_user_can('manage_options') ) {
            wp_die('Unauthorized');
        }
        
        $channels = Client::get_sales_channels_from_bling();
        
        if ( is_wp_error( $channels ) ) {
            wp_send_json_error( $channels->get_error_message() );
        } else {
            wp_send_json_success( $channels );
        }
    }
}