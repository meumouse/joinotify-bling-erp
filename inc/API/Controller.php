<?php

namespace MeuMouse\Joinotify\Bling\API;

use MeuMouse\Joinotify\Core\Workflow_Processor;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles the OAuth and Webhook REST API endpoints for Bling integration.
 *
 * @since 1.0.0
 * @version 1.0.4
 * @package MeuMouse\Joinotify\Bling\API
 * @author MeuMouse.com
 */
class Controller {

    /**
     * Constructor
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }
    
    /**
     * Register REST API routes
     *
     * @since 1.0.0
     * @return void
     */
    public function register_routes() {
        register_rest_route( 'bling/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'bling/v1', '/auth/callback', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_oauth_callback' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'bling/v1', '/refresh-token', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_manual_refresh' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ) );
    }
    
    /**
     * Verify the Bling webhook signature for security.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request The incoming REST request.
     * @return bool|WP_Error True if valid, WP_Error if invalid.
     */
    public static function verify_signature( WP_REST_Request $request ) {
        $signature = $request->get_header( 'x-bling-signature-256' );

        if ( empty( $signature ) ) {
            return new WP_Error( 
                'invalid_signature', 
                __( 'Assinatura do webhook não encontrada.', 'joinotify-bling-erp' ), 
                array( 'status' => 401 ) 
            );
        }

        $payload = $request->get_body();

        // Use specific webhook secret if set, otherwise fallback to client secret
        $secret = get_option( 'bling_webhook_secret', '' );
        
        if ( empty( $secret ) ) {
            $secret = get_option( 'bling_client_secret', '' );
            
            if ( empty( $secret ) ) {
                error_log( 'Bling Webhook: Secret key not configured.' );
                // Allow processing for development if no secret configured
                return true;
            }
        }

        $expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

        if ( hash_equals( $expected, $signature ) ) {
            return true;
        }

        return new WP_Error( 
            'invalid_signature', 
            __( 'Falha na verificação da assinatura do webhook.', 'joinotify-bling-erp' ), 
            array( 'status' => 401 ) 
        );
    }
    
    /**
     * Handle incoming Bling webhook events (Nota Fiscal events).
     *
     * @since 1.0.0
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response Response indicating result of processing.
     */
    public static function handle_webhook( WP_REST_Request $request ) {
        // Get raw body and parse JSON
        $raw_body = $request->get_body();
        $webhook_data = json_decode( $raw_body, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log( '[JOINOTIFY - BLING ERP]: Webhook JSON parse error: ' . json_last_error_msg() );
            return new WP_REST_Response( 
                array( 
                    'success' => false, 
                    'message' => 'Invalid JSON payload' 
                ), 
                400 
            );
        }
        
        if ( defined( 'JOINOTIFY_BLING_DEV_MODE' ) && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( '[JOINOTIFY - BLING ERP]: Bling Webhook Received: ' . print_r( $webhook_data, true ) );
        }
        
        // Validate required webhook data
        if ( empty( $webhook_data ) || ! is_array( $webhook_data ) ) {
            return new WP_REST_Response( 
                array( 
                    'success' => false, 
                    'message' => 'Invalid payload structure' 
                ), 
                400 
            );
        }
        
        // Extract event data - Bling sends data directly in the root array
        $event = isset( $webhook_data['event'] ) ? sanitize_text_field( $webhook_data['event'] ) : '';
        $situacao = isset( $webhook_data['data']['situacao'] ) ? intval( $webhook_data['data']['situacao'] ) : 0;
        $invoice_id = isset( $webhook_data['data']['id'] ) ? intval( $webhook_data['data']['id'] ) : 0;
        $invoice_number = isset( $webhook_data['data']['numero'] ) ? sanitize_text_field( $webhook_data['data']['numero'] ) : '';
        $event_id = isset( $webhook_data['eventId'] ) ? sanitize_text_field( $webhook_data['eventId'] ) : '';
        
        // Log webhook details for debugging
        if ( defined( 'JOINOTIFY_BLING_DEV_MODE' ) && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( sprintf(
                '[JOINOTIFY - BLING ERP]: Webhook Event: %s, Invoice ID: %d, Status: %d, Number: %s',
                $event,
                $invoice_id,
                $situacao,
                $invoice_number
            ) );
        }
        
        // Find the WooCommerce order by Bling invoice ID
        $order_id = self::get_order_id_by_bling_invoice_id( $invoice_id );
        
        if ( ! $order_id ) {
            error_log( sprintf(
                '[JOINOTIFY - BLING ERP]: Order not found for Bling invoice ID: %d',
                $invoice_id
            ) );
            
            return new WP_REST_Response(
                array(
                    'success'  => false,
                    'message'  => 'Webhook ignored - no linked order found',
                    'order_id' => 0,
                ),
                200
            );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_REST_Response(
                array(
                    'success'  => false,
                    'message'  => 'Webhook ignored - order not accessible',
                    'order_id' => 0,
                ),
                200
            );
        }

        $dedup_key = self::get_webhook_dedup_key( $event_id, $event, $invoice_id, $situacao );
        $processed_events = $order->get_meta( '_bling_webhook_processed_events' );
        $processed_events = is_array( $processed_events ) ? $processed_events : array();

        if ( isset( $processed_events[ $dedup_key ] ) ) {
            return new WP_REST_Response(
                array(
                    'success'  => true,
                    'message'  => 'Webhook ignored - duplicate event',
                    'order_id' => $order_id,
                ),
                200
            );
        }

        $processed_events[ $dedup_key ] = current_time( 'mysql' );
        $order->update_meta_data( '_bling_webhook_processed_events', $processed_events );
        $order->save();
        
        // Determine which trigger hook to fire based on event and status
        $trigger_hook = '';
        $trigger_description = '';

        if ( 'invoice.created' === $event ) {
            $trigger_hook = 'bling_invoice_created';
            $trigger_description = 'Nota Fiscal Criada';
        } elseif ( 'invoice.updated' === $event ) {
            // Map specific status codes to events of interest
            switch ( $situacao ) {
                case 5: // Autorizada
                case 6: // Emitida (DANFE emitida)
                    $trigger_hook = 'bling_invoice_authorized';
                    $trigger_description = 'Nota Fiscal Autorizada';
                    break;
                    
                case 2: // Cancelada
                    $trigger_hook = 'bling_invoice_cancelled';
                    $trigger_description = 'Nota Fiscal Cancelada';
                    break;
                    
                case 4: // Rejeitada
                    $trigger_hook = 'bling_invoice_rejected';
                    $trigger_description = 'Nota Fiscal Rejeitada';
                    break;
                    
                case 9: // Denegada
                    $trigger_hook = 'bling_invoice_denied';
                    $trigger_description = 'Nota Fiscal Denegada';
                    break;
                    
                case 1: // Em digitação
                    $trigger_hook = 'bling_invoice_draft';
                    $trigger_description = 'Nota Fiscal em Digitação';
                    break;
                    
                default:
                    // For other status updates, still trigger a generic update
                    $trigger_hook = 'bling_invoice_updated';
                    $trigger_description = 'Nota Fiscal Atualizada';
                    break;
            }
        } elseif ( 'invoice.deleted' === $event ) {
            $trigger_hook = 'bling_invoice_deleted';
            $trigger_description = 'Nota Fiscal Excluída';
        } else {
            // Event not handled
            error_log( sprintf(
                '[JOINOTIFY - BLING ERP]: Unhandled webhook event: %s',
                $event
            ) );
            
            return new WP_REST_Response( 
                array( 
                    'success' => false, 
                    'message' => 'Event ignored' 
                ), 
                200 
            );
        }
        
        if ( empty( $trigger_hook ) ) {
            // No relevant trigger for this event
            error_log( sprintf(
                '[JOINOTIFY - BLING ERP]: No trigger hook for event: %s, status: %d',
                $event,
                $situacao
            ) );
            
            return new WP_REST_Response( 
                array( 
                    'success' => false, 
                    'message' => 'Event ignored - no trigger' 
                ), 
                200 
            );
        }
        
        // Update order meta with new invoice status if we found an order
        self::update_order_invoice_status( $order_id, $invoice_id, $situacao, $invoice_number, $webhook_data );
        
        // Retrieve full invoice data from Bling API if possible (for placeholders)
        $invoice_data = self::get_invoice_details( $invoice_id );
        
        if ( is_wp_error( $invoice_data ) ) {
            error_log( '[JOINOTIFY - BLING ERP]: Bling API Error: ' . $invoice_data->get_error_message() );
            // Even if we fail to get details, we can proceed with basic data
            $invoice_data = $webhook_data['data'] ?? array();
        }
        
        if ( defined( 'JOINOTIFY_BLING_DEV_MODE' ) && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( sprintf(
                '[JOINOTIFY - BLING ERP]: Triggering hook: %s for order #%d',
                $trigger_hook,
                $order_id
            ) );
        }
        
        // Prepare payload for Joinotify Workflow Processor
        $payload = array(
            'type'                 => 'trigger',
            'hook'                 => $trigger_hook,
            'integration'          => 'bling',
            'invoice_id'           => $invoice_id,
            'invoice_number'       => $invoice_number,
            'invoice_status'       => $situacao,
            'invoice_status_label' => self::get_invoice_status_label( $situacao ),
            'order_id'             => $order_id,
            'invoice_data'         => $invoice_data,
            'webhook_data'         => $webhook_data,
            'event_id'             => $event_id,
            'event_type'           => $event,
            'description'          => $trigger_description,
            'timestamp'            => current_time( 'mysql' ),
        );
        
        if ( defined( 'JOINOTIFY_BLING_DEV_MODE' ) && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( '[JOINOTIFY - BLING ERP]: Webhook Payload for Workflows: ' . print_r( $payload, true ) );
        }
        
        // Only process workflows if integration is enabled in Joinotify settings
        if ( function_exists( 'MeuMouse\\Joinotify\\Admin\\Admin::get_setting' ) ) {
            // Note: You need to adjust this based on your actual Admin class structure
            $bling_integration_enabled = true; // Default to true for now
            
            // Uncomment and adjust when you have the proper Admin class
            /*
            $admin_class = 'MeuMouse\\Joinotify\\Admin\\Admin';
            if ( class_exists( $admin_class ) && method_exists( $admin_class, 'get_setting' ) ) {
                $bling_integration_enabled = $admin_class::get_setting( 'enable_bling_integration' ) === 'yes';
            }
            */
            
            if ( ! $bling_integration_enabled ) {
                return new WP_REST_Response( 
                    array( 
                        'success' => false, 
                        'message' => 'Bling integration disabled in settings' 
                    ), 
                    200 
                );
            }
        }
        
        // Process workflows in Joinotify that match this trigger
        if ( class_exists( 'MeuMouse\\Joinotify\\Core\\Workflow_Processor' ) ) {
            $processed = Workflow_Processor::process_workflows( 
                apply_filters( 'Joinotify/Process_Workflows/Bling', $payload ) 
            );
            
            if ( defined( 'JOINOTIFY_BLING_DEV_MODE' ) && JOINOTIFY_BLING_DEV_MODE ) {
                error_log( sprintf(
                    '[JOINOTIFY - BLING ERP]: Workflows processed: %s',
                    $processed ? 'Yes' : 'No'
                ) );
            }
        } else {
            error_log( '[JOINOTIFY - BLING ERP]: Workflow_Processor class not found' );
        }
        
        /**
         * Fire a general WordPress action for the event, if other plugins need to hook.
         * This allows other plugins to listen for Bling events even without Joinotify.
         */
        do_action( 'joinotify_bling_webhook_received', $payload );
        do_action( 'joinotify_' . $trigger_hook, $payload );
        do_action( 'joinotify_bling_' . $event, $payload );
        
        // Also fire a generic WordPress action for WooCommerce integration
        if ( $order_id ) {
            do_action( 'woocommerce_bling_invoice_updated', $order_id, $payload );
            do_action( 'woocommerce_bling_' . $trigger_hook, $order_id, $payload );
            
            // Add order note about the status change
            if ( $order_id && in_array( $situacao, array( 5, 6, 2, 4, 9 ) ) ) {
                self::add_order_note_for_status_change( $order_id, $situacao, $invoice_number );
            }
        }
        
        return new WP_REST_Response( array(
            'success'            => true,
            'message'            => 'Webhook processed successfully',
            'event_type'         => $event,
            'trigger_hook'       => $trigger_hook,
            'invoice_id'         => $invoice_id,
            'invoice_number'     => $invoice_number,
            'invoice_status'     => $situacao,
            'order_id'           => $order_id,
            'processed'          => isset( $processed ) ? $processed : false,
        ), 200 );
    }
    
    /**
     * Find WooCommerce order by Bling invoice ID
     *
     * @since 1.0.1
     * @param int $invoice_id Bling invoice ID
     * @return int Order ID or 0 if not found
     */
    private static function get_order_id_by_bling_invoice_id( $invoice_id ) {
        global $wpdb;
        
        if ( ! $invoice_id ) {
            return 0;
        }
        
        // Try to find order by _bling_invoice_id meta
        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bling_invoice_id' 
            AND meta_value = %d 
            LIMIT 1",
            $invoice_id
        ) );
        
        if ( ! empty( $order_ids ) ) {
            return (int) $order_ids[0];
        }
        
        // Also check for HPOS compatibility
        if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
            
            $order_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT order_id 
                FROM {$wpdb->prefix}wc_orders_meta 
                WHERE meta_key = '_bling_invoice_id' 
                AND meta_value = %d 
                LIMIT 1",
                $invoice_id
            ) );
            
            if ( ! empty( $order_ids ) ) {
                return (int) $order_ids[0];
            }
        }
        
        return 0;
    }

    
    /**
     * Build a deduplication key for webhook processing.
     *
     * @since 1.0.4
     * @param string $event_id Event ID from webhook.
     * @param string $event Event type.
     * @param int    $invoice_id Bling invoice ID.
     * @param int    $situacao Invoice status code.
     * @return string Deduplication key.
     */
    private static function get_webhook_dedup_key( $event_id, $event, $invoice_id, $situacao ) {
        if ( ! empty( $event_id ) ) {
            return 'event:' . sanitize_text_field( $event_id );
        }

        return sprintf(
            'event:%s|invoice:%d|status:%d',
            sanitize_text_field( $event ),
            intval( $invoice_id ),
            intval( $situacao )
        );
    }

    
    /**
     * Update order meta with invoice status from webhook
     *
     * @since 1.0.1
     * @param int $order_id WooCommerce order ID
     * @param int $invoice_id Bling invoice ID
     * @param int $situacao Invoice status code
     * @param string $invoice_number Invoice number
     * @param array $webhook_data Full webhook data
     * @return void
     */
    private static function update_order_invoice_status( $order_id, $invoice_id, $situacao, $invoice_number, $webhook_data ) {
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            return;
        }
        
        // Update invoice status
        $order->update_meta_data( '_bling_invoice_status', $situacao );
        
        // Update invoice number if not already set
        $existing_number = $order->get_meta( '_bling_invoice_number' );
        if ( empty( $existing_number ) && ! empty( $invoice_number ) ) {
            $order->update_meta_data( '_bling_invoice_number', $invoice_number );
        }
        
        // Update invoice series if available
        if ( isset( $webhook_data['data']['serie'] ) ) {
            $order->update_meta_data( '_bling_invoice_series', sanitize_text_field( $webhook_data['data']['serie'] ) );
        }
        
        // Update DANFE link if available
        if ( isset( $webhook_data['data']['linkDanfe'] ) ) {
            $order->update_meta_data( '_bling_danfe_link', esc_url_raw( $webhook_data['data']['linkDanfe'] ) );
        }
        
        // Update access key if available
        if ( isset( $webhook_data['data']['chaveAcesso'] ) ) {
            $order->update_meta_data( '_bling_invoice_access_key', sanitize_text_field( $webhook_data['data']['chaveAcesso'] ) );
        }
        
        // Save webhook timestamp
        if ( isset( $webhook_data['date'] ) ) {
            $order->update_meta_data( '_bling_webhook_last_update', sanitize_text_field( $webhook_data['date'] ) );
        }
        
        $order->save();
        
        if ( defined( 'JOINOTIFY_BLING_DEV_MODE' ) && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( sprintf(
                '[JOINOTIFY - BLING ERP]: Updated order #%d with invoice status %d',
                $order_id,
                $situacao
            ) );
        }
    }
    
    /**
     * Add order note for invoice status change
     *
     * @since 1.0.1
     * @param int $order_id Order ID
     * @param int $situacao Invoice status code
     * @param string $invoice_number Invoice number
     * @return void
     */
    private static function add_order_note_for_status_change( $order_id, $situacao, $invoice_number ) {
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            return;
        }
        
        $status_labels = array(
            2 => __( 'Cancelada', 'joinotify-bling-erp' ),
            4 => __( 'Rejeitada', 'joinotify-bling-erp' ),
            5 => __( 'Autorizada', 'joinotify-bling-erp' ),
            6 => __( 'Emitida (DANFE)', 'joinotify-bling-erp' ),
            9 => __( 'Denegada', 'joinotify-bling-erp' ),
        );
        
        $status_label = isset( $status_labels[ $situacao ] ) ? $status_labels[ $situacao ] : __( 'Atualizada', 'joinotify-bling-erp' );
        
        $note = sprintf(
            __( 'Nota Fiscal Bling %s: Status atualizado para "%s".', 'joinotify-bling-erp' ),
            $invoice_number ? '#' . $invoice_number : '',
            $status_label
        );
        
        $order->add_order_note( $note );
        
        if ( defined( 'JOINOTIFY_BLING_DEV_MODE' ) && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( sprintf(
                '[JOINOTIFY - BLING ERP]: Added order note to #%d: %s',
                $order_id,
                $note
            ) );
        }
    }
    
    /**
     * Get invoice status label from code
     *
     * @since 1.0.1
     * @param int $status_code Status code
     * @return string Status label
     */
    private static function get_invoice_status_label( $status_code ) {
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
        
        return isset( $statuses[ $status_code ] ) ? $statuses[ $status_code ] : __( 'Desconhecido', 'joinotify-bling-erp' );
    }
    
    /**
     * Handle the OAuth2 callback from Bling (after user authorizes the app).
     *
     * @since 1.0.0
     * @param WP_REST_Request $request The incoming request with auth code.
     * @return WP_REST_Response Response indicating success or failure.
     */
    public static function handle_oauth_callback( WP_REST_Request $request ) {
        $code = $request->get_param( 'code' );

        if ( empty( $code ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => __( 'Nenhum código de autorização recebido.', 'joinotify-bling-erp' ),
            ), 400 );
        }

        $client_id = get_option( 'bling_client_id' );
        $client_secret = get_option( 'bling_client_secret' );

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => __( 'Credenciais do Client não configuradas.', 'joinotify-bling-erp' ),
            ), 400 );
        }

        $redirect_uri = get_rest_url( null, 'bling/v1/auth/callback' );
        $auth_string = base64_encode( $client_id . ':' . $client_secret );
        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . $auth_string,
            ),
            'body'    => http_build_query( array(
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $redirect_uri,
            ) ),
            'timeout' => 30,
        );

        $response = wp_remote_post( 'https://www.bling.com.br/Api/v3/oauth/token', $args );

        if ( is_wp_error( $response ) ) {
            error_log( 'Bling OAuth Error: ' . $response->get_error_message() );
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => 'HTTP Request failed: ' . $response->get_error_message(),
            ), 500 );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        
        error_log( 'Bling OAuth Response Status: ' . $status );
        error_log( 'Bling OAuth Response Body: ' . print_r( $body, true ) );
        
        if ( $status !== 200 || empty( $body['access_token'] ) ) {
            $error_msg = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $status );
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => 'Bling API Error: ' . $error_msg,
                'details' => $body,
            ), 400 );
        }

        // Save tokens and expiration time
        update_option( 'bling_access_token', $body['access_token'] );
        update_option( 'bling_refresh_token', $body['refresh_token'] );
        update_option( 'bling_token_expires', time() + intval( $body['expires_in'] ) );
        
        return new WP_REST_Response( array(
            'success'      => true,
            'message'      => __( 'Autenticação bem-sucedida!', 'joinotify-bling-erp' ),
            'redirect_url' => admin_url( 'admin.php?page=joinotify-bling' ),
        ), 200 );
    }
    
    /**
     * Handle manual refresh token request from the admin interface.
     *
     * @since 1.0.0
     * @return WP_REST_Response Response with new token info or error.
     */
    public static function handle_manual_refresh() {
        $refresh_token = get_option( 'bling_refresh_token' );

        if ( empty( $refresh_token ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => __( 'Refresh token não encontrado.', 'joinotify-bling-erp' ),
            ), 400 );
        }
        
        $new_token = self::refresh_token( $refresh_token );

        if ( is_wp_error( $new_token ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => $new_token->get_error_message(),
            ), 500 );
        }

        return new WP_REST_Response( array(
            'success'      => true,
            'message'      => __( 'Token atualizado com sucesso!', 'joinotify-bling-erp' ),
            'access_token' => $new_token,
            'expires'      => date_i18n( 'd/m/Y H:i:s', get_option( 'bling_token_expires' ) ),
        ), 200 );
    }
    
    /**
     * Refresh the Bling API access token using a refresh token.
     *
     * @since 1.0.0
     * @param string $refresh_token The refresh token.
     * @return string|\WP_Error New access token on success, WP_Error on failure.
     */
    public static function refresh_token( $refresh_token ) {
        $client_id = get_option( 'bling_client_id' );
        $client_secret = get_option( 'bling_client_secret' );
       
        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return new WP_Error( 
                'missing_credentials', 
                __( 'Client ID ou Client Secret não configurados.', 'joinotify-bling-erp' ) 
            );
        }

        if ( empty( $refresh_token ) ) {
            return new WP_Error( 
                'missing_token', 
                __( 'Refresh token vazio.', 'joinotify-bling-erp' ) 
            );
        }

        $auth_string = base64_encode( $client_id . ':' . $client_secret );
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth_string,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body' => http_build_query( array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
            ) ),
            'timeout' => 30,
        );

        $response = wp_remote_post( 'https://api.bling.com.br/Api/v3/oauth/token', $args );
        
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'http_error', $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        error_log( '[JOINOTIFY - BLING ERP]: BLING REFRESH: Status ' . $status . ' -> ' . print_r( $body, true ) );

        if ( $status !== 200 || empty( $body['access_token'] ) ) {
            $err = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : __( 'Falha ao renovar token.', 'joinotify-bling-erp' ) );
            return new WP_Error( 'refresh_failed', $err, $body );
        }

        if ( defined( 'JOINOTIFY_BLING_DEV_MODE' ) && JOINOTIFY_BLING_DEV_MODE ) {
            error_log( '[JOINOTIFY - BLING ERP]: New access token: ' . print_r( $body['access_token'], true ) );
        }

        // Save new tokens
        update_option( 'bling_access_token', $body['access_token'] );
        update_option( 'bling_refresh_token', $body['refresh_token'] );
        update_option( 'bling_token_expires', time() + intval( $body['expires_in'] ) );
        
        return $body['access_token'];
    }
    
    /**
     * Fetch detailed invoice data from Bling API by ID.
     *
     * @since 1.0.0
     * @param int $invoice_id The Bling invoice ID.
     * @return array|\WP_Error Invoice data on success, WP_Error on failure.
     */
    public static function get_invoice_details( $invoice_id ) {
        $access_token = get_option( 'bling_access_token' );
        $refresh_token = get_option( 'bling_refresh_token' );

        if ( empty( $access_token ) ) {
            return new WP_Error( 
                'no_token', 
                __( 'Access token não configurado.', 'joinotify-bling-erp' ) 
            );
        }

        $api_url = "https://api.bling.com.br/Api/v3/nfe/{$invoice_id}";
        $response = wp_remote_get( $api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
        ) );

        $response_code = wp_remote_retrieve_response_code( $response );
        
        // If token expired or unauthorized, try refreshing
        if ( $response_code === 401 ) {
            $new_token = self::refresh_token( $refresh_token );
            
            if ( ! is_wp_error( $new_token ) ) {
                $response = wp_remote_get( $api_url, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $new_token,
                        'Accept' => 'application/json',
                    ),
                    'timeout' => 30,
                ) );

                $response_code = wp_remote_retrieve_response_code( $response );
            }
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Check for successful response
        if ( $response_code !== 200 ) {
            $error_message = wp_remote_retrieve_response_message( $response );
            return new WP_Error( 
                'api_error', 
                sprintf( 
                    __( 'Erro na API do Bling: %s (Código: %d)', 'joinotify-bling-erp' ), 
                    $error_message, 
                    $response_code 
                ) 
            );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        
        // Check if data exists and has the expected structure
        if ( empty( $data['data'] ) ) {
            return new WP_Error( 
                'invalid_response', 
                __( 'Resposta inválida da API do Bling', 'joinotify-bling-erp' ) 
            );
        }

        return $data['data'];
    }
}