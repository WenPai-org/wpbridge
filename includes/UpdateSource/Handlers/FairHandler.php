<?php
/**
 * FAIR 处理器
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource\Handlers;

use WPBridge\Core\SourceRegistry;
use WPBridge\FAIR\FairSourceAdapter;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FAIR 协议处理器
 */
class FairHandler extends AbstractHandler {

    /**
     * 获取能力列表
     *
     * @return array
     */
    public function get_capabilities(): array {
        return [
            'auth'      => 'token',
            'version'   => 'fair',
            'download'  => 'direct',
            'signature' => 'ed25519',
        ];
    }

    /**
     * 检查更新
     *
     * @param string $slug    插件/主题 slug
     * @param string $version 当前版本
     * @return UpdateInfo|null
     */
    public function check_update( string $slug, string $version ): ?UpdateInfo {
        $adapter = $this->get_adapter();

        $data = $this->source->item_type === 'theme'
            ? $adapter->check_theme_update( $slug, $version )
            : $adapter->check_plugin_update( $slug, $version );

        if ( null === $data ) {
            return null;
        }

        $info = UpdateInfo::from_array( $data );
        $info->slug = $slug;

        return $info;
    }

    /**
     * 获取项目信息
     *
     * @param string $slug 插件/主题 slug
     * @return array|null
     */
    public function get_info( string $slug ): ?array {
        $adapter = $this->get_adapter();

        return $this->source->item_type === 'theme'
            ? $adapter->get_theme_info( $slug )
            : $adapter->get_plugin_info( $slug );
    }

    /**
     * 获取 FAIR 适配器
     *
     * @return FairSourceAdapter
     */
    private function get_adapter(): FairSourceAdapter {
        return new FairSourceAdapter( $this->build_source_config() );
    }

    /**
     * 构建 FAIR 源配置
     *
     * @return array
     */
    private function build_source_config(): array {
        $headers = [
            'Accept' => 'application/json',
        ];

        $token = $this->get_auth_token();
        if ( ! empty( $token ) ) {
            $scheme = $this->source->metadata['auth_scheme'] ?? '';
            if ( $scheme === 'bearer' ) {
                $headers['Authorization'] = 'Bearer ' . $token;
            } elseif ( $scheme === 'basic' ) {
                $headers['Authorization'] = 'Basic ' . base64_encode( $token );
            } else {
                $headers['Authorization'] = 'Token ' . $token;
            }
        }

        return [
            'api_url'            => $this->source->api_url,
            'auth_type'          => SourceRegistry::AUTH_NONE,
            'auth_secret_ref'    => '',
            'signature_required' => (bool) ( $this->source->metadata['signature_required'] ?? false ),
            'headers'            => $headers,
        ];
    }
}
