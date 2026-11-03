<?php
/**
 * Site Health: a test for the active plugins and theme, and an Info section.
 *
 * @package WPEngine_PHPCompat
 */

declare( strict_types=1 );

namespace WPEngine_PHPCompat;

/**
 * Puts the stored scan results where admins already look: Tools > Site Health.
 *
 * Reads stored results only; it never scans. The test covers active plugins
 * and the active theme, since those are what run on the site.
 */
class Site_Health {

	/**
	 * Test ID in Site Health.
	 */
	const TEST = 'php_compatibility_checker';

	/**
	 * Source of items and stored results.
	 *
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 *
	 * @param Scanner|null $scanner Scanner.
	 */
	public function __construct( ?Scanner $scanner = null ) {
		$this->scanner = $scanner ? $scanner : new Scanner();
	}

	/**
	 * Hook into Site Health.
	 */
	public static function init(): void {
		$health = new self();
		add_filter( 'site_status_tests', array( $health, 'register_test' ) );
		add_filter( 'debug_information', array( $health, 'debug_information' ) );
	}

	/**
	 * Add the test.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function register_test( $tests ) {
		$tests['direct'][ self::TEST ] = array(
			'label' => __( 'PHP compatibility of plugins and themes', 'php-compatibility-checker' ),
			'test'  => array( $this, 'test' ),
		);
		return $tests;
	}

	/**
	 * Run the test from stored results.
	 *
	 * @return array Site Health result.
	 */
	public function test(): array {
		$scan     = $this->scanner->scan( $this->scanner->get_items( array( 'active_only' => true ) ) );
		$items    = $scan['items'];
		$problems = self::with_status( $items, 'problems' );
		$unknown  = self::with_status( $items, 'not_scanned' );
		$mismatch = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return ! empty( $item['needs_php'] );
				}
			)
		);
		$max      = $scan['max_php_checked'];

		$result = array(
			'label'       => __( 'Your active plugins and theme passed the PHP compatibility check', 'php-compatibility-checker' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Compatibility', 'php-compatibility-checker' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html(
				sprintf(
					/* translators: %s: newest PHP version checked. */
					__( 'No problems were found up to PHP %s, so they are ready for a PHP upgrade as far as static analysis can tell.', 'php-compatibility-checker' ),
					$max
				)
			) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( add_query_arg( 'page', WPEPHPCOMPAT_ADMIN_PAGE_SLUG, admin_url( 'tools.php' ) ) ),
				esc_html__( 'Open the PHP Compatibility Checker', 'php-compatibility-checker' )
			),
			'test'        => self::TEST,
		);

		if ( count( $unknown ) === count( $items ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Check your plugins and themes for PHP compatibility', 'php-compatibility-checker' );
			$result['description'] = '<p>' . esc_html__( 'Before you or your host upgrade PHP, check that your active plugins and theme work on newer versions. The PHP Compatibility Checker scans them on this site, a few files at a time.', 'php-compatibility-checker' ) . '</p>';
			if ( ! Scanner_Bundle::scanning_enabled() ) {
				$result['description'] .= '<p>' . esc_html( Scanner_Bundle::disabled_message() ) . '</p>';
			}
			return $result;
		}

		$lines = array();
		foreach ( $problems as $item ) {
			$lines[] = sprintf(
				/* translators: 1: plugin or theme name, 2: version, 3: PHP versions. */
				__( '%1$s %2$s: problems on PHP %3$s', 'php-compatibility-checker' ),
				$item['name'],
				$item['version'],
				implode( ', ', $item['failing_versions'] )
			);
		}
		foreach ( $mismatch as $item ) {
			$needs   = $item['needs_php'];
			$lines[] = '' !== $needs['declared']
				? sprintf(
					/* translators: 1: plugin or theme name, 2: PHP version it needs, 3: PHP version it says it needs. */
					__( '%1$s needs PHP %2$s, but says it requires PHP %3$s', 'php-compatibility-checker' ),
					$item['name'],
					$needs['needed'],
					$needs['declared']
				)
				: sprintf(
					/* translators: 1: plugin or theme name, 2: PHP version it needs. */
					__( "%1\$s needs PHP %2\$s, but doesn't say which PHP it requires", 'php-compatibility-checker' ),
					$item['name'],
					$needs['needed']
				);
		}

		$notes = array();
		if ( $unknown ) {
			$notes[] = sprintf(
				/* translators: %d: number of plugins and themes. */
				_n( '%d active plugin or theme has not been scanned with its current version.', '%d active plugins and themes have not been scanned with their current versions.', count( $unknown ), 'php-compatibility-checker' ),
				count( $unknown )
			);
		}

		if ( $lines ) {
			$result['status']      = 'recommended';
			$result['label']       = $problems
				? __( 'Some active plugins or themes have problems on newer PHP versions', 'php-compatibility-checker' )
				: __( 'Some active plugins or themes need a newer PHP than they say', 'php-compatibility-checker' );
			$result['description'] = '<p>' . esc_html__( 'Check for updates first: a newer version may already be compatible. Problems in bundled libraries are often guarded by runtime checks, so test on a staging site before treating them as blockers.', 'php-compatibility-checker' ) . '</p><ul class="ul-disc">' . implode(
				'',
				array_map(
					static function ( $line ) {
						return '<li>' . esc_html( $line ) . '</li>';
					},
					$lines
				)
			) . '</ul>';
		} elseif ( $unknown ) {
			$result['status'] = 'recommended';
			$result['label']  = __( 'Some active plugins or themes have not been checked for PHP compatibility', 'php-compatibility-checker' );
		}

		foreach ( $notes as $note ) {
			$result['description'] .= '<p>' . esc_html( $note ) . '</p>';
		}

		return $result;
	}

	/**
	 * A section on the Site Health Info tab, so the results go into "Copy site info".
	 *
	 * @param array $info Debug information sections.
	 * @return array
	 */
	public function debug_information( $info ) {
		$scan   = $this->scanner->scan( $this->scanner->get_items() );
		$fields = array(
			'scanner' => array(
				'label' => __( 'Scanner', 'php-compatibility-checker' ),
				'value' => Scanner_Bundle::tools(),
			),
		);

		foreach ( $scan['items'] as $item ) {
			$fields[ $item['type'] . '-' . $item['slug'] ] = array(
				'label' => sprintf( '%s %s', $item['name'], $item['version'] ) . ( $item['active'] ? '' : ' ' . __( '(inactive)', 'php-compatibility-checker' ) ),
				'value' => self::status_text( $item ),
				'debug' => self::status_debug( $item ),
			);
		}

		$info['php-compatibility-checker'] = array(
			'label'       => __( 'PHP Compatibility', 'php-compatibility-checker' ),
			'description' => __( 'Stored results of the PHP Compatibility Checker. Run a scan in Tools > PHP Compatibility to update them.', 'php-compatibility-checker' ),
			'fields'      => $fields,
		);

		return $info;
	}

	/**
	 * Items with one status.
	 *
	 * @param array[] $items  Results.
	 * @param string  $status Status.
	 * @return array[]
	 */
	private static function with_status( array $items, string $status ): array {
		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $status ) {
					return $status === $item['status'];
				}
			)
		);
	}

	/**
	 * One item's result, for people.
	 *
	 * @param array $item Result.
	 * @return string
	 */
	public static function status_text( array $item ): string {
		switch ( $item['status'] ) {
			case 'passes':
				$text = sprintf(
					/* translators: %s: newest PHP version checked. */
					__( 'Passes, up to PHP %s', 'php-compatibility-checker' ),
					$item['max_php_checked']
				);
				break;
			case 'problems':
				$text = sprintf(
					/* translators: %s: PHP versions. */
					__( 'Problems on PHP %s', 'php-compatibility-checker' ),
					implode( ', ', $item['failing_versions'] )
				);
				break;
			case 'not_scanned':
				return __( 'Not scanned with this version', 'php-compatibility-checker' );
			default:
				return (string) $item['reason'];
		}

		if ( ! empty( $item['needs_php'] ) ) {
			$text .= '; ' . sprintf(
				/* translators: %s: PHP version. */
				__( 'needs PHP %s', 'php-compatibility-checker' ),
				$item['needs_php']['needed']
			);
		}

		return $text;
	}

	/**
	 * One item's result for "Copy site info" (not translated, for support).
	 *
	 * @param array $item Result.
	 * @return string
	 */
	private static function status_debug( array $item ): string {
		$text = $item['status'];
		if ( $item['failing_versions'] ) {
			$text .= ' (' . implode( ', ', $item['failing_versions'] ) . ')';
		}
		if ( ! empty( $item['needs_php'] ) ) {
			$text .= '; needs PHP ' . $item['needs_php']['needed'] . ' (' . $item['needs_php']['source'] . ')';
		}
		return $text;
	}
}
