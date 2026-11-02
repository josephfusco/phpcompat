<?php
/**
 * One scan at a time per site.
 *
 * @package WPEngine_PHPCompat
 */

declare( strict_types=1 );

namespace WPEngine_PHPCompat;

/**
 * A lease on scanning, so two admins (or two tabs) don't keep two PHP workers
 * busy, or scan the same plugin twice.
 *
 * Each scan on the scan page sends a random token with every request. The
 * first request takes the lease with add_option(), which only writes when the
 * option doesn't exist yet; every request renews it. A lease nobody renews
 * expires after TTL seconds, so a closed tab can't block scanning for long.
 */
class Scan_Lock {

	/**
	 * Option holding the lease: token, user ID, and expiry time.
	 */
	const OPTION = 'wpephpcompat_scan_lock';

	/**
	 * Seconds a lease lasts without a request. Longer than the slowest request
	 * plus the longest pause between requests.
	 */
	const TTL = 120;

	/**
	 * Current time, replaceable in tests.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param callable|null $now Returns the current Unix time.
	 */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ? $now : 'time';
	}

	/**
	 * Take or renew the lease for a token.
	 *
	 * @param string $token   The scan's token.
	 * @param int    $user_id Who is scanning.
	 * @return array|null Null when the lease is ours; otherwise the other lease (token, user, expires).
	 */
	public function acquire( string $token, int $user_id ): ?array {
		$now   = (int) call_user_func( $this->now );
		$lease = array(
			'token'   => $token,
			'user'    => $user_id,
			'expires' => $now + self::TTL,
		);

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( add_option( self::OPTION, $lease, '', false ) ) {
				return null;
			}

			$current = get_option( self::OPTION );
			if ( ! is_array( $current ) || ! isset( $current['token'], $current['expires'] ) ) {
				delete_option( self::OPTION );
				continue;
			}
			if ( hash_equals( (string) $current['token'], $token ) ) {
				update_option( self::OPTION, $lease, false );
				return null;
			}
			if ( (int) $current['expires'] > $now ) {
				return $current;
			}

			// Expired: the scan that held it stopped without letting go.
			delete_option( self::OPTION );
		}

		$current = get_option( self::OPTION );
		return is_array( $current ) ? $current : null;
	}

	/**
	 * Let go of the lease, if this token holds it.
	 *
	 * @param string $token The scan's token.
	 */
	public function release( string $token ): void {
		$current = get_option( self::OPTION );
		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], $token ) ) {
			delete_option( self::OPTION );
		}
	}
}
