<?php

namespace MeuMouse\Joinotify\Bling\Integrations;

use MeuMouse\Joinotify\Integrations\Integrations_Base;
use MeuMouse\Joinotify\Admin\Admin as Joinotify_Admin;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Integration with Bling ERP for Joinotify triggers and placeholders.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Integration extends Integrations_Base {

    /**
     * Construct the integration and set up hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        error_log('Initializing Joinotify Bling Integration');
        // Add integration item in Joinotify settings (Integrations tab).
        add_filter( 'Joinotify/Settings/Tabs/Integrations', array( $this, 'add_integration_item' ), 10, 1 );
        
        // Register triggers for Bling events.
        add_filter( 'Joinotify/Builder/Get_All_Triggers', array( $this, 'add_triggers' ), 10, 1 );
        
        // Add triggers tab in Joinotify builder UI.
        add_action( 'Joinotify/Builder/Triggers', array( $this, 'add_triggers_tab' ), 20 );
        
        // Add triggers content in Joinotify builder UI.
        add_action( 'Joinotify/Builder/Triggers_Content', array( $this, 'add_triggers_content' ) );
        
        // Register placeholders for Bling data.
        add_filter( 'Joinotify/Builder/Placeholders_List', array( $this, 'add_placeholders' ), 10, 2 );
        
        // Add integration settings link or info in Joinotify settings page.
        add_action( 'Joinotify/Settings/Tabs/Integrations/Bling', array( $this, 'add_modal_settings' ) );
    }

    
    /**
     * Provide integration information for Joinotify settings.
     *
     * @since 1.0.0
     * @param array $integrations Current integrations array.
     * @return array Modified integrations array including Bling.
     */
    public function add_integration_item( $integrations ) {
        $integrations['bling'] = array(
            'title'       => esc_html__( 'Bling ERP', 'joinotify-bling-erp' ),
            'description' => esc_html__( 'Receba eventos de nota fiscal (NFe) e envie notificações automatizadas.', 'joinotify-bling-erp' ),
            'icon'        => '<svg width="32" height="32" xmlns="http://www.w3.org/2000/svg"><rect width="32" height="32" fill="#f5c013"/><text x="4" y="22" font-size="18" font-family="sans-serif" fill="#000">B</text></svg>',
            'setting_key' => 'enable_bling_integration',
            'action_hook' => 'Joinotify/Settings/Tabs/Integrations/Bling',
            'is_plugin'   => true,
            'plugin_active' => array('joinotify-bling-erp/joinotify-bling-erp.php'),
        );

        return $integrations;
    }
    

    /**
     * Define triggers for Bling NFe events (invoice).
     *
     * @since 1.0.0
     * @param array $triggers Existing triggers.
     * @return array Modified triggers including Bling events.
     */
    public function add_triggers( $triggers ) {
        $triggers['bling'] = array(
            array(
                'data_trigger'  => 'bling_invoice_created',
                'title'         => esc_html__( 'Nota fiscal criada (Bling)', 'joinotify-bling-erp' ),
                'description'   => esc_html__( 'Este acionamento é disparado quando uma nota fiscal eletrônica é criada no Bling.', 'joinotify-bling-erp' ),
                'require_settings' => false,
            ),
            array(
                'data_trigger'  => 'bling_invoice_authorized',
                'title'         => esc_html__( 'Nota fiscal autorizada (Bling)', 'joinotify-bling-erp' ),
                'description'   => esc_html__( 'Este acionamento é disparado quando uma NFe é autorizada pela SEFAZ no Bling.', 'joinotify-bling-erp' ),
                'require_settings' => false,
            ),
            array(
                'data_trigger'  => 'bling_invoice_cancelled',
                'title'         => esc_html__( 'Nota fiscal cancelada (Bling)', 'joinotify-bling-erp' ),
                'description'   => esc_html__( 'Este acionamento é disparado quando uma NFe autorizada é cancelada no Bling.', 'joinotify-bling-erp' ),
                'require_settings' => false,
            ),
            array(
                'data_trigger'  => 'bling_invoice_rejected',
                'title'         => esc_html__( 'Nota fiscal rejeitada (Bling)', 'joinotify-bling-erp' ),
                'description'   => esc_html__( 'Este acionamento é disparado quando uma NFe é rejeitada no processo de autorização.', 'joinotify-bling-erp' ),
                'require_settings' => false,
            ),
            array(
                'data_trigger'  => 'bling_invoice_denied',
                'title'         => esc_html__( 'Nota fiscal denegada (Bling)', 'joinotify-bling-erp' ),
                'description'   => esc_html__( 'Este acionamento é disparado quando a SEFAZ denega (não autoriza) uma NFe no Bling.', 'joinotify-bling-erp' ),
                'require_settings' => false,
            ),
            array(
                'data_trigger'  => 'bling_invoice_deleted',
                'title'         => esc_html__( 'Nota fiscal excluída (Bling)', 'joinotify-bling-erp' ),
                'description'   => esc_html__( 'Disparado quando uma nota fiscal é excluída permanentemente no Bling.', 'joinotify-bling-erp' ),
                'require_settings' => false,
            ),
        );

        return $triggers;
    }
    

    /**
     * Output the Bling tab in Joinotify's trigger selection UI.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_triggers_tab() {
        $integration_slug = 'bling';
        $integration_name = esc_html__( 'Bling ERP', 'joinotify-bling-erp' );
        $icon_svg = '<svg class="joinotify-tab-icon" width="24" height="24" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" fill="#f5c013"/><text x="3" y="17" font-size="12" font-family="sans-serif" fill="#000">Bling</text></svg>';
        
        $this->render_integration_trigger_tab( $integration_slug, $integration_name, $icon_svg );
    }
    
    
    /**
     * Output the content (list of triggers) for the Bling tab in triggers UI.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_triggers_content() {
        $this->render_integration_trigger_content('bling');
    }
    

    /**
     * Define text placeholders for use in messages related to Bling events.
     *
     * @since 1.0.0
     * @param array $placeholders Existing placeholders.
     * @param array $payload Payload data from the trigger event.
     * @return array Modified placeholders including Bling placeholders.
     */
    public function add_placeholders( $placeholders, $payload ) {
        // Only add placeholders for Bling integration triggers
        if ( isset($payload['integration']) && $payload['integration'] === 'bling' ) {
            $invoice = isset($payload['invoice_data']) ? $payload['invoice_data'] : array();
            $trigger_names = array( 
                'bling_invoice_created',
                'bling_invoice_authorized',
                'bling_invoice_cancelled',
                'bling_invoice_rejected',
                'bling_invoice_denied',
                'bling_invoice_deleted'
            );

            $numero = isset($invoice['numero']) ? $invoice['numero'] : '';
            $situacao = isset($invoice['situacao']) ? $invoice['situacao'] : '';
            $total = isset($invoice['valorNota']) ? $invoice['valorNota'] : ( isset($invoice['valorNotaFiscal']) ? $invoice['valorNotaFiscal'] : '' );
            $client_name = isset($invoice['cliente']) ? $invoice['cliente']['nome'] : '';
            
            $placeholders['bling'] = array(
                '{{ bling_invoice_number }}' => array(
                    'triggers' => $trigger_names,
                    'description' => esc_html__( 'Número da nota fiscal no Bling', 'joinotify-bling-erp' ),
                    'replacement' => array(
                        'production' => $numero,
                        'sandbox'    => '123'
                    ),
                ),
                '{{ bling_invoice_status }}' => array(
                    'triggers' => $trigger_names,
                    'description' => esc_html__( 'Situação/status atual da NFe no Bling', 'joinotify-bling-erp' ),
                    'replacement' => array(
                        'production' => $situacao,
                        'sandbox'    => 'Autorizada'
                    ),
                ),
                '{{ bling_invoice_total }}' => array(
                    'triggers' => $trigger_names,
                    'description' => esc_html__( 'Valor total da nota fiscal', 'joinotify-bling-erp' ),
                    'replacement' => array(
                        'production' => $total,
                        'sandbox'    => '100.00'
                    ),
                ),
                '{{ bling_client_name }}' => array(
                    'triggers' => $trigger_names,
                    'description' => esc_html__( 'Nome do cliente/destinatário da nota fiscal', 'joinotify-bling-erp' ),
                    'replacement' => array(
                        'production' => $client_name,
                        'sandbox'    => esc_html__( 'Nome do Cliente', 'joinotify-bling-erp' )
                    ),
                ),
            );
        }

        return $placeholders;
    }

    
    /**
     * Additional content or actions on the integration settings card.
     * Here we provide a link to the Bling config page.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_modal_settings() {
        // Only show settings button if integration is enabled and plugin configured
        if ( Joinotify_Admin::get_setting('enable_bling_integration') === 'yes' ) {
            $config_url = admin_url('tools.php?page=joinotify-bling');
            echo '<p><a href="'. esc_url($config_url) .'" class="button button-secondary" target="_blank">' . esc_html__( 'Configurar Bling ERP', 'joinotify-bling-erp' ) . '</a></p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Após ativar, configure o Bling em Ferramentas > Bling ERP.', 'joinotify-bling-erp' ) . '</p>';
        }
    }
}