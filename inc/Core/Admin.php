<?php

namespace MeuMouse\Joinotify\Bling\Core;

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
        // Optionally register webhook secret (if separate), not exposing in UI
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
     * Render the HTML content of the settings page.
     *
     * @since 1.0.0
     * @return void
     */
    public static function render_settings_page() {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'joinotify-bling-erp' ) );
        }
        
        // Fetch stored credentials and tokens
        $client_id     = get_option('bling_client_id', '');
        $client_secret = get_option('bling_client_secret', '');
        $access_token  = get_option('bling_access_token', '');
        $token_expires = get_option('bling_token_expires', 0);
        
        // Prepare OAuth URL if needed
        $auth_url = '';
        if ( $client_id && empty($access_token) ) {
            $redirect_uri = get_rest_url( null, 'bling/v1/auth/callback' );
            $auth_url = 'https://www.bling.com.br/Api/v3/oauth/authorize?response_type=code&client_id=' . urlencode($client_id) . '&redirect_uri=' . urlencode($redirect_uri);
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Configurações do Bling ERP', 'joinotify-bling-erp' ); ?></h1>
            
            <?php 
            // Success message after OAuth callback
            if ( isset($_GET['auth']) && $_GET['auth'] === 'success' ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__( 'Autenticação com o Bling realizada com sucesso!', 'joinotify-bling-erp' ); ?></p>
                </div>
            <?php endif; ?>
            
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
            
            <?php if ( $client_id && empty($access_token) ): ?>
                <h2><?php echo esc_html__( 'Autenticação', 'joinotify-bling-erp' ); ?></h2>
                <p><?php echo esc_html__( 'Clique abaixo para conectar sua conta Bling:', 'joinotify-bling-erp' ); ?></p>
                <p><a href="<?php echo esc_url($auth_url); ?>" class="button button-primary"><?php echo esc_html__( 'Conectar ao Bling', 'joinotify-bling-erp' ); ?></a></p>
            <?php elseif ( ! empty($access_token) ): ?>
                <h2><?php echo esc_html__( 'Status da Conexão', 'joinotify-bling-erp' ); ?></h2>
                <p><strong><?php echo esc_html__( '✅ Conectado ao Bling', 'joinotify-bling-erp' ); ?></strong></p>
                <?php if ( $token_expires ): ?>
                    <p><strong><?php echo esc_html__( 'Token expira em:', 'joinotify-bling-erp' ); ?></strong> <?php echo date_i18n( 'd/m/Y H:i:s', intval($token_expires) ); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ( ! empty($access_token) ): ?>
                <h2><?php echo esc_html__( 'Gerenciar Token', 'joinotify-bling-erp' ); ?></h2>
                <button id="bling-refresh-token" class="button button-secondary" 
                        data-endpoint="<?php echo esc_url( get_rest_url( null, 'bling/v1/refresh-token' ) ); ?>" 
                        data-nonce="<?php echo esc_attr( wp_create_nonce('wp_rest') ); ?>">
                    <?php echo esc_html__( 'Atualizar Token Agora', 'joinotify-bling-erp' ); ?>
                </button>
                <p id="bling-refresh-output" style="margin-top: 12px; font-weight: 600;"></p>
            <?php endif; ?>
        </div>
        <?php if ( ! empty($access_token) ): ?>
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