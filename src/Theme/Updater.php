<?php
/**
 * File: Updater.php
 *
 * BoldGrid Library Theme Updater Class.
 *
 * @package Boldgrid\Library\Theme
 * @subpackage \Updater
 *
 * @since 1.0.0
 * @author BoldGrid <wpb@boldgrid.com>
 */

namespace Boldgrid\Library\Theme;

/**
 * BoldGrid theme updater class.
 *
 * @since 1.0.0
 */
class Updater {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		\Boldgrid\Library\Library\Filter::add( $this );
	}

	/**
	 * Adds filters for theme update hooks.
	 *
	 * @since 1.0.0
	 *
	 * @hook: admin_init
	 */
	public function addHooks() {
		$isCron = ( defined( 'DOING_CRON' ) && DOING_CRON );
		$isWpcli = ( defined( 'WP_CLI' ) && WP_CLI );

		if ( $isCron || $isWpcli || is_admin() ) {
			if ( $isCron ){
				$this->wpcron();
			}

			add_filter( 'pre_set_site_transient_update_themes',
				array(
					$this,
					'filterTransient',
				), 11
			);

			add_filter( 'site_transient_update_themes',
				array(
					$this,
					'filterTransient',
				), 11
			);
		}
	}

	/**
	 * WP-CRON init.
	 *
	 * @since 1.0.0
	 */
	public function wpcron() {
		// Ensure required definitions for pluggable.
		if ( ! defined( 'AUTH_COOKIE' ) ) {
			define( 'AUTH_COOKIE', null );
		}

		if ( ! defined( 'LOGGED_IN_COOKIE' ) ) {
			define( 'LOGGED_IN_COOKIE', null );
		}

		// Load the pluggable class, if needed.
		require_once ABSPATH . 'wp-includes/pluggable.php';
	}

	/**
	 * Get theme data.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function getThemeData() {
		// Check if we have recent data.
		$themeData = get_site_transient( 'boldgrid_theme_data' );

		// If the theme data transient does not exist, then get the data and set it.
		if ( empty( $themeData ) ) {
			// Get the API URL and call URIs from configs, to make the URL to call.
			$baseUrl = \Boldgrid\Library\Library\Configs::get( 'api' );
			$apiCalls = \Boldgrid\Library\Library\Configs::get( 'api_calls' );
			$url = $baseUrl . $apiCalls['get_theme_data'];

			// Get the theme release channel.
			$relaseChannel = new \Boldgrid\Library\Library\ReleaseChannel();
			$channel = $relaseChannel->getThemeChannel();

			// Setup the API call.
			$apiCall = new \Boldgrid\Library\Library\Api\Call(
				$url,
				array(
					'channel' => $channel,
				),
				'get'
			 );

			// Make the API call.
			if ( ! $apiCall->getError() ) {
				$response = $apiCall->getResponse();

				if ( isset ( $response->result->data->theme_versions ) ) {
					/*
					 * Convert the object collection to an array.
					 * Using json_encode rather than wp_json_encode, due to an empty array in WP 4.6.
					 */
					$themeData = json_decode(
						json_encode( $response->result->data->theme_versions ),
						true
					);

					// Save the theme data for later.
					set_site_transient( 'boldgrid_theme_data', $themeData, 8 * HOUR_IN_SECONDS );
				}
			}
		}

		return $themeData;
	}

	/**
	 * Get the BoldGrid Theme Id.
	 *
	 * @since 1.0.0
	 *
	 * @param  object $theme A WordPress Theme object.
	 * @return string
	 */
	public function getBoldgridThemeId( $theme ) {
		$themeId = null;

		// Look for boldgrid-theme-id in the Tags line in the stylesheet.
		$tags = $theme->get( 'Tags' );

		// Iterate through the tags to find theme id (boldgrid-theme-id-##).
		foreach ( $tags as $tag ) {
			if ( preg_match( '/^boldgrid-theme-([0-9]+|parent)$/', $tag, $matches ) ) {
				$themeId = $matches[1];
				unset( $matches );
				break;
			}
		}

		// Get the theme slug (folder name).
		$slug = $theme->get_template();

		// If not a boldgrid theme, then skip.
		if ( null === $themeId && false !== strpos( $slug, 'boldgrid-' ) ) {
			$themeId = $slug;
		}

		return $themeId;
	}

	/**
	 * Update the theme update transient.
	 *
	 * @since 1.0.0
	 *
	 * @param  object $transient WordPress theme update transient object.
	 * @return object $transient
	 */
	public function filterTransient( $transient ) {
		// If we do not need to check for an update, then just return unchanged transient.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$themeData = $this->getThemeData();

		// If we have no data, then return.
		if ( empty( $themeData ) ) {
			return $transient;
		}

		// Get installed themes (array of WP_Theme objects).
		$installedThemes = wp_get_themes();

		// If themes are found, then iterate through them, adding update info for our themes.
		if ( count( $installedThemes ) ) {
			foreach ( $installedThemes as $installedTheme ) {
				$themeId = null;

				// Get the theme slug (folder name).
				$slug = $installedTheme->get_template();

				// Get the theme id.
				$themeId = $this->getBoldgridThemeId( $installedTheme );

				// If not a boldgrid theme, then skip.
				if ( null === $themeId ) {
					continue;
				}

				// Check if update available for a theme by comparing versions.
				$currentVersion = $installedTheme->Version;
				$incomingVersion = ! empty( $themeData[ $themeId ]['version'] ) ?
					$themeData[ $themeId ]['version'] : null;
				$updateAvailable = ( $incomingVersion && $currentVersion !== $incomingVersion );

				// Update is available, then update the transient object.
				if ( $updateAvailable ) {

					// Get the theme name, and theme URI.
					$themeName = $installedTheme->get( 'Name' );
					$themeUri = $installedTheme->get( 'ThemeURI' );

					// Add array elements to the transient.
					$transient->response[ $slug ]['theme'] = $slug;
					$transient->response[ $slug ]['new_version'] =
						$themeData[ $themeId ]['version'];

					// URL for the new theme version information iframe.
					$transient->response[ $slug ]['url'] = empty( $themeUri ) ?
						'//www.boldgrid.com/themes/' . strtolower( $themeName ) : $themeUri;

					// Theme package download link.
					$transient->response[ $slug ]['package'] = (
						isset( $themeData[ $themeId ]['package'] ) ?
						$themeData[ $themeId ]['package'] : null
					);

					$transient->response[ $slug ]['author'] = $installedTheme->Author;
					$transient->response[ $slug ]['Tag'] = $installedTheme->Tags;
					$transient->response[ $slug ]['fields'] = array(
						'version' => $themeData[ $themeId ]['version'],
						'author' => $installedTheme->Author,
						// 'preview_url' => '',
						// 'screenshot_url' = '',
						// 'screenshot_count' => 0,
						// 'screenshots' => array(),
						// 'sections' => array(),
						'description' => $installedTheme->Description,
						'download_link' => $transient->response[ $slug ]['package'],
						'name' => $installedTheme->Name,
						'slug' => $slug,
						'tags' => $installedTheme->Tags,
						// 'contributors' => '',
						'last_updated' => $themeData[ $themeId ]['updated'],
						'homepage' => 'http://www.boldgrid.com/',
					);
					unset( $themeId );
				} else {
					/*
					 * To prevent duplicate matches in the WordPress theme repo, check and
					 * unset references in the transient.
					 */
					if ( isset( $transient->response[ $slug ] ) ) {
						unset( $transient->response[ $slug ] );
					}
				}
			}
		}

		// Return the transient.
		return $transient;
	}
}
