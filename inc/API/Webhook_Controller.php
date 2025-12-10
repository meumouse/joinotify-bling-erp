<?php

namespace MeuMouse\Joinotify\Bling\API;

use WP_REST_Request;
use WP_REST_Response;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Extended Controller for webhook management.
 *
 * @since 1.0.0
 * @package MeuMouse.com
 */
class Webhook_Controller {

    /**
     * Constructor
     *
     * @since 1.0.0
     * @return void
     */
    public function __construct() {
        // Webhook management
        register_rest_route('bling/v1', '/webhooks', array(
            array(
                'methods'  => 'GET',
                'callback' => array(__CLASS__, 'get_webhooks'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ),
            array(
                'methods'  => 'POST',
                'callback' => array(__CLASS__, 'create_webhook'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ),
        ));
        
        register_rest_route('bling/v1', '/webhooks/(?P<id>\d+)', array(
            array(
                'methods'  => 'DELETE',
                'callback' => array(__CLASS__, 'delete_webhook'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ),
        ));
    }
    
    /**
     * Get webhooks from Bling.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public static function get_webhooks(WP_REST_Request $request) {
        $response = Client::get_webhooks();
        
        if (is_wp_error($response)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $response->get_error_message(),
            ), 400);
        }
        
        $webhooks = isset($response['data']['data']) ? $response['data']['data'] : array();
        
        ob_start();
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('ID', 'joinotify-bling-erp'); ?></th>
                    <th><?php echo esc_html__('Evento', 'joinotify-bling-erp'); ?></th>
                    <th><?php echo esc_html__('URL', 'joinotify-bling-erp'); ?></th>
                    <th><?php echo esc_html__('Status', 'joinotify-bling-erp'); ?></th>
                    <th><?php echo esc_html__('Ações', 'joinotify-bling-erp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($webhooks)) : ?>
                    <tr>
                        <td colspan="5"><?php echo esc_html__('Nenhum webhook configurado.', 'joinotify-bling-erp'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($webhooks as $webhook) : ?>
                        <tr>
                            <td><?php echo esc_html($webhook['id']); ?></td>
                            <td><?php echo esc_html($webhook['event']); ?></td>
                            <td style="word-break: break-all;"><?php echo esc_html($webhook['url']); ?></td>
                            <td>
                                <?php if ($webhook['status'] === 'active') : ?>
                                    <span style="color: green;"><?php echo esc_html__('Ativo', 'joinotify-bling-erp'); ?></span>
                                <?php else : ?>
                                    <span style="color: red;"><?php echo esc_html__('Inativo', 'joinotify-bling-erp'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="button button-small button-danger bling-delete-webhook" 
                                        data-id="<?php echo esc_attr($webhook['id']); ?>">
                                    <?php echo esc_html__('Excluir', 'joinotify-bling-erp'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <script type="text/javascript">
            (function($) {
                $('.bling-delete-webhook').on('click', function() {
                    if (!confirm('<?php echo esc_js(__('Tem certeza que deseja excluir este webhook?', 'joinotify-bling-erp')); ?>')) {
                        return;
                    }
                    
                    var button = $(this);
                    var webhookId = button.data('id');
                    
                    button.prop('disabled', true).text('<?php echo esc_js(__('Excluindo...', 'joinotify-bling-erp')); ?>');
                    
                    $.ajax({
                        url: '<?php echo esc_url(get_rest_url(null, 'bling/v1/webhooks/')); ?>' + webhookId,
                        method: 'DELETE',
                        headers: {
                            'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                button.closest('tr').fadeOut();
                            } else {
                                alert('<?php echo esc_js(__('Erro ao excluir webhook: ', 'joinotify-bling-erp')); ?>' + response.data);
                                button.prop('disabled', false).text('<?php echo esc_js(__('Excluir', 'joinotify-bling-erp')); ?>');
                            }
                        }
                    });
                });
            })(jQuery);
        </script>
        <?php
        $html = ob_get_clean();
        
        return new WP_REST_Response(array(
            'success' => true,
            'html' => $html,
        ), 200);
    }
    
    /**
     * Create webhook in Bling.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public static function create_webhook(WP_REST_Request $request) {
        $event = $request->get_param('event');
        $url = $request->get_param('url');
        
        if (empty($event) || empty($url)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => __('Evento e URL são obrigatórios.', 'joinotify-bling-erp'),
            ), 400);
        }
        
        $webhook_data = array(
            'event' => $event,
            'url' => $url,
            'status' => 'active',
        );
        
        $response = Client::create_webhook($webhook_data);
        
        if (is_wp_error($response)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $response->get_error_message(),
            ), 400);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Webhook criado com sucesso!', 'joinotify-bling-erp'),
        ), 201);
    }
    
    /**
     * Delete webhook from Bling.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public static function delete_webhook(WP_REST_Request $request) {
        $webhook_id = $request->get_param('id');
        
        $response = Client::delete_webhook($webhook_id);
        
        if (is_wp_error($response)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $response->get_error_message(),
            ), 400);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Webhook excluído com sucesso!', 'joinotify-bling-erp'),
        ), 200);
    }
}