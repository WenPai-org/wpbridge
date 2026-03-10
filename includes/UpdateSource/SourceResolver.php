<?php
/**
 * 更新源解析器
 *
 * 连接项目配置（方案 B）与更新处理器
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource;

use WPBridge\Core\DefaultsManager;
use WPBridge\Core\ItemSourceManager;
use WPBridge\Core\SourceRegistry;
use WPBridge\Security\Encryption;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 更新源解析器类
 */
class SourceResolver {

    /**
     * 源注册表
     *
     * @var SourceRegistry
     */
    private SourceRegistry $source_registry;

    /**
     * 项目配置管理器
     *
     * @var ItemSourceManager
     */
    private ItemSourceManager $item_manager;

    /**
     * 默认规则管理器
     *
     * @var DefaultsManager
     */
    private DefaultsManager $defaults_manager;

    /**
     * 构造函数
     */
    public function __construct() {
        $this->source_registry  = new SourceRegistry();
        $this->item_manager     = new ItemSourceManager( $this->source_registry );
        $this->defaults_manager = new DefaultsManager();
    }

    /**
     * 解析指定项目的更新源
     *
     * @param string $item_key  项目键
     * @param string $slug      插件/主题 slug
     * @param string $item_type 项目类型
     * @return array{mode:string,sources:SourceModel[],has_wporg:bool}
     */
    public function resolve( string $item_key, string $slug, string $item_type ): array {
        $config = $this->item_manager->get( $item_key );
        $mode   = $config['mode'] ?? ItemSourceManager::MODE_DEFAULT;

        if ( $mode === ItemSourceManager::MODE_DISABLED ) {
            return [
                'mode'      => $mode,
                'sources'   => [],
                'has_wporg' => false,
            ];
        }

        $sources = $this->item_manager->get_effective_sources( $item_key, $this->defaults_manager );

        if ( empty( $sources ) ) {
            return [
                'mode'      => $mode,
                'sources'   => [],
                'has_wporg' => false,
            ];
        }

        $has_wporg = false;
        $models = [];
        foreach ( $sources as $source ) {
            if ( ( $source['type'] ?? '' ) === SourceRegistry::TYPE_WPORG ) {
                $has_wporg = true;
            }

            $model = $this->convert_source( $source, $item_type, $slug, $mode === ItemSourceManager::MODE_CUSTOM );
            if ( null !== $model ) {
                $models[] = $model;
            }
        }

        return [
            'mode'      => $mode,
            'sources'   => $models,
            'has_wporg' => $has_wporg,
        ];
    }

    /**
     * 将 SourceRegistry 记录转换为 SourceModel
     *
     * @param array  $source     源配置
     * @param string $item_type  项目类型
     * @param string $slug       项目 slug
     * @param bool   $force_slug 是否强制绑定到 slug
     * @return SourceModel|null
     */
    private function convert_source( array $source, string $item_type, string $slug, bool $force_slug ): ?SourceModel {
        $type = $this->map_type( $source );
        if ( null === $type ) {
            return null;
        }

        $api_url = $source['api_url'] ?? '';
        if ( empty( $api_url ) && $type !== SourceType::VENDOR ) {
            Logger::warning( '源缺少 API URL', [ 'source' => $source['source_key'] ?? '' ] );
            return null;
        }

        $id = $source['source_key'] ?? '';
        if ( empty( $id ) ) {
            return null;
        }

        $model = new SourceModel();
        $model->id        = $id;
        $model->name      = $source['name'] ?? $id;
        $model->type      = $type;
        $model->api_url   = $api_url;
        $model->item_type = $item_type;
        $model->slug      = $force_slug ? $slug : '';
        $model->enabled   = ! empty( $source['enabled'] );
        $model->priority  = (int) ( $source['priority'] ?? $source['default_priority'] ?? 50 );
        $model->is_preset = ! empty( $source['is_preset'] );
        $model->metadata  = [
            'auth_scheme'        => $source['auth_type'] ?? '',
            'signature_required' => ! empty( $source['signature_required'] ),
            'vendor_id'          => $source['metadata']['vendor_id'] ?? '',
        ];

        $secret_ref = $source['auth_secret_ref'] ?? '';
        if ( ! empty( $secret_ref ) ) {
            $secret = get_option( 'wpbridge_secret_' . $secret_ref, '' );
            if ( ! empty( $secret ) ) {
                $model->auth_token = Encryption::encrypt( $secret );
            }
        }

        return $model;
    }

    /**
     * 映射源类型
     *
     * @param array $source 源配置
     * @return string|null
     */
    private function map_type( array $source ): ?string {
        $type = $source['type'] ?? '';

        switch ( $type ) {
            case SourceRegistry::TYPE_WPORG:
                return null;

            case SourceRegistry::TYPE_MIRROR:
                return SourceType::ARKPRESS;

            case SourceRegistry::TYPE_FAIR:
                return SourceType::FAIR;

            case SourceRegistry::TYPE_JSON:
                return SourceType::JSON;

            case SourceRegistry::TYPE_ARKPRESS:
                return SourceType::ARKPRESS;

            case SourceRegistry::TYPE_GIT:
                return $this->resolve_git_type( $source['api_url'] ?? '' );

            case SourceRegistry::TYPE_CUSTOM:
                return $this->guess_custom_type( $source['api_url'] ?? '' );

            case SourceRegistry::TYPE_VENDOR:
                return SourceType::VENDOR;

            default:
                return null;
        }
    }

    /**
     * 解析 Git 类型
     *
     * @param string $url 源 URL
     * @return string
     */
    private function resolve_git_type( string $url ): string {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

        if ( strpos( $host, 'github.com' ) !== false ) {
            return SourceType::GITHUB;
        }

        if ( strpos( $host, 'gitlab' ) !== false ) {
            return SourceType::GITLAB;
        }

        if ( strpos( $host, 'gitee.com' ) !== false ) {
            return SourceType::GITEE;
        }

        if ( strpos( $host, 'wenpai' ) !== false || strpos( $host, 'feicode' ) !== false ) {
            return SourceType::WENPAI_GIT;
        }

        return SourceType::WENPAI_GIT;
    }

    /**
     * 推断自定义类型
     *
     * @param string $url 源 URL
     * @return string
     */
    private function guess_custom_type( string $url ): string {
        if ( preg_match( '/\.zip$/i', $url ) ) {
            return SourceType::ZIP;
        }

        return SourceType::JSON;
    }
}
