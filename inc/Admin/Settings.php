<?php

namespace MeuMouse\Joinotify\Bling\Admin;

use MeuMouse\Joinotify\Bling\API\Client;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * NFe Automation Settings.
 *
 * @since 1.0.0
 * @package MeuMouse\Joinotify\Bling\Admin
 * @author MeuMouse.com
 */
class Settings {
    
    /**
     * Constructor
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_filter('bling_settings_tabs', array(__CLASS__, 'add_settings_tab'));
        add_action('bling_settings_tab_content', array(__CLASS__, 'render_settings_tab'));
    }

    
    /**
     * Register automation settings.
     *
     * @since 1.0.0
     * @version 1.0.1
     * @return void
     */
    public static function register_settings() {
        register_setting('bling-automation-group', 'bling_invoice_trigger_statuses');
        register_setting('bling-automation-group', 'bling_default_nature_operation');
        register_setting('bling-automation-group', 'bling_sync_products');
        register_setting('bling-automation-group', 'bling_sync_customers');
        register_setting('bling-automation-group', 'bling_auto_create_invoice');
        register_setting('bling-automation-group', 'bling_send_invoice_email');
        register_setting('bling-automation-group', 'bling_invoice_series');
        register_setting('bling-automation-group', 'bling_invoice_purpose');
        register_setting('bling-automation-group', 'bling_sales_channel_id');
        register_setting('bling-automation-group', 'bling_sales_channel_description');
    }
        

    /**
     * Add automation settings tab.
     *
     * @param array $tabs Existing tabs.
     * @return array Modified tabs.
     */
    public static function add_settings_tab($tabs) {
        $tabs['automation'] = __('Automação NFe', 'joinotify-bling-erp');

        return $tabs;
    }
    
    
    /**
     * Render automation settings tab.
     *
     * @since 1.0.0
     * @version 1.0.1
     * @param string $tab | Current tab.
     * @return void
     */
    public static function render_settings_tab( $tab ) {
        if ( $tab !== 'automation' ) {
            return;
        }
        
        $trigger_statuses = get_option('bling_invoice_trigger_statuses', array('completed'));
        $default_nature = get_option('bling_default_nature_operation', 1);
        $sync_products = get_option('bling_sync_products', 'no');
        $sync_customers = get_option('bling_sync_customers', 'no');
        $auto_create = get_option('bling_auto_create_invoice', 'yes');
        $send_email = get_option('bling_send_invoice_email', 'yes');
        $invoice_series = get_option('bling_invoice_series', '1');
        $invoice_purpose = get_option('bling_invoice_purpose', '1');
        $sales_channel_id = get_option('bling_sales_channel_id', '');

        // Try to get sales channels
        $sales_channels = Client::get_sales_channels_from_bling();
        $has_sales_channels = !is_wp_error($sales_channels) && !empty($sales_channels); ?>

        <form method="post" action="options.php">
            <?php settings_fields('bling-automation-group'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bling_auto_create_invoice">
                            <?php echo esc_html__('Criar NFe automaticamente', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <select name="bling_auto_create_invoice" id="bling_auto_create_invoice">
                            <option value="yes" <?php selected($auto_create, 'yes'); ?>>
                                <?php echo esc_html__('Sim', 'joinotify-bling-erp'); ?>
                            </option>
                            <option value="no" <?php selected($auto_create, 'no'); ?>>
                                <?php echo esc_html__('Não', 'joinotify-bling-erp'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php echo esc_html__('Criar nota fiscal automaticamente quando o pedido atingir determinado status.', 'joinotify-bling-erp'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label>
                            <?php echo esc_html__('Status que disparam NFe', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <div class="bling-checkbox-container">
                            <?php
                            $statuses = wc_get_order_statuses();
                            
                            if (empty($statuses)) {
                                echo '<p>' . esc_html__('Nenhum status de pedido encontrado. Certifique-se de que o WooCommerce está ativo.', 'joinotify-bling-erp') . '</p>';
                            } else {
                                foreach ($statuses as $status_key => $status_label) {
                                    $clean_key = str_replace('wc-', '', $status_key);
                                    $checked = in_array($clean_key, (array)$trigger_statuses) ? 'checked' : '';
                                    $field_id = 'bling_status_' . sanitize_title($clean_key);
                                    
                                    echo '<p>';
                                    echo '<input type="checkbox" id="' . esc_attr($field_id) . '" 
                                           name="bling_invoice_trigger_statuses[]" 
                                           value="' . esc_attr($clean_key) . '" 
                                           ' . $checked . ' />';
                                    echo '<label for="' . esc_attr($field_id) . '">';
                                    echo esc_html($status_label);
                                    echo '</label>';
                                    echo '</p>';
                                }
                            }
                            ?>
                        </div>
                        <p class="description">
                            <?php echo esc_html__('Selecione os status de pedido que devem disparar a criação de NFe.', 'joinotify-bling-erp'); ?>
                        </p>
                        <div class="bling-tool-buttons">
                            <button type="button" id="bling-select-all-statuses" class="button button-small">
                                <?php echo esc_html__('Selecionar todos', 'joinotify-bling-erp'); ?>
                            </button>
                            <button type="button" id="bling-deselect-all-statuses" class="button button-small">
                                <?php echo esc_html__('Desmarcar todos', 'joinotify-bling-erp'); ?>
                            </button>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="bling_sales_channel_id">
                            <?php echo esc_html__('Canal de Venda', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <div id="bling-sales-channel-container">
                            <?php if (is_wp_error($sales_channels)) : ?>
                                <div class="notice notice-error inline">
                                    <p><?php echo esc_html__('Erro ao carregar canais de venda:', 'joinotify-bling-erp'); ?> 
                                       <?php echo esc_html($sales_channels->get_error_message()); ?></p>
                                </div>
                                <button type="button" id="bling-retry-load-channels" class="button button-small">
                                    <?php echo esc_html__('Tentar novamente', 'joinotify-bling-erp'); ?>
                                </button>
                            <?php elseif (!$has_sales_channels) : ?>
                                <div class="notice notice-warning inline">
                                    <p><?php echo esc_html__('Nenhum canal de venda encontrado ou não foi possível carregar.', 'joinotify-bling-erp'); ?></p>
                                </div>
                                <button type="button" id="bling-retry-load-channels" class="button button-small">
                                    <?php echo esc_html__('Tentar novamente', 'joinotify-bling-erp'); ?>
                                </button>
                            <?php else : ?>
                                <select name="bling_sales_channel_id" id="bling_sales_channel_id" class="regular-text">
                                    <option value=""><?php echo esc_html__('-- Selecione um canal --', 'joinotify-bling-erp'); ?></option>
                                    <?php foreach ($sales_channels as $channel) : ?>
                                        <option value="<?php echo esc_attr($channel['id']); ?>" 
                                                <?php selected($sales_channel_id, $channel['id']); ?>
                                                data-type="<?php echo esc_attr($channel['tipo']); ?>">
                                            <?php echo esc_html($channel['descricao']); ?>
                                            <?php if ($channel['tipo']) : ?>
                                                (<?php echo esc_html($channel['tipo']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <p class="description">
                            <?php echo esc_html__('Selecione o canal de venda que será usado nas notas fiscais. Este campo é opcional.', 'joinotify-bling-erp'); ?>
                        </p>
                        <button type="button" id="bling-refresh-channels" class="button button-small" style="margin-top: 5px;">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px;"></span>
                            <?php echo esc_html__('Atualizar lista', 'joinotify-bling-erp'); ?>
                        </button>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="bling_default_nature_operation">
                            <?php echo esc_html__('Natureza da Operação Padrão', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" name="bling_default_nature_operation" id="bling_default_nature_operation" 
                               value="<?php echo esc_attr($default_nature); ?>" class="regular-text" />
                        <p class="description">
                            <?php echo esc_html__('ID da natureza da operação padrão no Bling.', 'joinotify-bling-erp'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="bling_invoice_series">
                            <?php echo esc_html__('Série da Nota Fiscal', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text" name="bling_invoice_series" id="bling_invoice_series" 
                               value="<?php echo esc_attr($invoice_series); ?>" class="regular-text" />
                        <p class="description">
                            <?php echo esc_html__('Série utilizada para as notas fiscais (ex: 1, 2, 3).', 'joinotify-bling-erp'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="bling_invoice_purpose">
                            <?php echo esc_html__('Finalidade da NF-e', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <select name="bling_invoice_purpose" id="bling_invoice_purpose">
                            <option value="1" <?php selected($invoice_purpose, '1'); ?>>
                                <?php echo esc_html__('1 - NF-e normal', 'joinotify-bling-erp'); ?>
                            </option>
                            <option value="2" <?php selected($invoice_purpose, '2'); ?>>
                                <?php echo esc_html__('2 - NF-e complementar', 'joinotify-bling-erp'); ?>
                            </option>
                            <option value="3" <?php selected($invoice_purpose, '3'); ?>>
                                <?php echo esc_html__('3 - NF-e de ajuste', 'joinotify-bling-erp'); ?>
                            </option>
                            <option value="4" <?php selected($invoice_purpose, '4'); ?>>
                                <?php echo esc_html__('4 - Devolução de mercadoria', 'joinotify-bling-erp'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="bling_send_invoice_email">
                            <?php echo esc_html__('Enviar e-mail da NFe', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <select name="bling_send_invoice_email" id="bling_send_invoice_email">
                            <option value="yes" <?php selected($send_email, 'yes'); ?>>
                                <?php echo esc_html__('Sim', 'joinotify-bling-erp'); ?>
                            </option>
                            <option value="no" <?php selected($send_email, 'no'); ?>>
                                <?php echo esc_html__('Não', 'joinotify-bling-erp'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php echo esc_html__('Enviar e-mail com a NFe para o cliente após emissão.', 'joinotify-bling-erp'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="bling_sync_products">
                            <?php echo esc_html__('Sincronizar Produtos', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <select name="bling_sync_products" id="bling_sync_products">
                            <option value="yes" <?php selected($sync_products, 'yes'); ?>>
                                <?php echo esc_html__('Sim', 'joinotify-bling-erp'); ?>
                            </option>
                            <option value="no" <?php selected($sync_products, 'no'); ?>>
                                <?php echo esc_html__('Não', 'joinotify-bling-erp'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php echo esc_html__('Sincronizar produtos do WooCommerce com o Bling automaticamente.', 'joinotify-bling-erp'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="bling_sync_customers">
                            <?php echo esc_html__('Sincronizar Clientes', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <select name="bling_sync_customers" id="bling_sync_customers">
                            <option value="yes" <?php selected($sync_customers, 'yes'); ?>>
                                <?php echo esc_html__('Sim', 'joinotify-bling-erp'); ?>
                            </option>
                            <option value="no" <?php selected($sync_customers, 'no'); ?>>
                                <?php echo esc_html__('Não', 'joinotify-bling-erp'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php echo esc_html__('Sincronizar clientes do WooCommerce com o Bling automaticamente.', 'joinotify-bling-erp'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('Salvar Configurações de Automação', 'joinotify-bling-erp')); ?>
        </form>
        
        <hr/>
        
        <h3 style="display: none !important;"><?php echo esc_html__('Ferramentas', 'joinotify-bling-erp'); ?></h3>
        
        <table class="form-table" style="display: none !important;">
            <tr>
                <th scope="row">
                    <?php echo esc_html__('Utilitários', 'joinotify-bling-erp'); ?>
                </th>
                <td>
                    <button id="bling-test-connection" class="button button-secondary">
                        <?php echo esc_html__('Testar Conexão com Bling', 'joinotify-bling-erp'); ?>
                    </button>
                    <button id="bling-clear-cache" class="button button-secondary">
                        <?php echo esc_html__('Limpar Cache de Sincronização', 'joinotify-bling-erp'); ?>
                    </button>
                    <p class="description">
                        <?php echo esc_html__('Teste a conexão com a API do Bling e limpe o cache de sincronização.', 'joinotify-bling-erp'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }
}