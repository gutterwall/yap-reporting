<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/**
 * SSRF policy for administrator-configured URLs (Reporting API and BMLT root server).
 *
 * - https only, no embedded credentials, no fragments
 * - every resolved address must be public: loopback, private, link-local (incl. cloud metadata
 *   169.254.169.254), reserved and unique-local ranges are refused
 * - private deployments can be allowed ONLY by defining YAPR_ALLOW_PRIVATE_HOSTS in wp-config.php,
 *   so nobody without file access to the server can redirect requests inward
 * Requests are additionally made with redirects disabled and, for public targets,
 * wp_safe_remote_get(), which re-validates the host at connection time.
 */
final class UrlGuard {

	/** @var callable|null test seam: fn(string $host): string[] */
	public static $resolver = null;

	public static function private_hosts_allowed(): bool {
		return defined( 'YAPR_ALLOW_PRIVATE_HOSTS' ) && true === YAPR_ALLOW_PRIVATE_HOSTS;
	}

	/** @return true|\WP_Error */
	public static function validate( string $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'yapr_bad_url', __( 'Enter a complete URL.', 'yap-reporting' ) );
		}
		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return new \WP_Error( 'yapr_https_required', __( 'The URL must start with https://.', 'yap-reporting' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'yapr_bad_url', __( 'The URL must not contain a username or password.', 'yap-reporting' ) );
		}
		if ( isset( $parts['fragment'] ) || isset( $parts['query'] ) ) {
			return new \WP_Error( 'yapr_bad_url', __( 'The URL must not contain a query string or fragment.', 'yap-reporting' ) );
		}
		if ( self::private_hosts_allowed() ) {
			return true;
		}
		$host = trim( (string) $parts['host'], '[]' );
		$ips  = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : self::resolve( $host );
		if ( array() === $ips ) {
			return new \WP_Error( 'yapr_dns', __( 'The host name could not be resolved.', 'yap-reporting' ) );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new \WP_Error( 'yapr_private_host', __( 'The URL points to a private or internal network address. Define YAPR_ALLOW_PRIVATE_HOSTS in wp-config.php only if this is intentional.', 'yap-reporting' ) );
			}
		}
		return true;
	}

	public static function is_public_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		$bin = @inet_pton( $ip );
		if ( false === $bin ) {
			return false;
		}
		if ( 4 === strlen( $bin ) ) {
			$first = ord( $bin[0] );
			$second = ord( $bin[1] );
			// 100.64.0.0/10 carrier-grade NAT, 0.0.0.0/8, 169.254/16 (also caught above on modern PHP).
			if ( 0 === $first || ( 100 === $first && $second >= 64 && $second <= 127 ) || ( 169 === $first && 254 === $second ) ) {
				return false;
			}
			return true;
		}
		$first = ord( $bin[0] );
		// fc00::/7 unique local, fe80::/10 link local, ::ffff: mapped handled by filter_var.
		if ( 0xfc === ( $first & 0xfe ) || ( 0xfe === $first && 0x80 === ( ord( $bin[1] ) & 0xc0 ) ) ) {
			return false;
		}
		return true;
	}

	/** @return string[] */
	private static function resolve( string $host ): array {
		if ( is_callable( self::$resolver ) ) {
			return (array) call_user_func( self::$resolver, $host );
		}
		$ips = array();
		$v4  = @gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$v6 = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $v6 ) ) {
				foreach ( $v6 as $rec ) {
					if ( ! empty( $rec['ipv6'] ) ) {
						$ips[] = $rec['ipv6'];
					}
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/** HTTP args shared by every outbound request. */
	public static function request_args( array $headers = array(), int $timeout = 20 ): array {
		return array(
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => ! self::private_hosts_allowed(),
			'user-agent'          => 'YAP-Reporting-WordPress/' . YAPR_VERSION . '; ' . home_url( '/' ),
			'headers'             => $headers,
			'limit_response_size' => 20 * MB_IN_BYTES,
		);
	}

	/** @return array|\WP_Error */
	public static function get( string $url, array $args ) {
		return self::private_hosts_allowed() ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
	}
}
