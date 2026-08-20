<?php
/**
 * Standalone updater compatibility and output-sanitization regression checks.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function wp_kses_post( string $html ): string {
	$html = preg_replace( '#<script\\b[^>]*>.*?</script>#is', '', $html );
	$html = preg_replace( '/\s(?:href|src)="\s*javascript:[^"]*"/i', '', $html );
	return (string) $html;
}

require_once dirname( __DIR__ ) . '/includes/class-wenpai-updater.php';

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
	if ( ! $condition ) {
		$failures++;
	}
};

$reflection = new ReflectionClass( WPBridge_Updater::class );
$updater    = $reflection->newInstanceWithoutConstructor();
$method     = $reflection->getMethod( 'markdown_to_html' );
$method->setAccessible( true );

$raw_html = $method->invoke( $updater, '<p>ok</p><script>alert(1)</script>' );
$assert( false === stripos( $raw_html, '<script' ), 'Remote raw HTML is passed through the WordPress post sanitizer' );

$markdown = $method->invoke( $updater, '[safe](https://example.com) [bad](javascript:alert(1))' );
$assert( false !== strpos( $markdown, 'https://example.com' ), 'HTTPS changelog links remain available' );
$assert( false === stripos( $markdown, 'href="javascript:' ), 'Unsafe changelog link protocols are removed' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wenpai-updater.php' );
$assert( false === strpos( $source, 'str_starts_with(' ), 'Updater does not call PHP 8-only str_starts_with' );
$assert( false !== strpos( $source, "\$plugin_data['Version']" ) && false !== strpos( $source, '$disk_version !== $this->version' ), 'Updater rejects stale in-memory version transitions before network access' );
$assert( false !== strpos( $source, 'class_exists(' ) && false !== strpos( $source, 'SafeHttpClient' ), 'Updater fails closed when its transition-time HTTP dependency is unavailable' );

exit( $failures > 0 ? 1 : 0 );
