/**
 * Bling ERP Frontend JavaScript
 * Version: 1.0.0
 */

(function($) {
    'use strict';

    // Frontend Bling object
    window.BlingFrontend = window.BlingFrontend || {};

    // Initialize when document is ready
    $(document).ready(function() {
        BlingFrontend.init();
    });

    /**
     * Frontend Module
     */
    BlingFrontend = {
        /**
         * Initialize frontend functionality
         */
        init: function() {
            this.checkInvoiceStatus();
            this.bindEvents();
        },

        /**
         * Check invoice status on order details page
         */
        checkInvoiceStatus: function() {
            var $invoiceStatus = $('.bling-invoice-status');
            if ($invoiceStatus.length) {
                var orderId = $invoiceStatus.data('order-id');
                if (orderId) {
                    this.updateInvoiceStatus(orderId);
                }
            }
        },

        /**
         * Update invoice status via AJAX
         */
        updateInvoiceStatus: function(orderId) {
            var $container = $('.bling-invoice-details');
            if (!$container.length) return;

            $container.html('<p>' + bling_frontend.strings.loading + '</p>');

            $.ajax({
                url: bling_frontend.ajax_url,
                type: 'POST',
                data: {
                    action: 'bling_get_invoice_status',
                    nonce: bling_frontend.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) {
                        $container.html(response.data.html);
                    } else {
                        $container.html('<p class="error">' + response.data + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    $container.html('<p class="error">' + bling_frontend.strings.error + '</p>');
                }
            });
        },

        /**
         * Bind frontend events
         */
        bindEvents: function() {
            // Refresh invoice status
            $(document).on('click', '.bling-refresh-status', function(e) {
                e.preventDefault();
                var orderId = $(this).data('order-id');
                if (orderId) {
                    BlingFrontend.updateInvoiceStatus(orderId);
                }
            });

            // View invoice in Bling
            $(document).on('click', '.bling-view-invoice', function(e) {
                e.preventDefault();
                var invoiceUrl = $(this).attr('href');
                window.open(invoiceUrl, '_blank');
            });
        }
    };

})(jQuery);