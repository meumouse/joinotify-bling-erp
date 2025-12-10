<?php

namespace MeuMouse\Joinotify\Bling\API;

use WP_REST_Request;
use WP_REST_Response;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Handles the OAuth and Webhook REST API endpoints for Bling integration.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Controller {

    /**
     * Register all REST API routes for Bling integration.
     *
     * @since 1.0.0
     * @return void
     */
    public static function register_routes() {
        register_rest_route('bling/v1', '/webhook', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => array(__CLASS__, 'verify_signature'),
        ));

        register_rest_route('bling/v1', '/auth/callback', array(
            'methods'  => 'GET',
            'callback' => array(__CLASS__, 'handle_oauth_callback'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('bling/v1', '/refresh-token', array(
            'methods'  => 'POST',
            'callback' => array(__CLASS__, 'handle_manual_refresh'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
    }
    
    /**
     * Verify the Bling webhook signature for security.
     *
     * @param WP_REST_Request $request The incoming REST request.
     * @return bool|WP_Error True if valid, WP_Error if invalid.
     */
    public static function verify_signature( WP_REST_Request $request ) {
        $signature = $request->get_header('x-bling-signature-256');

        if ( empty($signature) ) {
            return new \WP_Error( 'invalid_signature', __('Assinatura do webhook não encontrada.', 'joinotify-bling-erp'), array('status' => 401) );
        }

        $payload = $request->get_body();

        // Use specific webhook secret if set, otherwise fallback to client secret
        $secret = get_option('bling_webhook_secret', '');
        
        if ( empty($secret) ) {
            $secret = get_option('bling_client_secret', '');
            
            if ( empty($secret) ) {
                error_log('Bling Webhook: Secret key not configured.');
                // Allow processing for development if no secret configured
                return true;
            }
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if ( hash_equals($expected, $signature) ) {
            return true;
        }

        return new \WP_Error( 'invalid_signature', __('Falha na verificação da assinatura do webhook.', 'joinotify-bling-erp'), array('status' => 401) );
    }

    
    /**
     * Handle incoming Bling webhook events (Nota Fiscal events).
     *
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response Response indicating result of processing.
     */
    public static function handle_webhook( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        
        error_log('Bling Webhook Received: ' . print_r($body, true));
        
        if ( empty($body[0]['body']) ) {
            return new WP_REST_Response( array('error' => 'Invalid payload'), 400 );
        }

        $webhook_data = $body[0]['body'];
        $event = isset($webhook_data['event']) ? $webhook_data['event'] : '';
        $situacao = isset($webhook_data['data']['situacao']) ? intval($webhook_data['data']['situacao']) : 0;
        $invoice_id = isset($webhook_data['data']['id']) ? intval($webhook_data['data']['id']) : 0;
        
        // Determine which trigger hook to fire based on event and status
        $trigger_hook = '';

        if ( $event === 'invoice.created' ) {
            $trigger_hook = 'bling_invoice_created';
        } elseif ( $event === 'invoice.updated' ) {
            // Map specific status codes to events of interest
            if ( $situacao === 5 || $situacao === 6 ) {
                // 5 = Autorizada, 6 = Emitida (DANFE emitida, essentially authorized)
                $trigger_hook = 'bling_invoice_authorized';
            } elseif ( $situacao === 2 ) {
                // 2 = Cancelada
                $trigger_hook = 'bling_invoice_cancelled';
            } elseif ( $situacao === 4 ) {
                // 4 = Rejeitada
                $trigger_hook = 'bling_invoice_rejected';
            } elseif ( $situacao === 9 ) {
                // 9 = Denegada
                $trigger_hook = 'bling_invoice_denied';
            }
        } elseif ( $event === 'invoice.deleted' ) {
            $trigger_hook = 'bling_invoice_deleted';
        } else {
            // Event not handled
            return new WP_REST_Response( array('message' => 'Event ignored'), 200 );
        }
        
        if ( empty($trigger_hook) ) {
            // No relevant trigger for this event
            return new WP_REST_Response( array('message' => 'Event ignored'), 200 );
        }
        
        // Retrieve full invoice data from Bling API if possible (for placeholders)
        $invoice_data = self::get_invoice_details( $invoice_id );
        
        if ( is_wp_error($invoice_data) ) {
            error_log('Bling API Error: ' . $invoice_data->get_error_message());
            // Even if we fail to get details, we can proceed with basic data
            $invoice_data = array();
        }
        
        // Prepare payload for Joinotify Workflow Processor
        $payload = array(
            'type'        => 'trigger',
            'hook'        => $trigger_hook,
            'integration' => 'bling',
            'invoice_id'  => $invoice_id,
            'invoice_data'=> $invoice_data,
        );
        
        // Only process workflows if integration is enabled in Joinotify settings
        if ( function_exists('MeuMouse\\Joinotify\\Admin\\Admin::get_setting') && JoinotifyAdmin::get_setting('enable_bling_integration') !== 'yes' ) {
            return new WP_REST_Response( array('success' => false, 'message' => 'Bling integration disabled in settings'), 200 );
        }
        
        // Process workflows in Joinotify that match this trigger
        if ( class_exists('MeuMouse\\Joinotify\\Core\\Workflow_Processor') ) {
            Workflow_Processor::process_workflows( apply_filters('Joinotify/Process_Workflows/Bling', $payload) );
        }
        
        /**
         * Fire a general WordPress action for the event, if other plugins need to hook.
         * E.g., do_action('joinotify_bling_invoice_authorized', $webhook_data, $invoice_data);
         */
        do_action( 'joinotify_' . $trigger_hook, $webhook_data, $invoice_data );
        
        return new WP_REST_Response( array(
            'success'    => true,
            'message'    => 'Webhook processed',
            'event_type' => $trigger_hook,
            'invoice_id' => $invoice_id
        ), 200 );
    }

    
    /**
     * Handle the OAuth2 callback from Bling (after user authorizes the app).
     *
     * @param WP_REST_Request $request The incoming request with auth code.
     * @return WP_REST_Response Response indicating success or failure.
     */
    public static function handle_oauth_callback( WP_REST_Request $request ) {
        $code = $request->get_param('code');

        if ( empty($code) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => __('Nenhum código de autorização recebido.', 'joinotify-bling-erp')
            ), 400 );
        }

        $client_id = get_option('bling_client_id');
        $client_secret = get_option('bling_client_secret');

        if ( empty($client_id) || empty($client_secret) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => __('Credenciais do Client não configuradas.', 'joinotify-bling-erp')
            ), 400 );
        }

        $redirect_uri = get_rest_url( null, 'bling/v1/auth/callback' );
        $auth_string = base64_encode( $client_id . ':' . $client_secret );
        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . $auth_string,
            ),
            'body'    => http_build_query(array(
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $redirect_uri,
            )),
            'timeout' => 30,
        );

        $response = wp_remote_post('https://www.bling.com.br/Api/v3/oauth/token', $args);

        if ( is_wp_error($response) ) {
            error_log('Bling OAuth Error: ' . $response->get_error_message());
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => 'HTTP Request failed: ' . $response->get_error_message()
            ), 500 );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode( wp_remote_retrieve_body($response), true );
        
        error_log('Bling OAuth Response Status: ' . $status);
        error_log('Bling OAuth Response Body: ' . print_r($body, true));
        
        if ( $status !== 200 || empty($body['access_token']) ) {
            $error_msg = isset($body['error_description']) ? $body['error_description'] : ( isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $status );
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => 'Bling API Error: ' . $error_msg,
                'details' => $body
            ), 400 );
        }

        // Save tokens and expiration time
        update_option('bling_access_token', $body['access_token']);
        update_option('bling_refresh_token', $body['refresh_token']);
        update_option('bling_token_expires', time() + intval($body['expires_in']));
        
        return new WP_REST_Response( array(
            'success'      => true,
            'message'      => __('Autenticação bem-sucedida!', 'joinotify-bling-erp'),
            'redirect_url' => admin_url('tools.php?page=joinotify-bling&auth=success')
        ), 200 );
    }
    
    
    /**
     * Handle manual refresh token request from the admin interface.
     *
     * @return WP_REST_Response Response with new token info or error.
     */
    public static function handle_manual_refresh() {
        $refresh_token = get_option('bling_refresh_token');

        if ( empty($refresh_token) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => __('Refresh token não encontrado.', 'joinotify-bling-erp')
            ), 400 );
        }
        
        $new_token = self::refresh_token( $refresh_token );

        if ( is_wp_error($new_token) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => $new_token->get_error_message()
            ), 500 );
        }

        return new WP_REST_Response( array(
            'success'      => true,
            'message'      => __('Token atualizado com sucesso!', 'joinotify-bling-erp'),
            'access_token' => $new_token,
            'expires'      => date_i18n( 'd/m/Y H:i:s', get_option('bling_token_expires') )
        ), 200 );
    }
    

    /**
     * Refresh the Bling API access token using a refresh token.
     *
     * @param string $refresh_token The refresh token.
     * @return string|\WP_Error New access token on success, WP_Error on failure.
     */
    public static function refresh_token( $refresh_token ) {
        $client_id     = get_option('bling_client_id');
        $client_secret = get_option('bling_client_secret');
       
        if ( empty($client_id) || empty($client_secret) ) {
            return new \WP_Error( 'missing_credentials', __('Client ID ou Client Secret não configurados.', 'joinotify-bling-erp') );
        }

        if ( empty($refresh_token) ) {
            return new \WP_Error( 'missing_token', __('Refresh token vazio.', 'joinotify-bling-erp') );
        }

        $auth_string = base64_encode( $client_id . ':' . $client_secret );
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth_string,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body' => http_build_query(array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
            )),
            'timeout' => 30,
        );

        $response = wp_remote_post('https://api.bling.com.br/Api/v3/oauth/token', $args);
        
        if ( is_wp_error($response) ) {
            return new \WP_Error( 'http_error', $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode( wp_remote_retrieve_body($response), true );

        error_log('BLING REFRESH: Status ' . $status . ' -> ' . print_r($body, true));

        if ( $status !== 200 || empty($body['access_token']) ) {
            $err = isset($body['error_description']) ? $body['error_description'] : ( isset($body['error']) ? $body['error'] : __('Falha ao renovar token.', 'joinotify-bling-erp') );
            return new \WP_Error( 'refresh_failed', $err, $body );
        }

        // Save new tokens
        update_option('bling_access_token', $body['access_token']);
        update_option('bling_refresh_token', $body['refresh_token']);
        update_option('bling_token_expires', time() + intval($body['expires_in']));
        
        return $body['access_token'];
    }
    

    /**
     * Fetch detailed invoice data from Bling API by ID.
     *
     * @param int $invoice_id The Bling invoice ID.
     * @return array|\WP_Error Invoice data on success, WP_Error on failure.
     */
    public static function get_invoice_details( $invoice_id ) {
        $access_token  = get_option('bling_access_token');
        $refresh_token = get_option('bling_refresh_token');

        if ( empty($access_token) ) {
            return new \WP_Error( 'no_token', __('Access token não configurado.', 'joinotify-bling-erp') );
        }

        $api_url = "https://api.bling.com.br/Api/v3/nfe/{$invoice_id}";
        $response = wp_remote_get( $api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept'        => 'application/json',
            ),
            'timeout' => 30,
        ));

        // If token expired or unauthorized, try refreshing
        if ( wp_remote_retrieve_response_code($response) === 401 ) {
            $new_token = self::refresh_token( $refresh_token );
            
            if ( ! is_wp_error($new_token) ) {
                $response = wp_remote_get( $api_url, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $new_token,
                        'Accept'        => 'application/json',
                    ),
                    'timeout' => 30,
                ));
            }
        }

        if ( is_wp_error($response) ) {
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body($response), true );
        
        if ( empty($data['data'][0]) ) {
            return new \WP_Error( 'invalid_response', __('Resposta inválida da API do Bling', 'joinotify-bling-erp') );
        }

        return $data['data'][0];
    }
}