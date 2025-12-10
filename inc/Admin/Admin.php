<?php

namespace MeuMouse\Joinotify\Bling\Admin;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles the WordPress admin settings page for the Bling integration.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Admin {

    /**
     * Initialize admin menu and settings.
     *
     * @since 1.0.0
     * @return void
     */
    public static function init() {
        // Register settings and menu page
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_menu', array(__CLASS__, 'register_menu_page'));
        
        // Add settings tabs filter
        add_filter('bling_settings_tabs', array(__CLASS__, 'default_settings_tabs'));
    }
    
    /**
     * Register WordPress options for Bling integration.
     *
     * @since 1.0.0
     * @return void
     */
    public static function register_settings() {
        register_setting('bling-settings-group', 'bling_client_id');
        register_setting('bling-settings-group', 'bling_client_secret');
        register_setting('bling-settings-group', 'bling_refresh_token');
        register_setting('bling-settings-group', 'bling_access_token');
        register_setting('bling-settings-group', 'bling_token_expires');
        register_setting('bling-settings-group', 'bling_webhook_secret');
    }
    
    /**
     * Add the Bling integration settings page under Tools menu.
     *
     * @since 1.0.0
     * @return void
     */
    public static function register_menu_page() {
        add_management_page(
            __('Bling ERP Configurações', 'joinotify-bling-erp'),
            __('Bling ERP', 'joinotify-bling-erp'),
            'manage_options',
            'joinotify-bling',
            array(__CLASS__, 'render_settings_page')
        );
    }
    
    /**
     * Default settings tabs.
     *
     * @param array $tabs Tabs array.
     * @return array Modified tabs.
     */
    public static function default_settings_tabs($tabs) {
        $default_tabs = array(
            'credentials' => __('Credenciais', 'joinotify-bling-erp'),
            'webhooks' => __('Webhooks', 'joinotify-bling-erp'),
            'automation' => __('Automação', 'joinotify-bling-erp'),
        );
        
        return array_merge($tabs, $default_tabs);
    }
    
    /**
     * Render the HTML content of the settings page.
     *
     * @since 1.0.0
     * @return void
     */
    public static function render_settings_page() {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'joinotify-bling-erp' ) );
        }
        
        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'credentials';
        
        // Get all tabs
        $tabs = apply_filters('bling_settings_tabs', array());
        
        // Fetch stored credentials and tokens
        $client_id = get_option('bling_client_id', '');
        $client_secret = get_option('bling_client_secret', '');
        $access_token = get_option('bling_access_token', '');
        $token_expires = get_option('bling_token_expires', 0); ?>

        <div class="wrap">
            <h1><?php echo esc_html__( 'Configurações do Bling ERP', 'joinotify-bling-erp' ); ?></h1>
            
            <?php 
            // Success message after OAuth callback
            if ( isset($_GET['auth']) && $_GET['auth'] === 'success' ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__( 'Autenticação com o Bling realizada com sucesso!', 'joinotify-bling-erp' ); ?></p>
                </div>
            <?php endif; ?>
            
            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label) : ?>
                    <a href="?page=joinotify-bling&tab=<?php echo esc_attr($tab_key); ?>" 
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>
            
            <div class="bling-settings-content">
                <?php 
                // Render tab content
                do_action('bling_settings_tab_content', $current_tab);
                
                // Default content for credentials tab
                if ($current_tab === 'credentials') : ?>
                    <form method="post" action="options.php">
                        <?php settings_fields('bling-settings-group'); ?>
                        <?php do_settings_sections('bling-settings-group'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Client ID do Bling', 'joinotify-bling-erp' ); ?></th>
                                <td><input type="text" name="bling_client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text" /></td>
                            </tr>

                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Client Secret do Bling', 'joinotify-bling-erp' ); ?></th>
                                <td><input type="password" name="bling_client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text" /></td>
                            </tr>
                        </table>
                        
                        <?php submit_button( __('Salvar Credenciais', 'joinotify-bling-erp') ); ?>
                    </form>
                    
                    <hr/>
                    
                    <h3><?php echo esc_html__( 'Configuração do App no Bling', 'joinotify-bling-erp' ); ?></h3>
                    <p><?php echo esc_html__( 'Para configurar o aplicativo no Bling, você precisa informar as seguintes URLs:', 'joinotify-bling-erp' ); ?></p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'URL de Callback (OAuth):', 'joinotify-bling-erp' ); ?></th>
                            <td>
                                <code style="background: #f6f7f7; padding: 5px 10px; display: inline-block; margin-bottom: 5px;">
                                    <?php echo esc_url( get_rest_url( null, 'bling/v1/auth/callback' ) ); ?>
                                </code>
                                <p class="description"><?php echo esc_html__( 'Cole esta URL no campo "URL de Callback" nas configurações do seu app no Bling.', 'joinotify-bling-erp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'URL de Webhook:', 'joinotify-bling-erp' ); ?></th>
                            <td>
                                <code style="background: #f6f7f7; padding: 5px 10px; display: inline-block; margin-bottom: 5px;">
                                    <?php echo esc_url( get_rest_url( null, 'bling/v1/webhook' ) ); ?>
                                </code>
                                <p class="description"><?php echo esc_html__( 'Cole esta URL no campo "URL do Webhook" nas configurações do seu app no Bling.', 'joinotify-bling-erp' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <hr/>
                    
                    <?php if ( ! empty( $access_token ) ): ?>
                        <h3><?php echo esc_html__( 'Status da Conexão', 'joinotify-bling-erp' ); ?></h3>
                        <p><strong><?php echo esc_html__( '✅ Conectado ao Bling', 'joinotify-bling-erp' ); ?></strong></p>
                        
                        <?php if ( $token_expires ): ?>
                            <p><strong><?php echo esc_html__( 'Token expira em:', 'joinotify-bling-erp' ); ?></strong> <?php echo date_i18n( 'd/m/Y H:i:s', intval($token_expires) ); ?></p>
                        <?php endif; ?>

                        <h3><?php echo esc_html__( 'Gerenciar Token', 'joinotify-bling-erp' ); ?></h3>
                        <button id="bling-refresh-token" class="button button-secondary" 
                                data-endpoint="<?php echo esc_url( get_rest_url( null, 'bling/v1/refresh-token' ) ); ?>" 
                                data-nonce="<?php echo esc_attr( wp_create_nonce('wp_rest') ); ?>">
                            <?php echo esc_html__( 'Atualizar Token Agora', 'joinotify-bling-erp' ); ?>
                        </button>
                        <p id="bling-refresh-output" style="margin-top: 12px; font-weight: 600;"></p>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($current_tab === 'webhooks') : ?>
                    <h3><?php echo esc_html__( 'Gerenciar Webhooks', 'joinotify-bling-erp' ); ?></h3>
                    
                    <div id="bling-webhooks-list">
                        <p><?php echo esc_html__( 'Carregando webhooks...', 'joinotify-bling-erp' ); ?></p>
                    </div>
                    
                    <h3><?php echo esc_html__( 'Criar Novo Webhook', 'joinotify-bling-erp' ); ?></h3>
                    
                    <form id="bling-create-webhook">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="webhook_event"><?php echo esc_html__( 'Evento', 'joinotify-bling-erp' ); ?></label>
                                </th>
                                <td>
                                    <select name="event" id="webhook_event" class="regular-text">
                                        <option value="invoice.created"><?php echo esc_html__( 'Nota Fiscal Criada', 'joinotify-bling-erp' ); ?></option>
                                        <option value="invoice.updated"><?php echo esc_html__( 'Nota Fiscal Atualizada', 'joinotify-bling-erp' ); ?></option>
                                        <option value="invoice.deleted"><?php echo esc_html__( 'Nota Fiscal Excluída', 'joinotify-bling-erp' ); ?></option>
                                        <option value="order.created"><?php echo esc_html__( 'Pedido Criado', 'joinotify-bling-erp' ); ?></option>
                                        <option value="order.updated"><?php echo esc_html__( 'Pedido Atualizado', 'joinotify-bling-erp' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="webhook_url"><?php echo esc_html__( 'URL', 'joinotify-bling-erp' ); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="url" id="webhook_url" 
                                           value="<?php echo esc_url( get_rest_url( null, 'bling/v1/webhook' ) ); ?>" 
                                           class="regular-text" readonly />
                                </td>
                            </tr>
                        </table>
                        
                        <button type="submit" class="button button-primary">
                            <?php echo esc_html__( 'Criar Webhook', 'joinotify-bling-erp' ); ?>
                        </button>
                    </form>
                    
                    <script type="text/javascript">
                        (function($) {
                            // Load webhooks
                            function loadWebhooks() {
                                $.ajax({
                                    url: '<?php echo esc_url( get_rest_url( null, 'bling/v1/webhooks' ) ); ?>',
                                    method: 'GET',
                                    headers: {
                                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            $('#bling-webhooks-list').html(response.data.html);
                                        }
                                    }
                                });
                            }
                            
                            // Create webhook
                            $('#bling-create-webhook').on('submit', function(e) {
                                e.preventDefault();
                                
                                var formData = $(this).serialize();
                                
                                $.ajax({
                                    url: '<?php echo esc_url( get_rest_url( null, 'bling/v1/webhooks' ) ); ?>',
                                    method: 'POST',
                                    headers: {
                                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                                    },
                                    data: formData,
                                    success: function(response) {
                                        if (response.success) {
                                            alert('<?php echo esc_js(__('Webhook criado com sucesso!', 'joinotify-bling-erp')); ?>');
                                            loadWebhooks();
                                        } else {
                                            alert('<?php echo esc_js(__('Erro ao criar webhook: ', 'joinotify-bling-erp')); ?>' + response.data);
                                        }
                                    }
                                });
                            });
                            
                            // Initial load
                            loadWebhooks();
                        })(jQuery);
                    </script>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( ! empty( $access_token ) ): ?>
            <script type="text/javascript">
                (function() {
                    const btn = document.getElementById('bling-refresh-token');
                    if (!btn) return;
                    const output = document.getElementById('bling-refresh-output');
                    
                    btn.addEventListener('click', async function () {
                        btn.disabled = true;
                        btn.textContent = '<?php echo esc_js( 'Atualizando...', 'joinotify-bling-erp' ); ?>';
                        output.textContent = '';
                        output.style.color = '#444';
                        try {
                            const response = await fetch(btn.dataset.endpoint, {
                                method: 'POST',
                                headers: {
                                    'X-WP-Nonce': btn.dataset.nonce,
                                    'Content-Type': 'application/json'
                                }
                            });
                            const data = await response.json();
                            if (data.success) {
                                output.style.color = 'green';
                                output.textContent = '<?php echo esc_js( 'Token atualizado com sucesso! Novo vencimento: ', 'joinotify-bling-erp' ); ?>' + (data.expires || '');
                            } else {
                                output.style.color = 'red';
                                output.textContent = '<?php echo esc_js( 'Erro ao atualizar: ', 'joinotify-bling-erp' ); ?>' + (data.error || '<?php echo esc_js( 'Erro desconhecido', 'joinotify-bling-erp' ); ?>');
                            }
                        } catch (err) {
                            output.style.color = 'red';
                            output.textContent = '<?php echo esc_js( 'Erro inesperado: ', 'joinotify-bling-erp' ); ?>' + err.message;
                        }
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js( 'Atualizar Token Agora', 'joinotify-bling-erp' ); ?>';
                    });
                })();
            </script>
        <?php endif;
    }
}