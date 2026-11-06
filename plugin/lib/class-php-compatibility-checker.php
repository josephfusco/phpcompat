<?php
/**
 * Registers and sets up all needed PHP compat logic.
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat;

/**
 * Main PHP Compat class.
 */
class PHP_Compatibility_Checker {

	/**
	 * Contains singleton instance.
	 *
	 * @since 1.0.0
	 * @static
	 * @var WPEngine_PHPCompat|null
	 */
	private static $instance = null;

	/**
	 * Hook suffix of the admin page, set by create_menu().
	 *
	 * @since 1.6.5
	 * @var string|false|null
	 */
	public $page = null;

	/**
	 * Returns an instance of this class.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return WPEngine_PHPCompat An instance of this class.
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Enqueue any needed scripts and styles.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_scripts( $hook_suffix = '' ) {
		// Only load on our scan admin page.
		if ( 'tools_page_wpe-php-compat' !== $hook_suffix ) {
			return;
		}

		$assets_file = dirname( __DIR__ ) . '/build/scan.asset.php';

		if ( ! file_exists( $assets_file ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
					<div class="notice notice-error is-dismissible">
						<p><?php echo wp_kses_post( __( 'Looks like you are using a development version of <strong>PHP Compatibility Checker</strong>. Please run <code>make build</code> to build assets.', 'php-compatibility-checker' ) ); ?></p>
					</div>
					<?php
				}
			);

			return;
		}

		// phpcs:ignore PEAR.Files.IncludingFile.UseIncludeOnce
		$ajax_js_assets = require_once $assets_file;

		$scan_css = '../build/scan.css';
		$ajax_js  = '../build/scan.js';

		// Use core's design tokens (WordPress 7.0+) when available. The stylesheet
		// falls back to plain colors on older versions.
		$style_deps = wp_style_is( 'wp-theme', 'registered' ) ? array( 'wp-theme' ) : array();

		wp_enqueue_style(
			'phpcompat-scan',
			plugins_url( $scan_css, __FILE__ ),
			$style_deps,
			$ajax_js_assets['version']
		);

		wp_register_script(
			'phpcompat-scan',
			plugins_url( $ajax_js, __FILE__ ),
			array_merge( $ajax_js_assets['dependencies'], array( 'wp-util' ) ),
			$ajax_js_assets['version'],
			true
		);

		wp_localize_script(
			'phpcompat-scan',
			'checkerList',
			array(
				'plugins' => $this->get_plugins_to_scan(),
				'themes'  => $this->get_themes_to_scan(),
				'scanner' => Scanner_Bundle::browser_config(),
				'site'    => array(
					'url'            => home_url(),
					'name'           => get_bloginfo( 'name' ),
					'php'            => PHP_VERSION,
					'wp'             => get_bloginfo( 'version' ),
					'plugin_version' => WPEPHPCOMPAT_VERSION,
					'maker_url'      => $this->add_wpe_campaign( 'https://wpengine.com/', 'php_compatibility_checker_report' ),
				),
			)
		);

		wp_set_script_translations( 'phpcompat-scan', 'php-compatibility-checker' );

		wp_enqueue_script( 'phpcompat-scan' );
	}

	/**
	 * Setup our hooks.
	 */
	public function init() {
		$instance = self::instance();

		// Build our tools page.
		add_action( 'admin_menu', array( $instance, 'create_menu' ) );

		// Enqueue scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_scripts' ) );
	}

	/**
	 * Get all plugins that should be scanned.
	 *
	 * @return array
	 */
	public function get_plugins_to_scan() {
		if ( ! function_exists( 'get_plugins' ) ) {
			// phpcs:ignore PEAR.Files.IncludingFile.UseIncludeOnce -- strict requirement
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		/**
		 * Filter which plugins should be excluded from scans.
		 *
		 * This will exclude based on the plugin name, not the plugin slug.
		 *
		 * @param string[] $excluded_plugins Plugins we want to exclude.
		 *
		 * @since 1.6.0
		 */
		$excluded_plugins = apply_filters( 'phpcompat_excluded_plugins', array( 'PHP Compatibility Checker', 'Hello Dolly' ) );

		// Exclude some plugins.
		$plugins = array_filter(
			$plugins,
			function ( $plugin_data ) use ( $excluded_plugins ) {
				return ! in_array( $plugin_data['Name'], $excluded_plugins, true );
			}
		);

		if ( empty( $plugins ) ) {
			/** This filter is documented below. */
			return apply_filters( 'phpcompat_plugins_to_scan', array() );
		}

		$active_plugins = get_option( 'active_plugins' );

		// On multisite, get network active plugins.
		if ( is_multisite() ) {
			$active_plugins = array_merge(
				$active_plugins,
				array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
			);
		}

		// Add "active" attribute.
		$plugins = array_map(
			function ( $plugin_file, $plugin_data ) use ( $active_plugins ) {
				$plugin_data['plugin_file'] = $plugin_file;
				$plugin_data['active']      = in_array( $plugin_file, $active_plugins, true ) ? 'yes' : 'no';
				return $plugin_data;
			},
			array_keys( $plugins ),
			$plugins
		);

		$plugin_info = get_site_transient( 'update_plugins' );

		// Extract real plugin slugs from the update_plugins transient.
		foreach ( $plugins as $key => $plugin_data ) {
			$plugin_file = $plugin_data['plugin_file'];

			$plugins[ $key ]['update_version'] = '';
			$plugins[ $key ]['source']         = 'local';

			$update = null;
			if ( isset( $plugin_info->response[ $plugin_file ] ) ) {
				$update = $plugin_info->response[ $plugin_file ];
				// WordPress already knows a newer version exists; suggest it.
				if ( ! empty( $update->new_version ) ) {
					$plugins[ $key ]['update_version'] = $update->new_version;
				}
			} elseif ( isset( $plugin_info->no_update[ $plugin_file ] ) ) {
				$update = $plugin_info->no_update[ $plugin_file ];
			}

			if ( $update && ! empty( $update->slug ) ) {
				$plugins[ $key ]['slug'] = $update->slug;
				// Premium updaters add entries too; only "w.org/" IDs are WordPress.org.
				if ( isset( $update->id ) && 0 === strpos( (string) $update->id, 'w.org/' ) ) {
					$plugins[ $key ]['source'] = 'wporg';
				}
			} else {
				// One-file plugins (e.g. my-tweaks.php) have no folder: use the file name.
				$plugins[ $key ]['slug'] = false === strpos( $plugin_file, '/' ) ? basename( $plugin_file, '.php' ) : dirname( $plugin_file );
			}
		}

		// Compact output.
		$plugins = array_map(
			function ( $plugin ) {
				return array(
					'slug'           => sanitize_title( $plugin['slug'] ),
					'name'           => sanitize_text_field( $plugin['Name'] ),
					'version'        => sanitize_text_field( $plugin['Version'] ),
					'active'         => $plugin['active'],
					'requires_php'   => self::sanitize_php_version( isset( $plugin['RequiresPHP'] ) ? $plugin['RequiresPHP'] : '' ),
					'update_version' => sanitize_text_field( $plugin['update_version'] ),
					'source'         => $plugin['source'],
					'file'           => $plugin['plugin_file'],
				);
			},
			$plugins
		);

		/**
		 * Filter which plugins should be scanned.
		 *
		 * @param array[] $plugins {
		 *     List of plugins.
		 *
		 *     @type string $slug Plugin slug.
		 *     @type string $name Plugin name.
		 *     @type string $version Plugin version.
		 *     @type string $active Whether the plugin is active (yes or no).
		 *     @type string $requires_php Minimum PHP version from the plugin's "Requires PHP" header, or empty.
		 *     @type string $update_version Newer version available from WordPress updates, or empty.
		 *     @type string $source "wporg" if WordPress.org hosts it, otherwise "local".
		 *     @type string $file Plugin file relative to the plugins directory.
		 * }
		 *
		 * @since 1.6.0
		 */
		return apply_filters( 'phpcompat_plugins_to_scan', $plugins );
	}

	/**
	 * Get all themes that should be scanned.
	 *
	 * @return array
	 */
	public function get_themes_to_scan() {
		/**
		 * Filter which themes should be excluded from scans.
		 *
		 * This will exclude based on the theme name, not the theme slug.
		 *
		 * @param string[] $excluded_themes Themes we want to exclude.
		 *
		 * @since 1.6.0
		 */
		$excluded_themes = apply_filters( 'phpcompat_excluded_themes', array() );

		// wp_get_themes() works in every context (wp-admin, WP-CLI, REST), unlike
		// wp-admin's wp_prepare_themes_for_js(). "allowed" respects multisite settings.
		$current = get_stylesheet();
		$themes  = array();
		$updates = get_site_transient( 'update_themes' );

		foreach ( wp_get_themes(
			array(
				'errors'  => false,
				'allowed' => true,
			)
		) as $stylesheet => $theme ) {
			$name = $theme->get( 'Name' );

			if ( in_array( $name, $excluded_themes, true ) ) {
				continue;
			}

			$themes[] = array(
				'slug'           => sanitize_title( $stylesheet ),
				'name'           => sanitize_text_field( $name ),
				'version'        => sanitize_text_field( $theme->get( 'Version' ) ),
				'active'         => $stylesheet === $current ? 'yes' : 'no',
				'requires_php'   => self::sanitize_php_version( $theme->get( 'RequiresPHP' ) ),
				'update_version' => isset( $updates->response[ $stylesheet ]['new_version'] ) ? sanitize_text_field( $updates->response[ $stylesheet ]['new_version'] ) : '',
				'source'         => self::is_wporg_theme( $updates, $stylesheet ) ? 'wporg' : 'local',
				'file'           => (string) $stylesheet,
			);
		}

		// Active theme first, then by name (as on the Themes screen).
		usort(
			$themes,
			static function ( $a, $b ) {
				if ( $a['active'] !== $b['active'] ) {
					return 'yes' === $a['active'] ? -1 : 1;
				}
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		/**
		 * Filter which themes should be scanned.
		 *
		 * @param array[] $themes {
		 *     List of themes.
		 *
		 *     @type string $slug Theme slug.
		 *     @type string $name Theme name.
		 *     @type string $version Theme version.
		 *     @type string $active Whether the theme is active (yes or no).
		 *     @type string $requires_php Minimum PHP version from the theme's "Requires PHP" header, or empty.
		 * }
		 *
		 * @since 1.6.0
		 */
		return apply_filters( 'phpcompat_themes_to_scan', $themes );
	}

	/**
	 * Add the scan page to the wp-admin menu.
	 *
	 * @since 1.0.0
	 *
	 * @action admin_menu
	 */
	public function create_menu() {
		// Create under Tools sub-menu.
		$this->page = add_submenu_page( 'tools.php', esc_html__( 'PHP Compatibility', 'php-compatibility-checker' ), esc_html__( 'PHP Compatibility', 'php-compatibility-checker' ), WPEPHPCOMPAT_CAPABILITY, WPEPHPCOMPAT_ADMIN_PAGE_SLUG, array( self::instance(), 'scan_page' ) );
	}

	/**
	 * Render method for the scan page.
	 *
	 * @since 1.0.0
	 */
	public function scan_page() {

		// Whether the server's PHP is the latest release of a supported branch.
		$release = ( new Php_Releases() )->get_status();

		// Links for the WP Engine sidebar, shown only on sites hosted on WP Engine.
		$url_wpe_portal          = $this->get_wpe_portal_url( 'php_compatibility_checker_user_portal' );
		$url_wpe_logo            = $this->get_wpe_portal_url( 'php_compatibility_checker_logo' );
		$url_wpe_php_guide       = 'https://wpeng.in/407b79/'; // Short link already carries campaign tracking.
		$url_wpe_agency_partners = $this->add_wpe_campaign( 'https://wpengine.com/agency-directory/', 'php_compatibility_checker_agency_partners' );
		?>

		<div class="wrap wpe-pcc-wrap">
			<h1><?php esc_html_e( 'PHP Compatibility Checker', 'php-compatibility-checker' ); ?></h1>
			<div class="wpe-pcc-layout<?php echo function_exists( '\\is_wpe' ) && \is_wpe() ? ' wpe-pcc-has-aside' : ''; ?>">
			<div class="wpe-pcc-main">
				<p><?php esc_html_e( 'The PHP Compatibility Checker can be used on any WordPress website on any web host.', 'php-compatibility-checker' ); ?></p>
				<p>
					<?php
					printf(
						/* translators: 1: PHPCompatibility URL, 2: list of PHP versions. */
						wp_kses_post( __( 'This tool checks your plugins and themes with <a target="_blank" href="%1$s">PHPCompatibility</a> against PHP %2$s. Each problem shows the file, line, and why it breaks on that PHP version. The check runs in your browser on files this site sends a few at a time, so it barely uses your host\'s resources, and no code is sent anywhere else. A plugin or theme is only checked again when its files change. If something fails, check for an update first: a newer version may already be compatible.', 'php-compatibility-checker' ) ),
						esc_url( 'https://github.com/PHPCompatibility/PHPCompatibilityWP' ),
						esc_html( implode( ', ', Scanner_Bundle::PHP_VERSIONS ) )
					);
					?>
				</p>
				<?php $this->release_notice( $release ); ?>
				<hr>
				<div class="wpe-pcc-scan-options">
					<h2><?php esc_html_e( 'Scan Options', 'php-compatibility-checker' ); ?></h2>
					<table class="form-table wpe-pcc-form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Plugin / Theme Status', 'php-compatibility-checker' ); ?></th>
								<td>
									<fieldset>
										<legend class="screen-reader-text"><?php esc_html_e( 'Plugin / Theme Status', 'php-compatibility-checker' ); ?></legend>
										<label><input type="radio" name="active_plugins" value="yes" checked="checked" /> <?php esc_html_e( 'Only scan active plugins and theme', 'php-compatibility-checker' ); ?></label><br>
										<label><input type="radio" name="active_plugins" value="no" /> <?php esc_html_e( 'Scan all plugins and themes', 'php-compatibility-checker' ); ?></label><br>
										<label><input type="radio" name="active_plugins" value="choose" /> <?php esc_html_e( 'Choose which plugins and themes to scan', 'php-compatibility-checker' ); ?></label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"></th>
								<td>
									<div class="wpe-pcc-run-scan">
										<input name="run" id="runButton" type="button" value="<?php esc_attr_e( 'Scan site', 'php-compatibility-checker' ); ?>" class="button button-primary" <?php disabled( ! Scanner_Bundle::scanning_enabled() ); ?> />
										<div class="wpe-pcc-scan-information">
											<span style="display:none; visibility:visible;" class="spinner wpe-pcc-spinner"></span>
											<span class="wpe-progress-active wpe-pcc-progress" style="display:none;">
												<?php esc_html_e( 'Now scanning:', 'php-compatibility-checker' ); ?>
												<span class="wpe-pcc-progress-count"></span>
												<a href="#" id="cancelButton" class="wpe-pcc-cancel"><?php esc_html_e( 'Cancel', 'php-compatibility-checker' ); ?></a>
											</span>
										</div> <!-- /wpe-pcc-scan-information -->
									</div> <!-- /wpe-pcc-run-scan -->
									<?php if ( ! Scanner_Bundle::scanning_enabled() ) : ?>
										<div class="notice notice-info inline wpe-pcc-disabled"><p><?php echo esc_html( Scanner_Bundle::disabled_message() ); ?></p></div>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div> <!-- /wpe-pcc-scan-options -->

				<div class="wpe-pcc-results" style="display:none;">
					<hr>
					<h2><?php esc_html_e( 'Scan Results for PHP Compatibility', 'php-compatibility-checker' ); ?></h2>

					<div id="wpe-pcc-download-report" class="wpe-pcc-download-report" style="display:none;">
						<label class="screen-reader-text" for="wpe-pcc-export-format"><?php esc_html_e( 'Report format', 'php-compatibility-checker' ); ?></label>
						<select id="wpe-pcc-export-format">
							<option value="pdf" selected="selected"><?php esc_html_e( 'PDF', 'php-compatibility-checker' ); ?></option>
							<option value="txt"><?php esc_html_e( 'Text', 'php-compatibility-checker' ); ?></option>
							<option value="csv"><?php esc_html_e( 'CSV', 'php-compatibility-checker' ); ?></option>
							<option value="json"><?php esc_html_e( 'JSON', 'php-compatibility-checker' ); ?></option>
						</select>
						<a id="downloadReport" class="button button-primary" href="#"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download Report', 'php-compatibility-checker' ); ?></a>
						<a href="#" class="wpe-pcc-clear-results" id="cleanupButton"><?php esc_html_e( 'Clear results', 'php-compatibility-checker' ); ?></a>
						<label class="wpe-pcc-developer-mode">
							<input type="checkbox" id="developermode" name="developermode" value="yes" />
							<?php esc_html_e( 'View results as raw text', 'php-compatibility-checker' ); ?>
						</label>
						<hr>
					</div> <!-- /wpe-pcc-download-report -->

					<div class="wpe-pcc-select-actions" style="display:none;">
						<button type="button" class="button-link" data-select="all"><?php esc_html_e( 'Select all', 'php-compatibility-checker' ); ?></button> |
						<button type="button" class="button-link" data-select="none"><?php esc_html_e( 'Select none', 'php-compatibility-checker' ); ?></button>
					</div>

					<div id="wpe_pcc_results"></div>

					<div style="display:none;" id="developerMode">
						<textarea readonly="readonly" id="testResults" aria-label="<?php esc_attr_e( 'Scan results as text', 'php-compatibility-checker' ); ?>"></textarea>
					</div>

					<p class="wpe-pcc-attention wpe-pcc-playground" style="display:none;">
						<?php
						printf(
							/* translators: %s: WordPress Playground URL */
							wp_kses_post( __( '<strong>Attention:</strong> Not all errors are show-stoppers. <a id="playgroundLink" target="_blank" href="%s">Try the plugins and themes with problems in WordPress Playground on the latest PHP</a> to see if they just work. Playground runs a temporary WordPress site in your browser, without this site\'s content or settings.', 'php-compatibility-checker' ) ),
							esc_url( 'https://playground.wordpress.net/' )
						);
						?>
					</p>

				</div> <!-- /wpe-pcc-results -->

				<div class="wpe-pcc-footer">
					<hr>
					<strong><?php esc_html_e( 'Limitations &amp; Caveats', 'php-compatibility-checker' ); ?></strong>
					<ul class="wpe-pcc-bullets ul-disc">
						<li><?php esc_html_e( 'This tool does not execute your theme or plugin code, so it cannot detect runtime compatibility issues.', 'php-compatibility-checker' ); ?></li>
						<li><?php esc_html_e( 'PHP Warnings could cause compatibility issues with future PHP versions and/or spam your logs.', 'php-compatibility-checker' ); ?></li>
						<li><?php esc_html_e( 'Problems in bundled libraries (such as a vendor folder) are often false positives, because libraries usually check for PHP features before using them.', 'php-compatibility-checker' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: GitHub issues URL */
								wp_kses_post( __( 'Report false positives <a target="_blank" href="%s">on our GitHub repo</a>.', 'php-compatibility-checker' ) ),
								'https://github.com/wpengine/phpcompat/issues'
							);
							?>
						</li>
					</ul>
				</div> <!-- /wpe-pcc-footer -->
			</div> <!-- /wpe-pcc-main -->

			<?php if ( function_exists( '\is_wpe' ) && \is_wpe() ) : ?>
				<div class="wpe-pcc-aside">
					<a class="wpe-pcc-logo" href="<?php echo esc_url( $url_wpe_logo ); ?>" target="_blank" aria-label="<?php esc_attr_e( 'WP Engine User Portal', 'php-compatibility-checker' ); ?>"><svg width="182" height="34" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 268.3 51"><g fill="#40BAC8"><path d="M17.4 51h16.4V38.6l-4-4h-8.5l-3.9 4zM38.6 17.3l-3.9 3.9v8.6l3.9 3.9h12.5V17.3zM33.8 0H17.4v12.5l3.9 3.9h8.5l4-3.9zM51.1 51V38.6l-3.9-4H34.7V51zM4 0L.1 3.9v12.5h16.4V0zM34.7 0v12.5l3.9 3.9h12.5V0zM25.6 27.9c-1.3 0-2.3-1.1-2.3-2.3 0-1.3 1.1-2.3 2.3-2.3 1.3 0 2.3 1.1 2.3 2.3 0 1.2-1 2.3-2.3 2.3zM16.5 17.3H.1v16.4h12.4l4-3.9zM16.5 38.6l-4-4H.1V51h12.4l4-3.9z"/></g><g fill="#162A33"><path d="M86.2 38.6c-.3 0-.4-.1-.5-.4l-4.1-14.5h-.1l-4.1 14.5c-.1.3-.2.4-.5.4h-4.8c-.3 0-.4-.1-.5-.4l-7-25.2c0-.2 0-.4.3-.4h6.3c.3 0 .5.2.5.4L75 28.1h.1l4-15.1c.1-.3.2-.4.5-.4h3.9c.3 0 .4.1.5.4l4.2 15.1h.1L91.5 13c0-.2.2-.4.5-.4h6.3c.2 0 .3.2.3.4l-7 25.2c-.1.3-.2.4-.5.4h-4.9zM103.6 38.6c-.2 0-.4-.2-.4-.4V13c0-.2.2-.4.4-.4H114c6.3 0 9.6 3.6 9.6 8.6s-3.3 8.7-9.6 8.7h-3.8c-.2 0-.2.1-.2.2v8c0 .2-.2.4-.4.4h-6zm13.3-17.3c0-1.8-1.2-2.9-3.3-2.9h-3.4c-.2 0-.2.1-.2.2V24c0 .2.1.2.2.2h3.4c2.1 0 3.3-1.2 3.3-2.9zM132.5 32.2c-.5-1.4-.7-3.1-.7-6.5 0-3.3.3-5.1.7-6.5 1.3-4.1 4.5-6.2 8.6-6.2 4.2 0 7.3 2.1 8.6 6.2.5 1.4.7 3 .7 6.1 0 .3-.2.5-.6.5h-16.3c-.2 0-.3.2-.3.4 0 2.7.2 4.2.6 5.5 1.2 3.7 3.9 5.3 7.5 5.3 3.4 0 5.9-1.5 7.4-3.5.2-.3.5-.3.7-.1l.3.3c.3.2.3.5.1.7-1.7 2.4-4.6 4.1-8.4 4.1-4.5 0-7.5-2.1-8.9-6.3zm16.2-7.8c.2 0 .3-.1.3-.3 0-1.7-.2-3.1-.6-4.3-1.1-3.5-3.7-5.3-7.2-5.3s-6.1 1.7-7.2 5.3c-.4 1.2-.6 2.5-.6 4.3 0 .2.1.3.3.3h15zM173.6 38c-.3 0-.5-.2-.5-.5V22.9c0-5.8-2.4-8.3-7.1-8.3-4.1 0-7.5 2.8-7.5 7.6v15.4c0 .3-.2.5-.5.5h-.5c-.3 0-.5-.2-.5-.5V14.2c0-.3.2-.5.5-.5h.5c.3 0 .5.2.5.5v3.4h.1c1.2-2.8 4-4.5 7.5-4.5 5.5 0 8.6 3.1 8.6 9.4v15c0 .3-.2.5-.5.5h-.6zM182 44.3c-.2-.3-.2-.6.1-.7l.4-.3c.3-.2.5-.1.7.2 1.4 1.8 3.5 2.9 6.5 2.9 4.6 0 7.6-2.3 7.6-8.3V34h-.1c-1.2 2.7-3.3 4.6-7.6 4.6-4.1 0-6.9-2.2-8.1-5.8-.6-1.7-.8-3.9-.8-6.9 0-3 .3-5.2.8-6.9 1.2-3.6 4-5.8 8.1-5.8 4.3 0 6.4 1.9 7.6 4.6h.1v-3.5c0-.3.2-.5.5-.5h.5c.3 0 .5.2.5.5v23.9c0 6.7-3.6 9.7-9.2 9.7-3.5-.1-6.4-1.7-7.6-3.6zm14.6-12.1c.5-1.5.7-3.3.7-6.4 0-3-.2-4.9-.7-6.4-1.2-3.6-3.8-4.9-6.8-4.9-3.3 0-5.7 1.6-6.7 4.8-.5 1.5-.8 3.6-.8 6.4 0 2.8.3 4.9.8 6.4 1.1 3.2 3.4 4.8 6.7 4.8 3 .2 5.7-1.1 6.8-4.7zM207.2 6.1c-.3 0-.5-.2-.5-.5v-2c0-.3.2-.5.5-.5h1.2c.3 0 .5.2.5.5v2.1c0 .3-.2.5-.5.5h-1.2zm.4 31.9c-.3 0-.5-.2-.5-.5V14.2c0-.3.2-.5.5-.5h.5c.3 0 .5.2.5.5v23.3c0 .3-.2.5-.5.5h-.5zM233.5 38c-.3 0-.5-.2-.5-.5V22.9c0-5.8-2.4-8.3-7.1-8.3-4.1 0-7.5 2.8-7.5 7.6v15.4c0 .3-.2.5-.5.5h-.5c-.3 0-.5-.2-.5-.5V14.2c0-.3.2-.5.5-.5h.5c.3 0 .5.2.5.5v3.4h.1c1.2-2.8 4-4.5 7.5-4.5 5.5 0 8.6 3.1 8.6 9.4v15c0 .3-.2.5-.5.5h-.6zM241.4 32.2c-.5-1.4-.7-3.1-.7-6.5 0-3.3.3-5.1.7-6.5 1.3-4.1 4.5-6.2 8.6-6.2 4.2 0 7.3 2.1 8.6 6.2.5 1.4.7 3 .7 6.1 0 .3-.2.5-.6.5h-16.3c-.2 0-.3.2-.3.4 0 2.7.2 4.2.6 5.5 1.2 3.7 3.9 5.3 7.5 5.3 3.4 0 5.9-1.5 7.4-3.5.2-.3.5-.3.7-.1l.3.3c.3.2.3.5.1.7-1.7 2.4-4.6 4.1-8.4 4.1-4.5 0-7.6-2.1-8.9-6.3zm16.1-7.8c.2 0 .3-.1.3-.3 0-1.7-.2-3.1-.6-4.3-1.1-3.5-3.7-5.3-7.2-5.3s-6.1 1.7-7.2 5.3c-.4 1.2-.6 2.5-.6 4.3 0 .2.1.3.3.3h15z"/></g><g><path fill="#162A33" d="M262.3 16.1c0-1.7 1.3-3 3-3s3 1.3 3 3-1.3 3-3 3-3-1.3-3-3zm5.5 0c0-1.5-1.1-2.5-2.5-2.5-1.5 0-2.5 1.1-2.5 2.5 0 1.5 1.1 2.5 2.5 2.5s2.5-1 2.5-2.5zm-3.5 1.7c-.1 0-.1 0-.1-.1v-3.1c0-.1 0-.1.1-.1h1.2c.7 0 1.1.4 1.1 1 0 .4-.2.8-.7.9l.7 1.3c.1.1 0 .2-.1.2h-.3c-.1 0-.1-.1-.2-.1l-.7-1.3h-.7v1.2c0 .1-.1.1-.1.1h-.2zm1.8-2.4c0-.3-.2-.5-.6-.5h-.8v1h.8c.4 0 .6-.2.6-.5z"/></g></svg></a>

					<div class="wpe-pcc-get-hosting">
						<div class="wpe-pcc-aside-content">
							<h2><?php esc_html_e( 'Try a new PHP version safely', 'php-compatibility-checker' ); ?></h2>
							<p>
								<?php
								printf(
									/* translators: %s: PHP version, e.g. 8.2.35 */
									esc_html__( 'This environment runs PHP %s.', 'php-compatibility-checker' ),
									'<strong>' . esc_html( $release['current'] ) . '</strong>'
								);
								// The page notice has the full release message; keep this short.
								switch ( $release['state'] ) {
									case 'vulnerable':
										$php_status = __( 'It has a critical security flaw that attackers are exploiting. Update PHP now.', 'php-compatibility-checker' );
										break;
									case 'update_available':
										/* translators: %s: latest PHP release, e.g. 8.3.36 */
										$php_status = sprintf( __( 'PHP %s is available.', 'php-compatibility-checker' ), $release['latest'] );
										break;
									case 'unsupported_branch':
										$php_status = __( 'This version no longer gets security fixes.', 'php-compatibility-checker' );
										break;
									case 'vendor_build':
										$php_status = __( 'Check your host\'s security notices for PHP fixes.', 'php-compatibility-checker' );
										break;
									default:
										$php_status = $this->get_php_status_message();
								}
								if ( $php_status ) {
									echo ' ' . esc_html( $php_status );
								}
								?>
							</p>
							<?php // Filled in after a scan: which newer PHP the active plugins and theme are ready for (queue-manager.js). ?>
							<p class="wpe-pcc-readiness" hidden></p>
							<p><?php echo wp_kses_post( __( '<strong>Preview it:</strong> in the User Portal, open this environment and click <strong>Preview PHP</strong>. Only your browser sees the change.', 'php-compatibility-checker' ) ); ?></p>
							<p><?php echo wp_kses_post( __( '<strong>Or use a copy:</strong> change PHP on a staging or development environment first.', 'php-compatibility-checker' ) ); ?></p>
							<a target="_blank" class="wpe-pcc-button wpe-pcc-button-primary" href="<?php echo esc_url( $url_wpe_portal ); ?>"><?php echo esc_html( defined( 'PWP_NAME' ) ? __( 'Open this environment', 'php-compatibility-checker' ) : __( 'Open the User Portal', 'php-compatibility-checker' ) ); ?></a>
							<p><a target="_blank" href="<?php echo esc_url( $url_wpe_php_guide ); ?>"><?php esc_html_e( 'Read the WP Engine PHP guide', 'php-compatibility-checker' ); ?></a></p>
						</div> <!-- /wpe-pcc-aside-content -->
					</div> <!-- /wpe-pcc-get-hosting -->

					<div style="display: none;" class="wpe-pcc-information wpe-pcc-information-errors">
						<div class="wpe-pcc-aside-content">
							<h2><?php esc_html_e( 'Need help fixing these issues?', 'php-compatibility-checker' ); ?></h2>
							<p><?php esc_html_e( 'If an update doesn\'t fix a plugin or theme, or the problems are in custom code, a WP Engine agency partner can help.', 'php-compatibility-checker' ); ?></p>
							<a target="_blank" class="wpe-pcc-button" href="<?php echo esc_url( $url_wpe_agency_partners ); ?>"><?php esc_html_e( 'Find a WP Engine partner', 'php-compatibility-checker' ); ?></a>
						</div> <!-- /wpe-pcc-aside-content -->
					</div> <!-- /wpe-pcc-information -->

				</div> <!-- /wpe-pcc-aside -->
			<?php endif; ?>
			</div> <!-- /wpe-pcc-layout -->

		</div> <!-- /wpe-pcc-wrap -->

		<script type="text/html" id="tmpl-wpe-pcc-result">
			<div id="{{ data.type }}_{{ data.slug }}" class="wpe-pcc-alert wpe-pcc-alert-{{ data.status }}" data-result="{{ data.badge }}">
				<p>
					<# if ( data.selectable ) { #>
					<label>
						<input type="checkbox" class="wpe-pcc-select" value="{{ data.type }}_{{ data.slug }}" checked="checked" />
						<strong>{{ data.name }} {{ data.version }}</strong>
					</label>
					<# } else { #>
					<strong>{{ data.name }} {{ data.version }}</strong>
					<# } #>
					<# if ( data.inactive ) { #><span class="wpe-pcc-inactive"><?php esc_html_e( '(inactive)', 'php-compatibility-checker' ); ?></span><# } #>
					<span class="wpe-pcc-status">{{ data.status_text }}</span>
					<# if ( data.update_version ) { #>
					<span class="wpe-pcc-update">
						<?php
						/* translators: %s: version number. */
						printf( esc_html__( 'Update available: %s', 'php-compatibility-checker' ), '{{ data.update_version }}' );
						?>
					</span>
					<# } #>
				</p>

				<# if ( data.php && data.php.length ) { #>
					<p class="wpe-pcc-results">
						<# _.each( data.php, function( php ) { #>
							<# if ( php.unsupported ) { #>
							<span class="wpe-pcc-php-version-unsupported dashicons-before dashicons-minus"><span class="screen-reader-text"><?php esc_html_e( 'Not counted:', 'php-compatibility-checker' ); ?> </span>{{ php.phpversion }}</span>
							<# } else if ( php.passed ) { #>
							<span class="wpe-pcc-php-version-passed dashicons-before dashicons-yes"><span class="screen-reader-text"><?php esc_html_e( 'Passes:', 'php-compatibility-checker' ); ?> </span>{{ php.phpversion }}</span>
							<# } else { #>
							<a href="#" role="button" aria-expanded="false" data-php-version="{{ php.phpversion }}" aria-controls="report_{{ data.type }}_{{ data.slug }}_{{ php.id }}" class="wpe-pcc-php-version-errors dashicons-before dashicons-no-alt"><span class="screen-reader-text"><?php esc_html_e( 'Show problems on PHP', 'php-compatibility-checker' ); ?> </span>{{ php.phpversion }}</a>
							<# } #>
						<# } ); #>
					</p>
					<# if ( data.has_errors ) { #>
					<p class="description"><?php esc_html_e( 'Select a PHP version to see its problems.', 'php-compatibility-checker' ); ?></p>
					<# } #>
					<# if ( data.skipped_note ) { #>
					<p class="description">{{ data.skipped_note }}</p>
					<# } #>
					<# if ( data.needs_note ) { #>
					<p class="description wpe-pcc-needs-php">{{ data.needs_note }}</p>
					<# } #>
					<# if ( data.bundled_only ) { #>
					<p class="description"><?php esc_html_e( 'All of the problems are in bundled libraries, which often check for PHP features before using them. Test before treating them as blockers.', 'php-compatibility-checker' ); ?></p>
					<# } #>
					<# if ( data.has_unsupported ) { #>
					<p class="description">
						<?php
						/* translators: %s: minimum PHP version, e.g. 7.4 */
						printf( esc_html__( 'Versions marked with a dash are below its minimum of PHP %s, so they aren\'t counted.', 'php-compatibility-checker' ), '{{ data.requires_php }}' );
						?>
					</p>
					<# } #>
				<# } #>

				<# if ( data.message ) { #>
				<p>{{ data.message }}</p>
				<# } #>

				<# if ( data.reports && data.reports.length ) { #>
				<div class="wpe-pcc-reports">
					<# _.each( data.reports, function( report ) { #>
					<div id="report_{{ data.type }}_{{ data.slug }}_{{ report.id }}" data-php-version="{{ report.phpversion }}" class="wpe-pcc-php-version-report" style="display:none">
						<h4>PHP {{ report.phpversion }} <a href="#" class="wpe-pcc-php-version-report-close"><?php esc_html_e( 'close', 'php-compatibility-checker' ); ?></a></h4>
						<textarea readonly="readonly" aria-label="<?php esc_attr_e( 'Problem details', 'php-compatibility-checker' ); ?>">{{ report.text }}</textarea>
					</div>
					<# } ); #>
				</div>
				<# } #>
			</div>
		</script>

		<?php
	}

	/**
	 * Whether WordPress.org hosts a theme, from the update_themes transient.
	 *
	 * @since 2.0.0
	 *
	 * @param object|false $updates    update_themes transient.
	 * @param string       $stylesheet Theme directory name.
	 * @return bool
	 */
	private static function is_wporg_theme( $updates, string $stylesheet ): bool {
		foreach ( array( 'response', 'no_update' ) as $list ) {
			$entry = isset( $updates->{$list}[ $stylesheet ] ) ? (array) $updates->{$list}[ $stylesheet ] : null;
			if ( $entry ) {
				$url = isset( $entry['package'] ) && $entry['package'] ? $entry['package'] : ( isset( $entry['url'] ) ? $entry['url'] : '' );
				return (bool) preg_match( '#^https://(downloads\.)?wordpress\.org/#', (string) $url ); // phpcs:ignore WordPress.WP.CapitalPDangit -- Hostname.
			}
		}
		return false;
	}

	/**
	 * Notice about the server's PHP release, shown only when it needs attention.
	 *
	 * @since 1.6.5
	 *
	 * @param array $release Status from Php_Releases::get_status().
	 */
	public function release_notice( $release ) {
		$types = array(
			'vulnerable'         => 'error',
			'update_available'   => 'warning',
			'unsupported_branch' => 'error',
			'vendor_build'       => 'info',
		);

		if ( isset( $types[ $release['state'] ] ) ) {
			$links = array();
			foreach ( isset( $release['advisories'] ) ? $release['advisories'] : array() as $advisory ) {
				if ( empty( $advisory['conditions'] ) ) {
					$links[] = sprintf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
						esc_url( $advisory['url'] ),
						esc_html(
							sprintf(
								/* translators: %s: vulnerability name and CVE IDs. */
								__( 'About %s', 'php-compatibility-checker' ),
								Php_Releases::label( $advisory )
							)
						)
					);
				}
			}

			printf(
				'<div class="notice notice-%1$s inline wpe-pcc-release-notice"><p><strong>%2$s</strong> %3$s%4$s</p></div>',
				esc_attr( $types[ $release['state'] ] ),
				esc_html__( 'Server PHP:', 'php-compatibility-checker' ),
				esc_html( $release['message'] ),
				$links ? ' ' . implode( ' · ', $links ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}

		// Configuration-dependent advisories: show the workaround.
		foreach ( isset( $release['advisories'] ) ? $release['advisories'] : array() as $advisory ) {
			if ( empty( $advisory['conditions'] ) || empty( $advisory['mitigation'] ) ) {
				continue;
			}
			printf(
				'<div class="notice notice-warning inline wpe-pcc-release-notice"><p><strong>%1$s</strong> %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %s: CVE IDs. */
						__( 'This server\'s PHP settings are exposed to %s.', 'php-compatibility-checker' ),
						Php_Releases::label( $advisory )
					)
				),
				esc_html( $advisory['mitigation'] ),
				esc_url( $advisory['url'] ),
				esc_html__( 'Read more', 'php-compatibility-checker' )
			);
		}
	}

	/**
	 * Normalize a "Requires PHP" header value.
	 *
	 * @since 1.6.5
	 *
	 * @param mixed $version Raw header value.
	 * @return string Version like "7.4" or "8.0.2", or empty if missing or malformed.
	 */
	public static function sanitize_php_version( $version ) {
		$version = is_string( $version ) ? trim( $version ) : '';

		return preg_match( '/^\d+(\.\d+){0,2}$/', $version ) ? $version : '';
	}

	/**
	 * User Portal URL for this site, used in the WP Engine sidebar.
	 *
	 * Links straight to this environment's overview page (where "Preview PHP"
	 * lives) when WP Engine's PWP_NAME constant is available, otherwise to the
	 * Sites page.
	 *
	 * @since 1.6.5
	 *
	 * @param string $utm_content Campaign content identifier for the link.
	 * @return string
	 */
	public function get_wpe_portal_url( $utm_content ) {
		$url = 'https://my.wpengine.com/sites';

		if ( defined( 'PWP_NAME' ) && is_string( PWP_NAME ) && preg_match( '/^[a-z0-9][a-z0-9-]{0,62}$/i', PWP_NAME ) ) {
			$url = 'https://my.wpengine.com/installs/' . rawurlencode( strtolower( PWP_NAME ) );
		}

		return $this->add_wpe_campaign( $url, $utm_content );
	}

	/**
	 * Add the plugin's campaign parameters to a WP Engine link.
	 *
	 * Matches the parameters used by the plugin's existing short links.
	 *
	 * @since 1.6.5
	 *
	 * @param string $url         Link URL.
	 * @param string $utm_content Campaign content identifier.
	 * @return string
	 */
	public function add_wpe_campaign( $url, $utm_content ) {
		return add_query_arg(
			array(
				'utm_source'   => 'wordpress_org_plugins_directory',
				'utm_medium'   => 'content',
				'utm_campaign' => 'php_7_compatibility_checker',
				'utm_content'  => $utm_content,
			),
			$url
		);
	}

	/**
	 * Short status of the server's PHP version, from WordPress core's PHP check.
	 *
	 * Uses wp_check_php_version(), the same cached check behind the Dashboard and
	 * Site Health PHP notices.
	 *
	 * @since 1.6.5
	 *
	 * @return string Empty string if the status is unavailable.
	 */
	public function get_php_status_message() {
		if ( ! function_exists( 'wp_check_php_version' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/misc.php' ) ) {
			// Not loaded outside wp-admin (WP-CLI, REST).
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! function_exists( 'wp_check_php_version' ) ) {
			return '';
		}

		$response = wp_check_php_version();

		if ( ! is_array( $response ) ) {
			return '';
		}

		if ( isset( $response['is_secure'] ) && ! $response['is_secure'] ) {
			return __( 'It no longer gets security updates, so upgrade soon.', 'php-compatibility-checker' );
		}

		// "Supported" here means at or above WordPress's recommended version.
		if ( isset( $response['is_supported'] ) && ! $response['is_supported'] ) {
			if ( ! empty( $response['recommended_version'] ) ) {
				return sprintf(
					/* translators: %s: recommended PHP version, e.g. 8.3 */
					__( 'WordPress recommends PHP %s or newer.', 'php-compatibility-checker' ),
					$response['recommended_version']
				);
			}

			return __( 'WordPress recommends a newer version.', 'php-compatibility-checker' );
		}

		return __( 'That meets WordPress\'s recommended version.', 'php-compatibility-checker' );
	}

	/**
	 * Adds a link to the admin page to the plugin action links.
	 *
	 * @since 1.4.4
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function filter_plugin_links( $links ) {

		$url = add_query_arg( 'page', WPEPHPCOMPAT_ADMIN_PAGE_SLUG, admin_url( 'tools.php' ) );
		// Names where the link goes (like core's "Settings"); it opens the page, it doesn't start a scan.
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open checker', 'php-compatibility-checker' ) . '</a>' );

		return $links;
	}
}
