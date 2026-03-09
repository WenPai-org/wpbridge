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
     * Toast 通知系统 - 使用 WordPress 原生 notice 样式
     */
    var Toast = {
        container: null,

        init: function() {
            if (!this.container) {
                // 在 wpbridge-content 内部顶部创建通知容器
                this.container = $('<div class="wpbridge-notice-container"></div>');
                // 优先插入到 .wpbridge-content 内部顶部
                var $content = $('.wpbridge-content');
                if ($content.length) {
                    $content.prepend(this.container);
                } else {
                    // 回退：插入到 header 后面
                    $('.wpbridge-header').after(this.container);
                }
            }
        },

        show: function(message, type, duration) {
            this.init();
            type = type || 'info';
            duration = duration || 3000;

            // WordPress 原生 notice 类型映射
            var noticeType = {
                success: 'notice-success',
                error: 'notice-error',
                warning: 'notice-warning',
                info: 'notice-info'
            };

            // 图标映射
            var icons = {
                success: 'dashicons-yes-alt',
                error: 'dashicons-dismiss',
                warning: 'dashicons-warning',
                info: 'dashicons-info'
            };

            var $notice = $('<div class="notice ' + noticeType[type] + ' is-dismissible wpbridge-notice">' +
                '<p><span class="dashicons ' + icons[type] + ' wpbridge-notice-icon"></span><span class="wpbridge-notice-text"></span></p>' +
                '</div>');

            // 使用 .text() 防止 XSS
            $notice.find('.wpbridge-notice-text').text(message);

            this.container.append($notice);

            // 添加 WordPress 原生关闭按钮
            $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">' + (wpbridge.i18n.close_notice || '关闭此通知') + '</span></button>');

            // 动画显示
            $notice.hide().slideDown(200);

            // 关闭按钮事件
            $notice.find('.notice-dismiss').on('click', function() {
                Toast.hide($notice);
            });

            // 自动关闭
            if (duration > 0) {
                setTimeout(function() {
                    Toast.hide($notice);
                }, duration);
            }

            return $notice;
        },

        hide: function($notice) {
            $notice.slideUp(200, function() {
                $(this).remove();
            });
        },

        success: function(message, duration) {
            return this.show(message, 'success', duration);
        },

        error: function(message, duration) {
            return this.show(message, 'error', duration || 5000);
        },

        warning: function(message, duration) {
            return this.show(message, 'warning', duration);
        },

        info: function(message, duration) {
            return this.show(message, 'info', duration);
        }
    };

    /**
     * AJAX 错误处理 - 提供具体的错误信息
     */
    function ajaxErrorMessage(jqXHR, textStatus) {
        if (textStatus === 'timeout') {
            return wpbridge.i18n.error_timeout || '请求超时，请检查网络后重试';
        }
        if (textStatus === 'abort') {
            return wpbridge.i18n.error_aborted || '请求已取消';
        }
        if (!navigator.onLine) {
            return wpbridge.i18n.error_offline || '网络已断开，请检查网络连接';
        }
        if (jqXHR && jqXHR.status) {
            if (jqXHR.status === 403) {
                return wpbridge.i18n.error_forbidden || '权限不足，请刷新页面后重试';
            }
            if (jqXHR.status === 500) {
                return wpbridge.i18n.error_server || '服务器错误，请稍后重试';
            }
            if (jqXHR.status === 0) {
                return wpbridge.i18n.error_network || '无法连接服务器，请检查网络';
            }
            return (wpbridge.i18n.failed || '操作失败') + ' (' + jqXHR.status + ')';
        }
        return wpbridge.i18n.failed || '操作失败';
    }

    /**
     * 模态框系统 - 替代浏览器原生 confirm/prompt/alert
     */
    var Modal = {
        /**
         * 显示确认对话框
         * @param {Object} options 配置选项
         * @param {string} options.title 标题
         * @param {string} options.message 消息内容
         * @param {string} options.confirmText 确认按钮文字
         * @param {string} options.cancelText 取消按钮文字
         * @param {string} options.type 类型 (info/warning/danger)
         * @param {Function} options.onConfirm 确认回调
         * @param {Function} options.onCancel 取消回调
         */
        confirm: function(options) {
            var defaults = {
                title: wpbridge.i18n.confirm_title || '确认操作',
                message: '',
                confirmText: wpbridge.i18n.confirm_btn || '确定',
                cancelText: wpbridge.i18n.cancel_btn || '取消',
                type: 'warning',
                onConfirm: function() {},
                onCancel: function() {}
            };
            options = $.extend({}, defaults, options);

            var iconClass = {
                info: 'dashicons-info',
                warning: 'dashicons-warning',
                danger: 'dashicons-dismiss'
            };

            var html = '<div class="wpbridge-modal-overlay wpbridge-modal-confirm-overlay"></div>' +
                '<div class="wpbridge-modal wpbridge-modal-confirm wpbridge-modal-' + options.type + '">' +
                    '<div class="wpbridge-modal-header">' +
                        '<h3 class="wpbridge-modal-title">' +
                            '<span class="dashicons ' + (iconClass[options.type] || iconClass.warning) + '"></span> ' +
                            this.escapeHtml(options.title) +
                        '</h3>' +
                    '</div>' +
                    '<div class="wpbridge-modal-body">' +
                        '<p class="wpbridge-modal-message">' + this.escapeHtml(options.message) + '</p>' +
                    '</div>' +
                    '<div class="wpbridge-modal-footer">' +
                        '<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-modal-cancel">' +
                            this.escapeHtml(options.cancelText) +
                        '</button>' +
                        '<button type="button" class="wpbridge-btn wpbridge-btn-' + (options.type === 'danger' ? 'danger' : 'primary') + ' wpbridge-modal-confirm-btn">' +
                            this.escapeHtml(options.confirmText) +
                        '</button>' +
                    '</div>' +
                '</div>';

            var $modal = $(html).appendTo('body');
            $('body').addClass('wpbridge-modal-open');

            // 绑定事件
            $modal.filter('.wpbridge-modal').find('.wpbridge-modal-confirm-btn').on('click', function() {
                Modal.close($modal);
                options.onConfirm();
            });

            $modal.filter('.wpbridge-modal').find('.wpbridge-modal-cancel').on('click', function() {
                Modal.close($modal);
                options.onCancel();
            });

            $modal.filter('.wpbridge-modal-overlay').on('click', function() {
                Modal.close($modal);
                options.onCancel();
            });

            // ESC 关闭
            $(document).on('keydown.wpbridge-modal', function(e) {
                if (e.key === 'Escape') {
                    Modal.close($modal);
                    options.onCancel();
                }
            });

            // 聚焦确认按钮
            $modal.filter('.wpbridge-modal').find('.wpbridge-modal-confirm-btn').focus();

            return $modal;
        },

        /**
         * 显示输入对话框
         * @param {Object} options 配置选项
         * @param {string} options.title 标题
         * @param {string} options.message 提示消息
         * @param {string} options.placeholder 输入框占位符
         * @param {string} options.defaultValue 默认值
         * @param {string} options.inputType 输入类型 (text/password)
         * @param {string} options.confirmText 确认按钮文字
         * @param {string} options.cancelText 取消按钮文字
         * @param {Function} options.onConfirm 确认回调，参数为输入值
         * @param {Function} options.onCancel 取消回调
         * @param {Function} options.validate 验证函数，返回 true 或错误消息
         */
        prompt: function(options) {
            var defaults = {
                title: wpbridge.i18n.input_title || '请输入',
                message: '',
                placeholder: '',
                defaultValue: '',
                inputType: 'text',
                confirmText: wpbridge.i18n.confirm_btn || '确定',
                cancelText: wpbridge.i18n.cancel_btn || '取消',
                onConfirm: function() {},
                onCancel: function() {},
                validate: function() { return true; }
            };
            options = $.extend({}, defaults, options);

            var html = '<div class="wpbridge-modal-overlay wpbridge-modal-prompt-overlay"></div>' +
                '<div class="wpbridge-modal wpbridge-modal-prompt">' +
                    '<div class="wpbridge-modal-header">' +
                        '<h3 class="wpbridge-modal-title">' +
                            '<span class="dashicons dashicons-edit"></span> ' +
                            this.escapeHtml(options.title) +
                        '</h3>' +
                    '</div>' +
                    '<div class="wpbridge-modal-body">' +
                        (options.message ? '<p class="wpbridge-modal-message">' + this.escapeHtml(options.message) + '</p>' : '') +
                        '<input type="' + options.inputType + '" class="wpbridge-form-input wpbridge-modal-input" ' +
                            'placeholder="' + this.escapeHtml(options.placeholder) + '" ' +
                            'value="' + this.escapeHtml(options.defaultValue) + '">' +
                        '<p class="wpbridge-modal-error" style="display: none; color: #dc3545; margin-top: 8px;"></p>' +
                    '</div>' +
                    '<div class="wpbridge-modal-footer">' +
                        '<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-modal-cancel">' +
                            this.escapeHtml(options.cancelText) +
                        '</button>' +
                        '<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-modal-confirm-btn">' +
                            this.escapeHtml(options.confirmText) +
                        '</button>' +
                    '</div>' +
                '</div>';

            var $modal = $(html).appendTo('body');
            $('body').addClass('wpbridge-modal-open');

            var $input = $modal.filter('.wpbridge-modal').find('.wpbridge-modal-input');
            var $error = $modal.filter('.wpbridge-modal').find('.wpbridge-modal-error');

            var submitValue = function() {
                var value = $input.val().trim();
                var validation = options.validate(value);

                if (validation === true) {
                    Modal.close($modal);
                    options.onConfirm(value);
                } else {
                    $error.text(validation || wpbridge.i18n.invalid_input || '输入无效').show();
                    $input.focus();
                }
            };

            // 绑定事件
            $modal.filter('.wpbridge-modal').find('.wpbridge-modal-confirm-btn').on('click', submitValue);

            $input.on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitValue();
                }
            });

            $modal.filter('.wpbridge-modal').find('.wpbridge-modal-cancel').on('click', function() {
                Modal.close($modal);
                options.onCancel();
            });

            $modal.filter('.wpbridge-modal-overlay').on('click', function() {
                Modal.close($modal);
                options.onCancel();
            });

            // ESC 关闭
            $(document).on('keydown.wpbridge-modal', function(e) {
                if (e.key === 'Escape') {
                    Modal.close($modal);
                    options.onCancel();
                }
            });

            // 聚焦输入框
            $input.focus().select();

            return $modal;
        },

        /**
         * 显示提示对话框（显示重要信息，需要用户确认）
         * @param {Object} options 配置选项
         * @param {string} options.title 标题
         * @param {string} options.message 消息内容（支持 HTML）
         * @param {boolean} options.copyable 是否可复制
         * @param {string} options.copyText 要复制的文本
         * @param {string} options.confirmText 确认按钮文字
         * @param {Function} options.onConfirm 确认回调
         */
        alert: function(options) {
            var defaults = {
                title: wpbridge.i18n.notice_title || '提示',
                message: '',
                copyable: false,
                copyText: '',
                confirmText: wpbridge.i18n.confirm_btn || '确定',
                onConfirm: function() {}
            };
            options = $.extend({}, defaults, options);

            var copySection = '';
            if (options.copyable && options.copyText) {
                copySection = '<div class="wpbridge-modal-copy-section">' +
                    '<input type="text" class="wpbridge-form-input wpbridge-modal-copy-input" readonly value="' + this.escapeHtml(options.copyText) + '">' +
                    '<button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-modal-copy-btn">' +
                        '<span class="dashicons dashicons-clipboard"></span> ' + (wpbridge.i18n.copy || '复制') +
                    '</button>' +
                '</div>';
            }

            var html = '<div class="wpbridge-modal-overlay wpbridge-modal-alert-overlay"></div>' +
                '<div class="wpbridge-modal wpbridge-modal-alert">' +
                    '<div class="wpbridge-modal-header">' +
                        '<h3 class="wpbridge-modal-title">' +
                            '<span class="dashicons dashicons-info"></span> ' +
                            this.escapeHtml(options.title) +
                        '</h3>' +
                    '</div>' +
                    '<div class="wpbridge-modal-body">' +
                        '<div class="wpbridge-modal-message">' + options.message + '</div>' +
                        copySection +
                    '</div>' +
                    '<div class="wpbridge-modal-footer">' +
                        '<button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-modal-confirm-btn">' +
                            this.escapeHtml(options.confirmText) +
                        '</button>' +
                    '</div>' +
                '</div>';

            var $modal = $(html).appendTo('body');
            $('body').addClass('wpbridge-modal-open');

            // 复制功能
            if (options.copyable) {
                $modal.filter('.wpbridge-modal').find('.wpbridge-modal-copy-btn').on('click', function() {
                    var $input = $modal.filter('.wpbridge-modal').find('.wpbridge-modal-copy-input');
                    $input.select();
                    document.execCommand('copy');
                    Toast.success(wpbridge.i18n.copied || '已复制到剪贴板');
                });
            }

            // 绑定事件
            $modal.filter('.wpbridge-modal').find('.wpbridge-modal-confirm-btn').on('click', function() {
                Modal.close($modal);
                options.onConfirm();
            });

            // ESC 关闭
            $(document).on('keydown.wpbridge-modal', function(e) {
                if (e.key === 'Escape') {
                    Modal.close($modal);
                    options.onConfirm();
                }
            });

            // 聚焦确认按钮
            $modal.filter('.wpbridge-modal').find('.wpbridge-modal-confirm-btn').focus();

            return $modal;
        },

        /**
         * 关闭模态框
         */
        close: function($modal) {
            $(document).off('keydown.wpbridge-modal');
            $modal.remove();
            $('body').removeClass('wpbridge-modal-open');
        },

        /**
         * HTML 转义
         */
        escapeHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
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
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $button.find('.dashicons').removeClass('dashicons-update wpbridge-spin').addClass('dashicons-admin-site-alt3');
                }
            });
        },

        deleteSource: function($button) {
            var sourceId = $button.data('source-id');

            Modal.confirm({
                title: wpbridge.i18n.confirm_delete_title || '删除更新源',
                message: wpbridge.i18n.confirm_delete || '确定要删除这个更新源吗？',
                type: 'danger',
                confirmText: wpbridge.i18n.delete_btn || '删除',
                onConfirm: function() {
                    $('#wpbridge-delete-source-id').val(sourceId);
                    $('#wpbridge-delete-form').submit();
                }
            });
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
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
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
            Modal.prompt({
                title: wpbridge.i18n.generate_api_key || '生成 API Key',
                message: wpbridge.i18n.enter_key_name || '请输入 API Key 名称：',
                placeholder: wpbridge.i18n.key_name_placeholder || '例如：我的应用',
                validate: function(value) {
                    if (!value) {
                        return wpbridge.i18n.key_name_required || '请输入名称';
                    }
                    return true;
                },
                onConfirm: function(keyName) {
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
                                // 显示新生成的 Key（使用模态框）
                                Modal.alert({
                                    title: wpbridge.i18n.key_generated_title || 'API Key 已生成',
                                    message: '<p>' + (wpbridge.i18n.key_generated || 'API Key 已生成，请妥善保存：') + '</p>' +
                                        '<p class="wpbridge-key-warning"><span class="dashicons dashicons-warning"></span> ' +
                                        (wpbridge.i18n.key_warning || '此 Key 只会显示一次，请立即复制保存。') + '</p>',
                                    copyable: true,
                                    copyText: response.data.api_key,
                                    onConfirm: function() {
                                        location.reload();
                                    }
                                });
                            } else {
                                Toast.error(response.data.message || wpbridge.i18n.failed);
                            }
                        },
                        error: function(jqXHR, textStatus) {
                            Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                        },
                        complete: function() {
                            $button.prop('disabled', false);
                        }
                    });
                }
            });
        },

        revokeKey: function($button) {
            var keyId = $button.data('key-id');
            var keyName = $button.closest('.wpbridge-settings-row').find('.wpbridge-key-name').text() || 'API Key';

            Modal.confirm({
                title: wpbridge.i18n.revoke_key_title || '撤销 API Key',
                message: (wpbridge.i18n.confirm_revoke || '确定要撤销此 API Key 吗？') + '\n\n' + keyName,
                type: 'danger',
                confirmText: wpbridge.i18n.revoke_btn || '撤销',
                onConfirm: function() {
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
                                $button.prop('disabled', false);
                            }
                        },
                        error: function(jqXHR, textStatus) {
                            Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                            $button.prop('disabled', false);
                        }
                    });
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
            Modal.confirm({
                title: wpbridge.i18n.clear_logs_title || '清除日志',
                message: wpbridge.i18n.confirm_clear_logs || '确定要清除所有日志吗？',
                type: 'warning',
                confirmText: wpbridge.i18n.clear_btn || '清除',
                onConfirm: function() {
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
                        error: function(jqXHR, textStatus) {
                            Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                        },
                        complete: function() {
                            $button.prop('disabled', false);
                        }
                    });
                }
            });
        }
    };

    /**
     * 项目管理模块 (方案 B)
     */
    var Projects = {
        searchTimeout: null,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // 子 Tab 切换
            $(document).on('click', '.wpbridge-subtab', function(e) {
                e.preventDefault();
                var subtab = $(this).data('subtab');
                self.switchSubtab(subtab);
            });

            // 更新源选择
            $(document).on('change', '.wpbridge-source-select', function() {
                self.setItemSource($(this));
            });

            // 全选
            $(document).on('change', '#wpbridge-select-all-plugins', function() {
                $('#wpbridge-plugins-list .wpbridge-project-select').prop('checked', $(this).is(':checked'));
            });

            $(document).on('change', '#wpbridge-select-all-themes', function() {
                $('#wpbridge-themes-list .wpbridge-project-select').prop('checked', $(this).is(':checked'));
            });

            // 批量操作
            $(document).on('click', '#wpbridge-apply-bulk-plugins', function() {
                self.applyBulkAction('plugins');
            });

            $(document).on('click', '#wpbridge-apply-bulk-themes', function() {
                self.applyBulkAction('themes');
            });

            // 批量操作类型变化时显示/隐藏源选择
            $(document).on('change', '#wpbridge-bulk-action-plugins, #wpbridge-bulk-action-themes', function() {
                var type = $(this).attr('id').replace('wpbridge-bulk-action-', '');
                var action = $(this).val();
                if (action === 'set_source') {
                    $('#wpbridge-bulk-source-' + type).show();
                } else {
                    $('#wpbridge-bulk-source-' + type).hide();
                }
            });

            // 搜索（带防抖）
            $(document).on('input', '#wpbridge-search-plugins', function() {
                self.debouncedFilter('plugins', $(this).val());
            });

            $(document).on('input', '#wpbridge-search-themes', function() {
                self.debouncedFilter('themes', $(this).val());
            });

            // 默认规则覆盖切换
            $(document).on('change', '#plugin_override', function() {
                $('#wpbridge-plugin-sources').toggle($(this).is(':checked'));
            });

            $(document).on('change', '#theme_override', function() {
                $('#wpbridge-theme-sources').toggle($(this).is(':checked'));
            });

            // 默认规则表单提交
            $(document).on('submit', '#wpbridge-defaults-form', function(e) {
                e.preventDefault();
                self.saveDefaults($(this));
            });

            // P2: 插件类型手动标记
            $(document).on('change', '.wpbridge-plugin-type-select', function() {
                self.setPluginType($(this));
            });

            // 刷新商业插件检测
            $(document).on('click', '#wpbridge-refresh-detection', function() {
                self.refreshCommercialDetection($(this));
            });
        },

        switchSubtab: function(subtab) {
            $('.wpbridge-subtab').removeClass('wpbridge-subtab-active');
            $('.wpbridge-subtab[data-subtab="' + subtab + '"]').addClass('wpbridge-subtab-active');

            $('.wpbridge-subtab-pane').removeClass('wpbridge-subtab-pane-active');
            $('#subtab-' + subtab).addClass('wpbridge-subtab-pane-active');
        },

        setItemSource: function($select) {
            var itemKey = $select.data('item-key');
            var sourceKey = $select.val();
            var $item = $select.closest('.wpbridge-project-item');

            // 显示加载状态
            $select.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_set_item_source',
                    nonce: wpbridge.nonce,
                    item_key: itemKey,
                    source_key: sourceKey
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                        // 更新 UI 状态
                        $item.find('.wpbridge-badge-info, .wpbridge-badge-warning').remove();
                        if (sourceKey === 'disabled') {
                            $item.find('.wpbridge-project-name').append(
                                '<span class="wpbridge-badge wpbridge-badge-warning">' +
                                (wpbridge.i18n.updates_disabled || '更新已禁用') + '</span>'
                            );
                        } else if (sourceKey !== 'default') {
                            $item.find('.wpbridge-project-name').append(
                                '<span class="wpbridge-badge wpbridge-badge-info">' +
                                (wpbridge.i18n.custom_source || '自定义源') + '</span>'
                            );
                        }
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $select.prop('disabled', false);
                }
            });
        },

        applyBulkAction: function(type) {
            var action = $('#wpbridge-bulk-action-' + type).val();
            var items = [];

            $('#wpbridge-' + type + '-list .wpbridge-project-select:checked').each(function() {
                items.push($(this).val());
            });

            if (!action) {
                Toast.error(wpbridge.i18n.select_action || '请选择操作');
                return;
            }

            if (items.length === 0) {
                Toast.error(wpbridge.i18n.select_items || '请选择项目');
                return;
            }

            // 如果是设置源，先检查是否选择了源
            var sourceKey = '';
            if (action === 'set_source') {
                sourceKey = $('#wpbridge-bulk-source-' + type).val();
                if (!sourceKey) {
                    Toast.error(wpbridge.i18n.select_source || '请选择更新源');
                    return;
                }
            }

            // 确认操作
            var actionLabels = {
                set_source: wpbridge.i18n.action_set_source || '设置更新源',
                reset_default: wpbridge.i18n.action_reset || '重置为默认',
                disable: wpbridge.i18n.action_disable || '禁用更新'
            };
            var confirmMsg = (wpbridge.i18n.confirm_bulk_action || '确定要对选中的 {count} 个项目执行"{action}"操作吗？')
                .replace('{count}', items.length)
                .replace('{action}', actionLabels[action] || action);

            Modal.confirm({
                title: wpbridge.i18n.bulk_action_title || '批量操作',
                message: confirmMsg,
                type: 'warning',
                onConfirm: function() {
                    var data = {
                        action: 'wpbridge_batch_set_source',
                        nonce: wpbridge.nonce,
                        bulk_action: action,
                        item_keys: items
                    };

                    if (sourceKey) {
                        data.source_key = sourceKey;
                    }

                    $.ajax({
                        url: wpbridge.ajax_url,
                        type: 'POST',
                        data: data,
                        success: function(response) {
                            if (response.success) {
                                Toast.success(response.data.message);
                                location.reload();
                            } else {
                                Toast.error(response.data.message || wpbridge.i18n.failed);
                            }
                        },
                        error: function(jqXHR, textStatus) {
                            Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                        }
                    });
                }
            });
        },

        debouncedFilter: function(type, query) {
            var self = this;
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(function() {
                self.filterItems(type, query);
            }, 300);
        },

        filterItems: function(type, query) {
            query = query.toLowerCase();
            $('#wpbridge-' + type + '-list .wpbridge-project-item').each(function() {
                var $item = $(this);
                var name = $item.find('.wpbridge-project-name').text().toLowerCase();
                var slug = $item.find('.wpbridge-project-slug').text().toLowerCase();

                if (name.indexOf(query) > -1 || slug.indexOf(query) > -1) {
                    $item.show();
                } else {
                    $item.hide();
                }
            });
        },

        saveDefaults: function($form) {
            var $button = $form.find('button[type="submit"]');
            $button.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: $form.serialize() + '&action=wpbridge_save_defaults&nonce=' + wpbridge.nonce,
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * P2: 设置插件类型（手动标记）
         */
        setPluginType: function($select) {
            var pluginSlug = $select.data('plugin-slug');
            var pluginType = $select.val();
            var $item = $select.closest('.wpbridge-project-item');

            $select.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_set_plugin_type',
                    nonce: wpbridge.nonce,
                    plugin_slug: pluginSlug,
                    plugin_type: pluginType
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message || wpbridge.i18n.type_saved);

                        // 更新徽章
                        var $badge = $item.find('.wpbridge-badge-type-free, .wpbridge-badge-type-commercial, .wpbridge-badge-type-private, .wpbridge-badge-type-unknown');
                        if ($badge.length && response.data.label) {
                            $badge.removeClass('wpbridge-badge-type-free wpbridge-badge-type-commercial wpbridge-badge-type-private wpbridge-badge-type-unknown')
                                  .addClass('wpbridge-badge-type-' + response.data.type);
                            $badge.find('.dashicons').attr('class', 'dashicons ' + response.data.label.icon);
                            $badge.contents().filter(function() {
                                return this.nodeType === 3;
                            }).last().replaceWith(' ' + response.data.label.label);
                            $badge.attr('data-source', 'manual');
                            $badge.attr('title', wpbridge.i18n.manual_mark || '手动标记');
                        }

                        // 更新帮助文本
                        var $help = $select.closest('.wpbridge-config-field').find('.wpbridge-form-help');
                        if ($help.length) {
                            $help.text(wpbridge.i18n.manual_marked || '当前为手动标记');
                        }
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $select.prop('disabled', false);
                }
            });
        },

        /**
         * 刷新商业插件检测（异步批量处理）
         */
        refreshCommercialDetection: function($btn) {
            var self = this;
            var $icon = $btn.find('.dashicons');
            var originalClass = $icon.attr('class');
            var originalText = $btn.text().trim();
            var batchSize = 5; // 每批处理 5 个插件

            // 显示加载状态
            $btn.prop('disabled', true);
            $icon.attr('class', 'dashicons dashicons-update wpbridge-spin');

            // 更新按钮文本的辅助函数
            var updateBtnText = function(text) {
                $btn.contents().filter(function() {
                    return this.nodeType === 3; // 文本节点
                }).first().replaceWith(' ' + text);
            };

            // 第一步：准备刷新，获取插件列表
            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_prepare_refresh',
                    nonce: wpbridge.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var plugins = response.data.plugins;
                        var total = response.data.total;

                        if (total === 0) {
                            Toast.info(wpbridge.i18n.no_plugins || '没有插件需要检测');
                            $btn.prop('disabled', false);
                            $icon.attr('class', originalClass);
                            updateBtnText(originalText);
                            return;
                        }

                        // 更新按钮显示进度
                        updateBtnText('0/' + total);

                        // 分批处理
                        self.processBatches(plugins, batchSize, 0, total, {
                            free: 0,
                            commercial: 0,
                            private: 0,
                            unknown: 0
                        }, function(stats) {
                            // 完成
                            var msg = '已检测 ' + total + ' 个插件：' + stats.free + ' 免费，' +
                                stats.commercial + ' 商业，' + (stats.unknown + stats.private) + ' 第三方';
                            Toast.success(msg);

                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        }, function() {
                            // 恢复按钮状态
                            $btn.prop('disabled', false);
                            $icon.attr('class', originalClass);
                            updateBtnText(originalText);
                        }, updateBtnText);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                        $btn.prop('disabled', false);
                        $icon.attr('class', originalClass);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                    $btn.prop('disabled', false);
                    $icon.attr('class', originalClass);
                }
            });
        },

        /**
         * 批量处理插件检测
         */
        processBatches: function(plugins, batchSize, processed, total, stats, onComplete, onFinally, updateBtnText) {
            var self = this;
            var batch = plugins.slice(processed, processed + batchSize);

            if (batch.length === 0) {
                onComplete(stats);
                onFinally();
                return;
            }

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_refresh_batch',
                    nonce: wpbridge.nonce,
                    plugins: JSON.stringify(batch)
                },
                success: function(response) {
                    if (response.success) {
                        // 统计结果
                        var results = response.data.results;
                        for (var slug in results) {
                            var type = results[slug].type;
                            if (stats.hasOwnProperty(type)) {
                                stats[type]++;
                            }
                        }

                        processed += batch.length;
                        // 更新按钮文本显示进度
                        updateBtnText(processed + '/' + total);

                        // 继续下一批
                        self.processBatches(plugins, batchSize, processed, total, stats, onComplete, onFinally, updateBtnText);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                        onFinally();
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                    onFinally();
                }
            });
        }
    };

    /**
     * URL 自动推断模块 (P1)
     */
    var UrlInference = {
        patterns: {
            'github.com': {
                type: 'github',
                extract: /github\.com\/([^\/]+\/[^\/]+)/,
                namePrefix: 'GitHub: '
            },
            'gitlab.com': {
                type: 'gitlab',
                extract: /gitlab\.com\/([^\/]+\/[^\/]+)/,
                namePrefix: 'GitLab: '
            },
            'gitee.com': {
                type: 'gitee',
                extract: /gitee\.com\/([^\/]+\/[^\/]+)/,
                namePrefix: 'Gitee: '
            },
            'api.wordpress.org': {
                type: 'json',
                namePrefix: 'WordPress.org: '
            },
            'wpmirror.com': {
                type: 'json',
                namePrefix: '文派镜像: '
            }
        },

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // 监听 URL 输入变化
            $(document).on('blur', 'input[name="api_url"]', function() {
                self.inferFromUrl($(this).val());
            });
        },

        inferFromUrl: function(url) {
            if (!url) return;

            var result = this.parseUrl(url);
            if (!result) return;

            // 自动填充类型
            var $typeSelect = $('select[name="type"]');
            if ($typeSelect.length && !$typeSelect.data('user-changed')) {
                $typeSelect.val(result.type);
            }

            // 自动填充名称（如果为空）
            var $nameInput = $('input[name="name"]');
            if ($nameInput.length && !$nameInput.val()) {
                $nameInput.val(result.name);
            }
        },

        parseUrl: function(url) {
            try {
                var urlObj = new URL(url);
                var hostname = urlObj.hostname;

                for (var domain in this.patterns) {
                    // 精确匹配域名（防止 fakegithub.com 匹配 github.com）
                    if (hostname === domain || hostname.endsWith('.' + domain)) {
                        var pattern = this.patterns[domain];
                        var name = pattern.namePrefix || '';

                        if (pattern.extract) {
                            var match = url.match(pattern.extract);
                            if (match && match[1]) {
                                name += match[1];
                            }
                        } else {
                            name += hostname;
                        }

                        return {
                            type: pattern.type,
                            name: name
                        };
                    }
                }

                // 默认为 JSON 类型
                if (url.indexOf('.json') !== -1) {
                    return {
                        type: 'json',
                        name: hostname
                    };
                }

                return {
                    type: 'json',
                    name: hostname
                };
            } catch (e) {
                return null;
            }
        }
    };

    /**
     * 快速设置模块 (P1) - 内联折叠面板设计
     */
    var QuickSetup = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // 展开/折叠配置面板
            $(document).on('click', '.wpbridge-project-expand', function(e) {
                e.preventDefault();
                var itemKey = $(this).data('item-key');
                self.togglePanel(itemKey, $(this));
            });

            // 保存按钮 - 保存自定义源
            $(document).on('click', '.wpbridge-save-inline', function(e) {
                e.preventDefault();
                var itemKey = $(this).data('item-key');
                self.saveCustomSource(itemKey, $(this));
            });

            // 重置为默认
            $(document).on('click', '.wpbridge-reset-default', function(e) {
                e.preventDefault();
                var itemKey = $(this).data('item-key');
                self.setMode(itemKey, 'default', $(this));
            });

            // 禁用更新
            $(document).on('click', '.wpbridge-disable-update', function(e) {
                e.preventDefault();
                var itemKey = $(this).data('item-key');
                self.setMode(itemKey, 'disabled', $(this));
            });

            // 启用更新
            $(document).on('click', '.wpbridge-enable-update', function(e) {
                e.preventDefault();
                var itemKey = $(this).data('item-key');
                self.setMode(itemKey, 'default', $(this));
            });
        },

        togglePanel: function(itemKey, $button) {
            var $item = $button.closest('.wpbridge-project-item');
            var $panel = $item.find('.wpbridge-project-config-panel');

            if ($panel.is(':visible')) {
                $panel.slideUp(200);
                $item.removeClass('is-expanded');
            } else {
                // 关闭其他打开的面板
                $('.wpbridge-project-item.is-expanded').each(function() {
                    $(this).find('.wpbridge-project-config-panel').slideUp(200);
                    $(this).removeClass('is-expanded');
                });

                $panel.slideDown(200);
                $item.addClass('is-expanded');
            }
        },

        closePanel: function(itemKey) {
            var $item = $('.wpbridge-project-item').has('[data-item-key="' + itemKey + '"]').first();
            var $panel = $item.find('.wpbridge-project-config-panel');

            $panel.slideUp(200);
            $item.removeClass('is-expanded');

            // 清空输入
            $panel.find('input').val('');
        },

        // 设置模式（默认/禁用）
        setMode: function(itemKey, mode, $button) {
            $button.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_save_item_config',
                    nonce: wpbridge.nonce,
                    item_key: itemKey,
                    mode: mode
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                        location.reload();
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        // 保存自定义源
        saveCustomSource: function(itemKey, $button) {
            var $item = $button.closest('.wpbridge-project-item');
            var $panel = $item.find('.wpbridge-project-config-panel');
            var url = $panel.find('.wpbridge-inline-url').val();
            var token = $panel.find('.wpbridge-inline-token').val();

            if (!url) {
                Toast.error(wpbridge.i18n.enter_url || '请输入更新地址');
                return;
            }

            // 推断源类型和名称
            var inferred = UrlInference.parseUrl(url);
            if (!inferred) {
                Toast.error(wpbridge.i18n.invalid_url || '无效的 URL');
                return;
            }

            $button.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_save_item_config',
                    nonce: wpbridge.nonce,
                    item_key: itemKey,
                    mode: 'custom',
                    url: url,
                    type: inferred.type,
                    name: inferred.name,
                    token: token
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                        location.reload();
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        }
    };

    /**
     * 诊断工具模块 (P1)
     */
    var Diagnostics = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // Tab 链接跳转
            $(document).on('click', '[data-tab-link]', function(e) {
                e.preventDefault();
                var tabId = $(this).data('tab-link');
                Tabs.switchTo(tabId);
            });

            // 运行全部诊断
            $(document).on('click', '.wpbridge-run-all-diagnostics', function() {
                self.runAllDiagnostics($(this));
            });

            // 测试全部更新源
            $(document).on('click', '.wpbridge-test-all-sources', function() {
                self.testAllSources($(this));
            });

            // 测试单个更新源
            $(document).on('click', '.wpbridge-test-single-source', function() {
                self.testSingleSource($(this));
            });

            // 检查环境
            $(document).on('click', '.wpbridge-check-environment', function() {
                Toast.info(wpbridge.i18n.environment_ok || '环境检查已完成');
            });

            // 导出诊断报告
            $(document).on('click', '.wpbridge-export-diagnostics', function() {
                self.exportDiagnostics();
            });

            // 关闭模态框
            $(document).on('click', '.wpbridge-modal-close', function() {
                $(this).closest('.wpbridge-modal').hide();
                $(document).off('keydown.wpbridge-modal');
            });

            // 点击模态框背景关闭
            $(document).on('click', '.wpbridge-modal', function(e) {
                if ($(e.target).hasClass('wpbridge-modal')) {
                    $(this).hide();
                    $(document).off('keydown.wpbridge-modal');
                }
            });

            // 复制报告
            $(document).on('click', '.wpbridge-copy-report', function() {
                self.copyReport();
            });

            // 下载报告
            $(document).on('click', '.wpbridge-download-report', function() {
                self.downloadReport();
            });
        },

        runAllDiagnostics: function($button) {
            var self = this;
            $button.prop('disabled', true);
            $button.find('.dashicons').removeClass('dashicons-controls-play').addClass('dashicons-update wpbridge-spin');

            // 显示概览
            $('.wpbridge-diagnostics-summary').show();

            // 测试所有源
            this.testAllSources($('.wpbridge-test-all-sources'), function() {
                $button.prop('disabled', false);
                $button.find('.dashicons').removeClass('dashicons-update wpbridge-spin').addClass('dashicons-controls-play');
                Toast.success(wpbridge.i18n.diagnostics_complete || '诊断完成');
            });
        },

        testAllSources: function($button, callback) {
            var self = this;
            var $items = $('.wpbridge-source-test-item');
            var total = $items.length;
            var completed = 0;
            var passed = 0;
            var warnings = 0;
            var failed = 0;

            if (total === 0) {
                if (callback) callback();
                return;
            }

            $button.prop('disabled', true);
            $button.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-update wpbridge-spin');

            $items.each(function() {
                var $item = $(this);
                var sourceId = $item.data('source-id');
                var $testBtn = $item.find('.wpbridge-test-single-source');

                // 跳过已禁用的源
                if ($testBtn.prop('disabled')) {
                    completed++;
                    if (completed === total) {
                        self.updateSummary(passed, warnings, failed);
                        $button.prop('disabled', false);
                        $button.find('.dashicons').removeClass('wpbridge-spin');
                        if (callback) callback();
                    }
                    return;
                }

                $item.addClass('testing');

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

                            // 更新状态显示
                            var $status = $item.find('.wpbridge-source-test-status');
                            $status.find('.wpbridge-badge-status, .wpbridge-badge-unknown').remove();

                            var statusLabels = {
                                healthy: wpbridge.i18n.healthy || '正常',
                                degraded: wpbridge.i18n.degraded || '降级',
                                failed: wpbridge.i18n.failed_status || '失败'
                            };

                            $status.prepend(
                                '<span class="wpbridge-badge wpbridge-badge-status ' + status + '">' +
                                statusLabels[status] + '</span>'
                            );

                            // 更新响应时间
                            $status.find('.wpbridge-source-test-time').remove();
                            if (time) {
                                $status.append('<span class="wpbridge-source-test-time">' + time + 'ms</span>');
                            }

                            // 统计
                            if (status === 'healthy') {
                                passed++;
                            } else if (status === 'degraded') {
                                warnings++;
                            } else {
                                failed++;
                            }
                        } else {
                            failed++;
                        }
                    },
                    error: function(jqXHR, textStatus) {
                        failed++;
                    },
                    complete: function() {
                        $item.removeClass('testing');
                        completed++;

                        if (completed === total) {
                            self.updateSummary(passed, warnings, failed);
                            $button.prop('disabled', false);
                            $button.find('.dashicons').removeClass('wpbridge-spin');
                            if (callback) callback();
                        }
                    }
                });
            });
        },

        testSingleSource: function($button) {
            var sourceId = $button.data('source-id');
            var $item = $button.closest('.wpbridge-source-test-item');

            $button.prop('disabled', true);
            $button.find('.dashicons').removeClass('dashicons-admin-site-alt3').addClass('dashicons-update wpbridge-spin');
            $item.addClass('testing');

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

                        // 更新状态显示
                        var $status = $item.find('.wpbridge-source-test-status');
                        $status.find('.wpbridge-badge-status, .wpbridge-badge-unknown').remove();

                        var statusLabels = {
                            healthy: wpbridge.i18n.healthy || '正常',
                            degraded: wpbridge.i18n.degraded || '降级',
                            failed: wpbridge.i18n.failed_status || '失败'
                        };

                        $status.prepend(
                            '<span class="wpbridge-badge wpbridge-badge-status ' + status + '">' +
                            statusLabels[status] + '</span>'
                        );

                        // 更新响应时间
                        $status.find('.wpbridge-source-test-time').remove();
                        if (time) {
                            $status.append('<span class="wpbridge-source-test-time">' + time + 'ms</span>');
                        }

                        if (status === 'healthy') {
                            Toast.success(wpbridge.i18n.test_success + ' (' + time + 'ms)');
                        } else {
                            Toast.warning(response.data.error || wpbridge.i18n.test_degraded);
                        }
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $button.find('.dashicons').removeClass('dashicons-update wpbridge-spin').addClass('dashicons-admin-site-alt3');
                    $item.removeClass('testing');
                }
            });
        },

        updateSummary: function(passed, warnings, failed) {
            $('.wpbridge-diagnostics-passed .wpbridge-diagnostics-count').text(passed);
            $('.wpbridge-diagnostics-warnings .wpbridge-diagnostics-count').text(warnings);
            $('.wpbridge-diagnostics-failed .wpbridge-diagnostics-count').text(failed);
        },

        exportDiagnostics: function() {
            var self = this;
            var report = this.generateReport();
            var $modal = $('#wpbridge-export-modal');
            $('#wpbridge-diagnostics-report').val(report);
            $modal.show();

            // 焦点管理
            $modal.find('.wpbridge-modal-close').focus();

            // ESC 键关闭
            $(document).on('keydown.wpbridge-modal', function(e) {
                if (e.key === 'Escape') {
                    $modal.hide();
                    $(document).off('keydown.wpbridge-modal');
                }
            });
        },

        generateReport: function() {
            var i18n = wpbridge.i18n || {};
            var lines = [];
            lines.push('=== ' + (i18n.diagnostics_report || 'WPBridge Diagnostics Report') + ' ===');
            lines.push((i18n.generated_at || 'Generated at') + ': ' + new Date().toLocaleString());
            lines.push('');

            // 系统信息
            var $sysInfo = $('.wpbridge-system-info-item');
            if ($sysInfo.length) {
                lines.push('--- ' + (i18n.system_info || 'System Information') + ' ---');
                $sysInfo.each(function() {
                    var label = $(this).find('.wpbridge-system-info-label').text();
                    var value = $(this).find('.wpbridge-system-info-value').text();
                    lines.push(label + ': ' + value);
                });
                lines.push('');
            }

            // 环境检查
            var $envChecks = $('.wpbridge-environment-checks .wpbridge-check-item');
            if ($envChecks.length) {
                lines.push('--- ' + (i18n.environment_check || 'Environment Check') + ' ---');
                $envChecks.each(function() {
                    var $item = $(this);
                    var status = $item.hasClass('passed') ? '[' + (i18n.passed || 'PASS') + ']' :
                                 ($item.hasClass('warning') ? '[' + (i18n.warning || 'WARN') + ']' :
                                 '[' + (i18n.failed || 'FAIL') + ']');
                    var label = $item.find('.wpbridge-check-label').text();
                    var value = $item.find('.wpbridge-check-value').text();
                    lines.push(status + ' ' + label + ': ' + value);
                });
                lines.push('');
            }

            // 配置检查
            var $configChecks = $('.wpbridge-config-checks .wpbridge-check-item');
            if ($configChecks.length) {
                lines.push('--- ' + (i18n.config_check || 'Configuration Check') + ' ---');
                $configChecks.each(function() {
                    var $item = $(this);
                    var status = $item.hasClass('passed') ? '[' + (i18n.passed || 'PASS') + ']' :
                                 ($item.hasClass('warning') ? '[' + (i18n.warning || 'WARN') + ']' :
                                 '[' + (i18n.failed || 'FAIL') + ']');
                    var label = $item.find('.wpbridge-check-label').text();
                    var value = $item.find('.wpbridge-check-value').text();
                    lines.push(status + ' ' + label + ': ' + value);
                });
                lines.push('');
            }

            // 更新源状态
            var $sourceTests = $('.wpbridge-source-test-item');
            if ($sourceTests.length) {
                lines.push('--- ' + (i18n.source_status || 'Update Source Status') + ' ---');
                $sourceTests.each(function() {
                    var $item = $(this);
                    var name = $item.find('.wpbridge-source-test-name').text().trim();
                    var url = $item.find('.wpbridge-source-test-url').text().trim();
                    var status = $item.find('.wpbridge-badge-status').text().trim() || (i18n.not_tested || 'Not Tested');
                    var time = $item.find('.wpbridge-source-test-time').text().trim();
                    lines.push(name);
                    lines.push('  URL: ' + url);
                    lines.push('  ' + (i18n.status || 'Status') + ': ' + status + (time ? ' (' + time + ')' : ''));
                });
            }

            return lines.join('\n');
        },

        copyReport: function() {
            var text = $('#wpbridge-diagnostics-report').val();
            var self = this;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    Toast.success(wpbridge.i18n.copied || '已复制到剪贴板');
                }).catch(function() {
                    self.fallbackCopy();
                });
            } else {
                this.fallbackCopy();
            }
        },

        fallbackCopy: function() {
            var $textarea = $('#wpbridge-diagnostics-report');
            $textarea.select();
            document.execCommand('copy');
            Toast.success(wpbridge.i18n.copied || '已复制到剪贴板');
        },

        downloadReport: function() {
            var report = $('#wpbridge-diagnostics-report').val();
            var blob = new Blob([report], { type: 'text/plain' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'wpbridge-diagnostics-' + new Date().toISOString().slice(0, 10) + '.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    };

    /**
     * 配置导入导出管理
     */
    var ConfigManager = {
        init: function() {
            var self = this;

            // 导出配置
            $('#wpbridge-export-config').on('click', function() {
                self.exportConfig();
            });

            // 导入配置 - 触发文件选择
            $('#wpbridge-import-config').on('click', function() {
                $('#wpbridge-import-file').click();
            });

            // 文件选择后导入
            $('#wpbridge-import-file').on('change', function(e) {
                var file = e.target.files[0];
                if (file) {
                    self.importConfig(file);
                }
                // 重置文件输入，允许重复选择同一文件
                $(this).val('');
            });
        },

        exportConfig: function() {
            var $btn = $('#wpbridge-export-config');
            var originalHtml = $btn.html();
            var includeSecrets = $('#wpbridge-export-secrets').is(':checked');

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update wpbridge-spin"></span>');

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_export_config',
                    nonce: wpbridge.nonce,
                    include_secrets: includeSecrets ? 'true' : 'false'
                },
                success: function(response) {
                    if (response.success) {
                        // 下载 JSON 文件
                        var json = JSON.stringify(response.data.config, null, 2);
                        var blob = new Blob([json], { type: 'application/json' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);

                        Toast.success(wpbridge.i18n.config_exported || '配置已导出');
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },

        importConfig: function(file) {
            var self = this;
            var $btn = $('#wpbridge-import-config');
            var originalHtml = $btn.html();

            // 验证文件类型
            if (!file.name.endsWith('.json')) {
                Toast.error(wpbridge.i18n.invalid_file || '无效的配置文件');
                return;
            }

            // 确认导入
            Modal.confirm({
                title: wpbridge.i18n.import_config_title || '导入配置',
                message: wpbridge.i18n.confirm_import || '确定要导入配置吗？这将覆盖当前设置。',
                type: 'warning',
                confirmText: wpbridge.i18n.import_btn || '导入',
                onConfirm: function() {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        var content = e.target.result;

                // 验证 JSON 格式
                try {
                    JSON.parse(content);
                } catch (err) {
                    Toast.error(wpbridge.i18n.invalid_file || '无效的配置文件');
                    return;
                }

                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update wpbridge-spin"></span>');

                var merge = $('#wpbridge-import-merge').is(':checked');

                $.ajax({
                    url: wpbridge.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wpbridge_import_config',
                        nonce: wpbridge.nonce,
                        config: content,
                        merge: merge ? 'true' : 'false'
                    },
                    success: function(response) {
                        if (response.success) {
                            Toast.success(response.data.message || wpbridge.i18n.config_imported);
                            // 刷新页面以显示新配置
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            Toast.error(response.data.message || wpbridge.i18n.import_failed);
                        }
                    },
                    error: function(jqXHR, textStatus) {
                        Toast.error(wpbridge.i18n.import_failed || '导入失败');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                });
            };

            reader.readAsText(file);
                }
            });
        }
    };

    /**
     * 版本锁定管理
     */
    var VersionLock = {
        init: function() {
            var self = this;

            // 锁定类型选择变化
            $(document).on('change', '.wpbridge-lock-type-select', function() {
                var $select = $(this);
                var $btn = $select.siblings('.wpbridge-lock-version');

                if ($select.val()) {
                    $btn.show();
                } else {
                    $btn.hide();
                }
            });

            // 锁定版本
            $(document).on('click', '.wpbridge-lock-version', function() {
                var $btn = $(this);
                var $controls = $btn.closest('.wpbridge-version-lock-controls');
                var itemKey = $controls.data('item-key');
                var currentVersion = $controls.data('current-version');
                var lockType = $controls.find('.wpbridge-lock-type-select').val();

                if (!lockType) {
                    return;
                }

                self.lockVersion($btn, itemKey, lockType, currentVersion);
            });

            // 解锁版本
            $(document).on('click', '.wpbridge-unlock-version', function() {
                var $btn = $(this);
                var itemKey = $btn.data('item-key');

                Modal.confirm({
                    title: wpbridge.i18n.unlock_version_title || '解锁版本',
                    message: wpbridge.i18n.confirm_unlock || '确定要解锁此版本吗？',
                    type: 'warning',
                    confirmText: wpbridge.i18n.unlock_btn || '解锁',
                    onConfirm: function() {
                        self.unlockVersion($btn, itemKey);
                    }
                });
            });
        },

        lockVersion: function($btn, itemKey, lockType, version) {
            var originalHtml = $btn.html();

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update wpbridge-spin"></span>');

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_lock_version',
                    nonce: wpbridge.nonce,
                    item_key: itemKey,
                    lock_type: lockType,
                    version: version
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message || wpbridge.i18n.version_locked);
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },

        unlockVersion: function($btn, itemKey) {
            var originalHtml = $btn.html();

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update wpbridge-spin"></span>');

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_unlock_version',
                    nonce: wpbridge.nonce,
                    item_key: itemKey
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message || wpbridge.i18n.version_unlocked);
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        }
    };

    /**
     * 更新日志模块
     */
    var Changelog = {
        modal: null,

        init: function() {
            var self = this;

            // 查看更新日志按钮
            $(document).on('click', '.wpbridge-view-changelog', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var slug = $btn.data('slug');
                var type = $btn.data('type') || 'plugin';
                var sourceType = $btn.data('source-type') || 'wporg';
                var sourceUrl = $btn.data('source-url') || '';

                self.showChangelog(slug, type, sourceType, sourceUrl);
            });

            // 关闭模态框
            $(document).on('click', '.wpbridge-modal-close, .wpbridge-modal-overlay', function() {
                self.closeModal();
            });

            // ESC 关闭
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && self.modal) {
                    self.closeModal();
                }
            });
        },

        showChangelog: function(slug, type, sourceType, sourceUrl) {
            var self = this;

            // 创建模态框
            this.createModal(slug);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_get_changelog',
                    nonce: wpbridge.nonce,
                    slug: slug,
                    type: type,
                    source_type: sourceType,
                    source_url: sourceUrl
                },
                success: function(response) {
                    if (response.success) {
                        self.renderChangelog(response.data);
                    } else {
                        self.renderError(response.data.message || wpbridge.i18n.changelog_error);
                    }
                },
                error: function(jqXHR, textStatus) {
                    self.renderError(wpbridge.i18n.changelog_error || '获取更新日志失败');
                }
            });
        },

        createModal: function(slug) {
            // 移除已有模态框
            this.closeModal();

            var html = '<div class="wpbridge-modal-overlay"></div>' +
                '<div class="wpbridge-modal wpbridge-changelog-modal">' +
                    '<div class="wpbridge-modal-header">' +
                        '<h3 class="wpbridge-modal-title">' +
                            '<span class="dashicons dashicons-list-view"></span> ' +
                            (wpbridge.i18n.changelog_title || '更新日志') +
                            ' - <span class="wpbridge-changelog-slug"></span>' +
                        '</h3>' +
                        '<button type="button" class="wpbridge-modal-close">' +
                            '<span class="dashicons dashicons-no-alt"></span>' +
                        '</button>' +
                    '</div>' +
                    '<div class="wpbridge-modal-body">' +
                        '<div class="wpbridge-changelog-loading">' +
                            '<span class="dashicons dashicons-update wpbridge-spin"></span> ' +
                            (wpbridge.i18n.loading || '加载中...') +
                        '</div>' +
                    '</div>' +
                '</div>';

            this.modal = $(html).appendTo('body');
            this.modal.find('.wpbridge-changelog-slug').text(slug);
            $('body').addClass('wpbridge-modal-open');
        },

        renderChangelog: function(data) {
            if (!this.modal) return;

            var html = '<div class="wpbridge-changelog-content">';

            // 头部信息
            html += '<div class="wpbridge-changelog-header">';
            html += '<div class="wpbridge-changelog-meta">';
            if (data.name) {
                html += '<span class="wpbridge-changelog-name">' + this.escapeHtml(data.name) + '</span>';
            }
            if (data.version) {
                html += '<span class="wpbridge-badge wpbridge-badge-info">v' + this.escapeHtml(data.version) + '</span>';
            }
            if (data.source) {
                html += '<span class="wpbridge-badge wpbridge-badge-secondary">' + this.escapeHtml(data.source) + '</span>';
            }
            html += '</div>';
            if (data.last_updated) {
                html += '<div class="wpbridge-changelog-updated">' +
                    (wpbridge.i18n.last_updated || '最后更新') + ': ' +
                    this.escapeHtml(data.last_updated) +
                '</div>';
            }
            html += '</div>';

            // 版本列表（如果有）
            if (data.versions && data.versions.length > 0) {
                html += '<div class="wpbridge-changelog-versions">';
                html += '<strong>' + (wpbridge.i18n.recent_versions || '最近版本') + ':</strong> ';
                html += data.versions.slice(0, 5).map(function(v) {
                    return '<span class="wpbridge-version-tag">' + this.escapeHtml(v) + '</span>';
                }, this).join(' ');
                html += '</div>';
            }

            // 更新日志内容
            html += '<div class="wpbridge-changelog-body">';
            if (data.changelog_html) {
                html += data.changelog_html;
            } else {
                html += '<p class="wpbridge-changelog-empty">' +
                    (wpbridge.i18n.no_changelog || '暂无更新日志') +
                '</p>';
            }
            html += '</div>';

            html += '</div>';

            this.modal.find('.wpbridge-modal-body').html(html);
        },

        renderError: function(message) {
            if (!this.modal) return;

            var html = '<div class="wpbridge-changelog-error">' +
                '<span class="dashicons dashicons-warning"></span> ' +
                this.escapeHtml(message) +
            '</div>';

            this.modal.find('.wpbridge-modal-body').html(html);
        },

        closeModal: function() {
            if (this.modal) {
                this.modal.remove();
                this.modal = null;
                $('body').removeClass('wpbridge-modal-open');
            }
            $('.wpbridge-modal-overlay').remove();
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    /**
     * 供应商管理模块
     */
    var Vendors = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // 插件分组折叠/展开
            $(document).on('click', '.wpbridge-plugin-group-toggle', function() {
                var $btn = $(this);
                var $items = $btn.next('.wpbridge-plugin-group-items');
                var expanded = $btn.attr('aria-expanded') === 'true';
                $btn.attr('aria-expanded', !expanded);
                $btn.find('.wpbridge-group-arrow').toggleClass('is-open');
                $items.slideToggle(200);
            });

            // 预设供应商 - 激活按钮
            $(document).on('click', '.wpbridge-activate-preset-btn', function() {
                var presetId = $(this).closest('.wpbridge-vendor-preset-card').data('preset-id');
                self.showPresetModal(presetId);
            });

            // 预设供应商 - 保存
            $(document).on('click', '#wpbridge-save-preset', function() {
                self.savePreset();
            });

            // 预设供应商 - 停用
            $(document).on('click', '.wpbridge-deactivate-preset', function() {
                var presetId = $(this).data('preset-id');
                self.deactivatePreset(presetId, $(this));
            });

            // 刷新订阅状态
            $(document).on('click', '.wpbridge-refresh-subscription', function(e) {
                e.preventDefault();
                var $btn = $(this);
                if ($btn.hasClass('is-loading')) return;
                $btn.addClass('is-loading');
                $.ajax({
                    url: wpbridge.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'wpbridge_refresh_subscription',
                        nonce: wpbridge.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data && response.data.subscription) {
                            var sub = response.data.subscription;
                            var label = sub.label || sub.plan || 'free';
                            var isFree = sub.plan === 'free';
                            $btn.siblings('.wpbridge-subscription-badge')
                                .text(label)
                                .toggleClass('is-free', isFree);
                            Toast.success(wpbridge.i18n.subscriptionRefreshed || '订阅状态已刷新');
                        }
                    },
                    error: function(jqXHR, textStatus) {
                        Toast.error(wpbridge.i18n.subscriptionRefreshFailed || '刷新失败');
                    },
                    complete: function() {
                        $btn.removeClass('is-loading');
                    }
                });
            });

            // Bridge API - 添加按钮
            $(document).on('click', '.wpbridge-add-bridge-vendor-btn', function() {
                self.showBridgeVendorModal();
            });

            // Bridge API - 保存
            $(document).on('click', '#wpbridge-save-bridge', function() {
                self.saveBridgeVendor();
            });

            // 删除供应商
            $(document).on('click', '.wpbridge-remove-vendor', function() {
                var vendorId = $(this).data('vendor-id');
                self.removeVendor(vendorId, $(this));
            });

            // 测试供应商
            $(document).on('click', '.wpbridge-test-vendor', function() {
                var vendorId = $(this).data('vendor-id');
                self.testVendor(vendorId, $(this));
            });

            // 同步供应商
            $(document).on('click', '.wpbridge-sync-vendor', function() {
                var vendorId = $(this).data('vendor-id');
                self.syncVendor(vendorId, $(this));
            });

            // 切换供应商状态
            $(document).on('change', '.wpbridge-vendor-toggle', function() {
                var vendorId = $(this).data('vendor-id');
                var enabled = $(this).is(':checked');
                self.toggleVendor(vendorId, enabled);
            });

            // 同步全部
            $(document).on('click', '#wpbridge-sync-all-btn', function() {
                self.syncAllVendors($(this));
            });

            // 添加自定义插件按钮
            $(document).on('click', '#wpbridge-add-custom-btn', function() {
                self.showCustomModal();
            });

            // 保存自定义插件
            $(document).on('click', '#wpbridge-save-custom', function() {
                self.saveCustomPlugin();
            });

            // 删除自定义插件
            $(document).on('click', '.wpbridge-remove-custom', function() {
                var pluginSlug = $(this).data('plugin-slug');
                self.removeCustomPlugin(pluginSlug, $(this));
            });

            // 关闭弹窗
            $(document).on('click', '.wpbridge-modal-close, .wpbridge-modal-cancel', function() {
                $(this).closest('.wpbridge-modal').hide();
            });

            // 点击背景关闭弹窗
            $(document).on('click', '.wpbridge-modal', function(e) {
                if ($(e.target).hasClass('wpbridge-modal')) {
                    $(this).hide();
                }
            });
        },

        // === 预设供应商 ===

        showPresetModal: function(presetId) {
            var $card = $('[data-preset-id="' + presetId + '"]');
            var name = $card.find('.wpbridge-vendor-preset-name').text().trim();
            $('#wpbridge-preset-modal-title').text(name + ' — ' + (wpbridge.i18n.activate || '激活'));
            $('#wpbridge-preset-form')[0].reset();
            $('#preset_id').val(presetId);
            $('#wpbridge-preset-modal').show();
        },

        savePreset: function() {
            var $form = $('#wpbridge-preset-form');
            var $btn = $('#wpbridge-save-preset');

            var data = {
                action: 'wpbridge_activate_preset',
                nonce: wpbridge.nonce,
                preset_id: $form.find('#preset_id').val(),
                email: $form.find('#preset_email').val(),
                license_key: $form.find('#preset_license_key').val()
            };

            if (!data.email || !data.license_key) {
                Toast.error(wpbridge.i18n.fill_required || '请填写必填字段');
                return;
            }

            $btn.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                        $('#wpbridge-preset-modal').hide();
                        location.reload();
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        },

        deactivatePreset: function(presetId, $btn) {
            Modal.confirm({
                title: wpbridge.i18n.deactivate_preset_title || '停用供应商',
                message: wpbridge.i18n.confirm_deactivate_preset || '确定要停用此预设供应商吗？',
                type: 'warning',
                confirmText: wpbridge.i18n.deactivate_btn || '停用',
                onConfirm: function() {
                    $btn.prop('disabled', true);

                    $.ajax({
                        url: wpbridge.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'wpbridge_deactivate_preset',
                            nonce: wpbridge.nonce,
                            preset_id: presetId
                        },
                        success: function(response) {
                            if (response.success) {
                                Toast.success(response.data.message);
                                location.reload();
                            } else {
                                Toast.error(response.data.message || wpbridge.i18n.failed);
                                $btn.prop('disabled', false);
                            }
                        },
                        error: function(jqXHR, textStatus) {
                            Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                            $btn.prop('disabled', false);
                        }
                    });
                }
            });
        },

        // === Bridge API ===

        showBridgeVendorModal: function() {
            $('#wpbridge-bridge-form')[0].reset();
            $('#wpbridge-bridge-modal').show();
        },

        saveBridgeVendor: function() {
            var $form = $('#wpbridge-bridge-form');
            var $btn = $('#wpbridge-save-bridge');

            var data = {
                action: 'wpbridge_add_bridge_vendor',
                nonce: wpbridge.nonce,
                name: $form.find('#bridge_name').val(),
                api_url: $form.find('#bridge_api_url').val(),
                api_key: $form.find('#bridge_api_key').val()
            };

            if (!data.api_url || !data.api_key) {
                Toast.error(wpbridge.i18n.fill_required || '请填写必填字段');
                return;
            }

            $btn.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                        $('#wpbridge-bridge-modal').hide();
                        location.reload();
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        },

        // === 现有供应商操作（保持不变） ===

        showCustomModal: function() {
            $('#wpbridge-custom-form')[0].reset();
            $('#wpbridge-custom-modal').show();
        },

        removeVendor: function(vendorId, $btn) {
            Modal.confirm({
                title: wpbridge.i18n.remove_vendor_title || '删除供应商',
                message: wpbridge.i18n.confirm_remove_vendor || '确定要删除此供应商吗？',
                type: 'warning',
                confirmText: wpbridge.i18n.delete_btn || '删除',
                onConfirm: function() {
                    $btn.prop('disabled', true);

                    $.ajax({
                        url: wpbridge.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'wpbridge_remove_vendor',
                            nonce: wpbridge.nonce,
                            vendor_id: vendorId
                        },
                        success: function(response) {
                            if (response.success) {
                                Toast.success(response.data.message);
                                $btn.closest('tr').fadeOut(function() {
                                    $(this).remove();
                                });
                            } else {
                                Toast.error(response.data.message || wpbridge.i18n.failed);
                                $btn.prop('disabled', false);
                            }
                        },
                        error: function(jqXHR, textStatus) {
                            Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                            $btn.prop('disabled', false);
                        }
                    });
                }
            });
        },

        testVendor: function(vendorId, $btn) {
            var originalText = $btn.text();
            $btn.prop('disabled', true).text(wpbridge.i18n.testing || '测试中...');

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_test_vendor',
                    nonce: wpbridge.nonce,
                    vendor_id: vendorId
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        syncVendor: function(vendorId, $btn) {
            var originalText = $btn.text();
            $btn.prop('disabled', true).text(wpbridge.i18n.syncing || '同步中...');

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_sync_vendor_plugins',
                    nonce: wpbridge.nonce,
                    vendor_id: vendorId
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                        // 更新插件数
                        $btn.closest('tr').find('.wpbridge-plugin-count').text(response.data.count);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        toggleVendor: function(vendorId, enabled) {
            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_toggle_vendor',
                    nonce: wpbridge.nonce,
                    vendor_id: vendorId,
                    enabled: enabled ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                }
            });
        },

        syncAllVendors: function($btn) {
            var originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update wpbridge-spin"></span> ' + (wpbridge.i18n.syncing || '同步中...'));

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbridge_sync_vendor_plugins',
                    nonce: wpbridge.nonce
                },
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                        location.reload();
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },

        saveCustomPlugin: function() {
            var $form = $('#wpbridge-custom-form');
            var $btn = $('#wpbridge-save-custom');

            var data = {
                action: 'wpbridge_add_custom_plugin',
                nonce: wpbridge.nonce,
                plugin_slug: $form.find('#custom_slug').val(),
                name: $form.find('#custom_name').val(),
                update_url: $form.find('#custom_url').val()
            };

            if (!data.plugin_slug) {
                Toast.error(wpbridge.i18n.fill_required || '请填写必填字段');
                return;
            }

            $btn.prop('disabled', true);

            $.ajax({
                url: wpbridge.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        Toast.success(response.data.message);
                        $('#wpbridge-custom-modal').hide();
                        location.reload();
                    } else {
                        Toast.error(response.data.message || wpbridge.i18n.failed);
                    }
                },
                error: function(jqXHR, textStatus) {
                    Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        },

        removeCustomPlugin: function(pluginSlug, $btn) {
            Modal.confirm({
                title: wpbridge.i18n.remove_custom_title || '删除自定义插件',
                message: wpbridge.i18n.confirm_remove_custom || '确定要删除此自定义插件吗？',
                type: 'warning',
                confirmText: wpbridge.i18n.delete_btn || '删除',
                onConfirm: function() {
                    $btn.prop('disabled', true);

                    $.ajax({
                        url: wpbridge.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'wpbridge_remove_custom_plugin',
                            nonce: wpbridge.nonce,
                            plugin_slug: pluginSlug
                        },
                        success: function(response) {
                            if (response.success) {
                                Toast.success(response.data.message);
                                $btn.closest('tr').fadeOut(function() {
                                    $(this).remove();
                                });
                            } else {
                                Toast.error(response.data.message || wpbridge.i18n.failed);
                                $btn.prop('disabled', false);
                            }
                        },
                        error: function(jqXHR, textStatus) {
                            Toast.error(ajaxErrorMessage(jqXHR, textStatus));
                            $btn.prop('disabled', false);
                        }
                    });
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
        Projects.init();
        UrlInference.init();
        QuickSetup.init();
        Diagnostics.init();
        ConfigManager.init();
        VersionLock.init();
        Changelog.init();
        Vendors.init();
    });

})(jQuery);
