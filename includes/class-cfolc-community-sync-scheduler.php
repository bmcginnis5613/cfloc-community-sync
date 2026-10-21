<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CFOLC_Community_Sync_Scheduler {
	const START_HOOK   = 'cfolc_community_sync_start_check';
	const BATCH_HOOK   = 'cfolc_community_sync_run_batch';
	const WATCHDOG_HOOK = 'cfolc_community_sync_watchdog';
	const STATE_OPTION = 'cfolc_community_sync_state';
	const LOCK_OPTION  = 'cfolc_community_sync_batch_lock';
	const START_LOCK_OPTION = 'cfolc_community_sync_start_lock';
	const WATCHDOG_LOCK_OPTION = 'cfolc_community_sync_watchdog_lock';
	const REST_NAMESPACE = 'cfolc-community-sync/v1';
	const REST_ROUTE     = '/run-batch';
	const STALL_TIMEOUT  = 10 * MINUTE_IN_SECONDS;
	const MAX_STALL_RETRIES = 2;

	public static function init() {
		add_action( self::START_HOOK, array( __CLASS__, 'start_scheduled_run' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ), 10, 3 );
		add_action( self::WATCHDOG_HOOK, array( __CLASS__, 'watchdog' ) );
		add_action( 'update_option_' . CFOLC_Community_Sync_Settings::OPTION_NAME, array( __CLASS__, 'settings_updated' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );

		self::ensure_watchdog_scheduled();
		self::recover_stalled_run();
		self::ensure_running_batch_scheduled();
		self::ensure_automatic_run_scheduled();
	}

	public static function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_loopback' ),
				'permission_callback' => array( __CLASS__, 'authorize_loopback' ),
				'args'                => array(
					'run_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'page'   => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'retry'  => array( 'required' => false, 'default' => 0, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	public static function authorize_loopback( WP_REST_Request $request ) {
		$state = self::get_state();
		$token = (string) $request->get_header( 'x-cfolc-community-sync-token' );

		if ( 'running' === $state['status'] && $token !== '' && $state['worker_token'] !== '' && hash_equals( (string) $state['worker_token'], $token ) ) {
			return true;
		}

		return new WP_Error( 'cfolc_sync_forbidden_worker', 'Invalid background worker token.', array( 'status' => 403 ) );
	}

	public static function handle_loopback( WP_REST_Request $request ) {
		self::run_batch( $request->get_param( 'run_id' ), $request->get_param( 'page' ), $request->get_param( 'retry' ) );
		return new WP_REST_Response( array( 'accepted' => true ), 202 );
	}

	public static function activate() {
		if ( false === get_option( self::STATE_OPTION, false ) ) {
			add_option( self::STATE_OPTION, self::default_state(), '', 'no' );
		}
		self::reschedule_from_settings();
		self::ensure_watchdog_scheduled();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::START_HOOK );
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
		delete_option( self::LOCK_OPTION );
		delete_option( self::START_LOCK_OPTION );
		delete_option( self::WATCHDOG_LOCK_OPTION );
	}

	public static function settings_updated( $old_settings, $new_settings ) {
		$old_enabled  = ! empty( $old_settings['automatic_checks'] );
		$new_enabled  = ! empty( $new_settings['automatic_checks'] );
		$old_interval = isset( $old_settings['interval_hours'] ) ? (int) $old_settings['interval_hours'] : 24;
		$new_interval = isset( $new_settings['interval_hours'] ) ? (int) $new_settings['interval_hours'] : 24;
		$connection_changed = false;

		foreach ( array( 'source_url', 'source_username', 'source_app_password' ) as $key ) {
			if ( ( isset( $old_settings[ $key ] ) ? $old_settings[ $key ] : '' ) !== ( isset( $new_settings[ $key ] ) ? $new_settings[ $key ] : '' ) ) {
				$connection_changed = true;
				break;
			}
		}

		if ( $connection_changed || ! $new_enabled ) {
			self::cancel_running_run( $connection_changed ? 'The source connection changed.' : 'Automatic checks were disabled.' );
		}

		if ( $old_enabled !== $new_enabled || $old_interval !== $new_interval || $connection_changed ) {
			self::reschedule_from_settings();
		}
	}

	public static function cancel_current_run() {
		return self::cancel_running_run( 'Cancelled by an administrator.' );
	}

	private static function cancel_running_run( $reason ) {
		$state = self::get_state();
		if ( 'running' !== $state['status'] ) {
			return false;
		}

		wp_clear_scheduled_hook( self::BATCH_HOOK );
		delete_option( self::LOCK_OPTION );
		$state['status']      = 'cancelled';
		$state['finished_at'] = time();
		$state['next_run_at'] = 0;
		$state['last_error']  = '';
		$state['retry']       = 0;
		$state['worker_token'] = '';
		update_option( self::STATE_OPTION, $state, false );

		$logger = new CFOLC_Community_Sync_Logger();
		$logger->log( 'cancelled', 'The active check was cancelled. ' . $reason, array( 'run_id' => $state['run_id'] ) );
		$logger->flush();
		self::schedule_next_automatic_run();

		return true;
	}

	public static function reschedule_from_settings() {
		wp_clear_scheduled_hook( self::START_HOOK );
		$settings = CFOLC_Community_Sync_Settings::get();

		if ( ! empty( $settings['automatic_checks'] ) ) {
			self::schedule_start( time() + MINUTE_IN_SECONDS );
		} else {
			self::set_next_run( 0 );
		}
	}

	public static function start_scheduled_run() {
		$settings = CFOLC_Community_Sync_Settings::get();
		if ( empty( $settings['automatic_checks'] ) ) {
			self::set_next_run( 0 );
			return;
		}

		$lock_token = wp_generate_uuid4();
		if ( ! self::acquire_start_lock( $lock_token ) ) {
			return;
		}

		try {
			$state = self::get_state();
			if ( 'running' === $state['status'] ) {
				return;
			}

			// Cron requests can safely execute batch one here. When a normal page
			// request repairs an overdue event, use the asynchronous worker so the
			// visitor is not made to wait for the batch.
			self::start_new_run( false, 0, self::is_cron_request() );
		} finally {
			self::release_start_lock( $lock_token );
		}
	}

	public static function start_new_run( $manual = true, $stall_retry = 0, $run_immediately = false ) {
		$state = self::get_state();

		if ( 'running' === $state['status'] ) {
			return 'already_running';
		}

		$stall_retry = max( 0, (int) $stall_retry );
		if ( 0 === $stall_retry ) {
			CFOLC_Community_Sync_Logger::clear();
		}

		$settings  = CFOLC_Community_Sync_Settings::get();
		$batch_size = max( 1, min( 100, (int) $settings['batch_size'] ) );
		$run_id     = wp_generate_uuid4();
		$worker_token = wp_generate_password( 48, false, false );
		$state      = array(
			'status'       => 'running',
			'run_id'       => $run_id,
			'manual'       => (bool) $manual,
			'started_at'   => time(),
			'last_activity_at' => time(),
			'finished_at'  => 0,
			'next_run_at'  => 0,
			'page'         => 1,
			'completed_batches' => 0,
			'total_pages'  => 0,
			'total_source' => 0,
			'batch_size'   => $batch_size,
			'retry'        => 0,
			'stall_retry'  => $stall_retry,
			'last_error'   => '',
			'worker_token' => $worker_token,
			'stats'        => CFOLC_Community_Sync_Service::empty_stats(),
		);

		update_option( self::STATE_OPTION, $state, false );
		wp_clear_scheduled_hook( self::START_HOOK );

		$logger = new CFOLC_Community_Sync_Logger();
		$logger->log(
			'run',
			$stall_retry > 0
				? sprintf( 'Restarted stalled %1$s check (attempt %2$d of %3$d) with batches of %4$d.', $manual ? 'manual' : 'automatic', $stall_retry, self::MAX_STALL_RETRIES, $batch_size )
						: sprintf( 'Started %s user comparison and BuddyBoss group sync with batches of %d.', $manual ? 'manual' : 'automatic', $batch_size ),
			array( 'run_id' => $run_id )
		);
		$logger->flush();

		self::schedule_batch( $run_id, 1, 0, 0 );
		if ( $run_immediately ) {
			self::run_batch( $run_id, 1, 0 );
		} else {
			self::dispatch_loopback( $run_id, 1, 0, $worker_token );
		}
		return 'started';
	}

	public static function run_batch( $run_id, $page, $retry = 0 ) {
		$state = self::get_state();
		$page  = max( 1, (int) $page );
		$retry = max( 0, (int) $retry );

		if ( 'running' !== $state['status'] || $state['run_id'] !== $run_id || (int) $state['page'] !== $page ) {
			return;
		}

		self::unschedule_batch( $run_id, $page, $retry );

		$lock_token = $run_id . ':' . $page . ':' . wp_generate_uuid4();
		if ( ! self::acquire_lock( $lock_token ) ) {
			self::schedule_batch( $run_id, $page, $retry, 30 );
			return;
		}

		$state['last_activity_at'] = time();
		update_option( self::STATE_OPTION, $state, false );

		$logger     = new CFOLC_Community_Sync_Logger();
		$next_batch = null;

		try {
			$settings = CFOLC_Community_Sync_Settings::get();
			$client   = new CFOLC_Community_Sync_API_Client( $settings );
			$result   = $client->get_users_page( $page, '', $state['batch_size'] );

			if ( is_wp_error( $result ) ) {
				self::handle_batch_error( $state, $page, $retry, $result, $logger );
				return;
			}

			$current_state = self::get_state();
			if ( 'running' !== $current_state['status'] || $current_state['run_id'] !== $run_id || (int) $current_state['page'] !== $page ) {
				return;
			}
			$state = $current_state;

			$reporter = static function ( $level, $message, $context = array() ) use ( $logger, $run_id ) {
				$logger->log( $level, $message, array_merge( array( 'run_id' => $run_id ), $context ) );
			};
			$service     = new CFOLC_Community_Sync_Service( $reporter );
			$batch_stats = $service->check_batch( $result['users'] );

			$current_state = self::get_state();
			if ( 'running' !== $current_state['status'] || $current_state['run_id'] !== $run_id || (int) $current_state['page'] !== $page ) {
				return;
			}
			$state = $current_state;

			foreach ( $batch_stats as $key => $value ) {
				$state['stats'][ $key ] += $value;
			}

			$state['page']         = $page;
			$state['completed_batches'] = $page;
			$state['total_pages']  = $result['total_pages'];
			$state['total_source'] = $result['total'];
			$state['retry']        = 0;
			$state['last_error']   = '';
			$state['last_activity_at'] = time();

			$logger->log(
				'batch',
				sprintf( 'Completed batch %1$d of %2$d (%3$d record(s)).', $page, $result['total_pages'], count( $result['users'] ) ),
				array( 'run_id' => $run_id, 'page' => $page )
			);

			if ( $page >= $result['total_pages'] ) {
				self::finish_run( $state, 'complete', $logger );
			} else {
				$state['page'] = $page + 1;
				update_option( self::STATE_OPTION, $state, false );
				self::schedule_batch( $run_id, $page + 1, 0, MINUTE_IN_SECONDS );
				$next_batch = array( $run_id, $page + 1, 0, $state['worker_token'], $page + 1 >= $result['total_pages'] );
			}
		} catch ( Throwable $error ) {
			$current_state = self::get_state();
			if ( 'running' === $current_state['status'] && $current_state['run_id'] === $run_id && (int) $current_state['page'] === $page ) {
				self::handle_batch_error(
					$current_state,
					$page,
					$retry,
					new WP_Error(
						'cfolc_sync_worker_exception',
						sprintf( 'Unexpected background worker error (%1$s): %2$s', get_class( $error ), $error->getMessage() )
					),
					$logger
				);
			}
		} finally {
			$logger->flush();
			self::release_lock( $lock_token );
		}

		if ( $next_batch ) {
			if ( $next_batch[4] ) {
				self::run_batch( $next_batch[0], $next_batch[1], $next_batch[2] );
			} else {
				self::dispatch_loopback( $next_batch[0], $next_batch[1], $next_batch[2], $next_batch[3] );
			}
		}
	}

	private static function handle_batch_error( array $state, $page, $retry, WP_Error $error, CFOLC_Community_Sync_Logger $logger ) {
		++$state['stats']['failed'];
		$state['last_error'] = $error->get_error_message();
		$state['retry']      = $retry + 1;
		$state['last_activity_at'] = time();
		update_option( self::STATE_OPTION, $state, false );

		$logger->log( 'error', sprintf( 'Batch %1$d failed: %2$s', $page, $error->get_error_message() ), array( 'run_id' => $state['run_id'], 'page' => $page ) );

		if ( $retry < 2 ) {
			$delay = 30 * ( 2 ** $retry );
			$logger->log( 'retry', sprintf( 'Retrying batch %1$d in %2$d seconds.', $page, $delay ), array( 'run_id' => $state['run_id'], 'page' => $page ) );
			self::schedule_batch( $state['run_id'], $page, $retry + 1, $delay );
			return;
		}

		self::finish_run( $state, 'failed', $logger );
	}

	private static function finish_run( array $state, $status, CFOLC_Community_Sync_Logger $logger ) {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		$state['status']      = $status;
		$state['finished_at'] = time();
		$state['retry']       = 0;
		$state['worker_token'] = '';

		$logger->log(
			'summary',
			sprintf(
				'Check %1$s: %2$d users matched, %3$d fields updated, %4$d fields unchanged, %5$d users not found, %6$d invalid users, %7$d failed operations.',
				$status,
				$state['stats']['found'],
				$state['stats']['updated'],
				$state['stats']['unchanged'],
				$state['stats']['not_found'],
				$state['stats']['invalid'],
				$state['stats']['failed']
			),
			array( 'run_id' => $state['run_id'] )
		);

		update_option( self::STATE_OPTION, $state, false );
		self::schedule_next_automatic_run();
	}

	private static function schedule_next_automatic_run() {
		$settings = CFOLC_Community_Sync_Settings::get();
		wp_clear_scheduled_hook( self::START_HOOK );

		if ( empty( $settings['automatic_checks'] ) ) {
			self::set_next_run( 0 );
			return;
		}

		$timestamp = time() + max( 1, (int) $settings['interval_hours'] ) * HOUR_IN_SECONDS;
		self::schedule_start( $timestamp );
	}

	public static function watchdog() {
		self::ensure_watchdog_scheduled();
		self::recover_stalled_run();
	}

	private static function ensure_watchdog_scheduled() {
		if ( ! wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::WATCHDOG_HOOK );
		}
	}

	/**
	 * Keep the persisted next-run value and the real WP-Cron event in sync.
	 *
	 * WordPress single events can be removed by cron cleanup, cache restores,
	 * or deployment tooling. WP-Cron can also be disabled even while ordinary
	 * site traffic continues. In either case, repair the missing event and start
	 * an overdue check from the current request.
	 */
	private static function ensure_automatic_run_scheduled() {
		$settings = CFOLC_Community_Sync_Settings::get();
		if ( empty( $settings['automatic_checks'] ) ) {
			return;
		}

		$state = self::get_state();
		if ( 'running' === $state['status'] ) {
			return;
		}

		$now       = time();
		$scheduled = (int) wp_next_scheduled( self::START_HOOK );

		if ( $scheduled > $now ) {
			if ( (int) $state['next_run_at'] !== $scheduled ) {
				self::set_next_run( $scheduled );
			}
			return;
		}

		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::START_HOOK );
		}

		$next_run = (int) $state['next_run_at'];
		if ( $next_run > $now ) {
			self::schedule_start( $next_run );
			return;
		}

		self::start_scheduled_run();
	}

	private static function recover_stalled_run() {
		$state = self::get_state();
		$last_activity = ! empty( $state['last_activity_at'] ) ? (int) $state['last_activity_at'] : (int) $state['started_at'];

		if ( 'running' !== $state['status'] || ! $last_activity || $last_activity > time() - self::STALL_TIMEOUT ) {
			return;
		}

		if ( ! add_option( self::WATCHDOG_LOCK_OPTION, time(), '', 'no' ) ) {
			$lock_time = (int) get_option( self::WATCHDOG_LOCK_OPTION, 0 );
			if ( $lock_time > time() - MINUTE_IN_SECONDS ) {
				return;
			}
			delete_option( self::WATCHDOG_LOCK_OPTION );
			if ( ! add_option( self::WATCHDOG_LOCK_OPTION, time(), '', 'no' ) ) {
				return;
			}
		}

		try {
			$state = self::get_state();
			$last_activity = ! empty( $state['last_activity_at'] ) ? (int) $state['last_activity_at'] : (int) $state['started_at'];
			if ( 'running' !== $state['status'] || ! $last_activity || $last_activity > time() - self::STALL_TIMEOUT ) {
				return;
			}

			$stall_retry = isset( $state['stall_retry'] ) ? (int) $state['stall_retry'] : 0;
			$logger = new CFOLC_Community_Sync_Logger();
			wp_clear_scheduled_hook( self::BATCH_HOOK );
			delete_option( self::LOCK_OPTION );

			if ( $stall_retry >= self::MAX_STALL_RETRIES ) {
				$state['last_error'] = sprintf( 'The check stopped making progress for %d minutes and exhausted its automatic restarts.', self::STALL_TIMEOUT / MINUTE_IN_SECONDS );
				++$state['stats']['failed'];
				self::finish_run( $state, 'failed', $logger );
				$logger->flush();
				return;
			}

			$logger->log(
				'warning',
				sprintf( 'The check made no progress for %d minutes. Invalidating it and starting a fresh attempt.', self::STALL_TIMEOUT / MINUTE_IN_SECONDS ),
				array( 'run_id' => $state['run_id'], 'page' => $state['page'] )
			);
			$logger->flush();

			$state['status'] = 'stalled';
			$state['finished_at'] = time();
			$state['worker_token'] = '';
			update_option( self::STATE_OPTION, $state, false );
			self::start_new_run( ! empty( $state['manual'] ), $stall_retry + 1, self::is_cron_request() );
		} finally {
			delete_option( self::WATCHDOG_LOCK_OPTION );
		}
	}

	private static function schedule_start( $timestamp ) {
		$timestamp = (int) $timestamp;
		wp_schedule_single_event( $timestamp, self::START_HOOK );
		$scheduled = (int) wp_next_scheduled( self::START_HOOK );
		self::set_next_run( $scheduled ? $scheduled : $timestamp );

		if ( ! $scheduled ) {
			$logger = new CFOLC_Community_Sync_Logger();
			$logger->log( 'error', 'Could not schedule the next automatic check. Check the WordPress cron configuration.' );
			$logger->flush();
		}
	}

	private static function schedule_batch( $run_id, $page, $retry, $delay ) {
		$args = array( $run_id, (int) $page, (int) $retry );
		if ( ! wp_next_scheduled( self::BATCH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::BATCH_HOOK, $args );
		}
	}

	/**
	 * Restore the cron safety net if a worker died after removing its event.
	 *
	 * A fatal error or process termination can happen after run_batch() removes
	 * the pending event but before it schedules a retry. The next WordPress
	 * request repairs that missing event instead of leaving the state running
	 * forever.
	 */
	private static function ensure_running_batch_scheduled() {
		$state = self::get_state();
		if ( 'running' !== $state['status'] || empty( $state['run_id'] ) || empty( $state['page'] ) ) {
			return;
		}

		$args = array( $state['run_id'], (int) $state['page'], (int) $state['retry'] );
		if ( wp_next_scheduled( self::BATCH_HOOK, $args ) ) {
			return;
		}

		self::schedule_batch( $state['run_id'], $state['page'], $state['retry'], 0 );
		$restored = (bool) wp_next_scheduled( self::BATCH_HOOK, $args );

		$logger = new CFOLC_Community_Sync_Logger();
		$logger->log(
			$restored ? 'warning' : 'error',
			$restored
				? sprintf( 'Restored the missing scheduled fallback for batch %d.', $state['page'] )
				: sprintf( 'Could not restore the missing scheduled fallback for batch %d. Check the WordPress cron configuration.', $state['page'] ),
			array( 'run_id' => $state['run_id'], 'page' => $state['page'] )
		);
		$logger->flush();
	}

	private static function unschedule_batch( $run_id, $page, $retry ) {
		$args      = array( $run_id, (int) $page, (int) $retry );
		$timestamp = wp_next_scheduled( self::BATCH_HOOK, $args );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::BATCH_HOOK, $args );
		}
	}

	private static function dispatch_loopback( $run_id, $page, $retry, $worker_token ) {
		$response = wp_remote_post(
			rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
			array(
				'timeout'     => 1,
				'blocking'    => false,
				'redirection' => 0,
				'headers'     => array(
					'X-CFOLC-Community-Sync-Token' => $worker_token,
				),
				'body'        => array(
					'run_id' => $run_id,
					'page'   => (int) $page,
					'retry'  => (int) $retry,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$logger = new CFOLC_Community_Sync_Logger();
			$logger->log( 'warning', sprintf( 'Immediate handoff for batch %1$d failed; the scheduled fallback remains active. %2$s', $page, $response->get_error_message() ), array( 'run_id' => $run_id, 'page' => $page ) );
			$logger->flush();
		}
	}

	private static function is_cron_request() {
		if ( function_exists( 'wp_doing_cron' ) ) {
			return wp_doing_cron();
		}

		return defined( 'DOING_CRON' ) && DOING_CRON;
	}

	public static function get_pending_batch_timestamp() {
		$state = self::get_state();
		if ( 'running' !== $state['status'] || empty( $state['run_id'] ) || empty( $state['page'] ) ) {
			return 0;
		}

		return (int) wp_next_scheduled(
			self::BATCH_HOOK,
			array( $state['run_id'], (int) $state['page'], (int) $state['retry'] )
		);
	}

	private static function acquire_lock( $token ) {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $lock ) && ! empty( $lock['created_at'] ) && (int) $lock['created_at'] > time() - self::STALL_TIMEOUT ) {
			return false;
		}

		if ( $lock ) {
			delete_option( self::LOCK_OPTION );
		}

		return add_option(
			self::LOCK_OPTION,
			array(
				'token'      => $token,
				'created_at' => time(),
			),
			'',
			'no'
		);
	}

	private static function acquire_start_lock( $token ) {
		$lock = get_option( self::START_LOCK_OPTION, array() );
		if ( is_array( $lock ) && ! empty( $lock['created_at'] ) && (int) $lock['created_at'] > time() - MINUTE_IN_SECONDS ) {
			return false;
		}

		if ( $lock ) {
			delete_option( self::START_LOCK_OPTION );
		}

		return add_option(
			self::START_LOCK_OPTION,
			array(
				'token'      => $token,
				'created_at' => time(),
			),
			'',
			'no'
		);
	}

	private static function release_start_lock( $token ) {
		$lock = get_option( self::START_LOCK_OPTION, array() );
		if ( is_array( $lock ) && isset( $lock['token'] ) && hash_equals( (string) $lock['token'], (string) $token ) ) {
			delete_option( self::START_LOCK_OPTION );
		}
	}

	private static function release_lock( $token ) {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $lock ) && isset( $lock['token'] ) && hash_equals( (string) $lock['token'], (string) $token ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	private static function set_next_run( $timestamp ) {
		$state                = self::get_state();
		$state['next_run_at'] = (int) $timestamp;
		update_option( self::STATE_OPTION, $state, false );
	}

	public static function get_state() {
		$stored = get_option( self::STATE_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = wp_parse_args( $stored, self::default_state() );
		$state['stats'] = wp_parse_args(
			isset( $state['stats'] ) && is_array( $state['stats'] ) ? $state['stats'] : array(),
			CFOLC_Community_Sync_Service::empty_stats()
		);
		return $state;
	}

	private static function default_state() {
		return array(
			'status'       => 'idle',
			'run_id'       => '',
			'manual'       => false,
			'started_at'   => 0,
			'last_activity_at' => 0,
			'finished_at'  => 0,
			'next_run_at'  => 0,
			'page'         => 0,
			'completed_batches' => 0,
			'total_pages'  => 0,
			'total_source' => 0,
			'batch_size'   => 50,
			'retry'        => 0,
			'stall_retry'  => 0,
			'last_error'   => '',
			'worker_token' => '',
			'stats'        => CFOLC_Community_Sync_Service::empty_stats(),
		);
	}
}
