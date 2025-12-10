<?php

namespace MeuMouse\Joinotify\Bling\Core;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * AJAX handlers for Bling integration.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Ajax {
    
    /**
     * Initialize AJAX handlers.
     *
     * @return void
     */
    public static function init() {
        // Bulk sync handlers
        add_action('wp_ajax_bling_bulk_sync_products', array(__CLASS__, 'bulk_sync_products'));
        add_action('wp_ajax_bling_bulk_sync_customers', array(__CLASS__, 'bulk_sync_customers'));
        
        // Utility handlers
        add_action('wp_ajax_bling_test_connection', array(__CLASS__, 'test_connection'));
        add_action('wp_ajax_bling_clear_cache', array(__CLASS__, 'clear_cache'));
        
        // Webhook handlers
        add_action('wp_ajax_bling_create_webhook', array(__CLASS__, 'create_webhook'));
        add_action('wp_ajax_bling_delete_webhook', array(__CLASS__, 'delete_webhook'));
        add_action('wp_ajax_bling_get_webhooks', array(__CLASS__, 'get_webhooks'));
        
        // Order handlers
        add_action('wp_ajax_bling_create_invoice_for_order', array(__CLASS__, 'create_invoice_for_order'));
        add_action('wp_ajax_bling_get_invoice_status', array(__CLASS__, 'get_invoice_status'));
        
        // Product handlers
        add_action('wp_ajax_bling_sync_single_product', array(__CLASS__, 'sync_single_product'));
        add_action('wp_ajax_bling_get_product_status', array(__CLASS__, 'get_product_status'));
    }

    
    /**
     * Bulk sync products AJAX handler.
     *
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
     * @return void
     */
    public static function bulk_sync_customers() {
        check_ajax_referer('bling_bulk_sync', 'nonce');
        
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
     * @return void
     */
    public static function test_connection() {
        check_ajax_referer('bling_test_connection', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Check if we have access token
        $access_token = get_option('bling_access_token');
        
        if (empty($access_token)) {
            wp_send_json_error(__('Token de acesso não configurado. Configure as credenciais primeiro.', 'joinotify-bling-erp'));
        }
        
        // Test connection by fetching categories
        try {
            $client = new \MeuMouse\Joinotify\Bling\API\Client();
            $response = $client::get_categories(1, 1);
            
            if (is_wp_error($response)) {
                wp_send_json_error($response->get_error_message());
            }
            
            if ($response['status'] === 200) {
                wp_send_json_success(__('Conexão estabelecida com sucesso! A API do Bling está respondendo normalmente.', 'joinotify-bling-erp'));
            } else {
                wp_send_json_error(sprintf(
                    __('Falha na conexão. Status HTTP: %d', 'joinotify-bling-erp'),
                    $response['status']
                ));
            }
        } catch (\Exception $e) {
            wp_send_json_error(sprintf(
                __('Erro ao testar conexão: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }

    
    /**
     * Clear cache AJAX handler.
     *
     * @return void
     */
    public static function clear_cache() {
        check_ajax_referer('bling_clear_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        try {
            // Clear transients and meta data
            global $wpdb;
            
            // Delete Bling transients
            $deleted_transients = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bling_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bling_%'");
            
            // Clear product sync meta
            $deleted_product_meta = $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_bling_product_id', '_bling_last_sync', '_bling_sync_error')");
            
            // Clear order sync meta
            $deleted_order_meta = $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_bling_invoice_id', '_bling_order_sync', '_bling_invoice_error')");
            
            // Clear user sync meta
            $deleted_user_meta = $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('_bling_contact_id', '_bling_sync_date', '_bling_sync_error')");
            
            // Clear all cache
            wp_cache_flush();
            
            wp_send_json_success(array(
                'message' => __('Cache limpo com sucesso!', 'joinotify-bling-erp'),
                'stats' => array(
                    'transients' => $deleted_transients,
                    'product_meta' => $deleted_product_meta,
                    'order_meta' => $deleted_order_meta,
                    'user_meta' => $deleted_user_meta,
                ),
            ));
        } catch (\Exception $e) {
            wp_send_json_error(sprintf(
                __('Erro ao limpar cache: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }
    

    /**
     * Create webhook AJAX handler.
     *
     * @return void
     */
    public static function create_webhook() {
        check_ajax_referer('bling_webhook', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $event = sanitize_text_field($_POST['event'] ?? '');
        $url = esc_url_raw($_POST['url'] ?? '');
        
        if (empty($event) || empty($url)) {
            wp_send_json_error(__('Evento e URL são obrigatórios.', 'joinotify-bling-erp'));
        }
        
        try {
            $client = new \MeuMouse\Joinotify\Bling\API\Client();
            $response = $client::create_webhook(array(
                'event' => $event,
                'url' => $url,
                'status' => 'active',
            ));
            
            if (is_wp_error($response)) {
                wp_send_json_error($response->get_error_message());
            }
            
            if ($response['status'] === 201 || $response['status'] === 200) {
                wp_send_json_success(__('Webhook criado com sucesso!', 'joinotify-bling-erp'));
            } else {
                wp_send_json_error(sprintf(
                    __('Erro ao criar webhook. Status: %d', 'joinotify-bling-erp'),
                    $response['status']
                ));
            }
        } catch (\Exception $e) {
            wp_send_json_error(sprintf(
                __('Erro ao criar webhook: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }
    

    /**
     * Delete webhook AJAX handler.
     *
     * @return void
     */
    public static function delete_webhook() {
        check_ajax_referer('bling_webhook', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $webhook_id = intval($_POST['webhook_id'] ?? 0);
        
        if (!$webhook_id) {
            wp_send_json_error(__('ID do webhook é obrigatório.', 'joinotify-bling-erp'));
        }
        
        try {
            $client = new \MeuMouse\Joinotify\Bling\API\Client();
            $response = $client::delete_webhook($webhook_id);
            
            if (is_wp_error($response)) {
                wp_send_json_error($response->get_error_message());
            }
            
            if ($response['status'] === 200 || $response['status'] === 204) {
                wp_send_json_success(__('Webhook excluído com sucesso!', 'joinotify-bling-erp'));
            } else {
                wp_send_json_error(sprintf(
                    __('Erro ao excluir webhook. Status: %d', 'joinotify-bling-erp'),
                    $response['status']
                ));
            }
        } catch (\Exception $e) {
            wp_send_json_error(sprintf(
                __('Erro ao excluir webhook: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }
    

    /**
     * Get webhooks AJAX handler.
     *
     * @return void
     */
    public static function get_webhooks() {
        check_ajax_referer('bling_webhook', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        try {
            $client = new \MeuMouse\Joinotify\Bling\API\Client();
            $response = $client::get_webhooks();
            
            if (is_wp_error($response)) {
                wp_send_json_error($response->get_error_message());
            }
            
            $webhooks = isset($response['data']['data']) ? $response['data']['data'] : array();
            
            ob_start();
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('ID', 'joinotify-bling-erp'); ?></th>
                        <th><?php echo esc_html__('Evento', 'joinotify-bling-erp'); ?></th>
                        <th><?php echo esc_html__('URL', 'joinotify-bling-erp'); ?></th>
                        <th><?php echo esc_html__('Status', 'joinotify-bling-erp'); ?></th>
                        <th><?php echo esc_html__('Criado em', 'joinotify-bling-erp'); ?></th>
                        <th><?php echo esc_html__('Ações', 'joinotify-bling-erp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($webhooks)) : ?>
                        <tr>
                            <td colspan="6"><?php echo esc_html__('Nenhum webhook configurado.', 'joinotify-bling-erp'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($webhooks as $webhook) : ?>
                            <tr>
                                <td><?php echo esc_html($webhook['id'] ?? '-'); ?></td>
                                <td><?php echo esc_html($webhook['event'] ?? '-'); ?></td>
                                <td style="word-break: break-all;"><?php echo esc_html($webhook['url'] ?? '-'); ?></td>
                                <td>
                                    <?php if (($webhook['status'] ?? '') === 'active') : ?>
                                        <span class="bling-status-active"><?php echo esc_html__('Ativo', 'joinotify-bling-erp'); ?></span>
                                    <?php else : ?>
                                        <span class="bling-status-inactive"><?php echo esc_html__('Inativo', 'joinotify-bling-erp'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    if (isset($webhook['created_at'])) {
                                        echo esc_html(date_i18n('d/m/Y H:i', strtotime($webhook['created_at'])));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <button class="button button-small button-danger bling-delete-webhook" 
                                            data-id="<?php echo esc_attr($webhook['id'] ?? ''); ?>">
                                        <?php echo esc_html__('Excluir', 'joinotify-bling-erp'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php
            $html = ob_get_clean();
            
            wp_send_json_success(array(
                'html' => $html,
                'count' => count($webhooks),
            ));
        } catch (\Exception $e) {
            wp_send_json_error(sprintf(
                __('Erro ao obter webhooks: %s', 'joinotify-bling-erp'),
                $e->getMessage()
            ));
        }
    }
    

    /**
     * Create invoice for order AJAX handler.
     *
     * @return void
     */
    public static function create_invoice_for_order() {
        check_ajax_referer('bling_order_action', 'nonce');
        
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
            $woocommerce_integration = new \MeuMouse\Joinotify\Bling\Integrations\WooCommerce();
            $result = $woocommerce_integration::create_invoice_for_order($order);
            
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
     * @return void
     */
    public static function get_invoice_status() {
        check_ajax_referer('bling_order_action', 'nonce');
        
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
            $client = new \MeuMouse\Joinotify\Bling\API\Client();
            $response = $client::get_invoice($invoice_id);
            
            if (is_wp_error($response)) {
                wp_send_json_error($response->get_error_message());
            }
            
            $invoice_data = isset($response['data']['data'][0]) ? $response['data']['data'][0] : array();
            
            ob_start();
            ?>
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
                                echo \MeuMouse\Joinotify\Bling\Integrations\WooCommerce::get_invoice_status_label($status);
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
     * @return void
     */
    public static function sync_single_product() {
        check_ajax_referer('bling_product_action', 'nonce');
        
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
     * @return void
     */
    public static function get_product_status() {
        check_ajax_referer('bling_product_action', 'nonce');
        
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
            <?php
            $html = ob_get_clean();
            
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
}