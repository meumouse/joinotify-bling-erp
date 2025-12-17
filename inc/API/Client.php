<?php

namespace MeuMouse\Joinotify\Bling\API;

use WP_Error;
use Exception;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Bling API Client for handling all API requests to Bling.
 *
 * @since 1.0.0
 * @version 1.0.1
 * @package MeuMouse\Joinotify\Bling\API
 * @author MeuMouse.com
 */
class Client {
    
    /**
     * Get authenticated headers for API requests.
     *
     * @since 1.0.0
     * @return array Headers array.
     */
    private static function get_headers() {
        $access_token = get_option('bling_access_token');
        
        return array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        );
    }
    

    /**
     * Make API request to Bling with token refresh handling.
     *
     * @since 1.0.0
     * @param string $method HTTP method.
     * @param string $endpoint API endpoint.
     * @param array $data Request data.
     * @return array|WP_Error Response data or error.
     */
    public static function request($method, $endpoint, $data = array()) {
        $url = 'https://api.bling.com.br/Api/v3' . $endpoint;
        
        $args = array(
            'headers' => self::get_headers(),
            'timeout' => 30,
        );
        
        if (in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = wp_json_encode($data);
        }
        
        switch ($method) {
            case 'GET':
                $response = wp_remote_get($url, $args);
                break;
            case 'POST':
                $response = wp_remote_post($url, $args);
                break;
            case 'PUT':
                $response = wp_remote_request($url, array_merge($args, array('method' => 'PUT')));
                break;
            case 'DELETE':
                $response = wp_remote_request($url, array_merge($args, array('method' => 'DELETE')));
                break;
            default:
                return new WP_Error('invalid_method', 'Método HTTP inválido');
        }
        
        // Check if token expired
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ( $status_code === 401 ) {
            // Try to refresh token
            $refresh_token = get_option('bling_refresh_token');
            $new_token = Controller::refresh_token($refresh_token);
            
            if (!is_wp_error($new_token)) {
                // Retry with new token
                $args['headers']['Authorization'] = 'Bearer ' . $new_token;
                
                switch ($method) {
                    case 'GET':
                        $response = wp_remote_get($url, $args);
                        break;
                    case 'POST':
                        $response = wp_remote_post($url, $args);
                        break;
                    case 'PUT':
                        $response = wp_remote_request($url, array_merge($args, array('method' => 'PUT')));
                        break;
                    case 'DELETE':
                        $response = wp_remote_request($url, array_merge($args, array('method' => 'DELETE')));
                        break;
                }
            }
        }
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return array(
            'status' => $status_code,
            'data' => $data,
            'raw' => $body,
        );
    }

    
    /**
     * Get Bling categories for products.
     *
     * @since 1.0.0
     * @param int $page | Page number.
     * @param int $limit | Items per page.
     * @return array|WP_Error Categories or error.
     */
    public static function get_categories( $page = 1, $limit = 100 ) {
        $endpoint = '/categorias/produtos?pagina=' . $page . '&limite=' . $limit;

        return self::request('GET', $endpoint);
    }
    

    /**
     * Get Bling products.
     *
     * @since 1.0.0
     * @param array $params | Query parameters.
     * @return array|WP_Error Products or error.
     */
    public static function get_products($params = array()) {
        $defaults = array(
            'pagina' => 1,
            'limite' => 100,
        );
        
        $params = wp_parse_args($params, $defaults);
        $endpoint = '/produtos?' . http_build_query($params);
        
        return self::request('GET', $endpoint);
    }
    

    /**
     * Create a product in Bling.
     *
     * @since 1.0.0
     * @param array $product_data Product data.
     * @return array|WP_Error Created product or error.
     */
    public static function create_product($product_data) {
        return self::request('POST', '/produtos', $product_data);
    }
    

    /**
     * Update a product in Bling.
     *
     * @since 1.0.0
     * @param int $product_id | Product ID.
     * @param array $product_data | Product data.
     * @return array|WP_Error Updated product or error.
     */
    public static function update_product($product_id, $product_data) {
        return self::request('PUT', '/produtos/' . $product_id, $product_data);
    }
    
    
    /**
     * Create a sales order in Bling.
     *
     * @since 1.0.0
     * @param array $order_data | Order data.
     * @return array|WP_Error Created order or error.
     */
    public static function create_sales_order($order_data) {
        return self::request('POST', '/pedidos/vendas', $order_data);
    }
    

    /**
     * Create an invoice (NFe) in Bling.
     *
     * @since 1.0.0
     * @param array $invoice_data | Invoice data.
     * @return array|WP_Error Created invoice or error.
     */
    public static function create_invoice($invoice_data) {
        return self::request('POST', '/nfe', $invoice_data);
    }
    

    /**
     * Get an invoice by ID.
     *
     * @since 1.0.0
     * @param int $invoice_id | Invoice ID.
     * @return array|WP_Error Invoice data or error.
     */
    public static function get_invoice($invoice_id) {
        return self::request('GET', '/nfe/' . $invoice_id);
    }
    

    /**
     * Get invoices with filters.
     *
     * @since 1.0.0
     * @param array $params | Query parameters.
     * @return array|WP_Error Invoices or error.
     */
    public static function get_invoices($params = array()) {
        $defaults = array(
            'pagina' => 1,
            'limite' => 100,
        );
        
        $params = wp_parse_args($params, $defaults);
        $endpoint = '/nfe?' . http_build_query($params);
        
        return self::request('GET', $endpoint);
    }


    /**
     * Get contact by ID from Bling.
     *
     * @since 1.0.1
     * @param int $contact_id | Contact ID.
     * @return array|WP_Error Contact data or error.
     */
    public static function get_contact( $contact_id ) {
        return self::request('GET', '/contatos/' . $contact_id);
    }


    /**
     * Get contacts from Bling.
     *
     * @since 1.0.0
     * @param array $params Query parameters.
     * @return array|WP_Error Contacts or error.
     */
    public static function get_contacts($params = array()) {
        $defaults = array(
            'pagina' => 1,
            'limite' => 100,
        );
        
        $params = wp_parse_args($params, $defaults);
        $endpoint = '/contatos?' . http_build_query($params);
        
        return self::request('GET', $endpoint);
    }
    

    /**
     * Create or update a contact in Bling.
     *
     * @since 1.0.0
     * @param array $contact_data Contact data.
     * @return array|WP_Error Created/updated contact or error.
     */
    public static function save_contact( $contact_data ) {
        // Check if contact exists by CPF/CNPJ
        $cpf_cnpj = isset($contact_data['numeroDocumento']) ? $contact_data['numeroDocumento'] : '';
        
        if ($cpf_cnpj) {
            $existing = self::get_contacts(array(
                'numeroDocumento' => $cpf_cnpj,
            ));
            
            if (!is_wp_error($existing) && isset($existing['data']['data'][0])) {
                $contact_id = $existing['data']['data'][0]['id'];
                return self::request('PUT', '/contatos/' . $contact_id, $contact_data);
            }
        }
        
        return self::request('POST', '/contatos', $contact_data);
    }


    /**
     * Send invoice to SEFAZ
     *
     * @since 1.0.1
     * @param int $invoice_id | NFe ID
     * @return array|WP_Error
     */
    public static function send_invoice_to_sefaz( $invoice_id ) {
        return self::request( 'POST', '/nfe/' . intval( $invoice_id ) . '/enviar' );
    }


    /**
     * Get sales channels from Bling.
     *
     * @since 1.0.1
     * @return array|WP_Error Sales channels or error.
     */
    public static function get_sales_channels() {
        return self::request( 'GET', '/canais-venda' );
    }


    /**
     * Get sales channels from Bling API
     *
     * @since 1.0.1
     * @return array|WP_Error
     */
    public static function get_sales_channels_from_bling() {
        try {
            $response = self::get_sales_channels();
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            if ($response['status'] !== 200) {
                return new WP_Error('api_error', 'Erro ao buscar canais de venda');
            }
            
            $channels = array();
            
            if (isset($response['data']['data']) && is_array($response['data']['data'])) {
                foreach ($response['data']['data'] as $channel) {
                    if (isset($channel['id']) && isset($channel['descricao'])) {
                        // Filter only active channels (situacao: 1 = Ativo, 2 = Inativo)
                        if (($channel['situacao'] ?? 1) == 1) {
                            $channels[] = array(
                                'id' => $channel['id'],
                                'descricao' => $channel['descricao'],
                                'tipo' => $channel['tipo'] ?? '',
                                'situacao' => $channel['situacao'] ?? 1
                            );
                        }
                    }
                }
            }
            
            return $channels;
        } catch ( Exception $e ) {
            return new WP_Error( 'exception', $e->getMessage() );
        }
    }
}