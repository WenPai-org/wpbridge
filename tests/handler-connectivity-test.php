<?php
/**
 * Handler 连通性测试脚本
 *
 * 用于验证各个更新源 Handler 是否能正常获取更新信息并转接到云桥
 *
 * 使用方法:
 *   wp eval-file tests/handler-connectivity-test.php
 *   或
 *   php tests/handler-connectivity-test.php (需要 WordPress 环境)
 *
 * @package WPBridge
 * @since 0.9.7
 */

// 如果不在 WordPress 环境中，尝试加载
if ( ! defined( 'ABSPATH' ) ) {
	// 尝试找到 wp-load.php
	$wp_load_paths = [
		dirname( __DIR__, 4 ) . '/wp-load.php',      // 标准插件位置
		'/www/wwwroot/wpcy.com/wp-load.php',         // 本地测试站点
		'/var/www/html/wp-load.php',                 // Docker 环境
	];

	$loaded = false;
	foreach ( $wp_load_paths as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			$loaded = true;
			break;
		}
	}

	if ( ! $loaded ) {
		echo "错误: 无法加载 WordPress 环境\n";
		echo "请使用: wp eval-file tests/handler-connectivity-test.php\n";
		exit( 1 );
	}
}

use WPBridge\UpdateSource\Handlers\UpdateInfo;
use WPBridge\UpdateSource\Handlers\HealthStatus;
use WPBridge\UpdateSource\SourceModel;

/**
 * Handler 连通性测试类
 */
class HandlerConnectivityTest {

	/**
	 * 测试结果
	 */
	private array $results = [];

	/**
	 * 测试用例配置
	 */
	private array $test_cases = [];

	/**
	 * 构造函数
	 */
	public function __construct() {
		$this->init_test_cases();
	}

	/**
	 * 初始化测试用例
	 */
	private function init_test_cases(): void {
		$this->test_cases = [
			// GitHub Handler - 使用真实项目测试
			'github' => [
				'handler'   => 'GitHubHandler',
				'name'      => 'GitHub Releases',
				'api_url'   => 'https://github.com/developer-developer/developer-developer',
				'test_slug' => 'developer-developer',
				'version'   => '0.0.1',
				'auth'      => '',
				'skip'      => true,
				'skip_reason' => '需要配置真实 GitHub 项目',
			],

			// GitLab Handler
			'gitlab' => [
				'handler'   => 'GitLabHandler',
				'name'      => 'GitLab Releases',
				'api_url'   => 'https://gitlab.com/developer-developer/developer-developer',
				'test_slug' => 'developer-developer',
				'version'   => '1.0.0',
				'auth'      => '',
				'skip'      => true, // 需要真实项目
				'skip_reason' => '需要配置真实 GitLab 项目',
			],

			// Gitee Handler
			'gitee' => [
				'handler'   => 'GiteeHandler',
				'name'      => 'Gitee Releases',
				'api_url'   => 'https://gitee.com/developer-developer/developer-developer',
				'test_slug' => 'developer-developer',
				'version'   => '1.0.0',
				'auth'      => '',
				'skip'      => true,
				'skip_reason' => '需要配置真实 Gitee 项目',
			],

			// WenPai Git Handler
			'wenpai_git' => [
				'handler'   => 'WenPaiGitHandler',
				'name'      => 'WenPai Git',
				'api_url'   => 'https://git.developer.developer/developer-developer/developer-developer',
				'test_slug' => 'developer-developer',
				'version'   => '1.0.0',
				'auth'      => '',
				'skip'      => true,
				'skip_reason' => '需要配置真实 WenPai Git 项目',
			],

			// JSON/PUC Handler
			'json_puc' => [
				'handler'   => 'JsonHandler',
				'name'      => 'JSON/PUC API',
				'api_url'   => 'https://developer.developer/updates/{slug}.json',
				'test_slug' => 'developer-developer',
				'version'   => '1.0.0',
				'auth'      => '',
				'skip'      => true,
				'skip_reason' => '需要配置真实 PUC 端点',
			],

			// AspireCloud Handler
			'aspirecloud' => [
				'handler'   => 'AspireCloudHandler',
				'name'      => 'AspireCloud',
				'api_url'   => 'https://developer.developer',
				'test_slug' => 'developer-developer',
				'version'   => '1.0.0',
				'auth'      => '',
				'skip'      => true,
				'skip_reason' => '需要配置真实 AspireCloud 服务',
			],

			// ArkPress Handler
			'arkpress' => [
				'handler'   => 'ArkPressHandler',
				'name'      => 'ArkPress',
				'api_url'   => 'https://developer.developer',
				'test_slug' => 'developer-developer',
				'version'   => '1.0.0',
				'auth'      => '',
				'skip'      => true,
				'skip_reason' => '需要配置真实 ArkPress 服务',
			],

			// FAIR Handler
			'fair' => [
				'handler'   => 'FairHandler',
				'name'      => 'FAIR Protocol',
				'api_url'   => 'https://developer.developer/fair',
				'test_slug' => 'developer-developer',
				'version'   => '1.0.0',
				'auth'      => '',
				'skip'      => true,
				'skip_reason' => '需要配置真实 FAIR 服务',
			],

			// ZIP Handler
			'zip' => [
				'handler'   => 'ZipHandler',
				'name'      => 'Static ZIP',
				'api_url'   => 'https://developer.developer/plugins/developer-developer.zip',
				'test_slug' => 'developer-developer',
				'version'   => '1.0.0',
				'auth'      => '',
				'skip'      => true,
				'skip_reason' => '需要配置真实 ZIP URL',
			],

			// Bridge Server Handler
			'bridge_server' => [
				'handler'   => 'BridgeServerHandler',
				'name'      => 'Bridge Server',
				'api_url'   => 'http://localhost:8080',
				'test_slug' => 'developer-developer',
				'version'   => '1.0.0',
				'auth'      => '',
				'skip'      => true,
				'skip_reason' => '需要启动本地 Bridge Server',
			],
		];
	}

	/**
	 * 运行所有测试
	 */
	public function run(): void {
		$this->print_header();

		foreach ( $this->test_cases as $key => $case ) {
			$this->run_single_test( $key, $case );
		}

		$this->print_summary();
	}

	/**
	 * 运行单个测试
	 */
	private function run_single_test( string $key, array $case ): void {
		$handler_name = $case['handler'];
		$display_name = $case['name'];

		echo "\n┌─ 测试: {$display_name} ({$handler_name})\n";

		// 检查是否跳过
		if ( ! empty( $case['skip'] ) ) {
			$this->results[ $key ] = [
				'status'  => 'skipped',
				'name'    => $display_name,
				'handler' => $handler_name,
				'reason'  => $case['skip_reason'] ?? '已跳过',
			];
			echo "│  ⏭️  跳过: {$case['skip_reason']}\n";
			echo "└─────────────────────────────────\n";
			return;
		}

		// 创建 SourceModel
		$source = $this->create_source_model( $case );

		// 获取 Handler 类
		$handler_class = "WPBridge\\UpdateSource\\Handlers\\{$handler_name}";

		if ( ! class_exists( $handler_class ) ) {
			$this->results[ $key ] = [
				'status'  => 'error',
				'name'    => $display_name,
				'handler' => $handler_name,
				'error'   => "Handler 类不存在: {$handler_class}",
			];
			echo "│  ❌ 错误: Handler 类不存在\n";
			echo "└─────────────────────────────────\n";
			return;
		}

		try {
			$handler = new $handler_class( $source );
			$result  = $this->test_handler( $handler, $case );

			$this->results[ $key ] = array_merge( [
				'name'    => $display_name,
				'handler' => $handler_name,
			], $result );

			$this->print_test_result( $result );

		} catch ( \Throwable $e ) {
			$this->results[ $key ] = [
				'status'  => 'error',
				'name'    => $display_name,
				'handler' => $handler_name,
				'error'   => $e->getMessage(),
			];
			echo "│  ❌ 异常: {$e->getMessage()}\n";
		}

		echo "└─────────────────────────────────\n";
	}

	/**
	 * 测试 Handler
	 */
	private function test_handler( $handler, array $case ): array {
		$result = [
			'status'           => 'unknown',
			'connection'       => false,
			'check_update'     => false,
			'get_info'         => false,
			'response_time_ms' => 0,
			'update_info'      => null,
			'errors'           => [],
		];

		$slug    = $case['test_slug'];
		$version = $case['version'];

		// 1. 测试连通性
		echo "│  📡 测试连通性...\n";
		$start = microtime( true );

		try {
			$health = $handler->test_connection();
			$result['response_time_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );

			if ( $health->is_healthy() ) {
				$result['connection'] = true;
				echo "│     ✅ 连接成功 ({$result['response_time_ms']}ms)\n";
			} elseif ( $health->is_available() ) {
				$result['connection'] = true;
				echo "│     ⚠️  连接降级: {$health->error}\n";
			} else {
				$result['errors'][] = "连接失败: {$health->error}";
				echo "│     ❌ 连接失败: {$health->error}\n";
			}
		} catch ( \Throwable $e ) {
			$result['errors'][] = "连接异常: {$e->getMessage()}";
			echo "│     ❌ 连接异常: {$e->getMessage()}\n";
		}

		// 2. 测试 check_update
		echo "│  🔍 测试 check_update({$slug}, {$version})...\n";

		try {
			$update_info = $handler->check_update( $slug, $version );

			if ( $update_info instanceof UpdateInfo ) {
				$result['check_update'] = true;
				$result['update_info']  = [
					'slug'         => $update_info->slug,
					'version'      => $update_info->version,
					'download_url' => $update_info->download_url,
					'has_details'  => ! empty( $update_info->details_url ),
				];
				echo "│     ✅ 获取成功: v{$update_info->version}\n";
				echo "│        下载URL: " . ( $update_info->download_url ? '✓' : '✗' ) . "\n";
			} else {
				// null 可能表示无更新，不一定是错误
				$result['check_update'] = true;
				echo "│     ℹ️  无可用更新 (当前版本可能已是最新)\n";
			}
		} catch ( \Throwable $e ) {
			$result['errors'][] = "check_update 异常: {$e->getMessage()}";
			echo "│     ❌ 异常: {$e->getMessage()}\n";
		}

		// 3. 测试 get_info
		echo "│  📋 测试 get_info({$slug})...\n";

		try {
			$info = $handler->get_info( $slug );

			if ( is_array( $info ) && ! empty( $info ) ) {
				$result['get_info'] = true;
				echo "│     ✅ 获取成功\n";
				echo "│        版本: " . ( $info['version'] ?? 'N/A' ) . "\n";
			} else {
				echo "│     ⚠️  返回空数据\n";
			}
		} catch ( \Throwable $e ) {
			$result['errors'][] = "get_info 异常: {$e->getMessage()}";
			echo "│     ❌ 异常: {$e->getMessage()}\n";
		}

		// 确定最终状态
		if ( $result['connection'] && $result['check_update'] ) {
			$result['status'] = 'pass';
		} elseif ( $result['connection'] ) {
			$result['status'] = 'partial';
		} else {
			$result['status'] = 'fail';
		}

		return $result;
	}

	/**
	 * 创建 SourceModel
	 */
	private function create_source_model( array $case ): SourceModel {
		$source = new SourceModel();

		$source->id        = 0;
		$source->name      = $case['name'];
		$source->type      = strtolower( str_replace( 'Handler', '', $case['handler'] ) );
		$source->api_url   = $case['api_url'];
		$source->item_type = 'plugin';
		$source->enabled   = true;
		$source->metadata  = [];

		if ( ! empty( $case['auth'] ) ) {
			$source->metadata['auth_token'] = $case['auth'];
		}

		return $source;
	}

	/**
	 * 打印测试结果
	 */
	private function print_test_result( array $result ): void {
		$status_icons = [
			'pass'    => '✅',
			'partial' => '⚠️',
			'fail'    => '❌',
			'error'   => '💥',
			'skipped' => '⏭️',
		];

		$icon = $status_icons[ $result['status'] ] ?? '❓';
		echo "│  {$icon} 结果: {$result['status']}\n";
	}

	/**
	 * 打印头部
	 */
	private function print_header(): void {
		echo "\n";
		echo "╔═══════════════════════════════════════════════════════════╗\n";
		echo "║       WPBridge Handler 连通性测试                         ║\n";
		echo "║       测试各更新源 Handler 的云桥转接能力                  ║\n";
		echo "╚═══════════════════════════════════════════════════════════╝\n";
		echo "\n";
		echo "测试时间: " . date( 'Y-m-d H:i:s' ) . "\n";
		echo "Handler 数量: " . count( $this->test_cases ) . "\n";
	}

	/**
	 * 打印汇总
	 */
	private function print_summary(): void {
		echo "\n";
		echo "╔═══════════════════════════════════════════════════════════╗\n";
		echo "║                      测试汇总                             ║\n";
		echo "╚═══════════════════════════════════════════════════════════╝\n";
		echo "\n";

		$stats = [
			'pass'    => 0,
			'partial' => 0,
			'fail'    => 0,
			'error'   => 0,
			'skipped' => 0,
		];

		echo "┌────────────────────┬──────────┬────────┬──────────┬──────────┐\n";
		echo "│ Handler            │ 状态     │ 连接   │ 更新检查 │ 信息获取 │\n";
		echo "├────────────────────┼──────────┼────────┼──────────┼──────────┤\n";

		foreach ( $this->results as $key => $result ) {
			$stats[ $result['status'] ]++;

			$name   = str_pad( mb_substr( $result['name'], 0, 18 ), 18 );
			$status = $this->format_status( $result['status'] );

			if ( $result['status'] === 'skipped' ) {
				$conn  = '  -   ';
				$check = '   -    ';
				$info  = '   -    ';
			} else {
				$conn  = ( $result['connection'] ?? false ) ? '  ✅  ' : '  ❌  ';
				$check = ( $result['check_update'] ?? false ) ? '   ✅   ' : '   ❌   ';
				$info  = ( $result['get_info'] ?? false ) ? '   ✅   ' : '   ❌   ';
			}

			echo "│ {$name} │ {$status} │{$conn}│{$check}│{$info}│\n";
		}

		echo "└────────────────────┴──────────┴────────┴──────────┴──────────┘\n";

		echo "\n统计:\n";
		echo "  ✅ 通过: {$stats['pass']}\n";
		echo "  ⚠️  部分: {$stats['partial']}\n";
		echo "  ❌ 失败: {$stats['fail']}\n";
		echo "  💥 错误: {$stats['error']}\n";
		echo "  ⏭️  跳过: {$stats['skipped']}\n";

		$total    = array_sum( $stats );
		$executed = $total - $stats['skipped'];
		$success  = $stats['pass'] + $stats['partial'];

		if ( $executed > 0 ) {
			$rate = round( ( $success / $executed ) * 100, 1 );
			echo "\n执行率: {$executed}/{$total} ({$rate}% 成功)\n";
		}

		// 云桥转接能力评估
		echo "\n";
		echo "╔═══════════════════════════════════════════════════════════╗\n";
		echo "║                   云桥转接能力评估                        ║\n";
		echo "╚═══════════════════════════════════════════════════════════╝\n";

		if ( $stats['pass'] > 0 ) {
			echo "\n✅ 以下 Handler 已验证可正常转接到云桥:\n";
			foreach ( $this->results as $result ) {
				if ( $result['status'] === 'pass' ) {
					echo "   • {$result['name']} ({$result['handler']})\n";
				}
			}
		}

		if ( $stats['skipped'] > 0 ) {
			echo "\n⏭️  以下 Handler 需要配置后测试:\n";
			foreach ( $this->results as $result ) {
				if ( $result['status'] === 'skipped' ) {
					echo "   • {$result['name']}: {$result['reason']}\n";
				}
			}
		}

		echo "\n";
	}

	/**
	 * 格式化状态
	 */
	private function format_status( string $status ): string {
		$map = [
			'pass'    => ' ✅通过 ',
			'partial' => ' ⚠️部分 ',
			'fail'    => ' ❌失败 ',
			'error'   => ' 💥错误 ',
			'skipped' => ' ⏭️跳过 ',
		];

		return $map[ $status ] ?? ' ❓未知 ';
	}

	/**
	 * 配置测试用例
	 */
	public function configure( string $key, array $config ): self {
		if ( isset( $this->test_cases[ $key ] ) ) {
			$this->test_cases[ $key ] = array_merge( $this->test_cases[ $key ], $config );
			// 如果提供了配置，取消跳过
			if ( ! empty( $config['api_url'] ) ) {
				$this->test_cases[ $key ]['skip'] = false;
			}
		}
		return $this;
	}

	/**
	 * 启用测试用例
	 */
	public function enable( string $key ): self {
		if ( isset( $this->test_cases[ $key ] ) ) {
			$this->test_cases[ $key ]['skip'] = false;
		}
		return $this;
	}

	/**
	 * 获取结果
	 */
	public function get_results(): array {
		return $this->results;
	}
}

// 运行测试
if ( php_sapi_name() === 'cli' || defined( 'WP_CLI' ) ) {
	$test = new HandlerConnectivityTest();

	// 可以在这里配置真实的测试端点
	// $test->configure( 'github', [
	//     'api_url'   => 'https://github.com/developer-developer/developer-developer',
	//     'test_slug' => 'developer-developer',
	// ] );

	$test->run();
}