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
 * @version 1.0.2
 * @package MeuMouse\Joinotify\Bling\Integrations
 * @author MeuMouse.com
 */
class Joinotify extends Integrations_Base {

    /**
     * Construct the integration and set up hooks.
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        // Add integration item in Joinotify settings (Integrations tab).
        add_filter( 'Joinotify/Settings/Tabs/Integrations', array( $this, 'add_integration_item' ), 60, 1 );
        
        // Register triggers for Bling events.
        add_filter( 'Joinotify/Builder/Get_All_Triggers', array( $this, 'add_triggers' ), 10, 1 );
        
        // Add triggers tab in Joinotify builder UI.
        add_action( 'Joinotify/Builder/Triggers', array( $this, 'add_triggers_tab' ), 60 );
        
        // Add triggers content in Joinotify builder UI.
        add_action( 'Joinotify/Builder/Triggers_Content', array( $this, 'add_triggers_content' ) );
        
        // Register placeholders for Bling data.
        add_filter( 'Joinotify/Builder/Placeholders_List', array( $this, 'add_placeholders' ), 20, 2 );
        
        // Add integration settings link or info in Joinotify settings page.
        add_action( 'Joinotify/Settings/Tabs/Integrations/Bling', array( $this, 'add_modal_settings' ) );
    }

    
    /**
     * Provide integration information for Joinotify settings.
     *
     * @since 1.0.0
     * @param array $integrations | Current integrations array.
     * @return array Modified integrations array including Bling.
     */
    public function add_integration_item( $integrations ) {
        $integrations['bling'] = array(
            'title'         => esc_html__( 'Bling ERP', 'joinotify-bling-erp' ),
            'description'   => esc_html__( 'Receba eventos de nota fiscal (NFe) e envie notificações automatizadas.', 'joinotify-bling-erp' ),
            'icon'          => '<svg id="bling_erp_logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><g><circle id="bg" cx="16" cy="16" r="15.5" fill="#35AE62"></circle><path id="logo-bling" d="M23 9.3129C22.1128 10.1857 21.1827 11.4221 20.2095 13.0221C19.2424 14.608 18.4062 16.27 17.7093 17.9916C17.3473 18.8797 17.043 19.7901 16.7981 20.7173C16.4054 20.6724 15.9134 20.6298 15.3223 20.5893C14.7889 20.5529 14.2536 20.5504 13.7198 20.582C13.5819 18.848 13.7356 16.829 14.1809 14.5248C14.3738 13.4267 14.6697 12.3492 15.0649 11.3065C15.2146 10.894 15.4293 10.5081 15.7011 10.1634C14.7498 9.94023 14.0656 9.7911 13.6438 9.72233C13.693 9.30936 13.8097 8.90725 13.9892 8.53199C14.0564 8.36018 14.1379 8.19432 14.233 8.03614C14.249 7.99557 14.2907 7.98968 14.3581 8.01585C14.3685 8.01991 14.4215 8.04547 14.5089 8.08869C14.6005 8.13332 14.6661 8.16376 14.7135 8.18303C15.5548 8.52334 16.9051 8.80663 18.7642 9.03292C20.5607 9.24825 21.9544 9.31175 22.9454 9.22343C22.9623 9.23107 22.9765 9.24368 22.9861 9.25959C22.9957 9.2755 23.0002 9.29396 22.999 9.31249L23 9.3129ZM16.5503 24.3299C16.3971 24.7065 15.8392 24.923 14.8821 24.9852C14.0619 25.0317 13.2391 24.9682 12.4358 24.7963C12.1475 24.7391 11.8651 24.656 11.5918 24.548C11.0286 24.349 10.5518 23.9613 10.2426 23.4508C10.1136 23.2524 10.0333 23.0263 10.0084 22.7911C9.98341 22.5558 10.0144 22.318 10.0989 22.097C10.2976 21.607 10.9171 21.3761 11.9573 21.4179C12.7948 21.4509 13.6206 21.6264 14.399 21.9369C15.0947 22.1865 15.7177 22.6043 16.2124 23.1528C16.5765 23.5894 16.6907 23.9846 16.5501 24.3295L16.5503 24.3299Z" fill="white"></path></g></svg>',
            'setting_key'   => 'enable_bling_integration',
            'action_hook'   => 'Joinotify/Settings/Tabs/Integrations/Bling',
            'is_plugin'     => true,
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
        $icon_svg = '<svg class="joinotify-tab-icon bling-erp" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><style>.nav-tab.nav-tab-active .joinotify-tab-icon.bling-erp path {fill: #495057 !important;}</style><g><circle id="bg" cx="16" cy="16" r="15.5"/><path id="logo-bling" d="M23 9.3129C22.1128 10.1857 21.1827 11.4221 20.2095 13.0221C19.2424 14.608 18.4062 16.27 17.7093 17.9916C17.3473 18.8797 17.043 19.7901 16.7981 20.7173C16.4054 20.6724 15.9134 20.6298 15.3223 20.5893C14.7889 20.5529 14.2536 20.5504 13.7198 20.582C13.5819 18.848 13.7356 16.829 14.1809 14.5248C14.3738 13.4267 14.6697 12.3492 15.0649 11.3065C15.2146 10.894 15.4293 10.5081 15.7011 10.1634C14.7498 9.94023 14.0656 9.7911 13.6438 9.72233C13.693 9.30936 13.8097 8.90725 13.9892 8.53199C14.0564 8.36018 14.1379 8.19432 14.233 8.03614C14.249 7.99557 14.2907 7.98968 14.3581 8.01585C14.3685 8.01991 14.4215 8.04547 14.5089 8.08869C14.6005 8.13332 14.6661 8.16376 14.7135 8.18303C15.5548 8.52334 16.9051 8.80663 18.7642 9.03292C20.5607 9.24825 21.9544 9.31175 22.9454 9.22343C22.9623 9.23107 22.9765 9.24368 22.9861 9.25959C22.9957 9.2755 23.0002 9.29396 22.999 9.31249L23 9.3129ZM16.5503 24.3299C16.3971 24.7065 15.8392 24.923 14.8821 24.9852C14.0619 25.0317 13.2391 24.9682 12.4358 24.7963C12.1475 24.7391 11.8651 24.656 11.5918 24.548C11.0286 24.349 10.5518 23.9613 10.2426 23.4508C10.1136 23.2524 10.0333 23.0263 10.0084 22.7911C9.98341 22.5558 10.0144 22.318 10.0989 22.097C10.2976 21.607 10.9171 21.3761 11.9573 21.4179C12.7948 21.4509 13.6206 21.6264 14.399 21.9369C15.0947 22.1865 15.7177 22.6043 16.2124 23.1528C16.5765 23.5894 16.6907 23.9846 16.5501 24.3295L16.5503 24.3299Z" fill="white"/></g></svg>';
        
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
     * @version 1.0.2
     * @param array $placeholders | Existing placeholders.
     * @param array $payload | Payload data from the trigger event.
     * @return array Modified placeholders including Bling placeholders.
     */
    public function add_placeholders( $placeholders, $payload ) {
        $invoice = isset( $payload['invoice_data'] ) ? $payload['invoice_data'] : array();
        $trigger_names = array( 
            'bling_invoice_created',
            'bling_invoice_authorized',
            'bling_invoice_cancelled',
            'bling_invoice_rejected',
            'bling_invoice_denied',
            'bling_invoice_deleted'
        );

        // Extract data from the new payload structure
        $numero = isset( $invoice['numero'] ) ? $invoice['numero'] : '';
        $situacao = $this->get_invoice_status_text( $invoice['situacao'] ?? '' );
        $valorNota = isset( $invoice['valorNota'] ) ? $invoice['valorNota'] : '0';
        $total = is_numeric( $valorNota ) ? number_format( $valorNota, 2, ',', '.' ) : $valorNota;
        $client_name = isset( $invoice['contato']['nome'] ) ? $invoice['contato']['nome'] : '';
        $client_document = isset( $invoice['contato']['numeroDocumento'] ) ? $invoice['contato']['numeroDocumento'] : '';
        $client_email = isset( $invoice['contato']['email'] ) ? $invoice['contato']['email'] : '';
        $dataEmissao = isset( $invoice['dataEmissao'] ) ? $invoice['dataEmissao'] : '';
        $chaveAcesso = isset( $invoice['chaveAcesso'] ) ? $invoice['chaveAcesso'] : '';
        $linkDanfe = isset( $invoice['linkDanfe'] ) ? $invoice['linkDanfe'] : '';
        $linkPDF = isset( $invoice['linkPDF'] ) ? $invoice['linkPDF'] : '';
        $linkXML = isset( $invoice['xml'] ) ? $invoice['xml'] : '';
        $client_phone = '';

        $invoice_id = $invoice['id'] ?? '';

        if ( ! empty( $invoice_id ) ) {
            $order = $this->get_order_by_bling_invoice_id( $invoice_id );

            if ( $order instanceof \WC_Order ) {
                $client_phone = $order->get_billing_phone();
            }
        }

        // Fallback para telefone do Bling
        if ( empty( $client_phone ) ) {
            $client_phone = $invoice['contato']['telefone'] ?? '';
        }

        // Get product information (all items)
        $item_desc = '';
        $item_qtd = '';
        $item_valor = '';
        $all_items = '';
        
        if ( isset( $invoice['itens'] ) && is_array( $invoice['itens'] ) ) {
            // First item (for backward compatibility)
            $first_item = $invoice['itens'][0] ?? array();
            $item_desc = isset( $first_item['descricao'] ) ? $first_item['descricao'] : '';
            $item_qtd = isset( $first_item['quantidade'] ) ? $first_item['quantidade'] : '';
            $item_valor = isset( $first_item['valor'] ) ? number_format( $first_item['valor'], 2, ',', '.' ) : '';
            
            // All items formatted
            $items_formatted = array();
            foreach ( $invoice['itens'] as $index => $item ) {
                $product_number = $index + 1;
                $description = isset( $item['descricao'] ) ? $item['descricao'] : '';
                $quantity = isset( $item['quantidade'] ) ? $item['quantidade'] : '';
                $value = isset( $item['valor'] ) ? number_format( $item['valor'], 2, ',', '.' ) : '';
                
                $items_formatted[] = sprintf(
                    'Produto %d - %s - Valor R$%s - Quantidade %s',
                    $product_number,
                    $description,
                    $value,
                    $quantity
                );
            }
            
            $all_items = implode( "\n", $items_formatted );
        }
        
        // Get address information
        $client_address = '';
        $client_city = '';
        $client_state = '';
        $client_zip = '';
        
        if ( isset( $invoice['contato']['endereco'] ) ) {
            $endereco = $invoice['contato']['endereco'];
            $client_address = trim( ( $endereco['endereco'] ?? '' ) . ', ' . ( $endereco['numero'] ?? '' ) );
            $client_city = $endereco['municipio'] ?? '';
            $client_state = $endereco['uf'] ?? '';
            $client_zip = $endereco['cep'] ?? '';
        }

        $placeholders['bling'] = array(
            '{{ bling_invoice_number }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Número da nota fiscal no Bling', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $numero,
                    'sandbox'    => '123456'
                ),
            ),
            '{{ bling_invoice_status }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Situação/status atual da NFe no Bling', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $situacao,
                    'sandbox' => esc_html__( 'Autorizada', 'joinotify-bling-erp' )
                ),
            ),
            '{{ bling_invoice_total }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Valor total da nota fiscal formatado', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => 'R$ ' . $total,
                    'sandbox' => 'R$ 100,00'
                ),
            ),
            '{{ bling_invoice_raw_total }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Valor total da nota fiscal (número puro)', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $valorNota,
                    'sandbox' => '100'
                ),
            ),
            '{{ bling_client_name }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Nome do cliente/destinatário da nota fiscal', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $client_name,
                    'sandbox' => esc_html__( 'João da Silva', 'joinotify-bling-erp' )
                ),
            ),
            '{{ bling_client_document }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'CPF/CNPJ do cliente', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $client_document,
                    'sandbox' => '123.456.789-00'
                ),
            ),
            '{{ bling_client_email }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'E-mail do cliente', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $client_email,
                    'sandbox' => 'cliente@exemplo.com'
                ),
            ),
            '{{ bling_client_phone }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Telefone do cliente', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $client_phone,
                    'sandbox' => '(11) 99999-9999'
                ),
            ),
            '{{ bling_invoice_issue_date }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Data de emissão da nota fiscal', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $dataEmissao,
                    'sandbox' => '2025-12-11 12:35:00'
                ),
            ),
            '{{ bling_invoice_access_key }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Chave de acesso da NFe', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $chaveAcesso,
                    'sandbox' => '4125...4290'
                ),
            ),
            '{{ bling_invoice_danfe_link }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Link para visualizar DANFE', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $linkDanfe,
                    'sandbox' => 'https://www.bling.com.br/doc.view.php?id=ba7...'
                ),
            ),
            '{{ bling_invoice_pdf_link }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Link para baixar PDF da nota', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $linkPDF,
                    'sandbox' => 'https://www.bling.com.br/doc.view.php?PDF=true&id=ba7f46...'
                ),
            ),
            '{{ bling_invoice_xml_link }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Link para baixar XML da nota', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $linkXML,
                    'sandbox' => 'https://www.bling.com.br/relatorios/nfe.xml.php?chaveAcesso=4125...'
                ),
            ),
            '{{ bling_product_description }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Descrição do primeiro produto da nota', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $item_desc,
                    'sandbox' => esc_html__( 'Produto Exemplo', 'joinotify-bling-erp' )
                ),
            ),
            '{{ bling_product_quantity }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Quantidade do primeiro produto', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $item_qtd,
                    'sandbox' => '1'
                ),
            ),
            '{{ bling_product_unit_value }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Valor unitário do primeiro produto', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => 'R$ ' . $item_valor,
                    'sandbox' => 'R$ 100,00'
                ),
            ),
            '{{ bling_all_products }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Todos os produtos da nota fiscal formatados (um por linha)', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $all_items,
                    'sandbox' => "Produto 1 - Produto Exemplo 1 - Valor R$100,00 - Quantidade 2\nProduto 2 - Produto Exemplo 2 - Valor R$50,00 - Quantidade 1"
                ),
            ),
            '{{ bling_client_address }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Endereço completo do cliente', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $client_address,
                    'sandbox' => esc_html__( 'Rua Exemplo, 123', 'joinotify-bling-erp' )
                ),
            ),
            '{{ bling_client_city }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Cidade do cliente', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $client_city,
                    'sandbox' => esc_html__( 'São Paulo', 'joinotify-bling-erp' )
                ),
            ),
            '{{ bling_client_state }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'Estado do cliente', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $client_state,
                    'sandbox' => 'SP'
                ),
            ),
            '{{ bling_client_zip }}' => array(
                'triggers' => $trigger_names,
                'description' => esc_html__( 'CEP do cliente', 'joinotify-bling-erp' ),
                'replacement' => array(
                    'production' => $client_zip,
                    'sandbox' => '01234-567'
                ),
            ),
        );

        return $placeholders;
    }


    /**
     * Convert invoice status code to human-readable text.
     *
     * @since 1.0.0
     * @param string $status_code The status code from Bling.
     * @return string Human-readable status text.
     */
    private function get_invoice_status_text( $status_code ) {
        $status_map = array(
            '1' => esc_html__( 'Em digitação', 'joinotify-bling-erp' ),
            '2' => esc_html__( 'Autorizada', 'joinotify-bling-erp' ),
            '3' => esc_html__( 'Cancelada', 'joinotify-bling-erp' ),
            '4' => esc_html__( 'Encerrada', 'joinotify-bling-erp' ),
            '5' => esc_html__( 'Rejeitada', 'joinotify-bling-erp' ),
            '6' => esc_html__( 'Denegada', 'joinotify-bling-erp' ),
            '7' => esc_html__( 'Inutilizada', 'joinotify-bling-erp' ),
            '8' => esc_html__( 'Contingência', 'joinotify-bling-erp' ),
            '9' => esc_html__( 'Em processamento', 'joinotify-bling-erp' ),
        );

        return isset( $status_map[$status_code] ) ? $status_map[$status_code] : esc_html__( 'Desconhecido', 'joinotify-bling-erp' );
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
            $config_url = admin_url('admin.php?page=joinotify-bling');
            echo '<p><a href="'. esc_url( $config_url ) .'" class="btn btn-outline-primary mb-5" target="_blank">' . esc_html__( 'Configurar', 'joinotify-bling-erp' ) . '</a></p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Após ativar, configure o Bling em Joinotify > Bling ERP.', 'joinotify-bling-erp' ) . '</p>';
        }
    }


    /**
     * Get WooCommerce order by Bling invoice ID.
     *
     * @since 1.0.2
     * @param string|int $invoice_id
     * @return WC_Order|null
     */
    private function get_order_by_bling_invoice_id( $invoice_id ) {
        if ( empty( $invoice_id ) ) {
            return null;
        }

        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_bling_invoice_id',
            'meta_value' => $invoice_id,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ) );

        return ! empty( $orders ) ? $orders[0] : null;
    }
}