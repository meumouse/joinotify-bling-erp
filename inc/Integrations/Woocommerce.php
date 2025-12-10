<?php

namespace MeuMouse\Joinotify\Bling\Integrations;

use MeuMouse\Joinotify\Bling\API\Client;
use MeuMouse\Joinotify\Bling\API\Controller;

/**
 * WooCommerce integration for Bling.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Woocommerce {
    
    /**
     * Initialize WooCommerce integration.
     *
     * @return void
     */
    public static function init() {
        // Sync products on save
        add_action('save_post_product', array(__CLASS__, 'sync_product_to_bling'), 10, 3);
        
        // Sync order on status change
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'handle_order_status_change'), 10, 4);
        
        // Add custom order actions
        add_filter('woocommerce_order_actions', array(__CLASS__, 'add_order_actions'));
        add_action('woocommerce_order_action_bling_create_invoice', array(__CLASS__, 'create_invoice_manually'));
        
        // Add meta boxes
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_boxes'));
        
        // Sync customer to Bling
        add_action('user_register', array(__CLASS__, 'sync_customer_to_bling'), 10, 1);
        add_action('profile_update', array(__CLASS__, 'sync_customer_to_bling'), 10, 2);
    }
    

    /**
     * Sync WooCommerce product to Bling.
     *
     * @param int $post_id Product ID.
     * @param \WP_Post $post Post object.
     * @param bool $update Whether this is an update.
     * @return void
     */
    public static function sync_product_to_bling($post_id, $post, $update) {
        if (!get_option('bling_sync_products', 'no') === 'yes') {
            return;
        }
        
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        $product = wc_get_product($post_id);
        
        if (!$product) {
            return;
        }
        
        $bling_product_data = self::prepare_product_data($product);
        
        // Check if product already exists in Bling
        $sku = $product->get_sku();
        $existing = null;
        
        if ($sku) {
            $response = Client::get_products(array('codigo' => $sku));
            
            if (!is_wp_error($response) && isset($response['data']['data'][0])) {
                $existing = $response['data']['data'][0];
            }
        }
        
        if ($existing) {
            // Update existing product
            Client::update_product($existing['id'], $bling_product_data);
            update_post_meta($post_id, '_bling_product_id', $existing['id']);
        } else {
            // Create new product
            $response = Client::create_product($bling_product_data);
            
            if (!is_wp_error($response) && isset($response['data']['data'][0]['id'])) {
                $bling_id = $response['data']['data'][0]['id'];
                update_post_meta($post_id, '_bling_product_id', $bling_id);
            }
        }
    }
    

    /**
     * Prepare product data for Bling API.
     *
     * @param \WC_Product $product WooCommerce product.
     * @return array Product data for Bling.
     */
    private static function prepare_product_data($product) {
        $data = array(
            'nome' => $product->get_name(),
            'codigo' => $product->get_sku() ?: 'WC_' . $product->get_id(),
            'preco' => floatval($product->get_price()),
            'precoCusto' => floatval($product->get_cost() ?: $product->get_price()),
            'unidade' => 'UN',
            'descricaoCurta' => $product->get_short_description(),
            'descricaoComplementar' => $product->get_description(),
            'pesoLiquido' => $product->get_weight() ? floatval($product->get_weight()) : 0.1,
            'pesoBruto' => $product->get_weight() ? floatval($product->get_weight()) : 0.1,
            'estoque' => $product->get_stock_quantity() ? intval($product->get_stock_quantity()) : 0,
            'tipo' => 'P', // Produto
            'situacao' => $product->get_status() === 'publish' ? 'A' : 'I',
        );
        
        // Handle categories
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        
        if (!empty($categories) && !is_wp_error($categories)) {
            $category_names = array();
            foreach ($categories as $category) {
                $category_names[] = $category->name;
            }
            $data['categoria'] = array('descricao' => implode(', ', $category_names));
        }
        
        return $data;
    }
    

    /**
     * Handle WooCommerce order status change.
     *
     * @param int $order_id Order ID.
     * @param string $old_status Old status.
     * @param string $new_status New status.
     * @param \WC_Order $order Order object.
     * @return void
     */
    public static function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        $trigger_statuses = get_option('bling_invoice_trigger_statuses', array('completed', 'processing'));
        
        if (in_array($new_status, $trigger_statuses)) {
            // Check if invoice already exists
            $existing_invoice_id = $order->get_meta('_bling_invoice_id');
            
            if (!$existing_invoice_id) {
                // Create invoice in Bling
                self::create_invoice_for_order($order);
            }
        }
    }
    

    /**
     * Create invoice in Bling for WooCommerce order.
     *
     * @param \WC_Order $order WooCommerce order.
     * @return int|\WP_Error Invoice ID or error.
     */
    public static function create_invoice_for_order($order) {
        // First, ensure customer exists in Bling
        $customer_id = self::sync_order_customer_to_bling($order);
        
        if (is_wp_error($customer_id)) {
            return $customer_id;
        }
        
        // Prepare invoice data
        $invoice_data = self::prepare_invoice_data($order, $customer_id);
        
        // Create invoice in Bling
        $response = Client::create_invoice($invoice_data);
        
        if (is_wp_error($response)) {
            error_log('Bling Invoice Error: ' . $response->get_error_message());
            return $response;
        }
        
        if (isset($response['data']['data'][0]['id'])) {
            $invoice_id = $response['data']['data'][0]['id'];
            
            // Save invoice ID to order meta
            $order->update_meta_data('_bling_invoice_id', $invoice_id);
            $order->save();
            
            // Log the action
            $order->add_order_note(
                sprintf(
                    __('Nota fiscal criada no Bling (ID: %d)', 'joinotify-bling-erp'),
                    $invoice_id
                )
            );
            
            return $invoice_id;
        }
        
        return new \WP_Error('invoice_creation_failed', 'Falha ao criar nota fiscal no Bling');
    }

    
    /**
     * Sync order customer to Bling.
     *
     * @param \WC_Order $order WooCommerce order.
     * @return int|\WP_Error Customer ID in Bling.
     */
    private static function sync_order_customer_to_bling($order) {
        $customer_data = array(
            'nome' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'tipo' => 'F', // Física
            'numeroDocumento' => $order->get_meta('_billing_cpf') ?: $order->get_meta('_billing_cnpj'),
            'email' => $order->get_billing_email(),
            'telefone' => $order->get_billing_phone(),
            'endereco' => array(
                'endereco' => $order->get_billing_address_1(),
                'numero' => $order->get_meta('_billing_number'),
                'complemento' => $order->get_billing_address_2(),
                'bairro' => $order->get_meta('_billing_neighborhood'),
                'cep' => $order->get_billing_postcode(),
                'cidade' => $order->get_billing_city(),
                'uf' => $order->get_billing_state(),
            ),
        );
        
        $response = Client::save_contact($customer_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        if (isset($response['data']['data'][0]['id'])) {
            return $response['data']['data'][0]['id'];
        }
        
        return new \WP_Error('customer_sync_failed', 'Falha ao sincronizar cliente com Bling');
    }
    

    /**
     * Prepare invoice data for Bling API.
     *
     * @param \WC_Order $order WooCommerce order.
     * @param int $customer_id Customer ID in Bling.
     * @return array Invoice data.
     */
    private static function prepare_invoice_data($order, $customer_id) {
        // Get order items
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $bling_product_id = $product ? $product->get_meta('_bling_product_id') : null;
            
            $items[] = array(
                'produto' => array(
                    'id' => $bling_product_id ? intval($bling_product_id) : 0,
                    'codigo' => $product ? $product->get_sku() : '',
                    'nome' => $item->get_name(),
                    'unidade' => 'UN',
                ),
                'quantidade' => floatval($item->get_quantity()),
                'valor' => floatval($item->get_total()) / floatval($item->get_quantity()),
                'desconto' => 0,
            );
        }
        
        // Prepare invoice data
        $data = array(
            'cliente' => array(
                'id' => intval($customer_id),
            ),
            'transporte' => array(
                'volumes' => array(
                    array(
                        'servico' => 'SEDEX',
                        'codigoRastreamento' => $order->get_shipping_method(),
                    ),
                ),
            ),
            'itens' => $items,
            'parcelas' => array(
                array(
                    'dias' => 0,
                    'data' => date('Y-m-d'),
                    'valor' => floatval($order->get_total()),
                    'observacoes' => 'Pedido WooCommerce #' . $order->get_id(),
                ),
            ),
            'numero' => 'WC-' . $order->get_id(),
            'numeroLoja' => $order->get_id(),
            'dataOperacao' => $order->get_date_created()->date('Y-m-d'),
            'contato' => array(
                'id' => intval($customer_id),
            ),
            'naturezaOperacao' => array(
                'id' => intval(get_option('bling_default_nature_operation', 1)),
            ),
        );
        
        // Add shipping as item if exists
        if ($order->get_shipping_total() > 0) {
            $items[] = array(
                'produto' => array(
                    'id' => 0,
                    'codigo' => 'FRETE',
                    'nome' => 'Frete',
                    'unidade' => 'UN',
                ),
                'quantidade' => 1,
                'valor' => floatval($order->get_shipping_total()),
                'desconto' => 0,
            );
        }
        
        return $data;
    }
    

    /**
     * Add custom order actions.
     *
     * @param array $actions Order actions.
     * @return array Modified actions.
     */
    public static function add_order_actions($actions) {
        $actions['bling_create_invoice'] = __('Criar nota fiscal no Bling', 'joinotify-bling-erp');
        return $actions;
    }
    

    /**
     * Manually create invoice from order action.
     *
     * @param \WC_Order $order WooCommerce order.
     * @return void
     */
    public static function create_invoice_manually($order) {
        $result = self::create_invoice_for_order($order);
        
        if (is_wp_error($result)) {
            wc_admin_notice(
                sprintf(
                    __('Erro ao criar nota fiscal: %s', 'joinotify-bling-erp'),
                    $result->get_error_message()
                ),
                'error'
            );
        } else {
            wc_admin_notice(
                __('Nota fiscal criada com sucesso no Bling!', 'joinotify-bling-erp'),
                'success'
            );
        }
    }

    
    /**
     * Add meta boxes to order edit screen.
     *
     * @return void
     */
    public static function add_meta_boxes() {
        add_meta_box(
            'bling_order_info',
            __('Informações do Bling', 'joinotify-bling-erp'),
            array(__CLASS__, 'render_order_meta_box'),
            'shop_order',
            'side',
            'high'
        );
    }

    
    /**
     * Render order meta box.
     *
     * @param \WP_Post $post Post object.
     * @return void
     */
    public static function render_order_meta_box($post) {
        $order = wc_get_order($post->ID);
        $invoice_id = $order->get_meta('_bling_invoice_id');
        
        echo '<div class="bling-order-info">';
        
        if ($invoice_id) {
            echo '<p><strong>' . __('Nota Fiscal:', 'joinotify-bling-erp') . '</strong></p>';
            echo '<p>' . __('ID:', 'joinotify-bling-erp') . ' ' . esc_html($invoice_id) . '</p>';
            
            // Try to get invoice details
            $invoice = Client::get_invoice($invoice_id);
            
            if (!is_wp_error($invoice) && isset($invoice['data']['data'][0])) {
                $invoice_data = $invoice['data']['data'][0];
                $situacao = isset($invoice_data['situacao']) ? $invoice_data['situacao'] : '';
                $numero = isset($invoice_data['numero']) ? $invoice_data['numero'] : '';
                $chave = isset($invoice_data['chave']) ? $invoice_data['chave'] : '';
                
                echo '<p>' . __('Número:', 'joinotify-bling-erp') . ' ' . esc_html($numero) . '</p>';
                echo '<p>' . __('Situação:', 'joinotify-bling-erp') . ' ' . self::get_invoice_status_label($situacao) . '</p>';
                
                if ($chave) {
                    echo '<p><strong>' . __('Chave de Acesso:', 'joinotify-bling-erp') . '</strong></p>';
                    echo '<p style="word-break: break-all;">' . esc_html($chave) . '</p>';
                }
                
                // Add button to view in Bling
                echo '<p><a href="https://www.bling.com.br/nfe/' . esc_attr($invoice_id) . '" target="_blank" class="button">';
                echo __('Ver no Bling', 'joinotify-bling-erp');
                echo '</a></p>';
            }
        } else {
            echo '<p>' . __('Nenhuma nota fiscal criada no Bling para este pedido.', 'joinotify-bling-erp') . '</p>';
        }
        
        echo '</div>';
    }
    

    /**
     * Get invoice status label.
     *
     * @param int $status Status code.
     * @return string Status label.
     */
    private static function get_invoice_status_label($status) {
        $statuses = array(
            1 => __('Em digitação', 'joinotify-bling-erp'),
            2 => __('Cancelada', 'joinotify-bling-erp'),
            3 => __('Assinada e salva', 'joinotify-bling-erp'),
            4 => __('Rejeitada', 'joinotify-bling-erp'),
            5 => __('Autorizada', 'joinotify-bling-erp'),
            6 => __('Emitida DANFE', 'joinotify-bling-erp'),
            7 => __('Registrada', 'joinotify-bling-erp'),
            8 => __('Pendente', 'joinotify-bling-erp'),
            9 => __('Denegada', 'joinotify-bling-erp'),
        );
        
        return isset($statuses[$status]) ? $statuses[$status] : __('Desconhecido', 'joinotify-bling-erp');
    }
    
    
    /**
     * Sync WooCommerce customer to Bling.
     *
     * @param int $user_id User ID.
     * @param mixed $old_user_data Old user data (for updates).
     * @return void
     */
    public static function sync_customer_to_bling($user_id, $old_user_data = null) {
        if (!get_option('bling_sync_customers', 'no') === 'yes') {
            return;
        }
        
        $user = get_userdata($user_id);
        
        if (!$user) {
            return;
        }
        
        $customer_data = array(
            'nome' => $user->display_name,
            'tipo' => 'F', // Física
            'email' => $user->user_email,
        );
        
        // Get billing info from user meta
        $billing_cpf = get_user_meta($user_id, 'billing_cpf', true);
        $billing_phone = get_user_meta($user_id, 'billing_phone', true);
        
        if ($billing_cpf) {
            $customer_data['numeroDocumento'] = $billing_cpf;
        }
        
        if ($billing_phone) {
            $customer_data['telefone'] = $billing_phone;
        }
        
        Client::save_contact($customer_data);
    }
}