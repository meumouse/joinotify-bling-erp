/**
 * Bling ERP Admin JavaScript
 * Version: 1.0.0
 */

(function($) {
    'use strict';

    // Main Bling object
    window.Bling = window.Bling || {};

    // Initialize when document is ready
    $(document).ready(function() {
        Bling.init();
    });

    /**
     * Bling Core Module
     */
    Bling.Core = {
        /**
         * Initialize all modules
         */
        init: function() {
            this.initModules();
            this.bindEvents();
        },

        /**
         * Initialize individual modules
         */
        initModules: function() {
            Bling.UI.init();
            Bling.Ajax.init();
            Bling.Settings.init();
            Bling.Products.init();
            Bling.Orders.init();
            Bling.SalesChannels.init();
        },

        /**
         * Bind global events
         */
        bindEvents: function() {
            // Handle tab switching
            $(document).on('click', '.bling-tabs .nav-tab', function(e) {
                e.preventDefault();
                Bling.UI.switchTab($(this));
            });

            // Handle form submissions
            $(document).on('submit', '.bling-form', function(e) {
                e.preventDefault();
                Bling.Ajax.handleFormSubmit($(this));
            });

            // Handle select all/deselect all for checkboxes
            $(document).on('click', '#bling-select-all-statuses', function(e) {
                e.preventDefault();
                $('input[name="bling_invoice_trigger_statuses[]"]').prop('checked', true);
            });

            $(document).on('click', '#bling-deselect-all-statuses', function(e) {
                e.preventDefault();
                $('input[name="bling_invoice_trigger_statuses[]"]').prop('checked', false);
            });
        }
    };

    /**
     * UI Module
     */
    Bling.UI = {
        init: function() {
            this.initTooltips();
            this.initTabs();
        },

        /**
         * Initialize tooltips
         */
        initTooltips: function() {
            $('.bling-tooltip').each(function() {
                var title = $(this).data('title');
                
                if (title) {
                    $(this).attr('title', title);
                }
            });
        },

        /**
         * Initialize tabs
         */
        initTabs: function() {
            var currentTab = bling_admin.current_tab || 'credentials';
            this.showTab(currentTab);
        },

        /**
         * Switch tabs
         */
        switchTab: function($tab) {
            var tabName = $tab.data('tab') || $tab.attr('href').split('tab=')[1];
            
            // Update active tab
            $('.nav-tab').removeClass('nav-tab-active');
            $tab.addClass('nav-tab-active');
            
            // Show tab content
            this.showTab(tabName);
            
            // Update URL
            history.pushState(null, null, '?page=joinotify-bling&tab=' + tabName);
        },

        /**
         * Show tab content
         */
        showTab: function(tabName) {
            // Hide all tab content
            $('.bling-tab-content').hide();
            
            // Show selected tab content
            $('#bling-tab-' + tabName).show();
        },

        /**
         * Show loading state
         */
        showLoading: function($element) {
            $element.addClass('bling-loading').prop('disabled', true);
        },

        /**
         * Hide loading state
         */
        hideLoading: function($element) {
            $element.removeClass('bling-loading').prop('disabled', false);
        },

        /**
         * Show success message
         */
        showSuccess: function(message) {
            this.showMessage(message, 'success');
        },

        /**
         * Show error message
         */
        showError: function(message) {
            this.showMessage(message, 'error');
        },

        /**
         * Show notification message
         */
        showMessage: function(message, type) {
            type = type || 'success';
            var className = 'bling-alert-' + type;
            
            var $alert = $('<div class="bling-alert ' + className + ' bling-fade-in">' +
                '<p>' + message + '</p>' +
                '</div>');
            
            $('.wrap h1').after($alert);
            
            // Auto dismiss after 5 seconds
            setTimeout(function() {
                $alert.fadeOut(500, function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Show inline success message
         */
        showInlineSuccess: function($container, message) {
            var $message = $('<div class="notice notice-success inline"><p>' + message + '</p></div>');
            $container.prepend($message);
            
            // Remove message after 3 seconds
            setTimeout(function() {
                $message.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        },

        /**
         * Show inline error message
         */
        showInlineError: function($container, message) {
            var $message = $('<div class="notice notice-error inline"><p>' + message + '</p></div>');
            $container.html($message);
        },

        /**
         * Show confirmation dialog
         */
        confirm: function(message, callback) {
            if (confirm(message || bling_admin.strings.confirm)) {
                if (typeof callback === 'function') {
                    callback();
                }
                return true;
            }

            return false;
        }
    };

    /**
     * AJAX Module
     */
    Bling.Ajax = {
        init: function() {
            // Initialize AJAX handlers
            this.bindBulkActions();
            this.bindUtilityActions();
        },

        /**
         * Bind bulk action handlers
         */
        bindBulkActions: function() {
            $('#bling-sync-all-products').on('click', this.handleBulkSyncProducts.bind(this));
            $('#bling-sync-all-customers').on('click', this.handleBulkSyncCustomers.bind(this));
        },

        /**
         * Bind utility action handlers
         */
        bindUtilityActions: function() {
            $('#bling-test-connection').on('click', this.handleTestConnection.bind(this));
            $('#bling-clear-cache').on('click', this.handleClearCache.bind(this));
        },

        /**
         * Handle bulk product sync
         */
        handleBulkSyncProducts: function(e) {
            e.preventDefault();
            
            if (!Bling.UI.confirm(bling_admin.strings.confirm_sync)) {
                return;
            }
            
            var $button = $(e.currentTarget);
            Bling.UI.showLoading($button);
            
            $.ajax({
                url: bling_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'bling_bulk_sync_products',
                    nonce: bling_admin.nonce
                },
                success: function(response) {
                    Bling.UI.hideLoading($button);

                    if (response.success) {
                        Bling.UI.showSuccess(response.data.message || bling_admin.strings.sync_complete);
                    } else {
                        Bling.UI.showError(response.data || bling_admin.strings.sync_error);
                    }
                },
                error: function(xhr, status, error) {
                    Bling.UI.hideLoading($button);
                    Bling.UI.showError(bling_admin.strings.error + ': ' + error);
                }
            });
        },

        /**
         * Handle bulk customer sync
         */
        handleBulkSyncCustomers: function(e) {
            e.preventDefault();
            
            if (!Bling.UI.confirm(bling_admin.strings.confirm_sync)) {
                return;
            }
            
            var $button = $(e.currentTarget);
            Bling.UI.showLoading($button);
            
            $.ajax({
                url: bling_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'bling_bulk_sync_customers',
                    nonce: bling_admin.nonce
                },
                success: function(response) {
                    Bling.UI.hideLoading($button);

                    if (response.success) {
                        Bling.UI.showSuccess(response.data.message || bling_admin.strings.sync_complete);
                    } else {
                        Bling.UI.showError(response.data || bling_admin.strings.sync_error);
                    }
                },
                error: function(xhr, status, error) {
                    Bling.UI.hideLoading($button);
                    Bling.UI.showError(bling_admin.strings.error + ': ' + error);
                }
            });
        },

        /**
         * Handle test connection
         */
        handleTestConnection: function(e) {
            e.preventDefault();
            
            var $button = $(e.currentTarget);
            Bling.UI.showLoading($button);
            
            $.ajax({
                url: bling_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'bling_test_connection',
                    nonce: bling_admin.nonce
                },
                success: function(response) {
                    Bling.UI.hideLoading($button);

                    if (response.success) {
                        Bling.UI.showSuccess(response.data || bling_admin.strings.connection_success);
                    } else {
                        Bling.UI.showError(response.data || bling_admin.strings.connection_error);
                    }
                },
                error: function(xhr, status, error) {
                    Bling.UI.hideLoading($button);
                    Bling.UI.showError(bling_admin.strings.error + ': ' + error);
                }
            });
        },

        /**
         * Handle clear cache
         */
        handleClearCache: function(e) {
            e.preventDefault();
            
            if (!Bling.UI.confirm(bling_admin.strings.confirm_cache_clear)) {
                return;
            }
            
            var $button = $(e.currentTarget);
            Bling.UI.showLoading($button);
            
            $.ajax({
                url: bling_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'bling_clear_cache',
                    nonce: bling_admin.nonce
                },
                success: function(response) {
                    Bling.UI.hideLoading($button);

                    if (response.success) {
                        Bling.UI.showSuccess(response.data.message || bling_admin.strings.cache_cleared);
                    } else {
                        Bling.UI.showError(response.data || bling_admin.strings.error);
                    }
                },
                error: function(xhr, status, error) {
                    Bling.UI.hideLoading($button);
                    Bling.UI.showError(bling_admin.strings.error + ': ' + error);
                }
            });
        },

        /**
         * Handle form submission
         */
        handleFormSubmit: function($form) {
            var $submitButton = $form.find('input[type="submit"], button[type="submit"]');
            Bling.UI.showLoading($submitButton);
            
            var formData = $form.serialize();
            
            $.ajax({
                url: $form.attr('action') || bling_admin.ajax_url,
                type: $form.attr('method') || 'POST',
                data: formData,
                success: function(response) {
                    Bling.UI.hideLoading($submitButton);

                    if (response.success) {
                        Bling.UI.showSuccess(bling_admin.strings.settings_saved);
                    } else {
                        Bling.UI.showError(response.data || bling_admin.strings.error);
                    }
                },
                error: function(xhr, status, error) {
                    Bling.UI.hideLoading($submitButton);
                    Bling.UI.showError(bling_admin.strings.error + ': ' + error);
                }
            });
        }
    };

    /**
     * Settings Module
     */
    Bling.Settings = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Handle auto-create invoice toggle
            $('#bling_auto_create_invoice').on('change', this.handleAutoCreateToggle.bind(this));
        },

        handleAutoCreateToggle: function(e) {
            var isEnabled = $(e.currentTarget).val() === 'yes';
            
            // Show/hide related settings
            if (isEnabled) {
                $('.bling-trigger-statuses').show();
            } else {
                $('.bling-trigger-statuses').hide();
            }
        }
    };

    /**
     * Sales Channels Module
     */
    Bling.SalesChannels = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Handle refresh channels button click
            $(document).on('click', '#bling-refresh-channels, #bling-retry-load-channels', this.handleRefreshChannels.bind(this));
        },

        handleRefreshChannels: function(e) {
            e.preventDefault();
            
            var $button = $(e.currentTarget);
            var originalText = $button.text();
            var $container = $('#bling-sales-channel-container');
            
            // Show loading state
            $button.prop('disabled', true).text(bling_admin.strings.loading);
            
            // Make AJAX request
            $.ajax({
                url: bling_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'bling_get_sales_channels',
                    nonce: bling_admin.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).text(originalText);
                    
                    if (response.success) {
                        Bling.SalesChannels.handleSuccessResponse(response.data, $container);
                    } else {
                        Bling.SalesChannels.handleErrorResponse(response.data, $container);
                    }
                },
                error: function(xhr, status, error) {
                    $button.prop('disabled', false).text(originalText);
                    Bling.SalesChannels.handleAjaxError($container);
                }
            });
        },

        handleSuccessResponse: function(channels, $container) {
            var currentValue = $('#bling_sales_channel_id').val();
            
            if (channels && channels.length > 0) {
                var options = '<option value="">' + bling_admin.strings.select_channel + '</option>';
                
                channels.forEach(function(channel) {
                    var selected = (currentValue == channel.id) ? 'selected' : '';
                    var tipo = channel.tipo ? ' (' + channel.tipo + ')' : '';
                    options += '<option value="' + channel.id + '" ' + selected + ' data-type="' + (channel.tipo || '') + '">' + 
                               channel.descricao + tipo + '</option>';
                });
                
                $container.html('<select name="bling_sales_channel_id" id="bling_sales_channel_id" class="regular-text">' + options + '</select>');
                
                // Show success message
                Bling.UI.showInlineSuccess($container, bling_admin.strings.channels_loaded);
            } else {
                // No channels found
                $container.html('<div class="notice notice-warning inline"><p>' + bling_admin.strings.no_channels_found + '</p></div>' +
                              '<button type="button" id="bling-retry-load-channels" class="button button-small">' + bling_admin.strings.try_again + '</button>');
            }
        },

        handleErrorResponse: function(errorMessage, $container) {
            var message = bling_admin.strings.error + ': ' + (errorMessage || bling_admin.strings.unknown_error);
            $container.html('<div class="notice notice-error inline"><p>' + message + '</p></div>' +
                          '<button type="button" id="bling-retry-load-channels" class="button button-small">' + bling_admin.strings.try_again + '</button>');
        },

        handleAjaxError: function($container) {
            $container.html('<div class="notice notice-error inline"><p>' + bling_admin.strings.channels_load_error + '</p></div>' +
                          '<button type="button" id="bling-retry-load-channels" class="button button-small">' + bling_admin.strings.try_again + '</button>');
        }
    };

    // Products Module (placeholder)
    Bling.Products = {
        init: function() {
            // Product-related initialization
        }
    };

    // Orders Module (placeholder)
    Bling.Orders = {
        init: function() {
            // Order-related initialization
        }
    };

    // Initialize main Bling object
    Bling.init = function() {
        Bling.Core.init();
    };

})(jQuery);