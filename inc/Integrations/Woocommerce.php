<?php

namespace MeuMouse\Joinotify\Bling\Integrations;

use MeuMouse\Joinotify\Bling\API\Client;

use WC_Order;
use WP_Error;
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * WooCommerce integration for Bling.
 *
 * Handles integration between WooCommerce orders and Bling ERP system.
 *
 * @since 1.0.0
 * @version 1.0.3
 * @package MeuMouse\Joinotify\Bling\Integrations
 * @author MeuMouse.com
 */
class Woocommerce {
    
    /**
     * Instance of the class
     *
     * @since 1.0.0
     * @var Woocommerce
     */
    private static $instance = null;
    
    /**
     * Configuration options
     *
     * @since 1.0.0
     * @var array
     */
    private $config = array();
    
    /**
     * Invoice status cache for performance
     *
     * @since 1.0.2
     * @var array
     */
    private static $invoice_status_cache = array();

    /**
     * Set development mode
     * 
     * @since 1.0.3
     * @return bool
     */
    private static $dev_mode = false;
    
    /**
     * Constructor
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        self::$dev_mode = defined('JOINOTIFY_BLING_DEV_MODE') && JOINOTIFY_BLING_DEV_MODE;
        
        // Load configuration
        $this->load_config();
        
        // Sync order on status change
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 10, 4 );
        
        // Add custom order actions
        add_filter( 'woocommerce_order_actions', array( $this, 'add_order_actions' ) );
        add_action( 'woocommerce_order_action_bling_create_invoice', array( $this, 'create_invoice_manually' ) );
        add_action( 'woocommerce_order_action_bling_check_invoice_status', array( $this, 'check_invoice_status_manually' ) );
        
        // Add meta boxes
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        
        // Add NFe status column to orders list
        add_filter( 'manage_' . self::get_orders_page() . '_columns', array( $this, 'add_nfe_status_column' ) );
        add_action( 'manage_' . self::get_orders_page() . '_custom_column', array( $this, 'render_nfe_status_column' ), 10, 2 );
        
        // Make NFe status column sortable
        add_filter( 'manage_edit-' . self::get_orders_page() . '_sortable_columns', array( $this, 'make_nfe_status_column_sortable' ) );
        
        // Handle column sorting
        add_action( 'pre_get_posts', array( $this, 'handle_nfe_status_column_sorting' ) );
        
        // Add admin CSS for the column
        add_action( 'admin_head', array( $this, 'add_admin_css' ) );
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
            'auto_create'         => get_option( 'bling_auto_create_invoice', 'yes' ) === 'yes',
            'trigger_statuses'    => get_option( 'bling_invoice_trigger_statuses', array( 'completed' ) ),
            'nature_operation'    => intval( get_option( 'bling_default_nature_operation', 1 ) ),
            'invoice_series'      => get_option( 'bling_invoice_series', '1' ),
            'invoice_purpose'     => get_option( 'bling_invoice_purpose', '1' ),
            'send_email'          => get_option( 'bling_send_invoice_email', 'yes' ) === 'yes',
            'sync_customers'      => get_option( 'bling_sync_customers', 'no' ) === 'yes',
            'sync_products'       => get_option( 'bling_sync_products', 'no' ) === 'yes',
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
    private function should_create_invoice( $order, $new_status ) {
        // Check if auto creation is enabled
        if ( ! $this->config['auto_create'] ) {
            return false;
        }
        
        // Check if status triggers invoice creation
        if ( ! in_array( $new_status, (array) $this->config['trigger_statuses'] ) ) {
            return false;
        }
        
        // Check if invoice already exists
        $existing_invoice_id = $order->get_meta( '_bling_invoice_id' );

        if ( $existing_invoice_id ) {
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
    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
        // Load config to ensure it's fresh
        $this->load_config();
        
        if ( ! $this->should_create_invoice( $order, $new_status ) ) {
            return;
        }
        
        // Create invoice in Bling
        $result = $this->create_invoice_for_order( $order );
        
        if ( is_wp_error( $result ) ) {
            error_log( sprintf(
                'Bling Invoice Error for Order #%d: %s',
                $order_id,
                $result->get_error_message()
            ) );
        }
    }
    
    /**
     * Create invoice in Bling for WooCommerce order.
     *
     * @since 1.0.0
     * @param WC_Order $order WooCommerce order.
     * @return int|WP_Error Invoice ID or error.
     */
    public function create_invoice_for_order( $order ) {
        try {
            $order_id = $order->get_id();
            
            if ( self::$dev_mode ) {
                error_log( '[JOINOTIFY - BLING ERP]: Iniciando criação de NF para pedido #' . $order_id );
            }

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
            
            // Check if prepare_invoice_data returned an error
            if ( is_wp_error( $invoice_data ) ) {
                return $invoice_data;
            }
            
            // Add send email flag if configured
            if ( $this->config['send_email'] ) {
                $invoice_data['enviarEmail'] = true;
            }

            if ( $this->config['sync_customers'] && $customer_id ) {
                // First, get updated data from contact
                $contact_response = Client::get_contact( $customer_id );
                
                if ( ! is_wp_error( $contact_response ) && isset( $contact_response['data']['data'] ) ) {
                    $contact_data = $contact_response['data']['data'];
                    
                    // Use the contact data for validation
                    $validation = $this->validate_contact_data_for_invoice( $contact_data );
                    
                    if ( is_wp_error( $validation ) ) {
                        if ( self::$dev_mode ) {
                            error_log( '[JOINOTIFY - BLING ERP]: Dados do contato incompletos: ' . $validation->get_error_message() );
                        }
            
                        // If missing data, try update it with from WooCommerce
                        $woo_customer_data = $this->prepare_customer_data( $order );
                        $merged_data = $this->merge_contact_data( $contact_data, $woo_customer_data );
                        $merged_validation = $this->validate_contact_data_for_invoice( $merged_data );
                        
                        if ( is_wp_error( $merged_validation ) ) {
                            return new WP_Error(
                                'contact_data_incomplete',
                                sprintf(
                                    'Não foi possível emitir a NF-e. Dados do cliente incompletos no Bling: %s',
                                    $merged_validation->get_error_message()
                                )
                            );
                        } else {
                            $update_response = Client::save_contact( array_merge(
                                array( 'id' => $customer_id ),
                                $merged_data
                            ) );
                            
                            if ( is_wp_error( $update_response ) ) {
                                error_log( '[JOINOTIFY - BLING ERP]: Erro ao atualizar cliente: ' . $update_response->get_error_message() );
                            }
                        }
                    }
                } else {
                    if ( self::$dev_mode ) {
                        error_log( '[JOINOTIFY - BLING ERP]: Não foi possível buscar dados do contato ID: ' . $customer_id );
                    }

                    $customer_data = $this->prepare_customer_data( $order );
                    $validation = $this->validate_contact_data_for_invoice( $customer_data );
                    
                    if ( is_wp_error( $validation ) ) {
                        return $validation;
                    }
                    
                    $update_response = Client::save_contact( array_merge(
                        array( 'id' => $customer_id ),
                        $customer_data
                    ) );

                    if ( is_wp_error( $update_response ) ) {
                        error_log( '[JOINOTIFY - BLING ERP]: Erro ao atualizar cliente: ' . $update_response->get_error_message() );
                    }
                }
            }
                        
            // Create invoice in Bling
            $response = Client::create_invoice( $invoice_data );

            if ( self::$dev_mode ) {
                error_log( '[JOINOTIFY - BLING ERP]: Criando NF para pedido #' . $order_id );
            }
            
            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'api_error', sprintf(
                    'API Error: %s',
                    $response->get_error_message()
                ) );
            }
            
            if ( ! isset( $response['data']['data']['id'] ) ) {
                return new WP_Error(
                    'invoice_creation_failed',
                    __( 'Falha ao criar nota fiscal no Bling', 'joinotify-bling-erp' )
                );
            }

            $invoice_id = (int) $response['data']['data']['id'];
            
            // Save invoice ID to order meta
            $order->update_meta_data( '_bling_invoice_id', $invoice_id );
            $order->update_meta_data( '_bling_invoice_created', current_time( 'mysql' ) );

            // Save invoice number (already comes in creation response)
            if ( isset( $response['data']['data']['numero'] ) ) {
                $order->update_meta_data( '_bling_invoice_number', $response['data']['data']['numero'] );
            }

            // Save invoice series
            if ( isset( $response['data']['data']['serie'] ) ) {
                $order->update_meta_data( '_bling_invoice_series', $response['data']['data']['serie'] );
            }

            $order->save();
            
            // Log the action
            $order->add_order_note(
                sprintf(
                    __( 'Nota fiscal criada no Bling (ID: %d)', 'joinotify-bling-erp' ),
                    $invoice_id
                )
            );

            // Send to SEFAZ
            $send = Client::send_invoice_to_sefaz( $invoice_id );
            
            // Update invoice details from Bling
            $this->update_invoice_details_from_bling( $order, $invoice_id );
            
            if ( is_wp_error( $send ) ) {
                error_log(
                    '[JOINOTIFY - BLING ERP]: Erro ao enviar NF ' . $invoice_id . ' para SEFAZ: ' . $send->get_error_message()
                );

                $order->add_order_note(
                    'Erro ao enviar NF para SEFAZ: ' . $send->get_error_message()
                );
            } else {
                if ( self::$dev_mode ) {
                    error_log(
                        '[JOINOTIFY - BLING ERP]: NF ' . $invoice_id . ' enviada para SEFAZ com sucesso.'
                    );
                }

                $order->add_order_note(
                    'Nota fiscal enviada para SEFAZ com sucesso.'
                );
            }
            
            // Clear cache for this order
            $this->clear_invoice_status_cache( $order_id );
            
            return $invoice_id;
            
        } catch ( \Exception $e ) {
            return new WP_Error( 'exception', sprintf(
                'Exception: %s',
                $e->getMessage()
            ) );
        }
    }
    
    /**
     * Sync order customer to Bling.
     *
     * @since 1.0.0
     * @version 1.0.1 - Use existing contact data from Bling
     * @param WC_Order $order Order object
     * @return int|WP_Error Customer ID in Bling.
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
        
        // Prepare customer data from WooCommerce
        $customer_data = $this->prepare_customer_data( $order );
        
        // Skip if no CPF/CNPJ
        if ( empty( $customer_data['numeroDocumento'] ) ) {
            if ( self::$dev_mode ) {
                error_log( '[JOINOTIFY - BLING ERP]: Cliente não possui CPF/CNPJ cadastrado.' );
            }

            return new WP_Error( 'missing_document', 'Cliente não possui CPF/CNPJ cadastrado' );
        }
        
        // Check if contact already exists by CPF/CNPJ
        $existing_contact_id = $this->find_contact_by_document( $customer_data['numeroDocumento'] );

        if ( ! is_wp_error( $existing_contact_id ) && $existing_contact_id ) {
            // Get full contact data from Bling
            $existing_contact = Client::get_contact( $existing_contact_id );
            
            if ( ! is_wp_error( $existing_contact ) && isset( $existing_contact['data']['data'] ) ) {
                $bling_contact_data = $existing_contact['data']['data'];
                
                // Merge data: use Bling data, but update with WooCommerce data if missing
                $merged_data = $this->merge_contact_data( $bling_contact_data, $customer_data );
                
                // Update contact with merged data
                $response = Client::save_contact( $merged_data );
                
                if ( is_wp_error( $response ) ) {
                    error_log( '[JOINOTIFY - BLING ERP]: Erro ao atualizar cliente: ' . $response->get_error_message() );
                    // Return existing ID even if update fails
                }
            }
            
            // Save contact ID to user meta for future use
            if ( $user_id ) {
                update_user_meta( $user_id, '_bling_contact_id', $existing_contact_id );
            }
            
            return $existing_contact_id;
        }

        // Create new contact
        $response = Client::save_contact( $customer_data );

        if ( self::$dev_mode ) {
            error_log( '[JOINOTIFY - BLING ERP]: Criando novo cliente no Bling.' );
        }
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        if ( ! isset( $response['data'][0]['id'] ) ) {
            return new WP_Error( 'customer_sync_failed', 'Falha ao sincronizar cliente com Bling' );
        }
        
        $contact_id = $response['data'][0]['id'];
        
        // Save contact ID to user meta for future use
        if ( $user_id ) {
            update_user_meta( $user_id, '_bling_contact_id', $contact_id );
        }
        
        return $contact_id;
    }
    
    /**
     * Merge contact data from Bling with WooCommerce data
     *
     * @since 1.0.1
     * @param array $bling_data Contact data from Bling
     * @param array $woo_data Contact data from WooCommerce
     * @return array Merged contact data
     */
    private function merge_contact_data( $bling_data, $woo_data ) {
        // Start with Bling data as base
        $merged = $bling_data;
        
        // Preserve the contact ID
        if ( isset( $bling_data['id'] ) ) {
            $merged['id'] = $bling_data['id'];
        }
        
        // Update name if WooCommerce has a name and Bling doesn't or it's different
        if ( ! empty( $woo_data['nome'] ) && ( empty( $bling_data['nome'] ) || $bling_data['nome'] !== $woo_data['nome'] ) ) {
            $merged['nome'] = $woo_data['nome'];
        }
        
        // Update email if missing in Bling
        if ( ! empty( $woo_data['email'] ) && empty( $bling_data['email'] ) ) {
            $merged['email'] = $woo_data['email'];
            // Also update emailNotaFiscal if it's empty
            if ( empty( $bling_data['emailNotaFiscal'] ) ) {
                $merged['emailNotaFiscal'] = $woo_data['email'];
            }
        }
        
        // Update phone if missing in Bling
        if ( ! empty( $woo_data['telefone'] ) && ( empty( $bling_data['telefone'] ) && empty( $bling_data['celular'] ) ) ) {
            $merged['telefone'] = $woo_data['telefone'];
        }
        
        // Update address if missing in Bling
        if ( isset( $woo_data['endereco']['geral'] ) ) {
            $woo_endereco = $woo_data['endereco']['geral'];
            
            // Initialize address structure if not exists
            if ( ! isset( $merged['endereco'] ) ) {
                $merged['endereco'] = array(
                    'geral'     => array(),
                    'cobranca'  => isset( $bling_data['endereco']['cobranca'] ) ? $bling_data['endereco']['cobranca'] : array(),
                );
            } elseif ( ! isset( $merged['endereco']['geral'] ) ) {
                $merged['endereco']['geral'] = array();
            }
            
            // Update each address field if missing in Bling
            $address_fields = array( 'endereco', 'numero', 'complemento', 'bairro', 'cep', 'municipio', 'uf' );
            foreach ( $address_fields as $field ) {
                if ( ! empty( $woo_endereco[ $field ] ) && 
                    ( ! isset( $merged['endereco']['geral'][ $field ] ) || empty( $merged['endereco']['geral'][ $field ] ) ) ) {
                    $merged['endereco']['geral'][ $field ] = $woo_endereco[ $field ];
                }
            }
        }
        
        return $merged;
    }
    
    /**
     * Find contact by CPF/CNPJ
     *
     * @since 1.0.0
     * @param string $document
     * @return int|false Contact ID or false if not found
     */
    private function find_contact_by_document( $document ) {
        $clean_document = preg_replace( '/[^0-9]/', '', $document );
        
        if ( self::$dev_mode ) {
            error_log( '[JOINOTIFY - BLING ERP]: Buscando contato por documento: ' . $clean_document );
        }
        
        $response = Client::get_contacts( array(
            'numeroDocumento' => $clean_document,
            'limite'         => 1,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        if ( isset( $response['data']['data'][0]['id'] ) ) {
            $contact_id = $response['data']['data'][0]['id'];
            
            if ( self::$dev_mode ) {
                error_log( '[JOINOTIFY - BLING ERP]: Contato encontrado. ID: ' . $contact_id );
            }

            return $contact_id;
        }
        
        return false;
    }
    
    /**
     * Prepare customer data for Bling
     *
     * @since 1.0.0
     * @version 1.0.1
     * @param WC_Order $order
     * @return array
     */
    private function prepare_customer_data( $order ) {
        $billing_cpf = $order->get_meta( '_billing_cpf' );
        $billing_cnpj = $order->get_meta( '_billing_cnpj' );
        $cep = preg_replace( '/[^0-9]/', '', $order->get_billing_postcode() );
        $bairro = $order->get_meta( '_billing_neighborhood' );

        if ( empty( $bairro ) ) {
            $bairro = $this->get_bairro_by_cep( $cep );
        }
        
        return apply_filters( 'Joinotify/Bling/Prepare_Customer_Data', array(
            'nome'            => $order->get_formatted_billing_full_name(),
            'tipo'            => $billing_cnpj ? 'J' : 'F', // J = Jurídica, F = Física
            'numeroDocumento' => $billing_cnpj ?: $billing_cpf,
            'situacao'        => 'A',
            'email'           => $order->get_billing_email(),
            'telefone'        => $this->format_phone( $order->get_billing_phone() ),
            'endereco'        => array(
                'geral' => array(
                    'endereco'    => $order->get_billing_address_1(),
                    'numero'      => $order->get_meta( '_billing_number' ) ?: 'S/N',
                    'complemento' => $order->get_billing_address_2(),
                    'bairro'      => $bairro,
                    'cep'         => $cep,
                    'municipio'   => $order->get_billing_city(),
                    'uf'          => $order->get_billing_state(),
                ),
            ),
        ) );
    }
    
    /**
     * Format phone number
     *
     * @since 1.0.0
     * @param string $phone
     * @return string
     */
    private function format_phone( $phone ) {
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        
        if ( strlen( $phone ) === 11 ) {
            return sprintf( '(%s) %s-%s',
                substr( $phone, 0, 2 ),
                substr( $phone, 2, 5 ),
                substr( $phone, 7 )
            );
        } elseif ( strlen( $phone ) === 10 ) {
            return sprintf( '(%s) %s-%s',
                substr( $phone, 0, 2 ),
                substr( $phone, 2, 4 ),
                substr( $phone, 6 )
            );
        }

        return $phone;
    }
    
    /**
     * Prepare invoice data for Bling API.
     *
     * @since 1.0.0
     * @version 1.0.1
     * @param WC_Order $order WooCommerce order.
     * @param int|null $customer_id Customer ID in Bling.
     * @return array|WP_Error Invoice data or error.
     */
    private function prepare_invoice_data( $order, $customer_id = null ) {
        $items = array();

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $item_data = $this->prepare_item_data( $item, $product );

            if ( is_wp_error( $item_data ) ) {
                return $item_data;
            }

            if ( $item_data ) {
                $items[] = $item_data;
            }
        }

        if ( empty( $items ) ) {
            return new WP_Error(
                'no_items',
                'A nota fiscal não possui itens válidos.'
            );
        }

        // Get site URL for observation
        $site_url = get_site_url();

        $observation = sprintf( __( 'Pedido #%d - Site: %s', 'joinotify-bling-erp' ), $order->get_id(), $site_url );

        $data = array(
            'serie'               => $this->config['invoice_series'],
            'tipo'                => 1,
            'numeroLoja'          => (string) $order->get_id(),
            'dataOperacao'        => $order->get_date_created()->date( 'Y-m-d' ),
            'naturezaOperacao'    => array(
                'id' => $this->config['nature_operation'],
            ),
            'finalidade'          => $this->config['invoice_purpose'],
            'itens'               => $items,
            'observacoes'         => $observation,
            'parcelas'            => array(
                array(
                    'data'        => date( 'Y-m-d' ),
                    'valor'       => (float) $order->get_total(),
                    'observacoes' => $observation,
                ),
            ),
        );

        // Add sales channel if configured
        $sales_channel_id = get_option( 'bling_sales_channel_id', '' );

        if ( $sales_channel_id ) {
            // Try to get channel description
            $channel_description = $this->get_sales_channel_description( $sales_channel_id );
            
            $data['loja'] = array(
                'id'     => (int) $sales_channel_id,
                'numero' => $channel_description ?: 'Site: ' . $site_url,
            );
        }

        if ( $customer_id ) {
            // Get full contact data from Bling
            $contact_response = Client::get_contact( $customer_id );
            
            if ( ! is_wp_error( $contact_response ) && isset( $contact_response['data']['data'] ) ) {
                $contact_data = $contact_response['data']['data'];
                
                // Prepare contact data for invoice according to Bling API format
                $data['contato'] = $this->prepare_contact_for_invoice( $contact_data );
            } else {
                // Fallback: use just the ID
                $data['contato'] = array(
                    'id' => (int) $customer_id,
                );
            }
        }

        $shipping_total = (float) $order->get_shipping_total();

        if ( $shipping_total > 0 ) {
            $data['transporte'] = array(
                'fretePorConta' => 0, // 0 = emitente, 1 = destinatário
                'frete'         => $shipping_total,
            );
        }

        return $data;
    }
    
    /**
     * Prepare contact data for invoice in Bling API format
     *
     * @since 1.0.1
     * @param array $contact_data Full contact data from Bling
     * @return array Contact data formatted for invoice
     */
    private function prepare_contact_for_invoice( $contact_data ) {
        $contact_for_invoice = array(
            'id'              => (int) $contact_data['id'],
            'nome'            => isset( $contact_data['nome'] ) ? $contact_data['nome'] : '',
            'numeroDocumento' => isset( $contact_data['numeroDocumento'] ) ? $contact_data['numeroDocumento'] : '',
            'ie'              => isset( $contact_data['ie'] ) ? $contact_data['ie'] : '',
            'rg'              => isset( $contact_data['rg'] ) ? $contact_data['rg'] : '',
            'telefone'        => isset( $contact_data['telefone'] ) ? $contact_data['telefone'] : '',
            'email'           => isset( $contact_data['email'] ) ? $contact_data['email'] : '',
            'endereco'        => array(
                'endereco'    => '',
                'numero'      => '',
                'complemento' => '',
                'bairro'      => '',
                'cep'         => '',
                'municipio'   => '',
                'uf'          => '',
            ),
        );

        // Add address data if available
        if ( isset( $contact_data['endereco']['geral'] ) ) {
            $address = $contact_data['endereco']['geral'];
            
            if ( ! empty( $address['endereco'] ) ) {
                $contact_for_invoice['endereco']['endereco'] = $address['endereco'];
            }
            if ( ! empty( $address['numero'] ) ) {
                $contact_for_invoice['endereco']['numero'] = $address['numero'];
            }
            if ( ! empty( $address['complemento'] ) ) {
                $contact_for_invoice['endereco']['complemento'] = $address['complemento'];
            }
            if ( ! empty( $address['bairro'] ) ) {
                $contact_for_invoice['endereco']['bairro'] = $address['bairro'];
            }
            if ( ! empty( $address['cep'] ) ) {
                $contact_for_invoice['endereco']['cep'] = $address['cep'];
            }
            if ( ! empty( $address['municipio'] ) ) {
                $contact_for_invoice['endereco']['municipio'] = $address['municipio'];
            }
            if ( ! empty( $address['uf'] ) ) {
                $contact_for_invoice['endereco']['uf'] = $address['uf'];
            }
        }

        return $contact_for_invoice;
    }
    
    /**
     * Prepare item data for invoice
     *
     * @since 1.0.0
     * @version 1.0.1
     * @param \WC_Order_Item_Product $item
     * @param \WC_Product|null $product
     * @return array|WP_Error Item data or error.
     */
    private function prepare_item_data( $item, $product ) {
        if ( ! $product ) {
            return null;
        }

        $quantity = max( 1, (float) $item->get_quantity() );
        $price = (float) $item->get_total() / $quantity;
        $codigo = $product->get_sku();

        if ( empty( $codigo ) ) {
            return new WP_Error(
                'missing_sku',
                sprintf(
                    'O produto "%s" não possui SKU. NF-e no Bling exige código.',
                    $item->get_name()
                )
            );
        }

        return array(
            'codigo'     => $codigo,
            'descricao'  => $item->get_name(),
            'unidade'    => 'UN',
            'quantidade' => $quantity,
            'valor'      => $price,
            'tipo'       => 'P',
            'origem'     => 0,
        );
    }
    
    /**
     * Get sales channel description from Bling
     *
     * @since 1.0.1
     * @param int $channel_id Sales channel ID
     * @return string|null Channel description or null if not found
     */
    private function get_sales_channel_description( $channel_id ) {
        static $cache = null;
        
        if ( $cache === null ) {
            $cache = array();
        }
        
        if ( isset( $cache[ $channel_id ] ) ) {
            return $cache[ $channel_id ];
        }
        
        try {
            $response = Client::get_sales_channels( $channel_id );
            
            if ( is_wp_error( $response ) || ! isset( $response['data']['data']['descricao'] ) ) {
                // Try to get from the list of all channels
                $all_channels = $this->get_all_sales_channels();
                
                if ( is_array( $all_channels ) ) {
                    foreach ( $all_channels as $channel ) {
                        if ( $channel['id'] == $channel_id ) {
                            $cache[ $channel_id ] = $channel['descricao'];
                            return $channel['descricao'];
                        }
                    }
                }
                
                $cache[ $channel_id ] = null;
                return null;
            }
            
            $description = $response['data']['data']['descricao'];
            $cache[ $channel_id ] = $description;
            
            return $description;
            
        } catch ( \Exception $e ) {
            error_log( '[JOINOTIFY - BLING ERP]: Erro ao buscar canal de venda: ' . $e->getMessage() );
            $cache[ $channel_id ] = null;
            return null;
        }
    }
    
    /**
     * Get all sales channels with caching
     *
     * @since 1.0.1
     * @return array|WP_Error Array of channels or error
     */
    private function get_all_sales_channels() {
        static $cache = null;
        
        if ( $cache !== null ) {
            return $cache;
        }
        
        $response = Client::get_sales_channels();
        
        if ( is_wp_error( $response ) || ! isset( $response['data']['data'] ) || ! is_array( $response['data']['data'] ) ) {
            $cache = array();
            return $cache;
        }
        
        $channels = array();
        
        foreach ( $response['data']['data'] as $channel ) {
            if ( isset( $channel['id'] ) && isset( $channel['descricao'] ) ) {
                // Filter only active channels (situacao: 1 = Ativo, 2 = Inativo)
                if ( ( $channel['situacao'] ?? 1 ) == 1 ) {
                    $channels[] = array(
                        'id'         => $channel['id'],
                        'descricao'  => $channel['descricao'],
                        'tipo'       => $channel['tipo'] ?? '',
                        'situacao'   => $channel['situacao'] ?? 1,
                    );
                }
            }
        }
        
        $cache = $channels;
        
        return $channels;
    }
    
    /**
     * Validate required contact data before issuing NF-e
     *
     * @since 1.0.1
     * @param array $customer_data
     * @return true|WP_Error
     */
    private function validate_contact_data_for_invoice( $contact_data ) {
        if ( self::$dev_mode ) {
            error_log( '[JOINOTIFY - BLING ERP]: Validando dados do contato para NF-e.' );
        }

        // Check required basic fields
        $required_fields = array(
            'nome'            => 'Nome',
            'numeroDocumento' => 'CPF/CNPJ',
        );

        foreach ( $required_fields as $field => $label ) {
            if ( ! isset( $contact_data[ $field ] ) || empty( trim( $contact_data[ $field ] ) ) ) {
                return new WP_Error(
                    'missing_contact_data',
                    sprintf(
                        'Dados obrigatórios do cliente ausentes para emissão de NF-e: %s',
                        $label
                    )
                );
            }
        }

        // Check address structure
        $has_address = false;
        $address_fields = array();

        if ( isset( $contact_data['endereco']['geral'] ) ) {
            // Bling structure
            $has_address = true;
            $address_fields = $contact_data['endereco']['geral'];
        } elseif ( isset( $contact_data['endereco'] ) && is_array( $contact_data['endereco'] ) ) {
            // Alternative structure
            $has_address = true;
            $address_fields = $contact_data['endereco'];
        }

        if ( ! $has_address ) {
            return new WP_Error(
                'missing_contact_data',
                'Endereço do cliente ausente para emissão de NF-e'
            );
        }

        // Check required address fields
        $required_address_fields = array(
            'endereco'  => 'Endereço',
            'numero'    => 'Número',
            'bairro'    => 'Bairro',
            'cep'       => 'CEP',
            'municipio' => 'Município',
            'uf'        => 'UF',
        );

        foreach ( $required_address_fields as $field => $label ) {
            if ( ! isset( $address_fields[ $field ] ) || empty( trim( $address_fields[ $field ] ) ) ) {
                return new WP_Error(
                    'missing_contact_data',
                    sprintf(
                        'Dados obrigatórios do endereço do cliente ausentes para emissão de NF-e: %s',
                        $label
                    )
                );
            }
        }

        return true;
    }
    
    /**
     * Add custom order actions.
     *
     * @since 1.0.0
     * @param array $actions Order actions.
     * @return array Modified actions.
     */
    public function add_order_actions( $actions ) {
        $actions['bling_create_invoice'] = __( 'Criar nota fiscal no Bling', 'joinotify-bling-erp' );
        $actions['bling_check_invoice_status'] = __( 'Verificar status da nota fiscal', 'joinotify-bling-erp' );
        
        return $actions;
    }
    
    /**
     * Manually create invoice from order action.
     *
     * @since 1.0.0
     * @param WC_Order $order WooCommerce order.
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
        $invoice_id = $order->get_meta( '_bling_invoice_id' );
        
        if ( ! $invoice_id ) {
            $order->add_order_note( __( 'Nenhuma nota fiscal vinculada a este pedido.', 'joinotify-bling-erp' ) );
            return;
        }
        
        $response = Client::get_invoice( $invoice_id );
        
        if ( is_wp_error( $response ) ) {
            $order->add_order_note(
                sprintf(
                    __( 'Erro ao verificar status: %s', 'joinotify-bling-erp' ),
                    $response->get_error_message()
                )
            );
            return;
        }
        
        if ( isset( $response['data']['data'][0] ) ) {
            $invoice_data = $response['data']['data'][0];
            $status = self::get_invoice_status_label( $invoice_data['situacao'] ?? 0 );
            
            $order->add_order_note(
                sprintf(
                    __( 'Status da nota fiscal: %s', 'joinotify-bling-erp' ),
                    $status
                )
            );
        }
    }
    

    /**
     * Get orders page ID
     * 
     * @since 1.0.0
     * @version 1.0.3
     * @return string
     */
    public static function get_orders_page() {
        // HPOS active
        if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
            $container = wc_get_container();

            if ( $container && method_exists( $container, 'get' ) ) {
                $controller = $container->get( CustomOrdersTableController::class );

                if ( $controller && method_exists( $controller, 'custom_orders_table_usage_is_enabled' ) &&
                    $controller->custom_orders_table_usage_is_enabled()
                ) {
                    return 'woocommerce_page_wc-orders';
                }
            }
        }

        // classic mode
        return 'shop_order';
    }
    

    /**
     * Add meta boxes to order edit screen.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_meta_boxes() {
        $screen_id = self::get_orders_page();
        
        if ( empty( $screen_id ) ) {
            return;
        }
        
        add_meta_box(
            'bling_order_info', // metabox ID
            __( 'Informações do Bling', 'joinotify-bling-erp' ), // title
            array( $this, 'render_order_meta_box' ), // callback function
            $screen_id, // add meta box to orders page
            'side', // position (normal, side, advanced)
            'high' // priority (default, low, high, core)
        );
    }
    

    /**
     * Render order meta box.
     *
     * @since 1.0.0
     * @version 1.0.1
     * @param \WP_Post $post Post object.
     * @return void
     */
    public function render_order_meta_box( $post ) {
        $order = wc_get_order( $post->ID );
        if ( ! $order ) {
            return;
        }
        
        $invoice_id = $order->get_meta( '_bling_invoice_id' );
        $invoice_number = $order->get_meta( '_bling_invoice_number' );
        $invoice_series = $order->get_meta( '_bling_invoice_series' );
        $danfe_link = $order->get_meta( '_bling_danfe_link' );
        
        echo '<div class="bling-order-info">';
        
        if ( $invoice_id ) {
            echo '<p><strong>' . __( 'Nota Fiscal Bling:', 'joinotify-bling-erp' ) . '</strong></p>';
            
            // Show complete NF number (series + number)
            $full_invoice_number = '';

            if ( $invoice_number ) {
                $full_invoice_number = $invoice_number;
                if ( $invoice_series ) {
                    $full_invoice_number = $invoice_series . '/' . $invoice_number;
                }
            } else {
                // Try to get NF number from API if not saved
                $response = Client::get_invoice( $invoice_id );

                if ( ! is_wp_error( $response ) && isset( $response['data']['data']['numero'] ) ) {
                    $invoice_number = $response['data']['data']['numero'];
                    $order->update_meta_data( '_bling_invoice_number', $invoice_number );
                    $order->save();
                    
                    $full_invoice_number = $invoice_number;

                    if ( $invoice_series ) {
                        $full_invoice_number = $invoice_series . '/' . $invoice_number;
                    }
                }
            }
            
            if ( $full_invoice_number ) {
                echo '<p>' . __( 'Número NF:', 'joinotify-bling-erp' ) . ' <strong>' . esc_html( $full_invoice_number ) . '</strong></p>';
            }
            
            echo '<p>' . __( 'ID Bling:', 'joinotify-bling-erp' ) . ' ' . esc_html( $invoice_id ) . '</p>';
            
            // Get creation date if available
            $invoice_created = $order->get_meta( '_bling_invoice_created' );

            if ( $invoice_created ) {
                echo '<p>' . __( 'Criada em:', 'joinotify-bling-erp' ) . ' ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $invoice_created ) ) ) . '</p>';
            }
            
            // If we don't have DANFE link stored, try to get from API
            if ( empty( $danfe_link ) ) {
                $danfe_link = $this->get_danfe_link_from_api( $invoice_id );

                if ( ! empty( $danfe_link ) ) {
                    $order->update_meta_data( '_bling_danfe_link', $danfe_link );
                    $order->save();
                }
            }
            
            // Display DANFE link if available
            if ( ! empty( $danfe_link ) ) {
                echo '<p>';
                echo '<a href="' . esc_url( $danfe_link ) . '" target="_blank" class="button button-small button-primary" style="margin-right: 5px;">';
                echo '<span class="dashicons dashicons-external" style="vertical-align: middle; margin-top: -2px;"></span> ' . __( 'Consultar DANFE', 'joinotify-bling-erp' );
                echo '</a>';
                echo '</p>';
                
                // Also show NF number next to button if not already displayed
                if ( empty( $full_invoice_number ) ) {
                    echo '<p><small>' . __( 'Número NF:', 'joinotify-bling-erp' ) . ' ' . esc_html( $invoice_number ?: 'Não disponível' ) . '</small></p>';
                }
            } else {
                echo '<p><em>' . __( 'Link DANFE não disponível', 'joinotify-bling-erp' ) . '</em></p>';
            }
            
            // Add button to check NF status
            echo '<p><a href="#" class="button button-small check-bling-status" data-order-id="' . esc_attr( $order->get_id() ) . '" style="margin-right: 5px;">';
            echo '<span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span> ' . __( 'Atualizar Status', 'joinotify-bling-erp' );
            echo '</a></p>';
            
        } else {
            echo '<p>' . __( 'Nenhuma nota fiscal criada no Bling para este pedido.', 'joinotify-bling-erp' ) . '</p>';
            
            // Show button to create NF manually
            if ( current_user_can( 'manage_woocommerce' ) ) {
                $create_url = wp_nonce_url(
                    add_query_arg( array(
                        'action'   => 'bling_create_invoice',
                        'order_id' => $order->get_id(),
                    ), admin_url( 'admin-ajax.php' ) ),
                    'bling_create_invoice_' . $order->get_id()
                );
                
                echo '<p><a href="' . esc_url( $create_url ) . '" class="button button-small button-primary create-bling-invoice" data-order-id="' . esc_attr( $order->get_id() ) . '">';
                echo '<span class="dashicons dashicons-media-document" style="vertical-align: middle; margin-top: -2px;"></span> ' . __( 'Criar Nota Fiscal', 'joinotify-bling-erp' );
                echo '</a></p>';
            }
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
     * @version 1.0.1
     * @param int $status Status code.
     * @return string Status label.
     */
    public static function get_invoice_status_label( $status ) {
        $statuses = array(
            1 => __( 'Em digitação', 'joinotify-bling-erp' ),
            2 => __( 'Cancelada', 'joinotify-bling-erp' ),
            3 => __( 'Assinada e salva', 'joinotify-bling-erp' ),
            4 => __( 'Rejeitada', 'joinotify-bling-erp' ),
            5 => __( 'Autorizada', 'joinotify-bling-erp' ),
            6 => __( 'Emitida DANFE', 'joinotify-bling-erp' ),
            7 => __( 'Registrada', 'joinotify-bling-erp' ),
            8 => __( 'Pendente', 'joinotify-bling-erp' ),
            9 => __( 'Denegada', 'joinotify-bling-erp' ),
        );
        
        return isset( $statuses[ $status ] ) ? $statuses[ $status ] : __( 'Desconhecido', 'joinotify-bling-erp' );
    }
    
    /**
     * Get neighborhood (bairro) by CEP using ViaCEP
     *
     * @since 1.0.1
     * @param string $cep
     * @return string
     */
    private function get_bairro_by_cep( $cep ) {
        $cep = preg_replace( '/[^0-9]/', '', $cep );

        if ( empty( $cep ) || strlen( $cep ) !== 8 ) {
            return 'Centro';
        }

        $url = 'https://viacep.com.br/ws/' . $cep . '/json/';

        $response = wp_remote_get( $url, array(
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return 'Centro';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body ) || isset( $body['erro'] ) ) {
            return 'Centro';
        }

        if ( ! empty( $body['bairro'] ) ) {
            return $body['bairro'];
        }

        return 'Centro';
    }
    
    /**
     * Fetch invoice details from Bling and update order meta.
     *
     * @since 1.0.2
     * @param WC_Order $order Order object
     * @param int $invoice_id NFe ID
     * @return void
     */
    private function update_invoice_details_from_bling( $order, $invoice_id ) {
        $response = Client::get_invoice( $invoice_id );

        if ( is_wp_error( $response ) || empty( $response['data']['data'][0] ) ) {
            return;
        }

        $invoice = $response['data']['data'][0];

        // Link DANFE
        if ( ! empty( $invoice['linkDanfe'] ) ) {
            $order->update_meta_data( '_bling_danfe_link', $invoice['linkDanfe'] );
        }

        // Access key from invoice
        if ( ! empty( $invoice['chaveAcesso'] ) ) {
            $order->update_meta_data( '_bling_invoice_access_key', $invoice['chaveAcesso'] );
        }

        // Current situation from invoice
        if ( isset( $invoice['situacao'] ) ) {
            $order->update_meta_data( '_bling_invoice_status', $invoice['situacao'] );
        }

        $order->save();

        if ( self::$dev_mode ) {
            error_log(
                '[JOINOTIFY - BLING ERP]: Detalhes da NF atualizados (DANFE / chave / status) para pedido #' . $order->get_id()
            );
        }
    }
    
    /**
     * Add NFe status column to orders list
     *
     * @since 1.0.2
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_nfe_status_column( $columns ) {
        $new_columns = array();
        
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            
            // Add our column after order_status column
            if ( 'order_status' === $key ) {
                $new_columns['nfe_status'] = __( 'Status NFe', 'joinotify-bling-erp' );
            }
        }
        
        // If order_status column doesn't exist, add at the end
        if ( ! isset( $new_columns['nfe_status'] ) ) {
            $new_columns['nfe_status'] = __( 'Status NFe', 'joinotify-bling-erp' );
        }
        
        return $new_columns;
    }
    
    /**
     * Render NFe status column content
     *
     * @since 1.0.2
     * @param string $column Column name
     * @param int $order_id Order ID
     * @return void
     */
    public function render_nfe_status_column( $column, $order_id ) {
        if ( 'nfe_status' !== $column ) {
            return;
        }
        
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        
        $invoice_status = $this->get_cached_invoice_status( $order_id );
        
        echo '<div class="nfe-status-column">';
        
        if ( $invoice_status ) {
            $status_label = self::get_invoice_status_label( $invoice_status );
            $status_class = $this->get_status_class( $invoice_status );
            
            echo '<span class="nfe-status-badge nfe-status-' . esc_attr( $status_class ) . '">';
            echo esc_html( $status_label );
            echo '</span>';
            
            // Show invoice number if available
            $invoice_number = $order->get_meta( '_bling_invoice_number' );
            if ( $invoice_number ) {
                echo '<div class="nfe-number">';
                echo esc_html( sprintf( __( 'NF: %s', 'joinotify-bling-erp' ), $invoice_number ) );
                echo '</div>';
            }
        } else {
            $invoice_id = $order->get_meta( '_bling_invoice_id' );
            
            if ( $invoice_id ) {
                // Has invoice ID but no status cached, show loading
                echo '<span class="nfe-status-badge nfe-status-signed">';
                echo __( 'Emitida', 'joinotify-bling-erp' );
                echo '</span>';
            } else {
                // No invoice created
                echo '<span class="nfe-status-badge nfe-status-none">';
                echo __( 'Não emitida', 'joinotify-bling-erp' );
                echo '</span>';
            }
        }
        
        echo '</div>';
    }
    
    /**
     * Get cached invoice status with performance optimization
     *
     * @since 1.0.2
     * @param int $order_id Order ID
     * @return int|null Invoice status code or null
     */
    private function get_cached_invoice_status( $order_id ) {
        if ( is_object( $order_id ) ) {
            if ( method_exists( $order_id, 'get_id' ) ) {
                $order_id = $order_id->get_id();
            } elseif ( isset( $order_id->ID ) ) {
                $order_id = $order_id->ID;
            }
        }

        if ( is_array( $order_id ) ) {
            $order_id = $order_id['ID'] ?? $order_id['id'] ?? null;
        }

        $order_id = (int) $order_id;

        if ( $order_id <= 0 ) {
            return null;
        }

        // Check static cache first
        if ( isset( self::$invoice_status_cache[ $order_id ] ) ) {
            return self::$invoice_status_cache[ $order_id ];
        }
        
        // Check order meta
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return null;
        }
        
        $invoice_id = $order->get_meta( '_bling_invoice_id' );
        
        if ( ! $invoice_id ) {
            self::$invoice_status_cache[ $order_id ] = null;
            return null;
        }
        
        // Get status from order meta (cached during creation/update)
        $status = $order->get_meta( '_bling_invoice_status' );
        
        if ( $status ) {
            self::$invoice_status_cache[ $order_id ] = (int) $status;
            return (int) $status;
        }
        
        // If not in meta, try to fetch from API (with rate limiting)
        $status = $this->fetch_invoice_status_from_api( $invoice_id );
        
        if ( $status ) {
            // Cache the status
            $order->update_meta_data( '_bling_invoice_status', $status );
            $order->save();
            self::$invoice_status_cache[ $order_id ] = $status;
        } else {
            self::$invoice_status_cache[ $order_id ] = null;
        }
        
        return self::$invoice_status_cache[ $order_id ];
    }
    
    /**
     * Fetch invoice status from Bling API with rate limiting
     *
     * @since 1.0.2
     * @param int $invoice_id Invoice ID
     * @return int|null Status code or null
     */
    private function fetch_invoice_status_from_api( $invoice_id ) {
        // Implement rate limiting - only fetch once per hour per invoice
        $transient_key = 'bling_invoice_status_' . $invoice_id;
        $cached_status = get_transient( $transient_key );
        
        if ( $cached_status !== false ) {
            return (int) $cached_status;
        }
        
        try {
            $response = Client::get_invoice( $invoice_id );
            
            if ( is_wp_error( $response ) || ! isset( $response['data']['data'][0] ) ) {
                return null;
            }
            
            $invoice_data = $response['data']['data'][0];
            
            if ( isset( $invoice_data['situacao'] ) ) {
                $status = (int) $invoice_data['situacao'];
                // Cache for 1 hour to avoid excessive API calls
                set_transient( $transient_key, $status, HOUR_IN_SECONDS );
                return $status;
            }
        } catch ( \Exception $e ) {
            error_log( '[JOINOTIFY - BLING ERP]: Erro ao buscar status da NF: ' . $e->getMessage() );
        }
        
        return null;
    }
    
    /**
     * Get CSS class for invoice status badge
     *
     * @since 1.0.2
     * @param int $status Status code
     * @return string CSS class
     */
    private function get_status_class( $status ) {
        $classes = array(
            1 => 'draft',      // Em digitação
            2 => 'cancelled',  // Cancelada
            3 => 'signed',     // Assinada e salva
            4 => 'rejected',   // Rejeitada
            5 => 'authorized', // Autorizada
            6 => 'issued',     // Emitida DANFE
            7 => 'registered', // Registrada
            8 => 'pending',    // Pendente
            9 => 'denied',     // Denegada
        );
        
        return isset( $classes[ $status ] ) ? $classes[ $status ] : 'unknown';
    }
    
    /**
     * Make NFe status column sortable
     *
     * @since 1.0.2
     * @param array $columns Sortable columns
     * @return array Modified sortable columns
     */
    public function make_nfe_status_column_sortable( $columns ) {
        $columns['nfe_status'] = 'bling_nfe_status';
        return $columns;
    }
    
    /**
     * Handle sorting by NFe status column
     *
     * @since 1.0.2
     * @param \WP_Query $query WP Query object
     * @return void
     */
    public function handle_nfe_status_column_sorting( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        
        $orderby = $query->get( 'orderby' );
        
        if ( 'bling_nfe_status' === $orderby ) {
            $query->set( 'meta_key', '_bling_invoice_status' );
            $query->set( 'orderby', 'meta_value_num' );
        }
    }
    
    /**
     * Clear invoice status cache for an order
     *
     * @since 1.0.2
     * @param int $order_id Order ID
     * @return void
     */
    private function clear_invoice_status_cache( $order_id ) {
        unset( self::$invoice_status_cache[ $order_id ] );
        
        // Clear transient cache
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $invoice_id = $order->get_meta( '_bling_invoice_id' );
            if ( $invoice_id ) {
                delete_transient( 'bling_invoice_status_' . $invoice_id );
            }
        }
    }
    
    /**
     * Add admin CSS for NFe status column
     *
     * @since 1.0.2
     * @return void
     */
    public function add_admin_css() {
        $screen = get_current_screen();
        
        if ( ! $screen || self::get_orders_page() !== $screen->id ) {
            return;
        }
        
        ?>
        <style type="text/css">
            .column-nfe_status {
                width: 120px;
            }
            
            .nfe-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                line-height: 1.4;
                text-align: center;
                white-space: nowrap;
            }
            
            .nfe-status-none {
                background-color: #f0f0f0;
                color: #666;
            }
            
            .nfe-status-pending {
                background-color: #fff3cd;
                color: #856404;
            }
            
            .nfe-status-draft {
                background-color: #d1ecf1;
                color: #0c5460;
            }
            
            .nfe-status-signed {
                background-color: #d4edda;
                color: #155724;
            }
            
            .nfe-status-authorized,
            .nfe-status-issued,
            .nfe-status-registered {
                background-color: #d4edda;
                color: #155724;
            }
            
            .nfe-status-cancelled,
            .nfe-status-rejected,
            .nfe-status-denied {
                background-color: #f8d7da;
                color: #721c24;
            }
            
            .nfe-status-unknown {
                background-color: #e2e3e5;
                color: #383d41;
            }
            
            .nfe-number {
                font-size: 11px;
                color: #666;
                margin-top: 2px;
            }
            
            /* Ensure proper spacing in the column */
            .nfe-status-column {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 2px;
            }
        </style>
        <?php
    }
}