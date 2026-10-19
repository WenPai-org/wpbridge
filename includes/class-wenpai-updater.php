<?php
/**
 * WenPai 插件更新器
 *
 * 为文派系插件提供自建更新服务支持。
 * 通过文派云桥 (WenPai Bridge) 检查插件更新，
 * 利用 WordPress 5.8+ 的 Update URI 机制。
 *
 * 当 wp-china-yes 插件激活并启用集中更新时，
 * 此更新器会通过 wenpai_updater_override filter 自动让位。
 *
 * @package  WenPai
 * @version  1.0.0
 * @requires WordPress 5.8+
 * @requires PHP 7.4+
 * @link     https://feicode.com/WenPai-org
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WPBridge_Updater' ) ) {
	return;
}

/**
 * WPBridge 自更新器.
 *
 * 每个文派插件捆绑一份本文件，类名必须各插件唯一，
 * 不得共用同一个全局类名，否则多插件同装时会致命冲突.
 */
class WPBridge_Updater {

	/**
	 * 更新器版本号。
	 *
	 * @var string
	 */
	const VERSION = '1.2.1';

	/**
	 * 云桥 API 地址。
	 *
	 * @var string
	 */
	const API_URL = 'https://updates.wenpai.net/api/v1';

	/**
	 * 插件主文件 basename（如 wpslug/wpslug.php）。
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * 插件 slug（如 wpslug）。
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * 当前插件版本。
	 *
	 * @var string
	 */
	private $version;

	/**
	 * 初始化更新器。
	 *
	 * @param string $plugin_file 插件主文件路径（plugin_basename 格式）.
	 * @param string $version     当前插件版本号.
	 */
	public function __construct( string $plugin_file, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->slug        = dirname( $plugin_file );
		$this->version     = $version;

		// 检查是否被 wp-china-yes 集中更新接管.
		// 钩子名是文派插件间的共用契约，wp-china-yes 据此接管更新，
		// 加插件前缀会断掉对接，故豁免前缀规则.
		$is_overridden = apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'wenpai_updater_override',
			false,
			$this->slug
		);

		if ( ! $is_overridden ) {
			$this->register_hooks();
		}
	}

	/**
	 * 注册 WordPress hooks。
	 */
	private function register_hooks(): void {
		if ( class_exists( '\\WPBridge\\Security\\PackageIntegrityVerifier' ) ) {
			\WPBridge\Security\PackageIntegrityVerifier::init();
		}
		// Update URI: https://updates.wenpai.net 触发此 filter.
		add_filter(
			'update_plugins_updates.wenpai.net',
			[ $this, 'check_update' ],
			10,
			4
		);

		// 插件详情弹窗.
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );

		// 配置了本地公钥时，自更新包下载前必须有带签名要求的完整性记录.
		// 优先级 9：先于 PackageIntegrityVerifier::verify（10）.
		add_filter( 'upgrader_pre_download', [ $this, 'enforce_self_update_record' ], 9, 2 );
	}

	/**
	 * 检查插件更新。
	 *
	 * WordPress 在检查更新时，对声明了 Update URI 的插件
	 * 触发 update_plugins_{hostname} filter。
	 *
	 * @param array|false $update      当前更新数据.
	 * @param array       $plugin_data 插件头信息.
	 * @param string      $plugin_file 插件文件路径.
	 * @param string[]    $locales     语言列表.
	 * @return object|false 更新数据或 false。
	 */
	public function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $locales 由 WordPress 的 update_plugins_{hostname} filter 签名要求，必须保留.
		if ( $plugin_file !== $this->plugin_file ) {
			return $update;
		}

		// WordPress may run the updater-complete hooks after replacing this
		// plugin's files.  The updater object still represents the version
		// loaded at request start, so it must not execute against a different
		// on-disk version during an upgrade or rollback transition.
		$disk_version = sanitize_text_field( (string) ( $plugin_data['Version'] ?? '' ) );
		if ( '' === $disk_version || $disk_version !== $this->version ) {
			$this->log_version_guard_skip( $disk_version, 'check_update' );
			return $update;
		}

		$response = $this->api_request(
			'update-check',
			[
				'plugins' => [
					$this->plugin_file => [
						'Version' => $this->version,
					],
				],
			]
		);

		if ( is_wp_error( $response ) || empty( $response['plugins'][ $this->plugin_file ] ) ) {
			return $update;
		}

		$data               = $response['plugins'][ $this->plugin_file ];
		$new_version        = sanitize_text_field( (string) ( $data['version'] ?? '' ) );
		$package            = esc_url_raw( (string) ( $data['package'] ?? '' ) );
		$sha256             = strtolower( sanitize_text_field( (string) ( $data['sha256'] ?? $data['checksum_sha256'] ?? '' ) ) );
		$sha256_valid       = 1 === preg_match( '/^[a-f0-9]{64}$/', $sha256 );
		// 三种模式，都只由本站 wp-config.php 决定，服务端字段只能加严、不能放宽：
		// - 定义了 WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS：严格模式，必须带有效签名和
		// SHA-256，否则拒绝（防止响应去掉签名字段把校验降级）.
		// - 只定义了共用的 WPBRIDGE_ARTIFACT_PUBLIC_KEYS（商业源/Hub-Spoke 用）：
		// 带了签名就用它验证，无效或 kid 不受信即拒绝；没带签名照旧放行并记警告.
		// - 都没定义：无法验证签名，放行并记警告.
		// 任何模式下，服务端给了 SHA-256 就必须与下载的包一致.
		$self_keys          = $this->self_update_public_keys();
		$strict             = [] !== $self_keys;
		$public_keys        = $strict ? $self_keys : $this->artifact_public_keys();
		$can_verify         = [] !== $public_keys;
		$signature_required = $strict || ( $can_verify && ! empty( $data['signature_required'] ) );
		$signature_scheme   = strtolower( sanitize_text_field( (string) ( $data['signature_scheme'] ?? '' ) ) );
		$signature_kid      = sanitize_text_field( (string) ( $data['signature_kid'] ?? '' ) );
		$signature          = sanitize_text_field( (string) ( $data['signature'] ?? '' ) );
		$artifact_file      = sanitize_file_name( (string) ( $data['artifact_file'] ?? '' ) );
		$artifact_size      = max( 0, (int) ( $data['artifact_size'] ?? 0 ) );
		$artifact_signed_at = sanitize_text_field( (string) ( $data['artifact_signed_at'] ?? '' ) );

		// 失败时保留 WordPress 已有结果；拒绝降级、同版本和非 HTTPS 安装包.
		if ( ! version_compare( $new_version, $this->version, '>' ) || 'https' !== wp_parse_url( $package, PHP_URL_SCHEME ) ) {
			return $update;
		}

		$has_signature     = '' !== $signature || '' !== $signature_scheme || '' !== $signature_kid;
		$verify_signature  = $signature_required || ( $can_verify && $has_signature );
		$integrity_context = [
			'plugin'          => $this->plugin_file,
			'version'         => $new_version,
			'mode'            => $strict ? 'strict' : ( $can_verify ? 'shared' : 'none' ),
			'server_required' => ! empty( $data['signature_required'] ),
			'has_sha256'      => $sha256_valid,
			'has_signature'   => $has_signature,
			'signature_kid'   => $signature_kid,
		];

		// 服务端给了摘要但格式不对：无法保证下载校验，不提供这次更新.
		if ( '' !== $sha256 && ! $sha256_valid ) {
			$this->log_integrity_event( 'error', '自更新包的 SHA-256 格式无效，已拒绝提供这次更新', $integrity_context );
			return $update;
		}

		if ( ! class_exists( '\\WPBridge\\Security\\PackageIntegrityVerifier' ) ) {
			// 无法校验时，需要校验的更新一律不提供.
			if ( $verify_signature || $sha256_valid ) {
				return $update;
			}
		} else {
			$metadata = [
				'slug'    => $this->slug,
				'version' => $new_version,
			];
			if ( $verify_signature ) {
				$metadata += [
					'artifact_file'        => $artifact_file,
					'artifact_signed_at'   => $artifact_signed_at,
					'artifact_size'        => $artifact_size,
					'signature_scheme'     => $signature_scheme,
					'signature_kid'        => $signature_kid,
					'signature'            => $signature,
					'signature_required'   => $signature_required,
					'artifact_public_keys' => $public_keys,
				];
			}
			$remembered = \WPBridge\Security\PackageIntegrityVerifier::remember( $package, $sha256, DAY_IN_SECONDS, $metadata );
			if ( ( $verify_signature || $sha256_valid ) && ! $this->integrity_recorded( $remembered, $package, $sha256, $verify_signature ? $signature : '' ) ) {
				$this->log_integrity_event( 'error', '自更新包缺少有效的签名或 SHA-256，已拒绝提供这次更新', $integrity_context );
				return $update;
			}
		}

		if ( ! $verify_signature ) {
			$this->log_integrity_event(
				'warning',
				$has_signature ? '未配置公钥，签名未校验，按旧方式提供更新' : '自更新包没有签名，按旧方式提供更新',
				$integrity_context
			);
		}

		return (object) [
			'id'                 => $data['id'] ?? '',
			'slug'               => $data['slug'] ?? $this->slug,
			'plugin'             => $this->plugin_file,
			'version'            => $new_version,
			'new_version'        => $new_version,
			'url'                => $data['url'] ?? '',
			'package'            => $package,
			'icons'              => $data['icons'] ?? [],
			'banners'            => $data['banners'] ?? [],
			'requires'           => $data['requires'] ?? '',
			'tested'             => $data['tested'] ?? '',
			'requires_php'       => $data['requires_php'] ?? '',
			'sha256'             => preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ? $sha256 : '',
			'signature_scheme'   => $signature_scheme,
			'signature_kid'      => $signature_kid,
			'signature'          => $signature,
			'signature_required' => $signature_required,
			'artifact_file'      => $artifact_file,
			'artifact_signed_at' => $artifact_signed_at,
			'artifact_size'      => $artifact_size,
		];
	}

	/**
	 * 判断完整性记录是否已写入且与这次响应一致.
	 *
	 * set_site_transient() 在值没变时返回 false，所以 remember() 返回 false
	 * 不一定是失败：同一包地址已有内容相同的记录时也算写入成功.
	 *
	 * @param bool   $remembered remember() 的返回值.
	 * @param string $package    安装包地址.
	 * @param string $sha256     响应里的 SHA-256.
	 * @param string $signature  需要验签时的签名，否则为空.
	 * @return bool
	 */
	private function integrity_recorded( bool $remembered, string $package, string $sha256, string $signature ): bool {
		if ( $remembered ) {
			return true;
		}
		$record = \WPBridge\Security\PackageIntegrityVerifier::expected_integrity( $package );
		return isset( $record['sha256'], $record['signature'] )
			&& hash_equals( (string) $record['sha256'], $sha256 )
			&& hash_equals( (string) $record['signature'], $signature );
	}

	/**
	 * 本插件更新包需要校验时，拒绝没有完整性记录的下载.
	 *
	 * 记录过期或被对象缓存淘汰时，PackageIntegrityVerifier::verify() 会按旧
	 * 来源放行，这里把这个缺口堵上。需要记录的情况：严格模式；WordPress 缓存的
	 * 更新带 SHA-256；或带签名且本站有可用公钥。只处理本插件当前缓存的更新包.
	 *
	 * @param mixed $reply   之前的 upgrader_pre_download 结果.
	 * @param mixed $package 安装包地址.
	 * @return mixed
	 */
	public function enforce_self_update_record( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}

		$updates = get_site_transient( 'update_plugins' );
		$offer   = is_object( $updates ) && isset( $updates->response[ $this->plugin_file ] ) ? $updates->response[ $this->plugin_file ] : null;
		if ( ! is_object( $offer ) || ! isset( $offer->package ) || $offer->package !== $package ) {
			return $reply;
		}

		$strict     = [] !== $this->self_update_public_keys();
		$can_verify = $strict || [] !== $this->artifact_public_keys();
		$needs_sig  = $strict || ( $can_verify && '' !== (string) ( $offer->signature ?? '' ) );
		$needs_sha  = '' !== (string) ( $offer->sha256 ?? '' );
		if ( ! $needs_sig && ! $needs_sha ) {
			return $reply;
		}

		$record = class_exists( '\\WPBridge\\Security\\PackageIntegrityVerifier' )
			? \WPBridge\Security\PackageIntegrityVerifier::expected_integrity( $package )
			: [];
		if ( [] === $record || ( $needs_sig && '' === (string) ( $record['signature'] ?? '' ) ) || ( $strict && empty( $record['signature_required'] ) ) ) {
			$this->log_integrity_event(
				'error',
				'自更新包没有完整性记录，已中止安装',
				[
					'plugin'  => $this->plugin_file,
					'version' => (string) ( $offer->new_version ?? '' ),
				]
			);
			return new WP_Error(
				'wpbridge_selfupdate_signature_record_missing',
				__( 'WPBridge 更新包缺少完整性校验记录，已中止安装。请重新检查更新后再试。', 'wpbridge' )
			);
		}

		return $reply;
	}

	/**
	 * 记录完整性相关日志，只使用本次请求里已经加载的 Logger 和 Settings.
	 *
	 * @param string $level   error 或 warning.
	 * @param string $message 日志内容.
	 * @param array  $context 上下文.
	 */
	private function log_integrity_event( string $level, string $message, array $context ): void {
		if ( ! class_exists( '\\WPBridge\\Core\\Logger', false ) || ! class_exists( '\\WPBridge\\Core\\Settings', false ) ) {
			return;
		}
		if ( 'error' === $level ) {
			\WPBridge\Core\Logger::error( $message, $context );
			return;
		}
		\WPBridge\Core\Logger::warning( $message, $context );
	}

	/**
	 * 自更新专用公钥（严格模式），来自 wp-config.php 的 WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS.
	 *
	 * 插件不自带默认公钥。非空即视为站点明确要求自更新严格验签.
	 *
	 * @return array
	 */
	private function self_update_public_keys(): array {
		if ( ! defined( 'WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS' ) || ! is_array( WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS ) ) {
			return [];
		}
		return WPBRIDGE_SELF_UPDATE_PUBLIC_KEYS;
	}

	/**
	 * Shared artifact trust roots configured by deployment, indexed by key id.
	 *
	 * 来自 wp-config.php 的 WPBRIDGE_ARTIFACT_PUBLIC_KEYS，主要给商业源和
	 * Hub-Spoke 用。自更新只在响应带签名时用它验证，不据此强制要求签名.
	 *
	 * @return array
	 */
	private function artifact_public_keys(): array {
		if ( ! defined( 'WPBRIDGE_ARTIFACT_PUBLIC_KEYS' ) || ! is_array( WPBRIDGE_ARTIFACT_PUBLIC_KEYS ) ) {
			return [];
		}
		return WPBRIDGE_ARTIFACT_PUBLIC_KEYS;
	}

	/**
	 * 插件详情弹窗数据。
	 *
	 * 当用户在 WP 后台点击"查看详情"时触发。
	 *
	 * @param false|object|array $result 当前结果.
	 * @param string             $action API 动作.
	 * @param object             $args   请求参数.
	 * @return false|object 插件信息或 false。
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		// 与 check_update() 相同的版本号守卫：插件文件已被替换或插件头损坏时，
		// 内存中的更新器不得再发网络请求，直接走下面的本地兜底.
		$plugin_path  = WP_PLUGIN_DIR . '/' . $this->plugin_file;
		$disk_version = $this->disk_version( $plugin_path );
		$response     = null;
		if ( '' !== $disk_version && $disk_version === $this->version ) {
			$response = $this->api_request( "plugins/{$this->slug}/info" );
		} else {
			$this->log_version_guard_skip( $disk_version, 'plugin_info' );
		}

		if ( is_array( $response ) && ! isset( $response['error'] ) && ! empty( $response['name'] ) ) {
			$info                = new stdClass();
			$info->name          = $response['name'];
			$info->slug          = $response['slug'] ?? $this->slug;
			$info->version       = $response['version'] ?? '';
			$info->author        = $response['author'] ?? '';
			$info->homepage      = $response['homepage'] ?? '';
			$info->download_link = $response['download_link'] ?? '';
			$info->requires      = $response['requires'] ?? '';
			$info->tested        = $response['tested'] ?? '';
			$info->requires_php  = $response['requires_php'] ?? '';
			$info->last_updated  = $response['last_updated'] ?? '';
			$info->icons         = $response['icons'] ?? [];
			$info->banners       = $response['banners'] ?? [];
			$info->sections      = array_map( [ $this, 'markdown_to_html' ], $response['sections'] ?? [] );
			$info->external      = true;

			return $info;
		}

		// API 不可用、版本号守卫拦截或插件未注册时，用本地插件头信息兜底.
		if ( ! file_exists( $plugin_path ) ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( $plugin_path );

		$info               = new stdClass();
		$info->name         = $plugin_data['Name'] ?? $this->slug;
		$info->slug         = $this->slug;
		$info->version      = '' !== $disk_version ? $disk_version : $this->version;
		$info->author       = $plugin_data['AuthorName'] ?? '';
		$info->homepage     = $plugin_data['PluginURI'] ?? '';
		$info->requires     = $plugin_data['RequiresWP'] ?? '';
		$info->requires_php = $plugin_data['RequiresPHP'] ?? '';
		$info->sections     = [
			'description' => $plugin_data['Description'] ?? '',
		];
		$info->external     = true;

		return $info;
	}

	/**
	 * 读取磁盘上插件主文件头部的版本号.
	 *
	 * 只解析文件头，不翻译、不加标记，也不加载任何类.
	 *
	 * @param string $plugin_path 插件主文件绝对路径.
	 * @return string 版本号；文件不存在或版本号为空时返回空字符串.
	 */
	private function disk_version( string $plugin_path ): string {
		if ( ! file_exists( $plugin_path ) ) {
			return '';
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( $plugin_path, false, false );
		return sanitize_text_field( (string) ( $plugin_data['Version'] ?? '' ) );
	}

	/**
	 * 版本号守卫跳过网络请求时记一条 debug 日志，同一对版本号每天最多一条.
	 *
	 * 只使用本次请求里已经加载的 Logger 和 Settings（class_exists 第二个参数为
	 * false，不触发自动加载），避免在插件文件切换期间从磁盘加载另一个版本的类：
	 * Logger 在 $settings 为空时会 new Settings()，所以两个类都要先确认已加载.
	 * 调试日志关闭时直接返回，不写去重 transient，这样当天打开调试模式后
	 * 第一次跳过就能记下日志.
	 *
	 * 读版本的来源有意不同：check_update() 用 WordPress 传入的 $plugin_data
	 * （get_plugins 缓存），plugin_info() 和 Site Health 直接读磁盘文件头，
	 * 缓存过期前两者可能短暂不一致.
	 *
	 * @param string $disk_version 磁盘上插件头的版本号（可能为空）.
	 * @param string $context      触发位置：check_update 或 plugin_info.
	 */
	private function log_version_guard_skip( string $disk_version, string $context ): void {
		if ( ! class_exists( '\\WPBridge\\Core\\Logger', false ) || ! class_exists( '\\WPBridge\\Core\\Settings', false ) ) {
			return;
		}
		if ( ! method_exists( '\\WPBridge\\Core\\Logger', 'is_enabled' ) || ! \WPBridge\Core\Logger::is_enabled( 'debug' ) ) {
			return;
		}

		$key = 'wpbridge_updater_vguard_' . md5( $this->plugin_file . '|' . $disk_version . '|' . $this->version );
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );

		\WPBridge\Core\Logger::debug(
			'自更新器版本号不一致，已跳过云桥自身的更新请求',
			[
				'plugin'         => $this->plugin_file,
				'header_version' => '' === $disk_version ? '(empty)' : $disk_version,
				'loaded_version' => $this->version,
				'context'        => $context,
			]
		);
	}

	/**
	 * 向云桥 API 发送请求。
	 *
	 * @param string     $endpoint API 端点（不含 /api/v1/ 前缀）.
	 * @param array|null $body     POST 请求体（null 则用 GET）.
	 * @return array|\WP_Error 解码后的响应或错误。
	 */
	private function api_request( string $endpoint, ?array $body = null ) {
		if ( ! class_exists( '\\WPBridge\\Security\\SafeHttpClient' ) ) {
			return new WP_Error(
				'wpbridge_http_client_unavailable',
				'WPBridge HTTP client is unavailable during the plugin file transition.'
			);
		}

		$url = self::API_URL . '/' . ltrim( $endpoint, '/' );

		$args = [
			'timeout' => 10,
			'headers' => [
				'Accept' => 'application/json',
			],
		];

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			$args['method']                  = 'POST';
			$response                        = \WPBridge\Security\SafeHttpClient::request( $url, $args );
		} else {
			$args['method'] = 'GET';
			$response       = \WPBridge\Security\SafeHttpClient::request( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'wenpai_bridge_error',
				sprintf( 'WenPai Bridge API returned %d', $code )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'wenpai_bridge_parse_error',
				'Invalid JSON response from WenPai Bridge'
			);
		}

		return $data;
	}

	/**
	 * 将 Markdown 文本转换为 HTML。
	 *
	 * 仅处理 API 返回的 changelog 中常见的 Markdown 子集：
	 * 标题、列表、加粗、斜体、行内代码、链接。
	 *
	 * @param string $text Markdown 文本.
	 * @return string HTML。
	 */
	private function markdown_to_html( string $text ): string {
		if ( empty( $text ) ) {
			return '';
		}

		if ( 0 === strpos( trim( $text ), '<' ) ) {
			return wp_kses_post( $text );
		}

		// 截断 feicode-ai 自动追加的 AI 摘要（以 HTML 注释标记为界）.
		$text = preg_replace( '/---\s*\n\s*<!--\s*feicode-ai.*$/s', '', $text );

		// 去掉 Checksums 表格（不适合在 WordPress 弹窗展示）.
		$text = preg_replace( '/^###?\s+Checksums\s*\n+(\|.*\n)+/mi', '', $text );

		// 去掉 CI 自动生成的冗余标题行（WordPress 弹窗已显示插件名和版本）.
		$text = preg_replace( '/^##\s+\S+\s+v[\d.]+\s*\n+/m', '', $text );
		$text = preg_replace( '/^###?\s+What\'s Changed\s*\n+/mi', '', $text );

		$lines = explode( "\n", $text );
		$html  = '';
		$in_ul = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				if ( $in_ul ) {
					$html .= "</ul>\n";
					$in_ul = false;
				}
				continue;
			}

			// 标题 h2-h4.
			if ( preg_match( '/^(#{2,4})\s+(.+)$/', $trimmed, $m ) ) {
				if ( $in_ul ) {
					$html .= "</ul>\n";
					$in_ul = false;
				}
				$level = strlen( $m[1] );
				$html .= sprintf( "<h%d>%s</h%d>\n", $level, esc_html( $m[2] ), $level );
				continue;
			}

			// 无序列表.
			if ( preg_match( '/^[-*]\s+(.+)$/', $trimmed, $m ) ) {
				if ( ! $in_ul ) {
					$html .= "<ul>\n";
					$in_ul = true;
				}
				$html .= '<li>' . $this->inline_markdown( $m[1] ) . "</li>\n";
				continue;
			}

			// 普通段落.
			if ( $in_ul ) {
				$html .= "</ul>\n";
				$in_ul = false;
			}
			$html .= '<p>' . $this->inline_markdown( $trimmed ) . "</p>\n";
		}

		if ( $in_ul ) {
			$html .= "</ul>\n";
		}

		return wp_kses_post( $html );
	}

	/**
	 * 处理行内 Markdown：加粗、斜体、行内代码、链接。
	 *
	 * @param string $text 待处理的 Markdown 文本.
	 * @return string 转换后的 HTML.
	 */
	private function inline_markdown( string $text ): string {
		$text = esc_html( $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
		$text = preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text );

		return wp_kses_post( $text );
	}
}
