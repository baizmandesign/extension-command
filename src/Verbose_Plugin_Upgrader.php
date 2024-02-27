<?php

class Verbose_Plugin_Upgrader extends \Plugin_Upgrader {

	public function upgrade_strings () {
		parent::upgrade_strings ();
		$this->strings['package_slug'] = __( 'Updating %s&#8230;' );
	}

	public function install_strings() {
		parent::install_strings ();
		$this->strings['package_slug'] = __( 'Adding %s&#8230;' );
	}

	/* from wp-admin/includes/class-wp-upgrader.php */
	public function run( $options ) {

		$defaults = array(
			'package'                     => '', // Please always pass this.
			'destination'                 => '', // ...and this.
			'clear_destination'           => false,
			'clear_working'               => true,
			'abort_if_destination_exists' => true, // Abort if the destination directory exists. Pass clear_destination as false please.
			'is_multi'                    => false,
			'hook_extra'                  => array(), // Pass any extra $hook_extra args here, this will be passed to any hooked filters.
		);

		$options = wp_parse_args( $options, $defaults );

		/**
		 * Filters the package options before running an update.
		 *
		 * See also {@see 'upgrader_process_complete'}.
		 *
		 * @since 4.3.0
		 *
		 * @param array $options {
		 *     Options used by the upgrader.
		 *
		 *     @type string $package                     Package for update.
		 *     @type string $destination                 Update location.
		 *     @type bool   $clear_destination           Clear the destination resource.
		 *     @type bool   $clear_working               Clear the working resource.
		 *     @type bool   $abort_if_destination_exists Abort if the Destination directory exists.
		 *     @type bool   $is_multi                    Whether the upgrader is running multiple times.
		 *     @type array  $hook_extra {
		 *         Extra hook arguments.
		 *
		 *         @type string $action               Type of action. Default 'update'.
		 *         @type string $type                 Type of update process. Accepts 'plugin', 'theme', or 'core'.
		 *         @type bool   $bulk                 Whether the update process is a bulk update. Default true.
		 *         @type string $plugin               Path to the plugin file relative to the plugins directory.
		 *         @type string $theme                The stylesheet or template name of the theme.
		 *         @type string $language_update_type The language pack update type. Accepts 'plugin', 'theme',
		 *                                            or 'core'.
		 *         @type object $language_update      The language pack update offer.
		 *     }
		 * }
		 */
		$options = apply_filters( 'upgrader_package_options', $options );

		if ( ! $options['is_multi'] ) { // Call $this->header separately if running multiple times.
			$this->skin->header();
		}

		// Connect to the filesystem first.
		$res = $this->fs_connect( array( WP_CONTENT_DIR, $options['destination'] ) );
		// Mainly for non-connected filesystem.
		if ( ! $res ) {
			if ( ! $options['is_multi'] ) {
				$this->skin->footer();
			}
			return false;
		}

		$this->skin->before();

		if ( is_wp_error( $res ) ) {
			$this->skin->error( $res );
			$this->skin->after();
			if ( ! $options['is_multi'] ) {
				$this->skin->footer();
			}
			return $res;
		}

		// question: why isn't $this->bulk set when installing multiple plugins?
		if ( $this->bulk ) {
			// plugin update
			if ( isset ( $options['hook_extra']['plugin'] ) ) {
				$this->skin->feedback('package_slug', dirname($options['hook_extra']['plugin'])) ;
			}
		}

		// plugin installation
		if ( isset ( $options['hook_extra']['action'] ) && $options['hook_extra']['action'] == 'install' && isset ( $options['hook_extra']['type'] ) && $options['hook_extra']['type'] == 'plugin' ) {
			$this->skin->feedback('package_slug', basename ($options['package'] ));
		}

		/*
		 * Download the package. Note: If the package is the full path
		 * to an existing local file, it will be returned untouched.
		 */
		$download = $this->download_package( $options['package'], true, $options['hook_extra'] );

		/*
		 * Allow for signature soft-fail.
		 * WARNING: This may be removed in the future.
		 */
		if ( is_wp_error( $download ) && $download->get_error_data( 'softfail-filename' ) ) {

			// Don't output the 'no signature could be found' failure message for now.
			if ( 'signature_verification_no_signature' !== $download->get_error_code() || WP_DEBUG ) {
				// Output the failure error as a normal feedback, and not as an error.
				$this->skin->feedback( $download->get_error_message() );

				// Report this failure back to WordPress.org for debugging purposes.
				wp_version_check(
					array(
						'signature_failure_code' => $download->get_error_code(),
						'signature_failure_data' => $download->get_error_data(),
					)
				);
			}

			// Pretend this error didn't happen.
			$download = $download->get_error_data( 'softfail-filename' );
		}

		if ( is_wp_error( $download ) ) {
			$this->skin->error( $download );
			$this->skin->after();
			if ( ! $options['is_multi'] ) {
				$this->skin->footer();
			}
			return $download;
		}

		$delete_package = ( $download !== $options['package'] ); // Do not delete a "local" file.

		// Unzips the file into a temporary directory.
		$working_dir = $this->unpack_package( $download, $delete_package );
		if ( is_wp_error( $working_dir ) ) {
			$this->skin->error( $working_dir );
			$this->skin->after();
			if ( ! $options['is_multi'] ) {
				$this->skin->footer();
			}
			return $working_dir;
		}

		// With the given options, this installs it to the destination directory.
		$result = $this->install_package(
			array(
				'source'                      => $working_dir,
				'destination'                 => $options['destination'],
				'clear_destination'           => $options['clear_destination'],
				'abort_if_destination_exists' => $options['abort_if_destination_exists'],
				'clear_working'               => $options['clear_working'],
				'hook_extra'                  => $options['hook_extra'],
			)
		);

		/**
		 * Filters the result of WP_Upgrader::install_package().
		 *
		 * @since 5.7.0
		 *
		 * @param array|WP_Error $result     Result from WP_Upgrader::install_package().
		 * @param array          $hook_extra Extra arguments passed to hooked filters.
		 */
		$result = apply_filters( 'upgrader_install_package_result', $result, $options['hook_extra'] );

		$this->skin->set_result( $result );

		if ( is_wp_error( $result ) ) {
			if ( ! empty( $options['hook_extra']['temp_backup'] ) ) {
				$this->temp_restores[] = $options['hook_extra']['temp_backup'];

				/*
				 * Restore the backup on shutdown.
				 * Actions running on `shutdown` are immune to PHP timeouts,
				 * so in case the failure was due to a PHP timeout,
				 * it will still be able to properly restore the previous version.
				 */
				add_action( 'shutdown', array( $this, 'restore_temp_backup' ) );
			}
			$this->skin->error( $result );

			if ( ! method_exists( $this->skin, 'hide_process_failed' ) || ! $this->skin->hide_process_failed( $result ) ) {
				$this->skin->feedback( 'process_failed' );
			}
		} else {
			// Installation succeeded.
			$this->skin->feedback( 'process_success' );
		}

		$this->skin->after();

		// Clean up the backup kept in the temporary backup directory.
		if ( ! empty( $options['hook_extra']['temp_backup'] ) ) {
			// Delete the backup on `shutdown` to avoid a PHP timeout.
			add_action( 'shutdown', array( $this, 'delete_temp_backup' ), 100, 0 );
		}

		if ( ! $options['is_multi'] ) {

			/**
			 * Fires when the upgrader process is complete.
			 *
			 * See also {@see 'upgrader_package_options'}.
			 *
			 * @since 3.6.0
			 * @since 3.7.0 Added to WP_Upgrader::run().
			 * @since 4.6.0 `$translations` was added as a possible argument to `$hook_extra`.
			 *
			 * @param WP_Upgrader $upgrader   WP_Upgrader instance. In other contexts this might be a
			 *                                Theme_Upgrader, Plugin_Upgrader, Core_Upgrade, or Language_Pack_Upgrader instance.
			 * @param array       $hook_extra {
			 *     Array of bulk item update data.
			 *
			 *     @type string $action       Type of action. Default 'update'.
			 *     @type string $type         Type of update process. Accepts 'plugin', 'theme', 'translation', or 'core'.
			 *     @type bool   $bulk         Whether the update process is a bulk update. Default true.
			 *     @type array  $plugins      Array of the basename paths of the plugins' main files.
			 *     @type array  $themes       The theme slugs.
			 *     @type array  $translations {
			 *         Array of translations update data.
			 *
			 *         @type string $language The locale the translation is for.
			 *         @type string $type     Type of translation. Accepts 'plugin', 'theme', or 'core'.
			 *         @type string $slug     Text domain the translation is for. The slug of a theme/plugin or
			 *                                'default' for core translations.
			 *         @type string $version  The version of a theme, plugin, or core.
			 *     }
			 * }
			 */
			do_action( 'upgrader_process_complete', $this, $options['hook_extra'] );

			$this->skin->footer();
		}

		return $result;
	}

}
