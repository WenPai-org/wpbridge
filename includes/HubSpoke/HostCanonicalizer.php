<?php
/** Strict HTTPS Host canonicalization. */
declare(strict_types=1);
namespace WPBridge\HubSpoke;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class HostCanonicalizer {
	/** @return string|\WP_Error */
	public static function origin( string $host ) {
		$host = strtolower( trim( $host ) );
		if ( '' === $host || false !== strpos( $host, ',' ) || 1 !== preg_match( '/^(?=.{1,253}(?::443)?$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::443)?$/', $host ) ) {
			return new \WP_Error( 'wpbridge_hub_origin_invalid', __( '请求 Host 无效。', 'wpbridge' ), [ 'status' => 400 ] );
		}
		return 'https://' . preg_replace( '/:443$/', '', rtrim( $host, '.' ) );
	}
}
