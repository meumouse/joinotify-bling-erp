<?php

namespace MeuMouse\Joinotify\Bling\Admin;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * NFe Automation Settings.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Settings {
    
    /**
     * Initialize settings.
     *
     * @return void
     */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_filter('bling_settings_tabs', array(__CLASS__, 'add_settings_tab'));
        add_action('bling_settings_tab_content', array(__CLASS__, 'render_settings_tab'));
    }
    
    /**
     * Register automation settings.
     *
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
     * @param string $tab Current tab.
     * @return void
     */
    public static function render_settings_tab($tab) {
        if ($tab !== 'automation') {
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
        
        ?>
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
                        <label for="bling_invoice_trigger_statuses">
                            <?php echo esc_html__('Status que disparam NFe', 'joinotify-bling-erp'); ?>
                        </label>
                    </th>
                    <td>
                        <select name="bling_invoice_trigger_statuses[]" id="bling_invoice_trigger_statuses" multiple style="width: 300px; height: 150px;">
                            <?php
                            $statuses = wc_get_order_statuses();
                            foreach ($statuses as $status_key => $status_label) {
                                $clean_key = str_replace('wc-', '', $status_key);
                                $selected = in_array($clean_key, $trigger_statuses) ? 'selected' : '';
                                echo '<option value="' . esc_attr($clean_key) . '" ' . $selected . '>' . esc_html($status_label) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="description">
                            <?php echo esc_html__('Selecione os status de pedido que devem disparar a criação de NFe. Segure CTRL para selecionar múltiplos.', 'joinotify-bling-erp'); ?>
                        </p>
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
        
        <h3><?php echo esc_html__('Ferramentas', 'joinotify-bling-erp'); ?></h3>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <?php echo esc_html__('Sincronização em Massa', 'joinotify-bling-erp'); ?>
                </th>
                <td>
                    <button id="bling-sync-all-products" class="button button-secondary">
                        <?php echo esc_html__('Sincronizar Todos os Produtos', 'joinotify-bling-erp'); ?>
                    </button>
                    <button id="bling-sync-all-customers" class="button button-secondary">
                        <?php echo esc_html__('Sincronizar Todos os Clientes', 'joinotify-bling-erp'); ?>
                    </button>
                    <p class="description">
                        <?php echo esc_html__('Sincronize todos os produtos e clientes existentes com o Bling.', 'joinotify-bling-erp'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Bulk sync products
                $('#bling-sync-all-products').on('click', function(e) {
                    e.preventDefault();
                    
                    if (!confirm('<?php echo esc_js(__('Isso pode levar algum tempo. Deseja continuar?', 'joinotify-bling-erp')); ?>')) {
                        return;
                    }
                    
                    $(this).prop('disabled', true).text('<?php echo esc_js(__('Sincronizando...', 'joinotify-bling-erp')); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'bling_bulk_sync_products',
                            nonce: '<?php echo wp_create_nonce('bling_bulk_sync'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('<?php echo esc_js(__('Sincronização concluída com sucesso!', 'joinotify-bling-erp')); ?>');
                            } else {
                                alert('<?php echo esc_js(__('Erro na sincronização: ', 'joinotify-bling-erp')); ?>' + response.data);
                            }
                        },
                        error: function() {
                            alert('<?php echo esc_js(__('Erro na requisição.', 'joinotify-bling-erp')); ?>');
                        },
                        complete: function() {
                            $('#bling-sync-all-products').prop('disabled', false).text('<?php echo esc_js(__('Sincronizar Todos os Produtos', 'joinotify-bling-erp')); ?>');
                        }
                    });
                });
                
                // Bulk sync customers
                $('#bling-sync-all-customers').on('click', function(e) {
                    e.preventDefault();
                    
                    if (!confirm('<?php echo esc_js(__('Isso pode levar algum tempo. Deseja continuar?', 'joinotify-bling-erp')); ?>')) {
                        return;
                    }
                    
                    $(this).prop('disabled', true).text('<?php echo esc_js(__('Sincronizando...', 'joinotify-bling-erp')); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'bling_bulk_sync_customers',
                            nonce: '<?php echo wp_create_nonce('bling_bulk_sync'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('<?php echo esc_js(__('Sincronização concluída com sucesso!', 'joinotify-bling-erp')); ?>');
                            } else {
                                alert('<?php echo esc_js(__('Erro na sincronização: ', 'joinotify-bling-erp')); ?>' + response.data);
                            }
                        },
                        error: function() {
                            alert('<?php echo esc_js(__('Erro na requisição.', 'joinotify-bling-erp')); ?>');
                        },
                        complete: function() {
                            $('#bling-sync-all-customers').prop('disabled', false).text('<?php echo esc_js(__('Sincronizar Todos os Clientes', 'joinotify-bling-erp')); ?>');
                        }
                    });
                });
            });
        </script>
        <?php
    }
    
    /**
     * Handle bulk sync AJAX requests.
     *
     * @return void
     */
    public static function handle_ajax_requests() {
        add_action('wp_ajax_bling_bulk_sync_products', array(__CLASS__, 'ajax_bulk_sync_products'));
        add_action('wp_ajax_bling_bulk_sync_customers', array(__CLASS__, 'ajax_bulk_sync_customers'));
    }
    
    /**
     * Bulk sync products AJAX handler.
     *
     * @return void
     */
    public static function ajax_bulk_sync_products() {
        check_ajax_referer('bling_bulk_sync', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Get all published products
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        );
        
        $products = get_posts($args);
        $synced = 0;
        $errors = array();
        
        foreach ($products as $post) {
            $product = wc_get_product($post->ID);
            
            if ($product) {
                // Trigger product sync
                do_action('save_post_product', $post->ID, $post, true);
                $synced++;
            }
        }
        
        wp_send_json_success(
            sprintf(
                __('%d produtos sincronizados com sucesso.', 'joinotify-bling-erp'),
                $synced
            )
        );
    }
    
    /**
     * Bulk sync customers AJAX handler.
     *
     * @return void
     */
    public static function ajax_bulk_sync_customers() {
        check_ajax_referer('bling_bulk_sync', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Get all customers
        $users = get_users(array('role' => 'customer'));
        $synced = 0;
        
        foreach ($users as $user) {
            // Trigger customer sync
            do_action('profile_update', $user->ID, $user);
            $synced++;
        }
        
        wp_send_json_success(
            sprintf(
                __('%d clientes sincronizados com sucesso.', 'joinotify-bling-erp'),
                $synced
            )
        );
    }
}