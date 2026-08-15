<?php
/**
 * 输入校验
 *
 * @package WPBridge
 */

namespace WPBridge\Security;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 输入校验类
 */
class Validator {

	/**
	 * Resolve a URL to public addresses at request time.
	 *
	 * @param string $url URL.
	 * @return array|\WP_Error
	 */
	public static function resolve_public_url( string $url ) {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'wpbridge_invalid_url', __( '远程 URL 无效。', 'wpbridge' ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'wpbridge_invalid_url', __( '远程 URL 缺少主机名。', 'wpbridge' ) );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'wpbridge_invalid_url', __( '远程 URL 协议或凭据无效。', 'wpbridge' ) );
		}

		$port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		if ( $port < 1 || $port > 65535 ) {
			return new \WP_Error( 'wpbridge_invalid_url', __( '远程 URL 端口无效。', 'wpbridge' ) );
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$ips  = self::resolve_host( $host );
		if ( empty( $ips ) ) {
			return new \WP_Error( 'wpbridge_dns_failed', __( '远程主机 DNS 解析失败。', 'wpbridge' ) );
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new \WP_Error( 'wpbridge_private_address', __( '远程主机解析到了私有或保留地址。', 'wpbridge' ) );
			}
		}

		return [
			'host' => $host,
			'port' => $port,
			'ips'  => array_values( array_unique( $ips ) ),
		];
	}

	/**
	 * Resolve all A and AAAA records. Test fixtures may replace the result.
	 *
	 * @param string $host Host name or IP literal.
	 * @return string[]
	 */
	private static function resolve_host( string $host ): array {
		$pre_resolved = apply_filters( 'wpbridge_pre_resolve_host', null, $host );
		if ( is_array( $pre_resolved ) ) {
			$resolved = $pre_resolved;
		} elseif ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$resolved = [ $host ];
		} else {
			$resolved = [];
			if ( function_exists( 'dns_get_record' ) ) {
				$records = dns_get_record( $host, DNS_A | DNS_AAAA );
				if ( is_array( $records ) ) {
					foreach ( $records as $record ) {
						$ip = $record['ip'] ?? $record['ipv6'] ?? '';
						if ( is_string( $ip ) && '' !== $ip ) {
							$resolved[] = $ip;
						}
					}
				}
			}
			if ( empty( $resolved ) ) {
				$ipv4     = gethostbynamel( $host );
				$resolved = is_array( $ipv4 ) ? $ipv4 : [];
			}
		}

		$resolved = apply_filters( 'wpbridge_resolve_host', $resolved, $host );
		if ( ! is_array( $resolved ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$resolved,
				static function ( $ip ): bool {
					return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP );
				}
			)
		);
	}

	/**
	 * Check that an address is globally routable.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_public_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * 校验 URL
	 *
	 * @param string $url URL
	 * @return bool
	 */
	public static function is_valid_url( string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		// 基本格式校验
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// 只允许 http 和 https
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return false;
		}

		// 检查是否有主机名
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}

		return ! is_wp_error( self::resolve_public_url( $url ) );
	}

	/**
	 * 检查是否是本地地址
	 *
	 * @param string $host 主机名
	 * @return bool
	 */
	private static function is_local_address( string $host ): bool {
		// 本地主机名
		$local_hosts = [ 'localhost', '127.0.0.1', '::1' ];

		if ( in_array( $host, $local_hosts, true ) ) {
			return true;
		}

		// 私有 IP 范围
		$ip = gethostbyname( $host );

		if ( $ip === $host ) {
			// 无法解析，为安全起见视为本地地址
			return true;
		}

		// 检查私有 IP 范围（IPv4）
		$private_ranges = [
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
			'127.0.0.0/8',
		];

		foreach ( $private_ranges as $range ) {
			if ( self::ip_in_range( $ip, $range ) ) {
				return true;
			}
		}

		// 检查 IPv6 私有地址
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			// fc00::/7 (Unique Local Addresses)
			// fe80::/10 (Link-Local Addresses)
			if ( preg_match( '/^(fc|fd|fe80)/i', $ip ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 检查 IP 是否在范围内
	 *
	 * @param string $ip    IP 地址
	 * @param string $range CIDR 范围
	 * @return bool
	 */
	private static function ip_in_range( string $ip, string $range ): bool {
		list( $subnet, $bits ) = explode( '/', $range );

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		$mask        = -1 << ( 32 - (int) $bits );

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}

	/**
	 * 校验版本号
	 *
	 * @param string $version 版本号
	 * @return bool
	 */
	public static function is_valid_version( string $version ): bool {
		if ( empty( $version ) ) {
			return false;
		}

		// 支持语义化版本和 WordPress 风格版本
		// 例如: 1.0.0, 1.0, 1.0.0-beta, 1.0.0-rc.1
		$pattern = '/^[0-9]+(\.[0-9]+)*(-[a-zA-Z0-9]+(\.[a-zA-Z0-9]+)*)?$/';

		return (bool) preg_match( $pattern, $version );
	}

	/**
	 * 校验 slug
	 *
	 * @param string $slug Slug
	 * @return bool
	 */
	public static function is_valid_slug( string $slug ): bool {
		if ( empty( $slug ) ) {
			return true; // 空 slug 是允许的（表示匹配所有）
		}

		// 只允许小写字母、数字、连字符
		$pattern = '/^[a-z0-9-]+$/';

		return (bool) preg_match( $pattern, $slug );
	}

	/**
	 * 校验 JSON 响应结构
	 *
	 * @param array $data     数据
	 * @param array $required 必需字段
	 * @return array 错误数组
	 */
	public static function validate_json_structure( array $data, array $required ): array {
		$errors = [];

		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				$errors[] = sprintf( /* translators: %s: required field name */ __( '缺少必需字段: %s', 'wpbridge' ), $field );
			}
		}

		return $errors;
	}

	/**
	 * 校验更新信息 JSON
	 *
	 * @param array $data 数据
	 * @return array 错误数组
	 */
	public static function validate_update_info( array $data ): array {
		$errors = [];

		// 必需字段
		if ( empty( $data['version'] ) ) {
			$errors[] = __( '缺少版本号', 'wpbridge' );
		} elseif ( ! self::is_valid_version( $data['version'] ) ) {
			$errors[] = __( '无效的版本号格式', 'wpbridge' );
		}

		// 下载 URL
		$download_url = $data['download_url'] ?? $data['package'] ?? '';
		if ( ! empty( $download_url ) && ! self::is_valid_url( $download_url ) ) {
			$errors[] = __( '无效的下载 URL', 'wpbridge' );
		}

		return $errors;
	}

	/**
	 * 清理 HTML
	 *
	 * @param string $html HTML 内容
	 * @return string
	 */
	public static function sanitize_html( string $html ): string {
		return wp_kses_post( $html );
	}

	/**
	 * 清理文本
	 *
	 * @param string $text 文本
	 * @return string
	 */
	public static function sanitize_text( string $text ): string {
		return sanitize_text_field( $text );
	}

	/**
	 * 清理 URL
	 *
	 * @param string $url URL
	 * @return string
	 */
	public static function sanitize_url( string $url ): string {
		return esc_url_raw( $url );
	}
}
