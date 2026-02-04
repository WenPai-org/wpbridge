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
            $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">关闭此通知</span></button>');

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
                error: function() {
                    Toast.error(wpbridge.i18n.failed);
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

            // 确认操作
            var confirmMsg = wpbridge.i18n.confirm_bulk_action || '确定要对选中的 ' + items.length + ' 个项目执行此操作吗？';
            if (!confirm(confirmMsg)) {
                return;
            }

            var data = {
                action: 'wpbridge_batch_set_source',
                nonce: wpbridge.nonce,
                bulk_action: action,
                item_keys: items
            };

            // 如果是设置源，从下拉框获取选择的源
            if (action === 'set_source') {
                var sourceKey = $('#wpbridge-bulk-source-' + type).val();
                if (!sourceKey) {
                    Toast.error(wpbridge.i18n.select_source || '请选择更新源');
                    return;
                }
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
                error: function() {
                    Toast.error(wpbridge.i18n.failed);
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
                error: function() {
                    Toast.error(wpbridge.i18n.failed);
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
        Projects.init();
        UrlInference.init();
        QuickSetup.init();
    });

    // 添加旋转动画样式
    $('<style>')
        .text('.wpbridge-spin { animation: wpbridge-spin 1s linear infinite; }')
        .appendTo('head');

})(jQuery);
