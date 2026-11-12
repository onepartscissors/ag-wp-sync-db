<?php
/**
 * Runs push, pull and export migrations without a browser, for WP-CLI.
 * Follows the same steps, in the same order, as the admin screen's JavaScript.
 */
class WPSDB_CLI extends WPSDB_Base {

	const LOG_OPTION = 'ag_wpsdb_cli_log';
	const LOG_MAX_ENTRIES = 50;

	protected $lock_handle;

	/** Optional progress callback: function( string $message ). */
	public $progress;

	function __construct( $plugin_file_path ) {
		parent::__construct( $plugin_file_path );
	}

	function say( $message ) {
		if ( is_callable( $this->progress ) ) {
			call_user_func( $this->progress, $message );
		}
	}

	function cli_error( $message ) {
		return new WP_Error( 'wpsdb_cli_error', wp_strip_all_tags( html_entity_decode( (string) $message ) ) );
	}

	function verify_cli_response( $response, $function_name ) {
		global $wpsdb;
		if ( false === $response || null === $response ) {
			$error = $wpsdb->get_error();
			return $this->cli_error( $error ? $error : sprintf( '%s returned no response.', $function_name ) );
		}
		$response = trim( (string) $response );
		if ( false === $wpsdb->is_json( $response ) ) {
			return $this->cli_error( sprintf( '%1$s was expecting a JSON response, instead we received: %2$s', $function_name, $response ) );
		}
		$response = json_decode( $response, true );
		if ( isset( $response['wpsdb_error'] ) ) {
			return $this->cli_error( $response['body'] );
		}
		return $response;
	}

	/** Network-level failures that are safe to retry (nothing was imported). */
	function is_connection_error( $message ) {
		return (bool) preg_match( '/#134|cURL error (6|7|28|35|52|56)\b|Connection (timed out|refused|reset)/i', $message );
	}

	// ---------------------------------------------------------------- profiles

	function get_profiles() {
		$settings = get_option( 'wpsdb_settings' );
		return ( isset( $settings['profiles'] ) && is_array( $settings['profiles'] ) ) ? $settings['profiles'] : array();
	}

	function profile_url( $profile ) {
		$lines = explode( "\n", isset( $profile['connection_info'] ) ? $profile['connection_info'] : '' );
		return trim( $lines[0] );
	}

	/**
	 * Find a saved profile by number (as shown in the admin screen, starting at 1), exact name,
	 * or a unique part of the remote URL (e.g. "dev").
	 */
	function find_profile( $ref ) {
		$profiles = $this->get_profiles();
		if ( empty( $profiles ) ) {
			return $this->cli_error( 'No saved migration profiles. Create one under Tools > Migrate DB first.' );
		}
		if ( ctype_digit( (string) $ref ) && isset( $profiles[ (int) $ref - 1 ] ) ) {
			return array( (int) $ref, $profiles[ (int) $ref - 1 ] );
		}
		foreach ( $profiles as $i => $profile ) {
			if ( isset( $profile['name'] ) && 0 === strcasecmp( $profile['name'], $ref ) ) {
				return array( $i + 1, $profile );
			}
		}
		$matches = array();
		foreach ( $profiles as $i => $profile ) {
			if ( false !== stripos( $this->profile_url( $profile ), $ref ) ) {
				$matches[ $i + 1 ] = $profile;
			}
		}
		if ( 1 === count( $matches ) ) {
			return array( key( $matches ), current( $matches ) );
		}
		if ( count( $matches ) > 1 ) {
			return $this->cli_error( sprintf( '"%s" matches more than one profile (%s). Use the profile number.', $ref, implode( ', ', array_keys( $matches ) ) ) );
		}
		return $this->cli_error( sprintf( 'No profile matches "%s". Run `wp agsdb profiles` to list them.', $ref ) );
	}

	/**
	 * True when the URL looks like a production site. Dev/staging/local URLs return false.
	 */
	function is_live_url( $url ) {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$is_dev = (bool) preg_match( '/^(dev|staging|stage|test|testing|qa|uat|preview|local)[.-]|(^|\.)localhost$|\.(ddev\.site|test|local|localhost|lndo\.site)$/', $host );
		return (bool) apply_filters( 'ag_wpsdb_is_live_url', ! $is_dev && '' !== $host, $url );
	}

	/**
	 * Turn a saved profile into the form data the admin screen would send for $direction.
	 * Unticked checkboxes are removed (the form never sends them; the code checks isset()).
	 */
	function build_profile( $profile, $direction, $overrides = array() ) {
		$p = $profile;
		if ( in_array( $p['action'], array( 'pull', 'push' ), true ) && $p['action'] !== $direction ) {
			// Same as switching Pull/Push in the admin screen: find and replace swap sides.
			$old = isset( $p['replace_old'] ) ? $p['replace_old'] : array();
			$p['replace_old'] = isset( $p['replace_new'] ) ? $p['replace_new'] : array();
			$p['replace_new'] = $old;
		}
		$p['action'] = $direction;

		foreach ( $overrides as $key => $value ) {
			if ( null === $value ) continue;
			$p[ $key ] = $value ? '1' : '0';
		}

		$checkboxes = array( 'save_computer', 'gzip_file', 'replace_guids', 'exclude_spam', 'keep_active_plugins', 'create_backup', 'exclude_post_types', 'media_files', 'remove_local_media', 'exclude_transients' );
		foreach ( $checkboxes as $key ) {
			if ( isset( $p[ $key ] ) && '1' !== (string) $p[ $key ] ) {
				unset( $p[ $key ] );
			}
		}
		unset( $p['save_migration_profile'], $p['save_migration_profile_option'], $p['create_new_profile'], $p['name'], $p['default_profile'] );
		return $p;
	}

	// ---------------------------------------------------------------- locking & log

	function acquire_lock() {
		global $wpsdb;
		$dir = $wpsdb->get_upload_info( 'path' );
		$file = trailingslashit( $dir ) . '.ag-wpsdb-cli.lock';
		$this->lock_handle = @fopen( $file, 'c' );
		if ( ! $this->lock_handle ) {
			return true; // Can't create a lock file: don't block the sync over it.
		}
		if ( ! flock( $this->lock_handle, LOCK_EX | LOCK_NB ) ) {
			return $this->cli_error( 'Another AG WP Sync DB command-line sync is already running on this site.' );
		}
		return true;
	}

	function release_lock() {
		if ( $this->lock_handle ) {
			flock( $this->lock_handle, LOCK_UN );
			fclose( $this->lock_handle );
			$this->lock_handle = null;
		}
	}

	function write_log( $entry ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) $log = array();
		$log[] = $entry;
		$log = array_slice( $log, -self::LOG_MAX_ENTRIES );
		update_option( self::LOG_OPTION, $log, false );

		do_action( 'ag_wpsdb_cli_complete', $entry );

		$notify_url = apply_filters( 'ag_wpsdb_notify_url', defined( 'AG_WPSDB_NOTIFY_URL' ) ? AG_WPSDB_NOTIFY_URL : '' );
		if ( $notify_url ) {
			wp_remote_post( $notify_url, array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array_merge( array( 'site' => home_url() ), $entry ) ),
			) );
		}
	}

	// ---------------------------------------------------------------- steps

	function verify_connection( $profile ) {
		global $wpsdb;
		$wpsdb->set_cli_migration();
		$this->set_cli_migration();

		$lines = explode( "\n", $profile['connection_info'] );
		if ( count( $lines ) < 2 ) {
			return $this->cli_error( 'The profile has no connection info (URL and key).' );
		}
		$_POST = array(
			'intent' => $profile['action'],
			'url'    => trim( $lines[0] ),
			'key'    => trim( $lines[1] ),
		);
		$response = $wpsdb->ajax_verify_connection_to_remote_site();
		$verify = $this->verify_cli_response( $response, 'ajax_verify_connection_to_remote_site()' );
		if ( is_wp_error( $verify ) ) return $verify;
		return apply_filters( 'wpsdb_cli_verify_connection_response', $verify );
	}

	/**
	 * Run a pull or push. $profile must come from build_profile(). Returns a stats array or WP_Error.
	 */
	function migrate( $profile ) {
		global $wpsdb;
		$this->set_time_limit();
		$stats = array( 'tables' => 0, 'requests' => 0, 'backup_tables' => 0, 'media_files' => 0, 'media_bytes' => 0, 'backup_file' => '' );

		$profile = apply_filters( 'wpsdb_cli_profile_before_migration', $profile );
		do_action( 'wpsdb_cli_before_verify_connection_to_remote_site', $profile );

		$this->say( 'Connecting to ' . $this->profile_url( $profile ) . ' ...' );
		$verify = $this->verify_connection( $profile );
		if ( is_wp_error( $verify ) ) return $verify;
		$this->say( sprintf( 'Connected. Remote plugin %s, %d tables.', ! empty( $verify['ag_plugin_version'] ) ? 'AG ' . $verify['ag_plugin_version'] : 'WP Sync DB ' . $verify['plugin_version'], count( $verify['prefixed_tables'] ) ) );

		do_action( 'wpsdb_cli_before_initiate_migration', $profile, $verify );

		$form_data = http_build_query( $profile );
		$_POST['form_data'] = $form_data;
		$_POST['stage'] = empty( $profile['create_backup'] ) ? 'migrate' : 'backup';
		$_POST = apply_filters( 'wpsdb_cli_initiate_migration_args', $_POST, $profile, $verify );
		$initiate = $this->verify_cli_response( $wpsdb->ajax_initiate_migration(), 'ajax_initiate_migration()' );
		if ( is_wp_error( $initiate ) ) return $initiate;
		$initiate = apply_filters( 'wpsdb_cli_initiate_migration_response', $initiate );

		// Tables to back up (same rules as the admin screen).
		$tables_to_backup = array();
		if ( 'backup' === $_POST['stage'] ) {
			$backup_option = isset( $profile['backup_option'] ) ? $profile['backup_option'] : 'backup_only_with_prefix';
			if ( 'migrate_only_with_prefix' === $profile['table_migrate_option'] && 'backup_selected' === $backup_option ) {
				$backup_option = 'backup_only_with_prefix';
			}
			$all = ( 'push' === $profile['action'] ) ? $verify['tables'] : $this->get_tables();
			$prefixed = ( 'push' === $profile['action'] ) ? $verify['prefixed_tables'] : $this->get_tables( 'prefix' );
			if ( 'backup_only_with_prefix' === $backup_option ) {
				$tables_to_backup = $prefixed;
			} elseif ( 'backup_selected' === $backup_option ) {
				$tables_to_backup = array_intersect( (array) $profile['select_tables'], $all );
			} elseif ( 'backup_manual_select' === $backup_option ) {
				$tables_to_backup = array_intersect( isset( $profile['select_backup'] ) ? (array) $profile['select_backup'] : array(), $all );
			}
		}
		$tables_to_backup = array_values( apply_filters( 'wpsdb_cli_tables_to_backup', $tables_to_backup, $profile, $verify, $initiate ) );

		// Tables to migrate.
		$source_all = ( 'push' === $profile['action'] ) ? $this->get_tables() : $verify['tables'];
		$source_prefixed = ( 'push' === $profile['action'] ) ? $this->get_tables( 'prefix' ) : $verify['prefixed_tables'];
		if ( isset( $profile['table_migrate_option'] ) && 'migrate_select' === $profile['table_migrate_option'] ) {
			$tables_to_migrate = array_intersect( isset( $profile['select_tables'] ) ? (array) $profile['select_tables'] : array(), $source_all );
		} else {
			$tables_to_migrate = $source_prefixed;
		}
		$tables_to_migrate = array_values( apply_filters( 'wpsdb_cli_tables_to_migrate', $tables_to_migrate, $profile, $verify, $initiate ) );
		if ( empty( $tables_to_migrate ) ) {
			return $this->cli_error( 'No tables to migrate.' );
		}

		$_POST['dump_filename'] = $initiate['dump_filename'];
		$_POST['gzip'] = ( '1' == $verify['gzip'] ) ? 1 : 0;
		$_POST['bottleneck'] = $verify['bottleneck'];
		$_POST['prefix'] = $verify['prefix'];
		if ( ! empty( $verify['path_current_site'] ) && ! empty( $verify['domain'] ) ) {
			$_POST['path_current_site'] = $verify['path_current_site'];
			$_POST['domain_current_site'] = $verify['domain'];
		}

		do_action( 'wpsdb_cli_before_migrate_tables', $profile, $verify, $initiate );

		$stages = array();
		if ( 'backup' === $_POST['stage'] ) {
			$stages['backup'] = $tables_to_backup;
		}
		$stages['migrate'] = $tables_to_migrate;

		foreach ( $stages as $stage => $tables ) {
			$_POST['stage'] = $stage;
			$this->say( sprintf( '%s %d tables ...', 'backup' === $stage ? 'Backing up' : 'Migrating', count( $tables ) ) );
			foreach ( $tables as $i => $table ) {
				$current_row = -1;
				$primary_keys = '';
				$_POST['table'] = $table;
				$_POST['last_table'] = ( $i === count( $tables ) - 1 ) ? '1' : '0';
				do {
					$wpsdb->empty_current_chunk();
					$_POST['current_row'] = $current_row;
					$_POST['primary_keys'] = $primary_keys;
					$_POST = apply_filters( 'wpsdb_cli_migrate_table_args', $_POST, $profile, $verify, $initiate );
					$attempt = 0;
					do {
						$saved_post = $_POST;
						$result = $this->verify_cli_response( $wpsdb->ajax_migrate_table(), 'ajax_migrate_table()' );
						$_POST = $saved_post;
						$retry = is_wp_error( $result ) && $attempt < 2 && $this->is_connection_error( $result->get_error_message() );
						if ( $retry ) {
							++$attempt;
							$this->say( sprintf( '  connection problem on %s, retrying (%d/2) ...', $table, $attempt ) );
							$wpsdb->empty_current_chunk();
							sleep( 5 * $attempt );
						}
					} while ( $retry );
					if ( is_wp_error( $result ) ) {
						return $this->cli_error( sprintf( '%s (table %s)', $result->get_error_message(), $table ) );
					}
					$result = apply_filters( 'wpsdb_cli_migrate_table_response', $result, $_POST, $profile, $verify, $initiate );
					++$stats['requests'];
					$current_row = isset( $result['current_row'] ) ? $result['current_row'] : -1;
					$primary_keys = isset( $result['primary_keys'] ) ? $result['primary_keys'] : '';
				} while ( -1 != $current_row && '' !== (string) $current_row );
				$this->say( '  ' . $table );
				if ( 'backup' === $stage ) {
					++$stats['backup_tables'];
				} else {
					++$stats['tables'];
				}
			}
		}

		if ( ! empty( $tables_to_backup ) && ! empty( $initiate['dump_filename'] ) ) {
			$stats['backup_file'] = ( 'push' === $profile['action'] ? 'remote: ' : '' ) . 'wp-content/uploads/wp-sync-db/' . $initiate['dump_filename'] . '.sql';
		}

		do_action( 'wpsdb_cli_before_finalize_migration', $profile, $verify, $initiate );

		// Media files (AG WP Sync DB Media Files hooks in here).
		$media_hooks = has_filter( 'wpsdb_cli_finalize_migration' );
		if ( ! empty( $profile['media_files'] ) && $media_hooks ) {
			$this->say( 'Syncing media files ...' );
		}
		$_POST['temp_prefix'] = $verify['temp_prefix'];
		$saved_post = $_POST;
		$media = apply_filters( 'wpsdb_cli_finalize_migration', true, $profile, $verify, $initiate );
		if ( is_wp_error( $media ) ) return $media;
		$_POST = $saved_post;
		if ( ! empty( $GLOBALS['wpsdb_media_files'] ) && isset( $GLOBALS['wpsdb_media_files']->cli_media_count ) ) {
			$stats['media_files'] = (int) $GLOBALS['wpsdb_media_files']->cli_media_count;
			$stats['media_bytes'] = (int) $GLOBALS['wpsdb_media_files']->cli_media_bytes;
		}

		$this->say( 'Finalizing ...' );
		$_POST['tables'] = implode( ',', $tables_to_migrate );
		$_POST['temp_prefix'] = $verify['temp_prefix'];
		$_POST['form_data'] = $form_data;
		$_POST = apply_filters( 'wpsdb_cli_finalize_migration_args', $_POST, $profile, $verify, $initiate );
		$_POST = $this->filter_post_elements( $_POST, array( 'action', 'intent', 'url', 'key', 'form_data', 'prefix', 'type', 'location', 'tables', 'temp_prefix' ) );
		$response = trim( (string) $wpsdb->ajax_finalize_migration() );
		if ( '' !== $response ) {
			return $this->cli_error( $response );
		}

		do_action( 'wpsdb_cli_after_finalize_migration', $profile, $verify, $initiate );
		return $stats;
	}

	/**
	 * Export this site's database to an SQL file. Returns array( 'file' => path, 'tables' => n ) or WP_Error.
	 */
	function export( $find, $replace, $gzip, $tables = array() ) {
		global $wpsdb;
		$this->set_time_limit();
		$wpsdb->set_cli_migration();
		$this->set_cli_migration();

		$profile = array(
			'action'               => 'savefile',
			'replace_guids'        => '1',
			'exclude_transients'   => '1',
			'table_migrate_option' => empty( $tables ) ? 'migrate_only_with_prefix' : 'migrate_select',
			'select_tables'        => $tables,
			'replace_old'          => array(),
			'replace_new'          => array(),
		);
		if ( $gzip ) {
			$profile['gzip_file'] = '1';
		}
		foreach ( array_values( $find ) as $i => $value ) {
			$profile['replace_old'][ $i + 1 ] = $value;
			$profile['replace_new'][ $i + 1 ] = isset( $replace[ $i ] ) ? $replace[ $i ] : '';
		}

		// Read the table list before starting: starting creates the plugin's own temporary alter-statements table.
		$tables_to_export = empty( $tables ) ? $this->get_tables( 'prefix' ) : array_values( array_intersect( $tables, $this->get_tables() ) );
		$tables_to_export = array_values( array_diff( $tables_to_export, array( $wpsdb->get_alter_table_name() ) ) );
		if ( empty( $tables_to_export ) ) {
			return $this->cli_error( 'No tables to export.' );
		}

		$form_data = http_build_query( $profile );
		$_POST = array( 'intent' => 'savefile', 'form_data' => $form_data, 'stage' => 'migrate' );
		$initiate = $this->verify_cli_response( $wpsdb->ajax_initiate_migration(), 'ajax_initiate_migration()' );
		if ( is_wp_error( $initiate ) ) return $initiate;
		$file = $wpsdb->get_upload_info( 'path' ) . DS . $initiate['dump_filename'] . '.sql' . ( $gzip && $wpsdb->gzip() ? '.gz' : '' );

		$_POST['dump_filename'] = $initiate['dump_filename'];
		$_POST['gzip'] = 0;
		foreach ( $tables_to_export as $i => $table ) {
			$current_row = -1;
			$primary_keys = '';
			$_POST['table'] = $table;
			$_POST['last_table'] = ( $i === count( $tables_to_export ) - 1 ) ? '1' : '0';
			do {
				$wpsdb->empty_current_chunk();
				$_POST['current_row'] = $current_row;
				$_POST['primary_keys'] = $primary_keys;
				$result = $this->verify_cli_response( $wpsdb->ajax_migrate_table(), 'ajax_migrate_table()' );
				if ( is_wp_error( $result ) ) {
					@unlink( $file );
					return $this->cli_error( sprintf( '%s (table %s)', $result->get_error_message(), $table ) );
				}
				$current_row = isset( $result['current_row'] ) ? $result['current_row'] : -1;
				$primary_keys = isset( $result['primary_keys'] ) ? $result['primary_keys'] : '';
			} while ( -1 != $current_row && '' !== (string) $current_row );
			$this->say( '  ' . $table );
		}

		if ( ! file_exists( $file ) ) {
			return $this->cli_error( 'Export finished but the file was not found: ' . $file );
		}
		return array( 'file' => $file, 'tables' => count( $tables_to_export ) );
	}
}

/**
 * Push, pull and export WordPress databases with AG WP Sync DB.
 *
 * Uses the migration profiles saved under Tools > Migrate DB. Every command that changes a database needs --yes; without it the command only checks the connection (a dry run).
 *
 * ## EXAMPLES
 *
 *     # List saved profiles
 *     wp agsdb profiles
 *
 *     # Check a profile without changing anything
 *     wp agsdb check dev
 *
 *     # Replace this site's database (and media) with the remote one
 *     wp agsdb pull dev --media --yes
 *
 *     # Same, as JSON for scripts and agents
 *     wp agsdb pull dev --yes --format=json
 */
class AG_WPSDB_Command extends WP_CLI_Command {

	/** @return WPSDB_CLI */
	protected function runner( $quiet = false ) {
		global $wpsdb_cli;
		$wpsdb_cli->progress = $quiet ? null : function ( $message ) {
			WP_CLI::log( $message );
		};
		return $wpsdb_cli;
	}

	protected function fail( $message, $format, $extra = array() ) {
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( array_merge( array( 'status' => 'error', 'error' => $message ), $extra ) ) );
			WP_CLI::halt( 1 );
		}
		WP_CLI::error( $message );
	}

	/**
	 * List saved migration profiles.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agsdb profiles
	 *     wp agsdb profiles --format=json
	 */
	public function profiles( $args, $assoc_args ) {
		$runner = $this->runner( true );
		$items = array();
		foreach ( $runner->get_profiles() as $i => $profile ) {
			$url = $runner->profile_url( $profile );
			$items[] = array(
				'id'      => $i + 1,
				'name'    => isset( $profile['name'] ) ? $profile['name'] : '',
				'action'  => $profile['action'],
				'remote'  => $url,
				'live'    => ( $url && $runner->is_live_url( $url ) ) ? 'yes' : 'no',
				'media'   => ! empty( $profile['media_files'] ) && '1' === (string) $profile['media_files'] ? 'yes' : 'no',
				'backup'  => ! empty( $profile['create_backup'] ) && '1' === (string) $profile['create_backup'] ? 'yes' : 'no',
			);
		}
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( empty( $items ) && 'table' === $format ) {
			WP_CLI::log( 'No saved profiles. Create one under Tools > Migrate DB.' );
			return;
		}
		WP_CLI\Utils\format_items( $format, $items, array( 'id', 'name', 'action', 'remote', 'live', 'media', 'backup' ) );
	}

	/**
	 * Check a profile's connection without changing anything.
	 *
	 * Connects to the remote site and shows its plugin version, table count and size, whether media sync is available, and whether a pull or push would be allowed.
	 *
	 * ## OPTIONS
	 *
	 * <profile>
	 * : Profile number, name, or a unique part of the remote URL (e.g. "dev").
	 *
	 * [--direction=<direction>]
	 * : Check for pull or push. Defaults to the profile's own direction.
	 * ---
	 * options:
	 *   - pull
	 *   - push
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agsdb check dev
	 *     wp agsdb check 1 --direction=push --format=json
	 */
	public function check( $args, $assoc_args ) {
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$runner = $this->runner( true );
		$found = $runner->find_profile( $args[0] );
		if ( is_wp_error( $found ) ) $this->fail( $found->get_error_message(), $format );
		list( $id, $profile ) = $found;
		$direction = WP_CLI\Utils\get_flag_value( $assoc_args, 'direction', in_array( $profile['action'], array( 'pull', 'push' ), true ) ? $profile['action'] : 'pull' );
		$info = $this->check_info( $runner, $id, $profile, $direction );
		if ( is_wp_error( $info ) ) $this->fail( $info->get_error_message(), $format, array( 'profile' => $id ) );
		$this->output_check( $info, $format );
	}

	protected function check_info( $runner, $id, $profile, $direction ) {
		$built = $runner->build_profile( $profile, $direction );
		$verify = $runner->verify_connection( $built );
		if ( is_wp_error( $verify ) ) return $verify;
		$size_kb = 0;
		foreach ( (array) $verify['prefixed_tables'] as $table ) {
			$size_kb += isset( $verify['table_sizes'][ $table ] ) ? (int) $verify['table_sizes'][ $table ] : 0;
		}
		$remote = $runner->profile_url( $profile );
		$overwrites = ( 'push' === $direction ) ? $remote : home_url();
		return array(
			'status'             => 'ok',
			'profile'            => $id,
			'name'               => isset( $profile['name'] ) ? $profile['name'] : '',
			'direction'          => $direction,
			'remote'             => $remote,
			'overwrites'         => $overwrites,
			'overwrites_live'    => $runner->is_live_url( $overwrites ),
			'remote_plugin'      => ! empty( $verify['ag_plugin_version'] ) ? 'ag-wp-sync-db ' . $verify['ag_plugin_version'] : 'wp-sync-db ' . $verify['plugin_version'],
			'remote_tables'      => count( (array) $verify['prefixed_tables'] ),
			'remote_db_size_mb'  => round( $size_kb / 1024, 1 ),
			'remote_prefix'      => $verify['prefix'],
			'media_available'    => ! empty( $verify['media_files_available'] ),
			'profile_media'      => ! empty( $built['media_files'] ),
			'profile_backup'     => ! empty( $built['create_backup'] ),
			'max_request_mb'     => round( (int) $verify['bottleneck'] / 1048576, 1 ),
		);
	}

	protected function output_check( $info, $format ) {
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $info ) );
			return;
		}
		$rows = array();
		foreach ( $info as $key => $value ) {
			$rows[] = array( 'field' => $key, 'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Replace THIS site's database with the remote site's (pull).
	 *
	 * Without --yes this only checks the connection and shows what would happen. Pulling into a site that looks like production (not dev., staging., .ddev.site, .test, .local, localhost) also needs --allow-live.
	 *
	 * ## OPTIONS
	 *
	 * <profile>
	 * : Profile number, name, or a unique part of the remote URL (e.g. "dev").
	 *
	 * [--[no-]media]
	 * : Also sync media files. Defaults to the profile's setting.
	 *
	 * [--[no-]backup]
	 * : Back up this site's database first. Defaults to the profile's setting.
	 *
	 * [--[no-]keep-active-plugins]
	 * : Keep this site's list of active plugins instead of taking the remote one.
	 *
	 * [--yes]
	 * : Actually run it. Without this, nothing is changed.
	 *
	 * [--allow-live]
	 * : Allow overwriting a site that looks like production.
	 *
	 * [--agent=<name>]
	 * : Who is running this (e.g. Claude Code, deploy-script). Saved in the sync log.
	 *
	 * [--format=<format>]
	 * : Output format. json prints one result object and nothing else.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agsdb pull dev --media --yes
	 *     wp agsdb pull dev --yes --keep-active-plugins --agent="Claude Code" --format=json
	 */
	public function pull( $args, $assoc_args ) {
		$this->run_direction( 'pull', $args, $assoc_args );
	}

	/**
	 * Replace the REMOTE site's database with this site's (push).
	 *
	 * Without --yes this only checks the connection and shows what would happen. Pushing to a site that looks like production needs --allow-live. The remote database is backed up first unless --no-backup is given.
	 *
	 * ## OPTIONS
	 *
	 * <profile>
	 * : Profile number, name, or a unique part of the remote URL (e.g. "dev").
	 *
	 * [--[no-]media]
	 * : Also sync media files. Defaults to the profile's setting.
	 *
	 * [--[no-]backup]
	 * : Back up the remote database first. Default: on.
	 *
	 * [--[no-]keep-active-plugins]
	 * : Keep the remote site's list of active plugins.
	 *
	 * [--yes]
	 * : Actually run it. Without this, nothing is changed.
	 *
	 * [--allow-live]
	 * : Allow overwriting a site that looks like production.
	 *
	 * [--agent=<name>]
	 * : Who is running this. Saved in the sync log.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agsdb push dev --media --yes
	 */
	public function push( $args, $assoc_args ) {
		if ( ! isset( $assoc_args['backup'] ) ) {
			$assoc_args['backup'] = true;
		}
		$this->run_direction( 'push', $args, $assoc_args );
	}

	protected function run_direction( $direction, $args, $assoc_args ) {
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'text' );
		$runner = $this->runner( 'json' === $format );
		$found = $runner->find_profile( $args[0] );
		if ( is_wp_error( $found ) ) $this->fail( $found->get_error_message(), $format );
		list( $id, $profile ) = $found;

		$overrides = array(
			'media_files'         => isset( $assoc_args['media'] ) ? (bool) $assoc_args['media'] : null,
			'create_backup'       => isset( $assoc_args['backup'] ) ? (bool) $assoc_args['backup'] : null,
			'keep_active_plugins' => isset( $assoc_args['keep-active-plugins'] ) ? (bool) $assoc_args['keep-active-plugins'] : null,
		);
		$built = $runner->build_profile( $profile, $direction, $overrides );
		$remote = $runner->profile_url( $profile );
		$overwrites = ( 'push' === $direction ) ? $remote : home_url();
		$agent = WP_CLI\Utils\get_flag_value( $assoc_args, 'agent', getenv( 'AG_AGENT' ) ? getenv( 'AG_AGENT' ) : '' );

		$base = array(
			'profile'    => $id,
			'direction'  => $direction,
			'remote'     => $remote,
			'overwrites' => $overwrites,
			'media'      => ! empty( $built['media_files'] ),
			'backup'     => ! empty( $built['create_backup'] ),
		);

		if ( $runner->is_live_url( $overwrites ) && empty( $assoc_args['allow-live'] ) ) {
			$this->fail( sprintf( '%s looks like a live site. This %s would overwrite its database. Add --allow-live only if that is really intended.', $overwrites, $direction ), $format, $base );
		}

		if ( empty( $assoc_args['yes'] ) ) {
			$info = $this->check_info( $runner, $id, $profile, $direction );
			if ( is_wp_error( $info ) ) $this->fail( $info->get_error_message(), $format, $base );
			$info['status'] = 'dry-run';
			$info['profile_media'] = $base['media'];
			$info['profile_backup'] = $base['backup'];
			if ( 'json' !== $format ) {
				WP_CLI::log( sprintf( 'Dry run: nothing changed. Add --yes to %s (overwrites %s).', $direction, $overwrites ) );
			}
			$this->output_check( $info, 'json' === $format ? 'json' : 'table' );
			return;
		}

		$lock = $runner->acquire_lock();
		if ( is_wp_error( $lock ) ) $this->fail( $lock->get_error_message(), $format, $base );

		$start = microtime( true );
		try {
			$result = $runner->migrate( $built );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'wpsdb_cli_exception', $e->getMessage() );
		}
		$runner->release_lock();
		$duration = round( microtime( true ) - $start, 1 );

		$entry = array_merge( $base, array(
			'time'       => gmdate( 'c' ),
			'agent'      => $agent,
			'user'       => function_exists( 'get_current_user' ) ? get_current_user() : '',
			'duration_s' => $duration,
		) );

		if ( is_wp_error( $result ) ) {
			$entry['status'] = 'error';
			$entry['error'] = $result->get_error_message();
			$runner->write_log( $entry );
			$this->fail( $entry['error'], $format, $entry );
		}

		$entry = array_merge( $entry, array( 'status' => 'success' ), $result );
		$runner->write_log( $entry );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $entry ) );
			return;
		}
		$media = $entry['media'] ? sprintf( ', %d media files (%s)', $entry['media_files'], size_format( $entry['media_bytes'] ) ) : '';
		WP_CLI::success( sprintf( '%s %s complete in %ss: %d tables%s.', ucfirst( $direction ), 'push' === $direction ? 'to ' . $remote : 'from ' . $remote, $duration, $entry['tables'], $media ) );
	}

	/**
	 * Export this site's database to an SQL file, with optional find and replace.
	 *
	 * The file is written to --output (default: ./<database>-<date>.sql.gz in the current folder), not left in the public uploads folder.
	 *
	 * ## OPTIONS
	 *
	 * [--find=<values>]
	 * : Comma-separated values to find, e.g. "//example.com,/var/www/example".
	 *
	 * [--replace=<values>]
	 * : Comma-separated replacements, same order as --find.
	 *
	 * [--tables=<tables>]
	 * : Comma-separated tables. Default: all tables with this site's prefix.
	 *
	 * [--[no-]gzip]
	 * : Gzip the file. Default: on.
	 *
	 * [--output=<file>]
	 * : Where to save the file.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agsdb export
	 *     wp agsdb export --find=//example.ddev.site --replace=//example.com --output=/tmp/example.sql.gz
	 */
	public function export( $args, $assoc_args ) {
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'text' );
		$runner = $this->runner( 'json' === $format );
		$split = function ( $key ) use ( $assoc_args ) {
			$value = WP_CLI\Utils\get_flag_value( $assoc_args, $key, '' );
			return '' === $value ? array() : array_map( 'trim', explode( ',', $value ) );
		};
		$find = $split( 'find' );
		$replace = $split( 'replace' );
		if ( count( $find ) !== count( $replace ) ) {
			$this->fail( '--find and --replace need the same number of values.', $format );
		}
		$gzip = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'gzip', true );

		$lock = $runner->acquire_lock();
		if ( is_wp_error( $lock ) ) $this->fail( $lock->get_error_message(), $format );
		$start = microtime( true );
		$result = $runner->export( $find, $replace, $gzip, $split( 'tables' ) );
		$runner->release_lock();
		if ( is_wp_error( $result ) ) $this->fail( $result->get_error_message(), $format );

		$default = getcwd() . DIRECTORY_SEPARATOR . sanitize_title_with_dashes( DB_NAME ) . '-' . gmdate( 'Ymd-His' ) . '.sql' . ( $gzip ? '.gz' : '' );
		$output = WP_CLI\Utils\get_flag_value( $assoc_args, 'output', $default );
		if ( ! @rename( $result['file'], $output ) ) {
			if ( ! @copy( $result['file'], $output ) ) {
				@unlink( $result['file'] );
				$this->fail( 'Could not write the export to ' . $output, $format );
			}
			@unlink( $result['file'] );
		}
		$entry = array(
			'status'     => 'success',
			'file'       => $output,
			'bytes'      => filesize( $output ),
			'tables'     => $result['tables'],
			'duration_s' => round( microtime( true ) - $start, 1 ),
		);
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $entry ) );
			return;
		}
		WP_CLI::success( sprintf( 'Exported %d tables to %s (%s) in %ss.', $entry['tables'], $output, size_format( $entry['bytes'] ), $entry['duration_s'] ) );
	}

	/**
	 * Show recent command-line syncs on this site.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<n>]
	 * : How many entries to show.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agsdb log
	 *     wp agsdb log --count=1 --format=json
	 */
	public function log( $args, $assoc_args ) {
		$log = get_option( WPSDB_CLI::LOG_OPTION, array() );
		$log = array_slice( is_array( $log ) ? $log : array(), - (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'count', 10 ) );
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( array_values( $log ) ) );
			return;
		}
		if ( empty( $log ) ) {
			WP_CLI::log( 'No command-line syncs logged yet.' );
			return;
		}
		$rows = array();
		foreach ( $log as $e ) {
			$rows[] = array(
				'time'      => isset( $e['time'] ) ? $e['time'] : '',
				'direction' => isset( $e['direction'] ) ? $e['direction'] : '',
				'remote'    => isset( $e['remote'] ) ? $e['remote'] : '',
				'status'    => isset( $e['status'] ) ? $e['status'] : '',
				'seconds'   => isset( $e['duration_s'] ) ? $e['duration_s'] : '',
				'agent'     => isset( $e['agent'] ) ? $e['agent'] : '',
				'error'     => isset( $e['error'] ) ? $e['error'] : '',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'time', 'direction', 'remote', 'status', 'seconds', 'agent', 'error' ) );
	}
}
