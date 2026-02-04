/**
 * WPBridge 管理界面脚本
 *
 * 基于 WPMind 设计系统
 *
 * @package WPBridge
 * @since 0.5.0
 */

(function($) {
    'use strict';

    /**
     * Toast 通知
     */
    var Toast = {
        $el: null,
        timeout: null,

        init: function() {
            this.$el = $('#wpbridge-toast');
        },

        show: function(message, type) {
            type = type || 'info';

            if (this.timeout) {
                clearTimeout(this.timeout);
            }

            this.$el
                .removeClass('success error warning show')
                .addClass(type)
                .text(message)
                .addClass('show');

            this.timeout = setTimeout(function() {
                Toast.$el.removeClass('show');
            }, 3000);
        },

        success: function(message) {
            this.show(message, 'success');
        },

        error: function(message) {
            this.show(message, 'error');
        },

        warning: function(message) {
            this.show(message, 'warning');
        }
    };

    /**
     * Tab 导航
     */
    var Tabs = {
        init: function() {
            var self = this;

            // 点击 Tab
            $(document).on('click', '.wpbridge-tab', function(e) {
                e.preventDefault();
                var tabId = $(this).data('tab');
                self.switchTo(tabId);
            });

            // 从 URL hash 恢复 Tab
            var hash = window.location.hash.replace('#', '');
            if (hash && $('.wpbridge-tab[data-tab="' + hash + '"]').length) {
                this.switchTo(hash);
            }
        },

        switchTo: function(tabId) {
            // 更新 Tab 导航
            $('.wpbridge-tab').removeClass('wpbridge-tab-active');
            $('.wpbridge-tab[data-tab="' + tabId + '"]').addClass('wpbridge-tab-active');

            // 更新 Tab 内容
            $('.wpbridge-tab-pane').removeClass('wpbridge-tab-pane-active');
            $('#' + tabId).addClass('wpbridge-tab-pane-active');

            // 更新 URL hash
            if (history.pushState) {
                history.pushState(null, null, '#' + tabId);
            } else {
                window.location.hash = tabId;
            }
        }
    };

    /**
     * 更新源管理
     */
    var Sources = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // 切换源状态
            $(document).on('change', '.wpbridge-toggle-source', function() {
                self.toggleSource($(this));
            });

            // 测试源连通性
            $(document).on('click', '.wpbridge-test-source', function() {
                self.testSource($(this));
            });

            // 删除源
            $(document).on('click', '.wpbridge-delete-source', function() {
                self.deleteSource($(this));
            });

            // 清除缓存
            $(document).on('click', '.wpbridge-clear-cache', function() {
                self.clearCache($(this));
            });
        },

        toggleSource: function($toggle) {
            var sourceId = $toggle.data('source-id');
            var enabled = $toggle.is(':checked');

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_toggle_source',
                    nonce: wpbridge.nonce,
                    source_id: sourceId,
                    enabled: enabled ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(enabled ? wpbridge.i18n.enabled : wpbridge.i18n.disabled);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                        $toggle.prop('checked', !enabled);
                    }
                },
                error: function() {
                    Toast.error(wpbridge.i18n.failed);
                    $toggle.prop('checked', !enabled);
                }
            });
        },

        testSource: function($button) {
            var sourceId = $button.data('source-id');
            var $card = $button.closest('.wpbridge-source-card');

            // 显示加载状态
            $button.prop('disabled', true);
            $button.find('.dashicons').removeClass('dashicons-admin-site-alt3').addClass('dashicons-update wpbridge-spin');

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_test_source',
                    nonce: wpbridge.nonce,
                    source_id: sourceId
                },
                success: function(response) {
                    if (response.success) {
                        var status = response.data.status;
                        var time = response.data.response_time;

                        // 更新状态徽章
                        var $body = $card.find('.wpbridge-source-card-body');
                        $body.find('.wpbridge-badge-status').remove();

                        var statusLabels = {
                            healthy: wpbridge.i18n.healthy || '正常',
                            degraded: wpbridge.i18n.degraded || '降级',
                            failed: wpbridge.i18n.failed_status || '失败'
                        };

                        $body.append(
                            '<span class="wpbridge-badge wpbridge-badge-status ' + status + '">' +
                            statusLabels[status] + ' (' + time + 'ms)' +
                            '</span>'
                        );

                        if (status === 'healthy') {
                            Toast.success(wpbridge.i18n.test_success + ' (' + time + 'ms)');
                        } else {
                            Toast.warning(response.data.error || wpbridge.i18n.test_degraded);
                        }
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function() {
                    Toast.error(wpbridge.i18n.failed);
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $button.find('.dashicons').removeClass('dashicons-update wpbridge-spin').addClass('dashicons-admin-site-alt3');
                }
            });
        },

        deleteSource: function($button) {
            var sourceId = $button.data('source-id');

            if (confirm(wpbridge.i18n.confirm_delete)) {
                $('#wpbridge-delete-source-id').val(sourceId);
                $('#wpbridge-delete-form').submit();
            }
        },

        clearCache: function($button) {
            $button.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_clear_cache',
                    nonce: wpbridge.nonce
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message || wpbridge.i18n.cache_cleared);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function() {
                    Toast.error(wpbridge.i18n.failed);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        }
    };

    /**
     * API Key 管理
     */
    var ApiKeys = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // 生成新 Key
            $(document).on('click', '.wpbridge-generate-api-key', function() {
                self.generateKey($(this));
            });

            // 撤销 Key
            $(document).on('click', '.wpbridge-revoke-api-key', function() {
                self.revokeKey($(this));
            });
        },

        generateKey: function($button) {
            var keyName = prompt(wpbridge.i18n.enter_key_name || '请输入 API Key 名称：');
            if (!keyName) return;

            $button.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_generate_api_key',
                    nonce: wpbridge.nonce,
                    key_name: keyName
                },
                success: function(response) {
                    if (response.success) {
                        // 显示新生成的 Key
                        var message = wpbridge.i18n.key_generated + '\n\n' +
                            response.data.api_key + '\n\n' +
                            wpbridge.i18n.key_warning;
                        alert(message);
                        location.reload();
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function() {
                    Toast.error(wpbridge.i18n.failed);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        revokeKey: function($button) {
            if (!confirm(wpbridge.i18n.confirm_revoke || '确定要撤销此 API Key 吗？')) {
                return;
            }

            var keyId = $button.data('key-id');
            $button.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_revoke_api_key',
                    nonce: wpbridge.nonce,
                    key_id: keyId
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(wpbridge.i18n.key_revoked || 'API Key 已撤销');
                        $button.closest('.wpbridge-settings-row').fadeOut(function() {
                            $(this).remove();
                        });
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function() {
                    Toast.error(wpbridge.i18n.failed);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        }
    };

    /**
     * 日志管理
     */
    var Logs = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            $(document).on('click', '.wpbridge-clear-logs', function() {
                self.clearLogs($(this));
            });
        },

        clearLogs: function($button) {
            if (!confirm(wpbridge.i18n.confirm_clear_logs || '确定要清除所有日志吗？')) {
                return;
            }

            $button.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_clear_logs',
                    nonce: wpbridge.nonce
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(wpbridge.i18n.logs_cleared || '日志已清除');
                        $('.wpbridge-logs-list').html(
                            '<div class="wpbridge-logs-empty">' +
                            '<span class="dashicons dashicons-media-text" style="font-size: 32px; width: 32px; height: 32px; color: var(--wpbridge-gray-300);"></span>' +
                            '<p>' + (wpbridge.i18n.no_logs || '暂无日志记录') + '</p>' +
                            '</div>'
                        );
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function() {
                    Toast.error(wpbridge.i18n.failed);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        }
    };

    /**
     * 初始化
     */
    $(document).ready(function() {
        Toast.init();
        Tabs.init();
        Sources.init();
        ApiKeys.init();
        Logs.init();
    });

    // 添加旋转动画样式
    $('<style>')
        .text('.wpbridge-spin { animation: wpbridge-spin 1s linear infinite; }')
        .appendTo('head');

})(jQuery);
