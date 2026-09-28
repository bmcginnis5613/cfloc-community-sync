<?php

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

/**
 * Matches source users by email and syncs changed fields to this site.
 */
final class CFOLC_Community_Sync_CLI_Command {
	/**
	 * Tests authentication and access to the source site's user endpoint.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cfolc-community test
	 */
	public function test() {
		$client = new CFOLC_Community_Sync_API_Client( CFOLC_Community_Sync_Settings::get() );
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Connected to the source site and confirmed access to %d user record(s) and BuddyBoss groups.', $result['total'] ) );
	}

	/**
	 * Compares source users and syncs changed ACF fields in the terminal.
	 *
	 * ## OPTIONS
	 *
	 * [--email=<address>]
	 * : Checks only this exact source email address.
	 *
	 * [--batch-size=<number>]
	 * : Source records requested per batch. Default: saved setting. Maximum: 100.
	 *
	 * [--log-file=<path>]
	 * : Appends terminal events as JSON Lines to this file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cfolc-community check
	 *     wp cfolc-community check --email=jane@example.com
	 */
	public function check( $args, $assoc_args ) {
		$settings   = CFOLC_Community_Sync_Settings::get();
		$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : (int) $settings['batch_size'];
		$batch_size = max( 1, min( 100, $batch_size ) );
		$log_file   = isset( $assoc_args['log-file'] ) ? wp_normalize_path( $assoc_args['log-file'] ) : '';

		$this->validate_log_file( $log_file );

		$reporter = static function ( $level, $message, $context = array() ) use ( $log_file ) {
			WP_CLI::log( sprintf( '[%1$s] %2$s', strtoupper( str_replace( '_', ' ', $level ) ), $message ) );

			if ( $log_file !== '' ) {
				$event = array_merge(
					array(
						'timestamp' => gmdate( 'c' ),
						'level'     => $level,
						'message'   => $message,
					),
					$context
				);
				if ( false === file_put_contents( $log_file, wp_json_encode( $event ) . PHP_EOL, FILE_APPEND | LOCK_EX ) ) {
					WP_CLI::warning( sprintf( 'Could not append to log file: %s', $log_file ) );
				}
			}
		};

		$client  = new CFOLC_Community_Sync_API_Client( $settings );
		$service = new CFOLC_Community_Sync_Service( $reporter );
		$result  = $service->run(
			$client,
			array(
				'email'      => isset( $assoc_args['email'] ) ? $assoc_args['email'] : '',
				'batch_size' => $batch_size,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$this->render_summary( $result );
		WP_CLI::success( 'Check completed. Changed BuddyBoss group values were synced.' );
	}

	/**
	 * Starts an immediate background check using the saved batch size.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cfolc-community start
	 */
	public function start() {
		$result = CFOLC_Community_Sync_Scheduler::start_new_run( true );
		WP_CLI::success( sprintf( 'Background check: %s.', str_replace( '_', ' ', $result ) ) );
	}

	/**
	 * Cancels the active background check and removes its pending batches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cfolc-community cancel
	 */
	public function cancel() {
		if ( CFOLC_Community_Sync_Scheduler::cancel_current_run() ) {
			WP_CLI::success( 'The active background check was cancelled.' );
			return;
		}

		WP_CLI::warning( 'There is no running background check to cancel.' );
	}

	/**
	 * Shows the current automatic-check state and totals.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cfolc-community status
	 */
	public function status() {
		$state = CFOLC_Community_Sync_Scheduler::get_state();
		WP_CLI::log( sprintf( 'Status: %s', $state['status'] ) );
		WP_CLI::log( sprintf( 'Completed batches: %d of %d', $state['completed_batches'], $state['total_pages'] ) );
		$this->render_summary( $state['stats'] );
	}

	/**
	 * Prints recent automatic-check events to the terminal.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Number of events to display. Default: 100. Maximum: 250.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cfolc-community log
	 *     wp cfolc-community log --limit=25
	 */
	public function log( $args, $assoc_args ) {
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 100;
		$events = array_reverse( CFOLC_Community_Sync_Logger::recent( max( 1, min( 250, $limit ) ) ) );

		if ( empty( $events ) ) {
			WP_CLI::log( 'No automatic-check events have been recorded.' );
			return;
		}

		foreach ( $events as $event ) {
			WP_CLI::log(
				sprintf(
					'%1$s [%2$s] %3$s',
					wp_date( 'Y-m-d H:i:s T', isset( $event['timestamp'] ) ? (int) $event['timestamp'] : 0 ),
					strtoupper( str_replace( '_', ' ', isset( $event['level'] ) ? $event['level'] : '' ) ),
					isset( $event['message'] ) ? $event['message'] : ''
				)
			);
		}
	}

	/**
	 * Backward-compatible alias of the check command.
	 *
	 * ## OPTIONS
	 *
	 * [--email=<address>]
	 * : Checks only this exact source email address.
	 *
	 * [--batch-size=<number>]
	 * : Source records requested per batch.
	 *
	 * [--log-file=<path>]
	 * : Appends terminal events as JSON Lines to this file.
	 */
	public function sync( $args, $assoc_args ) {
		WP_CLI::warning( '`sync` is an alias of `check`.' );
		$this->check( $args, $assoc_args );
	}

	private function validate_log_file( $log_file ) {
		if ( $log_file === '' ) {
			return;
		}

		$directory = dirname( $log_file );
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			WP_CLI::error( sprintf( 'The log directory is not writable: %s', $directory ) );
		}
		if ( file_exists( $log_file ) && ! is_writable( $log_file ) ) {
			WP_CLI::error( sprintf( 'The log file is not writable: %s', $log_file ) );
		}
	}

	private function render_summary( array $stats ) {
		WP_CLI::log( '' );
		WP_CLI::log( 'Summary:' );
		$labels = array(
			'fetched'   => 'Fetched',
			'examined'  => 'Examined',
			'found'     => 'Matched',
			'updated'   => 'Fields updated',
			'unchanged' => 'Fields unchanged',
			'not_found' => 'Not found',
			'invalid'   => 'Invalid',
			'failed'    => 'Failed',
		);

		foreach ( $labels as $key => $label ) {
			WP_CLI::log( sprintf( '  %-12s %d', $label . ':', isset( $stats[ $key ] ) ? $stats[ $key ] : 0 ) );
		}
	}
}
