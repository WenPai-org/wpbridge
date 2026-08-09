<?php
/**
 * DNS-pinned WordPress HTTP client.
 *
 * @package WPBridge
 */

namespace WPBridge\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and pins every outbound request hop to a public IP address.
 */
final class SafeHttpClient {

	/** Maximum redirect count. */
	private const MAX_REDIRECTS = 5;

	/**
	 * Perform an HTTP request with request-time DNS validation and pinning.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args WordPress HTTP API arguments.
	 * @return array|\WP_Error
	 */
	public static function request( string $url, array $args = [] ) {
		$remaining                  = min( self::MAX_REDIRECTS, max( 0, (int) ( $args['redirection'] ?? self::MAX_REDIRECTS ) ) );
		$args['redirection']        = 0;
		$args['reject_unsafe_urls'] = true;
		$args['sslverify']          = true;

		while ( true ) {
			$target = Validator::resolve_public_url( $url );
			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$ip                            = $target['ips'][0];
			$port                          = $target['port'];
			$args['_wpbridge_resolved_ip'] = $ip;

			if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
				return new \WP_Error( 'wpbridge_safe_http_no_curl', __( '安全 HTTP 请求需要 cURL DNS 固定支持。', 'wpbridge' ) );
			}

			$pin_applied      = false;
			$was_preempted    = false;
			$pin_callback     = static function ( $handle, $parsed_args, $request_url ) use ( $url, $target, $ip, $port, &$pin_applied ): void {
				if ( $request_url !== $url || ( $parsed_args['_wpbridge_resolved_ip'] ?? '' ) !== $ip ) {
					return;
				}

				$pinned_ip = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress exposes this handle specifically for transport options; DNS pinning has no HTTP API argument.
				curl_setopt( $handle, CURLOPT_RESOLVE, [ $target['host'] . ':' . $port . ':' . $pinned_ip ] );
				$pin_applied = true;
			};
			$preempt_callback = static function ( $preempt ) use ( &$was_preempted ) {
				if ( false !== $preempt ) {
					$was_preempted = true;
				}
				return $preempt;
			};

			add_action( 'http_api_curl', $pin_callback, PHP_INT_MAX, 3 );
			add_filter( 'pre_http_request', $preempt_callback, PHP_INT_MAX, 1 );
			try {
				$response = wp_remote_request( $url, $args );
			} finally {
				remove_action( 'http_api_curl', $pin_callback, PHP_INT_MAX );
				remove_filter( 'pre_http_request', $preempt_callback, PHP_INT_MAX );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// A short-circuited request never opens a socket, so no cURL pin is required.
			if ( ! $pin_applied && ! $was_preempted ) {
				return new \WP_Error( 'wpbridge_safe_http_unpinned', __( '安全 HTTP 请求无法固定解析地址。', 'wpbridge' ) );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( ! in_array( $status, [ 301, 302, 303, 307, 308 ], true ) ) {
				return $response;
			}

			if ( $remaining <= 0 ) {
				return new \WP_Error( 'http_request_failed', __( '重定向次数过多。', 'wpbridge' ) );
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! is_string( $location ) || '' === trim( $location ) ) {
				return new \WP_Error( 'wpbridge_safe_http_redirect', __( '远程服务器返回了无效重定向。', 'wpbridge' ) );
			}

			$next_url = self::absolute_url( trim( $location ), $url );
			if ( is_wp_error( $next_url ) ) {
				return $next_url;
			}

			if ( self::origin( $next_url ) !== self::origin( $url ) ) {
				$args['headers'] = self::strip_sensitive_headers( (array) ( $args['headers'] ?? [] ) );
			}

			if ( 303 === $status || ( in_array( $status, [ 301, 302 ], true ) && 'POST' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) ) {
				$args['method'] = 'GET';
				unset( $args['body'] );
			}

			$url = $next_url;
			--$remaining;
		}
	}

	/**
	 * Resolve a relative Location header.
	 *
	 * @param string $location Redirect location.
	 * @param string $base     Current request URL.
	 * @return string|\WP_Error
	 */
	private static function absolute_url( string $location, string $base ) {
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}

		$base_parts = wp_parse_url( $base );
		if ( ! is_array( $base_parts ) || empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
			return new \WP_Error( 'wpbridge_safe_http_redirect', __( '无法解析重定向来源。', 'wpbridge' ) );
		}

		if ( 0 === strpos( $location, '//' ) ) {
			return $base_parts['scheme'] . ':' . $location;
		}

		$authority = $base_parts['scheme'] . '://' . $base_parts['host'];
		if ( isset( $base_parts['port'] ) ) {
			$authority .= ':' . (int) $base_parts['port'];
		}

		if ( 0 === strpos( $location, '/' ) ) {
			return $authority . $location;
		}

		$path = $base_parts['path'] ?? '/';
		return $authority . trailingslashit( dirname( $path ) ) . $location;
	}

	/**
	 * Build a normalized origin string.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port   = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Do not forward credentials to a different redirect origin.
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	private static function strip_sensitive_headers( array $headers ): array {
		foreach ( $headers as $name => $value ) {
			if ( in_array( strtolower( (string) $name ), [ 'authorization', 'proxy-authorization', 'x-api-key', 'api-key' ], true ) ) {
				unset( $headers[ $name ] );
			}
		}
		return $headers;
	}
}
