<?php
/**
 * Host details for the panel beside the scan results.
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat;

/**
 * What the scan page tells people about changing PHP on their host.
 *
 * Hosts describe themselves through the wpephpcompat_host filter. WP Engine
 * is one of them, registered like any other. With no host details, the page
 * falls back to the PHP guide URL WordPress core already lets hosts set
 * (WP_UPDATE_PHP_URL or the wp_update_php_url filter).
 *
 * @since 2.0.0
 */
class Hosting {

	/**
	 * Register the built-in host providers.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		add_filter( 'wpephpcompat_host', array( __CLASS__, 'wp_engine' ), 5 );
	}

	/**
	 * Host details for this site, or null to leave the panel out.
	 *
	 * @since 2.0.0
	 *
	 * @return array|null {
	 *     @type string $name       Host name, or empty if unknown.
	 *     @type string $change_php HTML: how to try a newer PHP on this host.
	 *     @type array  $links      Links shown under it: list of { label, url }. The first is a button.
	 *     @type array  $help       Shown when a scan finds problems: { text, links }.
	 * }
	 */
	public static function get() {
		/**
		 * Filters the host details shown beside the scan results.
		 *
		 * Return an array to show the panel, or false to hide it. Keys:
		 *
		 * - name (string): host name, e.g. "Example Hosting".
		 * - change_php (string): how to try a newer PHP on this host. Basic HTML is allowed;
	 *   a blank line starts a new paragraph.
		 * - links (array): list of array( 'label' => ..., 'url' => ... ). The first is shown as a button.
		 * - help (array): array( 'text' => ..., 'links' => array( ... ) ), shown only when a scan finds problems.
		 *
		 * @since 2.0.0
		 *
		 * @param array|false $host Host details. Defaults to a panel linking to the host's PHP
		 *                          guide when WP_UPDATE_PHP_URL or wp_update_php_url sets one,
		 *                          otherwise false.
		 */
		$host = apply_filters( 'wpephpcompat_host', self::default_host() );

		return is_array( $host ) ? self::normalize( $host ) : null;
	}

	/**
	 * The panel for a host that set a PHP guide URL through WordPress core.
	 *
	 * @since 2.0.0
	 *
	 * @return array|false False when the URL is still WordPress.org's default.
	 */
	public static function default_host() {
		if ( ! function_exists( 'wp_get_update_php_url' ) || wp_get_update_php_url() === wp_get_default_update_php_url() ) {
			return false;
		}

		return array(
			'name'       => '',
			'change_php' => __( 'Your host sets the PHP version. Try a newer one on a staging copy of the site first, if your host offers one.', 'php-compatibility-checker' ),
			'links'      => array(
				array(
					'label' => __( 'How to change PHP on your host', 'php-compatibility-checker' ),
					'url'   => wp_get_update_php_url(),
				),
			),
		);
	}

	/**
	 * Details for sites hosted on WP Engine.
	 *
	 * @since 2.0.0
	 *
	 * @param array|false $host Host details from earlier filters.
	 * @return array|false
	 */
	public static function wp_engine( $host ) {
		if ( ! function_exists( '\is_wpe' ) || ! \is_wpe() ) {
			return $host;
		}

		$checker = PHP_Compatibility_Checker::instance();

		return array(
			'name'       => 'WP Engine',
			'change_php' => __( '<strong>Preview it:</strong> in the User Portal, open this environment and click <strong>Preview PHP</strong>. Only your browser sees the change.', 'php-compatibility-checker' )
				. "\n\n" . __( '<strong>Or use a copy:</strong> change PHP on a staging or development environment first.', 'php-compatibility-checker' ),
			'links'      => array(
				array(
					'label' => defined( 'PWP_NAME' ) ? __( 'Open this environment', 'php-compatibility-checker' ) : __( 'Open the User Portal', 'php-compatibility-checker' ),
					'url'   => $checker->get_wpe_portal_url( 'php_compatibility_checker_user_portal' ),
				),
				array(
					'label' => __( 'WP Engine PHP guide', 'php-compatibility-checker' ),
					'url'   => 'https://wpeng.in/407b79/', // Short link already carries campaign tracking.
				),
			),
			'help'       => array(
				'text'  => __( 'If an update doesn\'t fix a plugin or theme, or the problems are in custom code, a developer can help.', 'php-compatibility-checker' ),
				'links' => array(
					array(
						'label' => __( 'WP Engine agency partners', 'php-compatibility-checker' ),
						'url'   => $checker->add_wpe_campaign( 'https://wpengine.com/agency-directory/', 'php_compatibility_checker_agency_partners' ),
					),
				),
			),
		);
	}

	/**
	 * Fill in missing keys and drop links without a label or URL.
	 *
	 * @since 2.0.0
	 *
	 * @param array $host Host details from the filter.
	 * @return array
	 */
	public static function normalize( array $host ) {
		$host = array_merge(
			array(
				'name'       => '',
				'change_php' => '',
				'links'      => array(),
				'help'       => array(),
			),
			$host
		);

		$host['name']       = (string) $host['name'];
		$host['change_php'] = (string) $host['change_php'];
		$host['links']      = self::normalize_links( $host['links'] );

		$help         = is_array( $host['help'] ) ? $host['help'] : array();
		$host['help'] = array(
			'text'  => isset( $help['text'] ) ? (string) $help['text'] : '',
			'links' => self::normalize_links( isset( $help['links'] ) ? $help['links'] : array() ),
		);

		return $host;
	}

	/**
	 * Keep links that have both a label and a URL.
	 *
	 * @param mixed $links List of { label, url }.
	 * @return array
	 */
	private static function normalize_links( $links ) {
		$clean = array();

		foreach ( is_array( $links ) ? $links : array() as $link ) {
			if ( is_array( $link ) && ! empty( $link['label'] ) && ! empty( $link['url'] ) ) {
				$clean[] = array(
					'label' => (string) $link['label'],
					'url'   => (string) $link['url'],
				);
			}
		}

		return $clean;
	}
}
