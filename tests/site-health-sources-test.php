<?php
/**
 * Site Health "wpbridge_sources" contract test.
 *
 * Standalone (no WordPress). Regression for the 1.3.0 fatal error: Settings::get_enabled_sources()
 * returns raw arrays, but HealthChecker::check() is typed SourceModel, so opening site-health.php with
 * the default wenpai-open source enabled threw a TypeError. The HealthChecker below is a stub with the
 * exact production signature (asserted against the real file), so no network request is made.
 */

namespace WPBridge\Cache {
	use WPBridge\UpdateSource\SourceModel;
	use WPBridge\UpdateSource\Handlers\HealthStatus;

	class HealthChecker {
		public static array $seen   = [];
		public static string $result = 'healthy';

		public function check( SourceModel $source, bool $force = false ): HealthStatus {
			self::$seen[] = $source;
			return 'healthy' === self::$result ? HealthStatus::healthy( 120 ) : HealthStatus::failed( 'stub failure' );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function __( $text, $domain = 'default' ) {
		return $text;
	}
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_url( $url ) {
		return (string) $url;
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
	function add_filter( ...$args ): bool {
		return true;
	}

	$root = dirname( __DIR__ );
	require_once $root . '/includes/Core/Settings.php';
	require_once $root . '/includes/UpdateSource/SourceType.php';
	require_once $root . '/includes/UpdateSource/SourceModel.php';
	require_once $root . '/includes/UpdateSource/Handlers/HealthStatus.php';
	require_once $root . '/includes/Core/SiteHealth.php';

	$failures = 0;
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
		if ( ! $condition ) {
			++$failures;
		}
	};

	// The stub must keep the production signature, otherwise this test would stop reproducing the TypeError.
	$checker_src = (string) file_get_contents( $root . '/includes/Cache/HealthChecker.php' );
	$assert( 1 === preg_match( '/public function check\(\s*SourceModel \$source,\s*bool \$force = false\s*\): HealthStatus/', $checker_src ), 'HealthChecker::check() is still typed check( SourceModel $source, bool $force = false ): HealthStatus' );

	// Real default configuration: the wenpai-open preset from Settings.
	$settings_ref = new ReflectionClass( \WPBridge\Core\Settings::class );
	$settings     = $settings_ref->newInstanceWithoutConstructor();
	$presets      = $settings_ref->getMethod( 'get_preset_sources' );
	$presets->setAccessible( true );
	$default_sources = $presets->invoke( $settings );
	$cached          = $settings_ref->getProperty( 'cached_sources' );
	$cached->setAccessible( true );
	$cached->setValue( $settings, $default_sources );
	$assert( 'wenpai-open' === ( $default_sources[0]['id'] ?? null ) && ! empty( $default_sources[0]['enabled'] ) && is_array( $settings->get_enabled_sources()[0] ?? null ), 'Default config: wenpai-open is enabled and Settings::get_enabled_sources() returns it as an array' );

	$health_ref = new ReflectionClass( \WPBridge\Core\SiteHealth::class );
	$health     = $health_ref->newInstanceWithoutConstructor();
	$prop       = $health_ref->getProperty( 'settings' );
	$prop->setAccessible( true );
	$prop->setValue( $health, $settings );

	$run = static function () use ( $health ) {
		\WPBridge\Cache\HealthChecker::$seen = [];
		try {
			return $health->test_sources();
		} catch ( \Throwable $e ) {
			return $e;
		}
	};
	$shape_ok = static function ( $r ): bool {
		return is_array( $r )
			&& is_string( $r['label'] ?? null ) && '' !== $r['label']
			&& in_array( $r['status'] ?? null, [ 'good', 'recommended', 'critical' ], true )
			&& is_string( $r['badge']['label'] ?? null ) && is_string( $r['badge']['color'] ?? null )
			&& is_string( $r['description'] ?? null ) && '' !== $r['description']
			&& 'wpbridge_sources' === ( $r['test'] ?? null );
	};

	// Healthy default source.
	\WPBridge\Cache\HealthChecker::$result = 'healthy';
	$result = $run();
	$assert( ! $result instanceof \Throwable, 'test_sources() with the default wenpai-open source does not throw' . ( $result instanceof \Throwable ? ' (' . get_class( $result ) . ': ' . $result->getMessage() . ')' : '' ) );
	$assert( $shape_ok( $result ), 'test_sources() returns a Site Health result (label, status, badge, description, test=wpbridge_sources)' );
	$assert( is_array( $result ) && 'good' === ( $result['status'] ?? null ), 'Healthy default source reports status good' );
	$seen = \WPBridge\Cache\HealthChecker::$seen;
	$assert( 1 === count( $seen ) && $seen[0] instanceof \WPBridge\UpdateSource\SourceModel && 'wenpai-open' === $seen[0]->id && 'json' === $seen[0]->type, 'HealthChecker::check() receives a SourceModel built from the stored source array' );

	// Failed default source: the failure branch reads the source name.
	\WPBridge\Cache\HealthChecker::$result = 'failed';
	$result = $run();
	$assert( ! $result instanceof \Throwable && $shape_ok( $result ) && 'critical' === ( $result['status'] ?? null ), 'Failed default source reports status critical without throwing' );
	$assert( is_array( $result ) && false !== strpos( (string) ( $result['description'] ?? '' ), '文派开源更新源' ), 'Critical result names the failed source' );

	// No enabled sources: unchanged recommended branch, checker not called.
	$cached->setValue( $settings, [ array_merge( $default_sources[0], [ 'enabled' => false ] ) ] );
	$result = $run();
	$assert( ! $result instanceof \Throwable && $shape_ok( $result ) && 'recommended' === ( $result['status'] ?? null ) && [] === \WPBridge\Cache\HealthChecker::$seen, 'No enabled sources keeps the recommended result and makes no health check' );

	exit( $failures > 0 ? 1 : 0 );
}
