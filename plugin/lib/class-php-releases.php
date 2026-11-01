<?php
/**
 * PHP release status from php.net.
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat;

/**
 * Checks whether the server's PHP is the latest release of a supported branch.
 *
 * Uses php.net's release data, so there's no list of vulnerabilities to
 * maintain: if a newer patch release exists (especially one tagged as a
 * security release) or the branch no longer gets security fixes, say so.
 */
class Php_Releases {

	/**
	 * Branch states (stable, security, eol) and support dates.
	 */
	const STATES_URL = 'https://www.php.net/releases/states.php';

	/**
	 * Latest release of each supported branch.
	 */
	const ACTIVE_URL = 'https://www.php.net/releases/active.php';

	/**
	 * Cache lifetime. Security releases matter, so check twice a day.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Cache lifetime after a failed request, so page loads don't keep waiting on php.net.
	 */
	const CACHE_TTL_FAILED = HOUR_IN_SECONDS;

	/**
	 * Release status of the PHP version this server runs.
	 *
	 * @return array See evaluate().
	 */
	public function get_status() {
		return self::evaluate(
			sprintf( '%d.%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION ),
			PHP_EXTRA_VERSION,
			$this->fetch( 'states', self::STATES_URL ),
			$this->fetch( 'active', self::ACTIVE_URL )
		);
	}

	/**
	 * Work out the release status of a PHP version.
	 *
	 * @param string     $version       Version as major.minor.release, e.g. "8.3.35".
	 * @param string     $extra_version PHP_EXTRA_VERSION, e.g. "-1ubuntu1" for distribution builds.
	 * @param array|null $states        php.net states.php data, or null if unavailable.
	 * @param array|null $active        php.net active.php data, or null if unavailable.
	 * @return array {
	 *     @type string   $state                up_to_date, update_available, unsupported_branch, vendor_build, or unknown.
	 *     @type string   $current              The version checked.
	 *     @type string   $branch               Major.minor.
	 *     @type string   $latest               Latest release of the branch, if known.
	 *     @type string   $latest_date          Release date of $latest, if known.
	 *     @type bool     $latest_is_security   Whether $latest is tagged as a security release.
	 *     @type string   $security_support_end Date (Y-m-d) the branch stops/stopped getting security fixes, if known.
	 *     @type string[] $supported_branches   Branches that still get security fixes.
	 *     @type string   $message              Plain-language summary.
	 * }
	 */
	public static function evaluate( $version, $extra_version, $states, $active ) {
		$parts  = explode( '.', $version );
		$major  = $parts[0];
		$branch = $parts[0] . '.' . ( isset( $parts[1] ) ? $parts[1] : '0' );

		$branch_state = isset( $states[ $major ][ $branch ] ) ? $states[ $major ][ $branch ] : null;
		$release      = isset( $active[ $major ][ $branch ] ) ? $active[ $major ][ $branch ] : null;

		$status = array(
			'state'                => 'unknown',
			'current'              => $version,
			'branch'               => $branch,
			'latest'               => $release && isset( $release['version'] ) ? (string) $release['version'] : '',
			'latest_date'          => $release && isset( $release['date'] ) ? (string) $release['date'] : '',
			'latest_is_security'   => $release && isset( $release['tags'] ) && in_array( 'security', (array) $release['tags'], true ),
			'security_support_end' => $branch_state && isset( $branch_state['security_support_end'] ) ? substr( (string) $branch_state['security_support_end'], 0, 10 ) : '',
			'supported_branches'   => self::supported_branches( $states ),
			'message'              => __( 'Couldn\'t check php.net for the latest PHP releases.', 'php-compatibility-checker' ),
		);

		if ( ! $branch_state ) {
			return $status;
		}

		if ( 'eol' === $branch_state['state'] ) {
			$status['state']   = 'unsupported_branch';
			$status['message'] = sprintf(
				/* translators: 1: PHP branch, e.g. 7.4, 2: date, 3: list of supported PHP branches. */
				__( 'PHP %1$s stopped getting security fixes on %2$s. Move to a supported version (%3$s).', 'php-compatibility-checker' ),
				$branch,
				$status['security_support_end'],
				implode( ', ', $status['supported_branches'] )
			);

			if ( '' !== $extra_version ) {
				$status['message'] .= ' ' . __( 'Your host or Linux distribution may still patch this version. Check their security notices.', 'php-compatibility-checker' );
			}

			return $status;
		}

		if ( '' === $status['latest'] ) {
			return $status;
		}

		if ( '' !== $extra_version ) {
			$status['state']   = 'vendor_build';
			$status['message'] = sprintf(
				/* translators: 1: full PHP version string, 2: PHP branch, 3: latest release. */
				__( 'This server runs a PHP build from your host or Linux distribution (%1$s), which may include security fixes under an older version number. The latest PHP %2$s release is %3$s. Check your host\'s security notices to confirm you have its fixes.', 'php-compatibility-checker' ),
				$version . $extra_version,
				$branch,
				$status['latest']
			);
			return $status;
		}

		if ( version_compare( $version, $status['latest'], '<' ) ) {
			$status['state']   = 'update_available';
			$status['message'] = $status['latest_is_security']
				? sprintf(
					/* translators: 1: latest PHP release, 2: current PHP version. */
					__( 'PHP %1$s is available and includes security fixes. This server runs %2$s. Ask your host to update.', 'php-compatibility-checker' ),
					$status['latest'],
					$version
				)
				: sprintf(
					/* translators: 1: latest PHP release, 2: current PHP version. */
					__( 'PHP %1$s is available. This server runs %2$s.', 'php-compatibility-checker' ),
					$status['latest'],
					$version
				);
			return $status;
		}

		$status['state']   = 'up_to_date';
		$status['message'] = sprintf(
			/* translators: 1: current PHP version, 2: PHP branch. */
			__( 'PHP %1$s is the latest PHP %2$s release.', 'php-compatibility-checker' ),
			$version,
			$branch
		);

		return $status;
	}

	/**
	 * Branches that still get security fixes, oldest first.
	 *
	 * @param array|null $states php.net states.php data.
	 * @return string[]
	 */
	private static function supported_branches( $states ) {
		$branches = array();

		foreach ( (array) $states as $major_branches ) {
			foreach ( (array) $major_branches as $branch => $data ) {
				if ( isset( $data['state'] ) && 'eol' !== $data['state'] ) {
					$branches[] = (string) $branch;
				}
			}
		}

		usort( $branches, 'version_compare' );

		return $branches;
	}

	/**
	 * Fetch and cache JSON from php.net.
	 *
	 * @param string $name Cache name.
	 * @param string $url  URL.
	 * @return array|null Decoded data, or null if unavailable.
	 */
	private function fetch( $name, $url ) {
		$key    = 'wpephpcompat_php_' . $name;
		$cached = get_site_transient( $key );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 5 ) );
		$data     = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response )
			? null
			: json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			// Remember the failure briefly ("none" isn't false, so it's a cache hit).
			set_site_transient( $key, 'none', self::CACHE_TTL_FAILED );
			return null;
		}

		set_site_transient( $key, $data, self::CACHE_TTL );

		return $data;
	}
}
