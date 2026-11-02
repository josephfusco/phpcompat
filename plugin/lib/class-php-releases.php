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
 * Uses php.net's release data: if a newer patch release exists (especially
 * one tagged as a security release) or the branch no longer gets security
 * fixes, say so. php.net doesn't say how serious a security release is, so a
 * short list of critical advisories (see advisories()) adds that: whether this
 * server is affected and which release fixes it.
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
		/**
		 * Filters the critical PHP advisories checked against the server's PHP.
		 *
		 * @since 2.0.0
		 *
		 * @param array[] $advisories See Php_Releases::advisories().
		 */
		$advisories = apply_filters( 'wpephpcompat_php_advisories', self::advisories() );

		return self::evaluate(
			sprintf( '%d.%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION ),
			PHP_EXTRA_VERSION,
			$this->fetch( 'states', self::STATES_URL ),
			$this->fetch( 'active', self::ACTIVE_URL ),
			is_array( $advisories ) ? $advisories : array(),
			array(
				'session.upload_progress.enabled' => (string) ini_get( 'session.upload_progress.enabled' ),
				'session.serialize_handler'       => (string) ini_get( 'session.serialize_handler' ),
			)
		);
	}

	/**
	 * Critical PHP vulnerabilities worth naming on their own.
	 *
	 * Only for flaws serious enough that "a security release is available"
	 * undersells them: remote code execution, especially when exploited.
	 *
	 * @since 2.0.0
	 *
	 * @return array[] Each advisory: {
	 *     @type string   $id         Stable identifier.
	 *     @type string   $name       Common name, or empty.
	 *     @type string[] $cves       CVE IDs.
	 *     @type string   $url        Where to read more.
	 *     @type bool     $exploited  Whether it is known to be exploited.
	 *     @type string   $since      First affected version.
	 *     @type string[] $fixed      Fixed release per branch, e.g. '8.3' => '8.3.36'. Empty if no fix yet.
	 *     @type array    $conditions Optional ini settings that must all match for the server to be exposed:
	 *                                setting => list of matching values.
	 *     @type string   $mitigation Optional workaround until a fix ships.
	 * }
	 */
	public static function advisories() {
		return array(
			array(
				'id'         => 'php2shell',
				'name'       => 'PHP2Shell',
				'cves'       => array( 'CVE-2026-109618', 'CVE-2026-109619' ),
				'url'        => 'https://www.cisa.gov/news-events/alerts/2026/10/21/critical-vulnerabilities-php-require-immediate-patching',
				'exploited'  => true,
				'since'      => '5.4.0',
				'fixed'      => array(
					'8.2' => '8.2.35',
					'8.3' => '8.3.36',
					'8.4' => '8.4.27',
					'8.5' => '8.5.12',
				),
				'conditions' => array(),
				'mitigation' => '',
			),
			array(
				'id'         => 'session-upload-progress',
				'name'       => '',
				'cves'       => array( 'CVE-2026-115560' ),
				'url'        => 'https://www.cve.org/CVERecord?id=CVE-2026-115560',
				'exploited'  => false,
				'since'      => '5.4.0',
				'fixed'      => array(),
				'conditions' => array(
					'session.upload_progress.enabled' => array( '1', 'on', 'On', 'true' ),
					'session.serialize_handler'       => array( 'php_binary', 'php_serialize' ),
				),
				'mitigation' => __( 'Until PHP releases a fix, set session.upload_progress.enabled=0 in php.ini and restart PHP. Your host can do this for you.', 'php-compatibility-checker' ),
			),
		);
	}

	/**
	 * Advisories that affect a PHP version and configuration.
	 *
	 * @since 2.0.0
	 *
	 * @param string  $version    Version as major.minor.release.
	 * @param array[] $advisories See advisories().
	 * @param array   $ini        Current ini values, setting => value.
	 * @return array[] Matching advisories, each with fixed_in (the fix for this branch, or empty if none).
	 */
	public static function affecting( $version, $advisories, $ini = array() ) {
		$parts   = explode( '.', $version );
		$branch  = $parts[0] . '.' . ( isset( $parts[1] ) ? $parts[1] : '0' );
		$matches = array();

		foreach ( $advisories as $advisory ) {
			if ( ! empty( $advisory['since'] ) && version_compare( $version, $advisory['since'], '<' ) ) {
				continue;
			}

			$fixed = isset( $advisory['fixed'] ) ? (array) $advisory['fixed'] : array();
			if ( isset( $fixed[ $branch ] ) ) {
				if ( version_compare( $version, $fixed[ $branch ], '>=' ) ) {
					continue;
				}
			} elseif ( $fixed ) {
				// Branches newer than every fixed one were released after the fix.
				$fixed_branches = array_keys( $fixed );
				usort( $fixed_branches, 'version_compare' );
				if ( version_compare( $branch, end( $fixed_branches ), '>' ) ) {
					continue;
				}
			}

			foreach ( isset( $advisory['conditions'] ) ? (array) $advisory['conditions'] : array() as $setting => $values ) {
				if ( ! isset( $ini[ $setting ] ) || ! in_array( (string) $ini[ $setting ], (array) $values, true ) ) {
					continue 2;
				}
			}

			$advisory['fixed_in'] = isset( $fixed[ $branch ] ) ? $fixed[ $branch ] : '';
			$matches[]            = $advisory;
		}

		return $matches;
	}

	/**
	 * Work out the release status of a PHP version.
	 *
	 * @param string     $version       Version as major.minor.release, e.g. "8.3.35".
	 * @param string     $extra_version PHP_EXTRA_VERSION, e.g. "-1ubuntu1" for distribution builds.
	 * @param array|null $states        php.net states.php data, or null if unavailable.
	 * @param array|null $active        php.net active.php data, or null if unavailable.
	 * @param array[]    $advisories    Critical advisories to check (see advisories()).
	 * @param array      $ini           Current ini values, for advisories that depend on configuration.
	 * @return array {
	 *     @type string   $state                vulnerable, up_to_date, update_available, unsupported_branch, vendor_build, or unknown.
	 *     @type string   $current              The version checked.
	 *     @type string   $branch               Major.minor.
	 *     @type string   $latest               Latest release of the branch, if known.
	 *     @type string   $latest_date          Release date of $latest, if known.
	 *     @type bool     $latest_is_security   Whether $latest is tagged as a security release.
	 *     @type string   $security_support_end Date (Y-m-d) the branch stops/stopped getting security fixes, if known.
	 *     @type string[] $supported_branches   Branches that still get security fixes.
	 *     @type string   $message              Plain-language summary.
	 *     @type array[]  $advisories           Critical advisories that affect this server (see affecting()).
	 * }
	 */
	public static function evaluate( $version, $extra_version, $states, $active, $advisories = array(), $ini = array() ) {
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
			'advisories'           => self::affecting( $version, $advisories, $ini ),
		);

		$status = self::evaluate_release( $status, $version, $extra_version, $branch_state );

		return self::apply_advisories( $status );
	}

	/**
	 * Release state from php.net data alone.
	 *
	 * @param array      $status        Status so far.
	 * @param string     $version       Version as major.minor.release.
	 * @param string     $extra_version PHP_EXTRA_VERSION.
	 * @param array|null $branch_state  php.net state for the branch.
	 * @return array Status.
	 */
	private static function evaluate_release( $status, $version, $extra_version, $branch_state ) {
		$branch = $status['branch'];

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
	 * Fold critical advisories into the state and message.
	 *
	 * Version-based advisories make the state "vulnerable" (unless the branch is
	 * already unsupported or a host build that may carry the fix). Advisories
	 * that depend on configuration are returned in $status['advisories'] with
	 * their mitigation, and don't change the state.
	 *
	 * @param array $status Status from evaluate_release().
	 * @return array Status.
	 */
	private static function apply_advisories( $status ) {
		$by_version = array_values(
			array_filter(
				$status['advisories'],
				static function ( $advisory ) {
					return empty( $advisory['conditions'] );
				}
			)
		);

		if ( ! $by_version ) {
			return $status;
		}

		$names     = implode( '; ', array_map( array( __CLASS__, 'label' ), $by_version ) );
		$exploited = (bool) array_filter( array_column( $by_version, 'exploited' ) );
		$fixes     = array_filter( array_column( $by_version, 'fixed_in' ) );
		usort( $fixes, 'version_compare' );
		$fix = $fixes ? end( $fixes ) : '';

		if ( 'vendor_build' === $status['state'] ) {
			$status['message'] .= ' ' . sprintf(
				/* translators: %s: vulnerability names and CVE IDs. */
				__( 'Confirm with your host that this build includes the fixes for %s.', 'php-compatibility-checker' ),
				$names
			);
			return $status;
		}

		if ( 'unsupported_branch' === $status['state'] ) {
			$status['message'] .= ' ' . sprintf(
				$exploited
					/* translators: %s: vulnerability names and CVE IDs. */
					? __( 'It is also affected by %s, which attackers are actively exploiting, and PHP has no official fix for this version.', 'php-compatibility-checker' )
					/* translators: %s: vulnerability names and CVE IDs. */
					: __( 'It is also affected by %s, and PHP has no official fix for this version.', 'php-compatibility-checker' ),
				$names
			);
			return $status;
		}

		$status['state'] = 'vulnerable';

		if ( '' === $fix ) {
			$status['message'] = sprintf(
				$exploited
					/* translators: 1: PHP version, 2: vulnerability names and CVE IDs. */
					? __( 'PHP %1$s is affected by %2$s, a critical flaw that attackers are actively exploiting to take over servers. There is no official fix for this version: move to a supported PHP version now.', 'php-compatibility-checker' )
					/* translators: 1: PHP version, 2: vulnerability names and CVE IDs. */
					: __( 'PHP %1$s is affected by %2$s, a critical flaw. There is no official fix for this version: move to a supported PHP version.', 'php-compatibility-checker' ),
				$status['current'],
				$names
			);
			return $status;
		}

		$status['message'] = sprintf(
			$exploited
				/* translators: 1: PHP version, 2: vulnerability names and CVE IDs, 3: fixed PHP version. */
				? __( 'PHP %1$s is affected by %2$s, a critical flaw that attackers are actively exploiting to take over servers. Update to PHP %3$s or newer now; your host can do this for you.', 'php-compatibility-checker' )
				/* translators: 1: PHP version, 2: vulnerability names and CVE IDs, 3: fixed PHP version. */
				: __( 'PHP %1$s is affected by %2$s, a critical flaw. Update to PHP %3$s or newer; your host can do this for you.', 'php-compatibility-checker' ),
			$status['current'],
			$names,
			$fix
		);

		return $status;
	}

	/**
	 * "PHP2Shell (CVE-…, CVE-…)", or just the CVE IDs when there is no name.
	 *
	 * @param array $advisory Advisory.
	 * @return string
	 */
	public static function label( $advisory ) {
		$cves = implode( ', ', (array) $advisory['cves'] );
		return '' !== $advisory['name'] ? sprintf( '%1$s (%2$s)', $advisory['name'], $cves ) : $cves;
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
