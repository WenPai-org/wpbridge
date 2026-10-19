<?php
/**
 * WordPress Site Health 集成
 *
 * @package WPBridge
 */

namespace WPBridge\Core;

use WPBridge\Cache\HealthChecker;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Health 集成类
 */
class SiteHealth {

	/**
	 * 设置实例
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * 构造函数
	 *
	 * @param Settings $settings 设置实例
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->init_hooks();
	}

	/**
	 * 初始化钩子
	 */
	private function init_hooks(): void {
		// 添加健康检查测试
		add_filter( 'site_status_tests', [ $this, 'add_tests' ] );

		// 添加调试信息
		add_filter( 'debug_information', [ $this, 'add_debug_info' ] );
	}

	/**
	 * 添加健康检查测试
	 *
	 * @param array $tests 测试列表
	 * @return array
	 */
	public function add_tests( array $tests ): array {
		$tests['direct']['wpbridge_sources'] = [
			'label' => __( 'WPBridge 更新源状态', 'wpbridge' ),
			'test'  => [ $this, 'test_sources' ],
		];

		$tests['direct']['wpbridge_config'] = [
			'label' => __( 'WPBridge 配置检查', 'wpbridge' ),
			'test'  => [ $this, 'test_config' ],
		];

		$tests['direct']['wpbridge_updater_version'] = [
			'label' => __( 'WPBridge 自身更新检查', 'wpbridge' ),
			'test'  => [ $this, 'test_updater_version' ],
		];

		$tests['direct']['wpbridge_selfupdate_signature'] = [
			'label' => __( 'WPBridge 自身更新包签名校验', 'wpbridge' ),
			'test'  => [ $this, 'test_selfupdate_signature' ],
		];

		return $tests;
	}

	/**
	 * 测试更新源状态
	 *
	 * @return array
	 */
	public function test_sources(): array {
		$sources        = $this->settings->get_enabled_sources();
		$health_checker = new HealthChecker();

		$healthy        = 0;
		$degraded       = 0;
		$failed         = 0;
		$failed_sources = [];

		foreach ( $sources as $source ) {
			$status = $health_checker->check( $source );

			if ( $status->status === 'healthy' ) {
				++$healthy;
			} elseif ( $status->status === 'degraded' ) {
				++$degraded;
			} else {
				++$failed;
				$failed_sources[] = $source->name;
			}
		}

		$total = count( $sources );

		if ( $total === 0 ) {
			return [
				'label'       => __( 'WPBridge: 未配置更新源', 'wpbridge' ),
				'status'      => 'recommended',
				'badge'       => [
					'label' => __( '推荐', 'wpbridge' ),
					'color' => 'orange',
				],
				'description' => sprintf(
					'<p>%s</p>',
					__( '您尚未配置任何自定义更新源。如果您需要使用自定义更新源，请在 WPBridge 设置中添加。', 'wpbridge' )
				),
				'actions'     => sprintf(
					'<a href="%s">%s</a>',
					admin_url( 'admin.php?page=wpbridge' ),
					__( '配置更新源', 'wpbridge' )
				),
				'test'        => 'wpbridge_sources',
			];
		}

		if ( $failed > 0 ) {
			return [
				'label'       => sprintf(
					/* translators: %d: number of unavailable update sources */
					__( 'WPBridge: %d 个更新源不可用', 'wpbridge' ),
					$failed
				),
				'status'      => 'critical',
				'badge'       => [
					'label' => __( '错误', 'wpbridge' ),
					'color' => 'red',
				],
				'description' => sprintf(
					'<p>%s</p><p>%s: %s</p>',
					__( '部分更新源无法连接，这可能导致插件/主题无法正常更新。', 'wpbridge' ),
					__( '不可用的更新源', 'wpbridge' ),
					implode( ', ', $failed_sources )
				),
				'actions'     => sprintf(
					'<a href="%s">%s</a>',
					admin_url( 'admin.php?page=wpbridge&tab=diagnostics' ),
					__( '查看诊断', 'wpbridge' )
				),
				'test'        => 'wpbridge_sources',
			];
		}

		if ( $degraded > 0 ) {
			return [
				'label'       => sprintf(
					/* translators: %d: number of slow update sources */
					__( 'WPBridge: %d 个更新源响应较慢', 'wpbridge' ),
					$degraded
				),
				'status'      => 'recommended',
				'badge'       => [
					'label' => __( '警告', 'wpbridge' ),
					'color' => 'orange',
				],
				'description' => sprintf(
					'<p>%s</p>',
					__( '部分更新源响应时间较长，可能影响更新检查速度。', 'wpbridge' )
				),
				'actions'     => sprintf(
					'<a href="%s">%s</a>',
					admin_url( 'admin.php?page=wpbridge&tab=diagnostics' ),
					__( '查看诊断', 'wpbridge' )
				),
				'test'        => 'wpbridge_sources',
			];
		}

		return [
			'label'       => sprintf(
				/* translators: %d: number of healthy update sources */
				__( 'WPBridge: 所有 %d 个更新源正常', 'wpbridge' ),
				$healthy
			),
			'status'      => 'good',
			'badge'       => [
				'label' => __( '正常', 'wpbridge' ),
				'color' => 'green',
			],
			'description' => sprintf(
				'<p>%s</p>',
				__( '所有配置的更新源都可以正常连接。', 'wpbridge' )
			),
			'test'        => 'wpbridge_sources',
		];
	}

	/**
	 * 测试配置
	 *
	 * @return array
	 */
	public function test_config(): array {
		$issues = [];

		// 检查调试模式
		if ( $this->settings->is_debug() ) {
			$issues[] = __( '调试模式已启用，建议在生产环境中关闭', 'wpbridge' );
		}

		// 检查缓存时间
		$cache_ttl = $this->settings->get_cache_ttl();
		if ( $cache_ttl < 3600 ) {
			$issues[] = __( '缓存时间设置过短，可能导致频繁请求', 'wpbridge' );
		}

		// 检查备份功能
		if ( ! $this->settings->get( 'backup_enabled', true ) ) {
			$issues[] = __( '更新前备份已禁用，建议启用以便回滚', 'wpbridge' );
		}

		// 检查 ZipArchive
		if ( ! class_exists( 'ZipArchive' ) ) {
			$issues[] = __( 'PHP ZipArchive 扩展未安装，备份功能将不可用', 'wpbridge' );
		}

		if ( ! empty( $issues ) ) {
			return [
				'label'       => __( 'WPBridge: 配置需要优化', 'wpbridge' ),
				'status'      => 'recommended',
				'badge'       => [
					'label' => __( '建议', 'wpbridge' ),
					'color' => 'orange',
				],
				'description' => sprintf(
					'<p>%s</p><ul><li>%s</li></ul>',
					__( '发现以下配置问题：', 'wpbridge' ),
					implode( '</li><li>', $issues )
				),
				'actions'     => sprintf(
					'<a href="%s">%s</a>',
					admin_url( 'admin.php?page=wpbridge&tab=settings' ),
					__( '调整设置', 'wpbridge' )
				),
				'test'        => 'wpbridge_config',
			];
		}

		return [
			'label'       => __( 'WPBridge: 配置正常', 'wpbridge' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( '正常', 'wpbridge' ),
				'color' => 'green',
			],
			'description' => sprintf(
				'<p>%s</p>',
				__( 'WPBridge 配置正确，所有功能正常运行。', 'wpbridge' )
			),
			'test'        => 'wpbridge_config',
		];
	}

	/**
	 * 测试插件头版本号与 WPBRIDGE_VERSION 是否一致
	 *
	 * 自更新器在两者不一致或插件头版本号为空时会跳过云桥自身的更新检查，
	 * 这里把这种静默状态显示出来。只读插件文件头，不存储任何状态。
	 *
	 * @return array
	 */
	public function test_updater_version(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data    = get_plugin_data( WPBRIDGE_FILE, false, false );
		$header_version = sanitize_text_field( (string) ( $plugin_data['Version'] ?? '' ) );

		// 网络启用时子站管理员在子站插件页无法重装，链接改指网络后台.
		$plugins_url = admin_url( 'plugins.php' );
		if ( is_multisite() && is_plugin_active_for_network( WPBRIDGE_BASENAME ) ) {
			$plugins_url = network_admin_url( 'plugins.php' );
		}

		if ( '' !== $header_version && WPBRIDGE_VERSION === $header_version ) {
			return [
				'label'       => __( 'WPBridge: 自身更新检查正常', 'wpbridge' ),
				'status'      => 'good',
				'badge'       => [
					'label' => __( '正常', 'wpbridge' ),
					'color' => 'green',
				],
				'description' => sprintf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: WPBridge version */
							__( '插件头版本号与运行中的版本号一致（%s），云桥自身的更新检查正常进行。', 'wpbridge' ),
							WPBRIDGE_VERSION
						)
					)
				),
				'test'        => 'wpbridge_updater_version',
			];
		}

		return [
			'label'       => __( 'WPBridge: 自身更新检查已暂停', 'wpbridge' ),
			'status'      => 'recommended',
			'badge'       => [
				'label' => __( '建议', 'wpbridge' ),
				'color' => 'orange',
			],
			'description' => sprintf(
				'<p>%s</p><p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: version in the plugin file header, 2: running WPBRIDGE_VERSION */
						__( '插件主文件头部的版本号（%1$s）与运行中的版本号（%2$s）不一致或为空，云桥自身的更新检查和插件详情请求已暂停，此站点将收不到 WPBridge 的更新。', 'wpbridge' ),
						'' === $header_version ? __( '空', 'wpbridge' ) : $header_version,
						WPBRIDGE_VERSION
					)
				),
				esc_html__( '请重新安装当前版本的 WPBridge，或检查 wpbridge.php 是否被手动修改、只部署了部分文件或已损坏。', 'wpbridge' )
			),
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $plugins_url ),
				esc_html__( '前往插件页面', 'wpbridge' )
			),
			'test'        => 'wpbridge_updater_version',
		];
	}

	/**
	 * 自更新包签名校验状态
	 *
	 * 看 wp-config.php 里的 WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS（只要 defined 就是
	 * 严格模式）和 wenpai_updater_override 接管状态，不发请求、不存状态。
	 *
	 * @return array
	 */
	public function test_selfupdate_signature(): array {
		$good_badge = [
			'label' => __( '正常', 'wpbridge' ),
			'color' => 'green',
		];

		// 被 wp-china-yes 集中更新接管时，WPBridge_Updater 不注册钩子，这里的策略不生效.
		$overridden = (bool) apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'wenpai_updater_override',
			false,
			dirname( WPBRIDGE_BASENAME )
		);
		if ( $overridden ) {
			return [
				'label'       => __( 'WPBridge: 自身更新已由集中更新接管', 'wpbridge' ),
				'status'      => 'good',
				'badge'       => $good_badge,
				'description' => sprintf(
					'<p>%s</p>',
					esc_html__( 'WPBridge 自身的更新由 wp-china-yes 集中更新接管，本插件的自更新签名策略（包括 WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS）不在此生效。', 'wpbridge' )
				),
				'test'        => 'wpbridge_selfupdate_signature',
			];
		}

		if ( defined( 'WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS' ) ) {
			$valid_keys = is_array( WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS ) && class_exists( '\\WPBridge\\Security\\PackageIntegrityVerifier' )
				? \WPBridge\Security\PackageIntegrityVerifier::trusted_keys( WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS )
				: [];

			if ( [] === $valid_keys ) {
				return [
					'label'       => __( '配置错误：WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS 无有效公钥，自更新已暂停', 'wpbridge' ),
					'status'      => 'recommended',
					'badge'       => [
						'label' => __( '建议', 'wpbridge' ),
						'color' => 'orange',
					],
					'description' => sprintf(
						'<p>%s</p><p>%s</p>',
						esc_html__( '配置错误：WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS 无有效公钥，自更新已暂停', 'wpbridge' ),
						esc_html__( '常量已定义，所以严格验签已开启，但其中没有一个格式有效的 Ed25519 公钥（应为以 kid 为键的数组）。在修正或删除该常量之前，WPBridge 不会提供或安装自身的更新。', 'wpbridge' )
					),
					'test'        => 'wpbridge_selfupdate_signature',
				];
			}

			return [
				'label'       => __( 'WPBridge: 自身更新包严格验签已启用', 'wpbridge' ),
				'status'      => 'good',
				'badge'       => $good_badge,
				'description' => sprintf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %d: number of valid configured public keys */
							__( '已在 wp-config.php 配置 WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS（%d 个公钥）。WPBridge 自身的更新包必须带有效的 Ed25519 签名和 SHA-256，缺少或无效时会拒绝更新。', 'wpbridge' ),
							count( $valid_keys )
						)
					)
				),
				'test'        => 'wpbridge_selfupdate_signature',
			];
		}

		return [
			'label'       => __( 'WPBridge: 自身更新包签名校验（未启用严格模式）', 'wpbridge' ),
			'status'      => 'good',
			'badge'       => $good_badge,
			'description' => sprintf(
				'<p>%s</p><p>%s</p>',
				esc_html__( 'WPBridge 自身的更新包目前不强制要求签名：更新服务器提供 SHA-256 时会校验摘要；带签名且已配置 WPBRIDGE_ARTIFACT_PUBLIC_KEYS 时会验证签名，无效即拒绝；没有签名时照常更新并记录日志。', 'wpbridge' ),
				esc_html__( '更新服务器开始发布签名后，可在 wp-config.php 定义 WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS 启用严格验签，之后缺少签名或签名无效的自更新都会被拒绝。', 'wpbridge' )
			),
			'test'        => 'wpbridge_selfupdate_signature',
		];
	}

	/**
	 * 添加调试信息
	 *
	 * @param array $info 调试信息
	 * @return array
	 */
	public function add_debug_info( array $info ): array {
		$sources         = $this->settings->get_sources();
		$enabled_sources = $this->settings->get_enabled_sources();
		$version_lock    = VersionLock::get_instance();
		$backup_manager  = BackupManager::get_instance();

		$info['wpbridge'] = [
			'label'  => 'WPBridge',
			'fields' => [
				'version'         => [
					'label' => __( '版本', 'wpbridge' ),
					'value' => WPBRIDGE_VERSION,
				],
				'total_sources'   => [
					'label' => __( '总更新源数', 'wpbridge' ),
					'value' => count( $sources ),
				],
				'enabled_sources' => [
					'label' => __( '已启用更新源', 'wpbridge' ),
					'value' => count( $enabled_sources ),
				],
				'locked_items'    => [
					'label' => __( '已锁定项目数', 'wpbridge' ),
					'value' => count( $version_lock->get_all() ),
				],
				'backup_size'     => [
					'label' => __( '备份总大小', 'wpbridge' ),
					'value' => size_format( $backup_manager->get_total_size() ),
				],
				'debug_mode'      => [
					'label' => __( '调试模式', 'wpbridge' ),
					'value' => $this->settings->is_debug() ? __( '已启用', 'wpbridge' ) : __( '已禁用', 'wpbridge' ),
				],
				'cache_ttl'       => [
					'label' => __( '缓存时间', 'wpbridge' ),
					'value' => human_time_diff( 0, $this->settings->get_cache_ttl() ),
				],
				'backup_enabled'  => [
					'label' => __( '更新前备份', 'wpbridge' ),
					'value' => $this->settings->get( 'backup_enabled', true ) ? __( '已启用', 'wpbridge' ) : __( '已禁用', 'wpbridge' ),
				],
			],
		];

		return $info;
	}
}
