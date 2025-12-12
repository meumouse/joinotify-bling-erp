<?php

namespace MeuMouse\Joinotify\Bling\Integrations;

use MeuMouse\Joinotify\Bling\API\Client;
use MeuMouse\Joinotify\Bling\API\Controller;

use WC_Order;
use WP_Error;
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * WooCommerce integration for Bling.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Woocommerce {
    
    /**
     * Instance of the class
     *
     * @var Woocommerce
     */
    private static $instance = null;
    
    /**
     * Configuration options
     *
     * @var array
     */
    private $config = array();
    
    /**
     * Constructor
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        if ( ! class_exists('WooCommerce') ) {
            return;
        }
        
        // Load configuration
        $this->load_config();
        
        // Sync order on status change
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 10, 4 );
        
        // Add custom order actions
        add_filter( 'woocommerce_order_actions', array( $this, 'add_order_actions' ) );
        add_action( 'woocommerce_order_action_bling_create_invoice', array( $this, 'create_invoice_manually' ) );
        
        // Add meta boxes
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
    }

    
    /**
     * Get singleton instance
     *
     * @since 1.0.0
     * @return Woocommerce
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }
    

    /**
     * Load configuration from settings
     *
     * @since 1.0.0
     * @return void
     */
    private function load_config() {
        $this->config = array(
            'auto_create' => get_option('bling_auto_create_invoice', 'yes') === 'yes',
            'trigger_statuses' => get_option('bling_invoice_trigger_statuses', array('completed')),
            'nature_operation' => intval(get_option('bling_default_nature_operation', 1)),
            'invoice_series' => get_option('bling_invoice_series', '1'),
            'invoice_purpose' => get_option('bling_invoice_purpose', '1'),
            'send_email' => get_option('bling_send_invoice_email', 'yes') === 'yes',
            'sync_customers' => get_option('bling_sync_customers', 'no') === 'yes',
            'sync_products' => get_option('bling_sync_products', 'no') === 'yes',
        );
    }
    

    /**
     * Check if invoice should be created for order
     *
     * @since 1.0.0
     * @param WC_Order $order
     * @param string $new_status
     * @return bool
     */
    private function should_create_invoice($order, $new_status) {
        // Check if auto creation is enabled
        if (!$this->config['auto_create']) {
            return false;
        }
        
        // Check if status triggers invoice creation
        if (!in_array($new_status, (array)$this->config['trigger_statuses'])) {
            return false;
        }
        
        // Check if invoice already exists
        $existing_invoice_id = $order->get_meta('_bling_invoice_id');

        if ($existing_invoice_id) {
            return false;
        }
        
        return true;
    }
    

    /**
     * Handle WooCommerce order status change.
     *
     * @since 1.0.0
     * @param int $order_id Order ID.
     * @param string $old_status Old status.
     * @param string $new_status New status.
     * @param WC_Order $order Order object.
     * @return void
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        // Load config to ensure it's fresh
        $this->load_config();
        
        if (!$this->should_create_invoice($order, $new_status)) {
            return;
        }
        
        // Create invoice in Bling
        $result = $this->create_invoice_for_order($order);
        
        if (is_wp_error($result)) {
            error_log(sprintf(
                'Bling Invoice Error for Order #%d: %s',
                $order_id,
                $result->get_error_message()
            ));
        }
    }
    

    /**
     * Create invoice in Bling for WooCommerce order.
     *
     * @since 1.0.0
     * @param WC_Order $order WooCommerce order.
     * @return int|\WP_Error Invoice ID or error.
     */
    public function create_invoice_for_order( $order ) {
        try {
            // First, ensure customer exists in Bling if sync is enabled
            $customer_id = null;

            if ( $this->config['sync_customers'] ) {
                $customer_id = $this->sync_order_customer_to_bling( $order );

                if ( is_wp_error( $customer_id ) ) {
                    return $customer_id;
                }
            }
            
            // Prepare invoice data
            $invoice_data = $this->prepare_invoice_data( $order, $customer_id );
            
            // Add send email flag if configured
            if ( $this->config['send_email'] ) {
                $invoice_data['enviarEmail'] = true;
            }
            
            // Create invoice in Bling
            $response = Client::create_invoice( $invoice_data );

            if ( defined('JOINOTIFY_BLING_DEV_MODE') && JOINOTIFY_BLING_DEV_MODE ) {
                error_log( '[JOINOTIFY - BLING ERP]: Criando NF: ' . print_r( $response, true ) );
            }
            
            if ( is_wp_error( $response ) ) {
                return new WP_Error('api_error', sprintf(
                    'API Error: %s',
                    $response->get_error_message()
                ));
            }
            
            if ( ! isset( $response['data'][0]['id'] ) ) {
                return new WP_Error( 'invoice_creation_failed', sprintf( __( 'Falha ao criar nota fiscal no Bling: %s', 'joinotify-bling-erp' ), print_r( $response['data']['error'], true ) ) );
            }
            
            $invoice_id = $response['data'][0]['id'];
            
            // Save invoice ID to order meta
            $order->update_meta_data( '_bling_invoice_id', $invoice_id );
            $order->update_meta_data( '_bling_invoice_created', current_time('mysql') );
            $order->save();
            
            // Log the action
            $order->add_order_note(
                sprintf(
                    __('Nota fiscal criada no Bling (ID: %d)', 'joinotify-bling-erp'),
                    $invoice_id
                )
            );
            
            // Update order meta with invoice details if available
            if ( isset( $response['data']['data'][0]['numero'] ) ) {
                $order->update_meta_data( '_bling_invoice_number', $response['data']['data'][0]['numero'] );
                $order->save();
            }
            
            return $invoice_id;
            
        } catch (\Exception $e) {
            return new WP_Error('exception', sprintf(
                'Exception: %s',
                $e->getMessage()
            ));
        }
    }
    

    /**
     * Sync order customer to Bling.
     *
     * @since 1.0.0
     * @param WC_Order $order Order object
     * @return int|\WP_Error Customer ID in Bling.
     */
    private function sync_order_customer_to_bling( $order ) {
        // Try to get existing contact ID from user meta
        $user_id = $order->get_user_id();

        if ( $user_id ) {
            $existing_contact_id = get_user_meta( $user_id, '_bling_contact_id', true );

            if ( $existing_contact_id ) {
                return $existing_contact_id;
            }
        }
        
        // Prepare customer data
        $customer_data = $this->prepare_customer_data( $order );
        
        // Skip if no CPF/CNPJ
        if ( empty( $customer_data['numeroDocumento'] ) ) {
            if ( defined('JOINOTIFY_BLING_DEV_MODE') && JOINOTIFY_BLING_DEV_MODE ) {
                error_log( '[JOINOTIFY - BLING ERP]: Cliente não possui CPF/CNPJ cadastrado. ' . print_r( $customer_data, true ) );
            }

            return new WP_Error('missing_document', 'Cliente não possui CPF/CNPJ cadastrado');
        }
        
        // Check if contact already exists by CPF/CNPJ
        $existing_contact_id = $this->find_contact_by_document( $customer_data['numeroDocumento'] );

        if ( ! is_wp_error( $existing_contact_id ) && $existing_contact_id ) {
            return $existing_contact_id;
        }

        // Create new contact
        $response = Client::save_contact( $customer_data );

        if ( defined('JOINOTIFY_BLING_DEV_MODE') && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( '[JOINOTIFY - BLING ERP]: Resposta ao criar cliente. ' . print_r( $response, true ) );
        }
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        if ( ! isset( $response['data'][0]['id'] ) ) {
            return new WP_Error('customer_sync_failed', 'Falha ao sincronizar cliente com Bling');
        }
        
        $contact_id = $response['data'][0]['id'];
        
        // Save contact ID to user meta for future use
        if ( $user_id ) {
            update_user_meta( $user_id, '_bling_contact_id', $contact_id );
        }
        
        return $contact_id;
    }

    
    /**
     * Find contact by CPF/CNPJ
     *
     * @since 1.0.0
     * @param string $document
     * @return int|false Contact ID or false if not found
     */
    private function find_contact_by_document( $document ) {
        $clean_document = preg_replace('/[^0-9]/', '', $document);
        
        if ( defined('JOINOTIFY_BLING_DEV_MODE') && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( '[JOINOTIFY - BLING ERP]: Buscando contato por documento: ' . $clean_document );
        }
        
        $response = Client::get_contacts( array(
            'numeroDocumento' => $clean_document,
            'limite' => 1
        ));
        
        if ( defined('JOINOTIFY_BLING_DEV_MODE') && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( '[JOINOTIFY - BLING ERP]: Resposta da busca por documento: ' . print_r( $response, true ) );
        }
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        if ( isset( $response['data']['data'][0]['id'] ) ) {
            $contact_id = $response['data']['data'][0]['id'];
            
            if ( defined('JOINOTIFY_BLING_DEV_MODE') && JOINOTIFY_BLING_DEV_MODE ) {
                error_log( '[JOINOTIFY - BLING ERP]: Contato encontrado. ID: ' . $contact_id );
            }

            return $contact_id;
        }
        
        if ( defined('JOINOTIFY_BLING_DEV_MODE') && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( '[JOINOTIFY - BLING ERP]: Estrutura da resposta não contém o ID esperado.' );
        }
        
        return false;
    }


    /**
     * Prepare customer data for Bling
     *
     * @since 1.0.0
     * @param WC_Order $order
     * @return array
     */
    private function prepare_customer_data( $order ) {
        $billing_cpf = $order->get_meta('_billing_cpf');
        $billing_cnpj = $order->get_meta('_billing_cnpj');
        
        return apply_filters( 'Joinotify/Bling/Prepare_Customer_Data', array(
            'nome' => $order->get_formatted_billing_full_name(),
            'tipo' => $billing_cnpj ? 'J' : 'F', // J = Jurídica, F = Física
            'numeroDocumento' => $billing_cnpj ?: $billing_cpf,
            'situacao' => 'A',
            'email' => $order->get_billing_email(),
            'telefone' => $this->format_phone( $order->get_billing_phone() ),
            'endereco' => array(
                'geral' => array(
                    'endereco' => $order->get_billing_address_1(),
                    'numero' => $order->get_meta('_billing_number') ?: 'S/N',
                    'complemento' => $order->get_billing_address_2(),
                    'bairro' => $order->get_meta('_billing_neighborhood') ?: '',
                    'cep' => preg_replace('/[^0-9]/', '', $order->get_billing_postcode()),
                    'municipio' => $order->get_billing_city(),
                    'uf' => $order->get_billing_state(),
                )
            ),
        ));
    }
    

    /**
     * Format phone number
     *
     * @since 1.0.0
     * @param string $phone
     * @return string
     */
    private function format_phone( $phone ) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) === 11) {
            return sprintf('(%s) %s-%s',
                substr($phone, 0, 2),
                substr($phone, 2, 5),
                substr($phone, 7)
            );
        } elseif (strlen($phone) === 10) {
            return sprintf('(%s) %s-%s',
                substr($phone, 0, 2),
                substr($phone, 2, 4),
                substr($phone, 6)
            );
        }

        return $phone;
    }
    

    /**
     * Prepare invoice data for Bling API.
     *
     * @since 1.0.0
     * @param WC_Order $order WooCommerce order.
     * @param int|null $customer_id Customer ID in Bling.
     * @return array Invoice data.
     */
    private function prepare_invoice_data( $order, $customer_id = null ) {
        // Get order items
        $items = array();
        $order_items = $order->get_items();
        
        foreach ( $order_items as $item ) {
            $product = $item->get_product();
            $item_data = $this->prepare_item_data( $item, $product );

            if ( $item_data ) {
                $items[] = $item_data;
            }
        }
        
        // Add shipping as item if exists
        if ( $order->get_shipping_total() > 0 ) {
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
        
        // Prepare invoice data
        $data = array(
            'serie' => $this->config['invoice_series'],
            'numero' => 'WC-' . $order->get_id(),
            'numeroLoja' => (string)$order->get_id(),
            'dataOperacao' => $order->get_date_created()->date('Y-m-d'),
            'naturezaOperacao' => array(
                'id' => $this->config['nature_operation'],
            ),
            'finalidade' => $this->config['invoice_purpose'],
            'itens' => $items,
            'parcelas' => array(
                array(
                    'dias' => 0,
                    'data' => date('Y-m-d'),
                    'valor' => floatval($order->get_total()),
                    'observacoes' => 'Pedido WooCommerce #' . $order->get_id(),
                ),
            ),
        );
        
        // Add customer if available
        if ( $customer_id ) {
            $data['cliente'] = array( 'id' => intval( $customer_id ) );
            $data['contato'] = array( 'id' => intval( $customer_id ) );
        }
        
        return $data;
    }

    
    /**
     * Prepare item data for invoice
     *
     * @since 1.0.0
     * @param \WC_Order_Item_Product $item
     * @param \WC_Product|null $product
     * @return array|null
     */
    private function prepare_item_data( $item, $product ) {
        if ( ! $product ) {
            return null;
        }
        
        $price = floatval($item->get_total()) / floatval($item->get_quantity());
        
        return array(
            'produto' => array(
                'id' => intval($product->get_meta('_bling_product_id') ?: 0),
                'codigo' => $product->get_sku() ?: 'PROD-' . $product->get_id(),
                'nome' => $item->get_name(),
                'unidade' => 'UN',
            ),
            'quantidade' => floatval($item->get_quantity()),
            'valor' => $price,
            'desconto' => 0,
        );
    }
    

    /**
     * Add custom order actions.
     *
     * @since 1.0.0
     * @param array $actions Order actions.
     * @return array Modified actions.
     */
    public function add_order_actions( $actions ) {
        $actions['bling_create_invoice'] = __('Criar nota fiscal no Bling', 'joinotify-bling-erp');
        $actions['bling_check_invoice_status'] = __('Verificar status da nota fiscal', 'joinotify-bling-erp');
        
        return $actions;
    }
    

    /**
     * Manually create invoice from order action.
     *
     * @since 1.0.0
     * @param WC_Order $order | WooCommerce order.
     * @return void
     */
    public function create_invoice_manually( $order ) {
        $result = $this->create_invoice_for_order( $order );
        
        if ( is_wp_error( $result ) ) {
            $order->add_order_note(
                sprintf(
                    __( 'Erro ao criar nota fiscal no Bling: %s', 'joinotify-bling-erp' ),
                    $result->get_error_message()
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    __( 'Nota fiscal criada com sucesso no Bling. ID: %d', 'joinotify-bling-erp' ),
                    $result
                )
            );
        }
    }

    
    /**
     * Check invoice status manually
     *
     * @since 1.0.0
     * @param WC_Order $order
     * @return void
     */
    public function check_invoice_status_manually( $order ) {
        $invoice_id = $order->get_meta('_bling_invoice_id');
        
        if ( ! $invoice_id ) {
            $order->add_order_note( __('Nenhuma nota fiscal vinculada a este pedido.', 'joinotify-bling-erp') );

            return;
        }
        
        $response = Client::get_invoice( $invoice_id );
        
        if ( is_wp_error( $response ) ) {
            $order->add_order_note(
                sprintf(
                    __('Erro ao verificar status: %s', 'joinotify-bling-erp'),
                    $response->get_error_message()
                ),
            );

            return;
        }
        
        if ( isset( $response['data']['data'][0] ) ) {
            $invoice_data = $response['data']['data'][0];
            $status = $this->get_invoice_status_label($invoice_data['situacao'] ?? 0);
            
            $order->add_order_note(
                sprintf(
                    __('Status da nota fiscal: %s', 'joinotify-bling-erp'),
                    $status
                ),
            );
        }
    }


    /**
     * Get orders page ID
     * 
     * @since 1.0.0
     * @return string
     */
    public static function get_orders_page() {
        // compatibility with HPOS
        if ( class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ) {
            return wc_get_page_screen_id('shop-order');
        }

        return 'shop_order';
    }
    

    /**
     * Add meta boxes to order edit screen.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_meta_boxes() {
        add_meta_box(
            'bling_order_info', // metabox ID
            __('Informações do Bling', 'joinotify-bling-erp'), // title
            array( $this, 'render_order_meta_box' ), // callback function
            self::get_orders_page(), // add meta box to orders page
            'side', // position (normal, side, advanced)
            'high', // priority (default, low, high, core)
        );
    }

    
    /**
     * Render order meta box.
     *
     * @since 1.0.0
     * @param \WP_Post $post | Post object.
     * @return void
     */
    public function render_order_meta_box( $post ) {
        $order = wc_get_order( $post->ID );
        $invoice_id = $order->get_meta('_bling_invoice_id');
        
        echo '<div class="bling-order-info">';
            if ( $invoice_id ) {
                echo '<p><strong>' . __('Nota Fiscal:', 'joinotify-bling-erp') . '</strong></p>';
                echo '<p>' . __('ID:', 'joinotify-bling-erp') . ' ' . esc_html( $invoice_id ) . '</p>';
                
                // Try to get invoice details from meta first
                $invoice_number = $order->get_meta('_bling_invoice_number');
                if ( $invoice_number ) {
                    echo '<p>' . __('Número:', 'joinotify-bling-erp') . ' ' . esc_html( $invoice_number ) . '</p>';
                }
                
                // Get DANFE link from order meta (stored from webhook)
                $danfe_link = $order->get_meta('_bling_danfe_link');
                
                // If we don't have the DANFE link stored, try to get it from API
                if ( empty( $danfe_link ) ) {
                    $danfe_link = $this->get_danfe_link_from_api( $invoice_id );
                }
                
                // Display DANFE link button if available
                if ( ! empty( $danfe_link ) ) {
                    echo '<p><a href="' . esc_url( $danfe_link ) . '" target="_blank" class="button button-small button-primary">';
                        echo __('Consultar nota fiscal', 'joinotify-bling-erp');
                    echo '</a></p>';
                }
            } else {
                echo '<p>' . __('Nenhuma nota fiscal criada no Bling para este pedido.', 'joinotify-bling-erp') . '</p>';
            }
        echo '</div>';
    }


    /**
     * Get DANFE link from Bling API
     *
     * @since 1.0.0
     * @param int $invoice_id Invoice ID from Bling
     * @return string DANFE link or empty string
     */
    private function get_danfe_link_from_api( $invoice_id ) {
        try {
            $response = Client::get_invoice( $invoice_id );
            
            if ( is_wp_error( $response ) || ! isset( $response['data']['data'][0] ) ) {
                return '';
            }
            
            $invoice_data = $response['data']['data'][0];
            
            // Check for DANFE link in the response
            if ( isset( $invoice_data['linkDanfe'] ) && ! empty( $invoice_data['linkDanfe'] ) ) {
                return $invoice_data['linkDanfe'];
            }
            
            // Alternative: construct link from chaveAcesso if available
            if ( isset( $invoice_data['chaveAcesso'] ) && ! empty( $invoice_data['chaveAcesso'] ) ) {
                return 'https://www.bling.com.br/doc.view.php?chaveAcesso=' . $invoice_data['chaveAcesso'];
            }
            
            return '';
            
        } catch ( \Exception $e ) {
            error_log( 'Erro ao buscar link DANFE: ' . $e->getMessage() );
            return '';
        }
    }

    
    /**
     * Get invoice status label.
     *
     * @since 1.0.0
     * @param int $status | Status code.
     * @return string Status label.
     */
    private function get_invoice_status_label( $status ) {
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
        
        return isset( $statuses[$status] ) ? $statuses[$status] : __('Desconhecido', 'joinotify-bling-erp');
    }
}