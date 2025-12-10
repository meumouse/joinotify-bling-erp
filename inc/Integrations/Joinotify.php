<?php

namespace MeuMouse\Joinotify\Bling\Integrations;

use MeuMouse\Joinotify\Integrations\Integrations_Base;
use MeuMouse\Joinotify\Admin\Admin as Joinotify_Admin;

// Exit if accessed directly.
defined('ABSPATH') || exit;

if ( class_exists('MeuMouse\Joinotify\Integrations\Integrations_Base') ) {

    /**
     * Integration with Bling ERP for Joinotify triggers and placeholders.
     *
     * @since 1.0.0
     * @package MeuMouse.com
     */
    class Joinotify extends Integrations_Base {

        /**
         * Construct the integration and set up hooks.
         *
         * @since 1.0.0
         * @return void
         */
        public function __construct() {
            error_log('teste');
            // Add integration item in Joinotify settings (Integrations tab).
            add_filter( 'Joinotify/Settings/Tabs/Integrations', array( $this, 'add_integration_item' ), 55, 1 );
            
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
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="521.953" height="200.207"><g data-name="Grupo 1344"><g fill="#5ac782" data-name="Grupo 1331"><path d="M.81 117.94c0 11.26-.4 23.36-.81 29.92h26.43l1.24-13.94h.4c6.97 11.88 18.45 16.22 30.13 16.22 22.95 0 45.7-18.07 45.7-54.14.21-30.75-17.21-50.62-41.2-50.62-13.93 0-24.39 5.54-30.33 14.14h-.4V2.13H.81v115.8zm31.16-27.47a26.22 26.22 0 01.61-5.75c2.05-9.01 9.84-15.36 18.25-15.36 14.54 0 21.51 12.29 21.51 27.86 0 18.04-8.2 28.3-21.51 28.3-9.02 0-16.2-6.56-18.25-14.76a23.07 23.07 0 01-.61-5.54V90.47zm76.52 57.39h31.15V2.13h-31.15v145.73zm72.61 0V47.64h-31.14v100.22zM165.74 4.19c-10.05 0-16.6 6.75-16.6 15.57 0 8.6 6.35 15.57 16.18 15.57 10.46 0 16.81-6.97 16.81-15.57-.2-8.82-6.35-15.57-16.4-15.57zm25.47 143.67h31.16v-57.8a22.38 22.38 0 011.02-7.78c2.25-5.74 7.38-11.69 15.99-11.69 11.27 0 15.78 8.81 15.78 21.72v55.55h31.15V88.62c0-29.51-15.37-43.24-35.87-43.24-16.8 0-26.84 9.63-30.95 16.19h-.6l-1.44-13.93H190.4c.4 9.01.81 19.47.81 31.96v68.26zm200.1-70.3c0-14.96.42-23.37.83-29.92h-27.06l-1.03 12.1h-.4c-5.12-8.4-13.74-14.36-27.46-14.36-24.8 0-45.5 20.5-45.5 52.87 0 28.7 17.63 48.78 42.42 48.78 11.47 0 21.12-4.7 27.06-12.9h.4v6.35c0 18.68-11.27 26.48-26.02 26.48a60.86 60.86 0 01-29.11-7.6l-6.14 23.79c9.01 4.91 22.74 7.58 36.07 7.58 14.75 0 29.71-2.88 40.58-12.3 11.47-10.05 15.36-25.82 15.36-45.13V77.56zm-31.14 25.62a28.62 28.62 0 01-1.03 8.4 17.03 17.03 0 01-16.6 12.5c-12.91 0-20.29-11.69-20.29-26.65 0-18.23 9.02-28.48 20.5-28.48 8.6 0 14.56 5.53 16.81 13.73a27.66 27.66 0 01.61 5.73v14.77z" data-name="Caminho 1143"/><path d="M521.95 15.46q-15.65 15.42-32.82 43.68a402.65 402.65 0 00-29.4 58.52 263.21 263.21 0 00-10.71 32.1q-6.93-.79-17.36-1.5a148.7 148.7 0 00-18.84-.09q-2.44-30.63 5.42-71.33 4.54-23.46 10.4-37.9c2.48-6.14 4.98-10.6 7.48-13.46-11.19-2.64-19.23-4.39-24.2-5.2 0-2.73 1.37-7.38 4.07-14.02a38.9 38.9 0 012.88-5.84c.19-.47.68-.54 1.47-.23.12.04.74.35 1.77.86 1.08.52 1.85.88 2.4 1.1q14.85 6.02 47.64 10.02 31.7 3.8 49.17 2.24a1.07 1.07 0 01.64 1.05zM446.1 192.31c-1.8 4.43-8.36 6.98-19.62 7.71a107.96 107.96 0 01-28.77-2.22 57.42 57.42 0 01-9.92-2.93q-11.1-4.48-15.87-12.92-4.74-8.42-1.69-15.94c2.34-5.78 9.63-8.5 21.86-8a86.6 86.6 0 0128.71 6.1q14.37 5.85 21.33 14.33c4.28 5.14 5.62 9.8 3.97 13.87z" data-name="Caminho 1144"/></g></g></svg>',
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
}