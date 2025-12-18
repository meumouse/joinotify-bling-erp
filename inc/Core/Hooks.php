<?php

namespace MeuMouse\Joinotify\Bling\Core;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Handle plugin hooks
 *
 * @since 1.0.0
 * @package MeuMouse\Joinotify\Bling\Core
 * @author MeuMouse.com
 */
class Hooks {
    
    /**
     * Constructor
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        add_action( 'joinotify_bling_invoice_authorized', array( $this, 'handle_invoice_authorized' ), 10, 1 );
    }
    

    /**
     * Handle Bling NFe authorized
     * 
     * @since 1.0.0
     * @param array $payload | Hook payload
     * @return void
     */
    public function handle_invoice_authorized( $payload ) {
        if ( defined('JOINOTIFY_BLING_DEV_MODE') && JOINOTIFY_BLING_DEV_MODE ) {
            error_log('[JOINOTIFY - BLING ERP]: NFe autorizada (joinotify_bling_invoice_authorized): ' . print_r( $payload, true ) );
        }

        $invoice_data = $payload['invoice_data'] ?? array();
        $invoice_id = $payload['invoice_id'] ?? 0;
        
        $orders = wc_get_orders( array(
            'meta_key' => '_bling_invoice_id',
            'meta_value' => $invoice_id,
            'limit' => 1,
        ));
        
        if ( ! empty( $orders ) ) {
            $order = $orders[0];
            
            if ( isset( $invoice_data['linkDanfe'] ) && ! empty( $invoice_data['linkDanfe'] ) ) {
                $order->update_meta_data( '_bling_danfe_link', $invoice_data['linkDanfe'] );
                
                if ( isset( $invoice_data['numero'] ) ) {
                    $order->update_meta_data( '_bling_invoice_number', $invoice_data['numero'] );
                }
                
                if ( isset( $invoice_data['chaveAcesso'] ) ) {
                    $order->update_meta_data( '_bling_invoice_key', $invoice_data['chaveAcesso'] );
                }
                
                $order->save();
                
                // add order note
                $order->add_order_note(
                    sprintf(
                        __( 'Nota fiscal autorizada no Bling. <a href="%s" target="_blank">Consultar DANFE</a>', 'joinotify-bling-erp' ),
                        esc_url( $invoice_data['linkDanfe'] )
                    )
                );
            }
        }
    }
}