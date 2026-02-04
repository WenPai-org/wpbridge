/**
 * WPBridge 管理界面脚本
 *
 * @package WPBridge
 */

(function($) {
    'use strict';

    // 切换源状态
    $(document).on('change', '.wpbridge-toggle-source', function() {
        var $toggle = $(this);
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
                if (!response.success) {
                    alert(response.data.message || wpbridge.i18n.failed);
                    $toggle.prop('checked', !enabled);
                }
            },
            error: function() {
                alert(wpbridge.i18n.failed);
                $toggle.prop('checked', !enabled);
            }
        });
    });

    // 测试源连通性
    $(document).on('click', '.wpbridge-test-source', function() {
        var $button = $(this);
        var sourceId = $button.data('source-id');
        var originalText = $button.text();

        $button.text(wpbridge.i18n.testing).prop('disabled', true);

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
                    var message = status === 'healthy'
                        ? wpbridge.i18n.success + ' (' + time + 'ms)'
                        : response.data.error || wpbridge.i18n.failed;

                    alert(message);
                } else {
                    alert(response.data.message || wpbridge.i18n.failed);
                }
            },
            error: function() {
                alert(wpbridge.i18n.failed);
            },
            complete: function() {
                $button.text(originalText).prop('disabled', false);
            }
        });
    });

    // 删除源
    $(document).on('click', '.wpbridge-delete-source', function() {
        var sourceId = $(this).data('source-id');

        if (confirm(wpbridge.i18n.confirm_delete)) {
            $('#wpbridge-delete-source-id').val(sourceId);
            $('#wpbridge-delete-form').submit();
        }
    });

    // 清除缓存
    $(document).on('click', '.wpbridge-clear-cache', function() {
        var $button = $(this);
        var originalText = $button.text();

        $button.text(wpbridge.i18n.testing).prop('disabled', true);

        $.ajax({
            url: wpbridge.ajax_url,
            type: 'POST',
            data: {
                action: 'wpbridge_clear_cache',
                nonce: wpbridge.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                } else {
                    alert(response.data.message || wpbridge.i18n.failed);
                }
            },
            error: function() {
                alert(wpbridge.i18n.failed);
            },
            complete: function() {
                $button.text(originalText).prop('disabled', false);
            }
        });
    });

})(jQuery);
