<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CFOLC_Community_Sync_Settings {
	const OPTION_NAME = 'cfolc_community_sync_settings';

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_cfolc_community_sync_run_now', array( __CLASS__, 'handle_run_now' ) );
		add_action( 'admin_post_cfolc_community_sync_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_cfolc_community_sync_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_cfolc_community_sync_clear_log', array( __CLASS__, 'handle_clear_log' ) );
	}

	public static function activate() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::defaults(), '', 'no' );
		}
	}

	public static function defaults() {
		return array(
			'source_url'          => '',
			'source_username'     => '',
			'source_app_password' => '',
			'automatic_checks'     => 0,
			'interval_hours'      => 24,
			'batch_size'          => 50,
		);
	}

	public static function get() {
		$settings = wp_parse_args( get_option( self::OPTION_NAME, array() ), self::defaults() );

		$constant_overrides = array(
			'source_url'          => 'CFOLC_COMMUNITY_SYNC_SOURCE_URL',
			'source_username'     => 'CFOLC_COMMUNITY_SYNC_SOURCE_USERNAME',
			'source_app_password' => 'CFOLC_COMMUNITY_SYNC_SOURCE_APP_PASSWORD',
		);

		foreach ( $constant_overrides as $key => $constant ) {
			if ( defined( $constant ) && constant( $constant ) !== '' ) {
				$settings[ $key ] = (string) constant( $constant );
			}
		}

		return $settings;
	}

	public static function add_settings_page() {
		add_options_page(
			__( 'CFOLC Community Sync', 'cfolc-community-sync' ),
			__( 'CFOLC Community Sync', 'cfolc-community-sync' ),
			'manage_options',
			'cfolc-community-sync',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'cfolc_community_sync',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'cfolc_community_sync_source',
			__( 'Source connection', 'cfolc-community-sync' ),
			static function () {
				echo '<p>' . esc_html__( 'The source is read through the authenticated WordPress REST API. Destination users are matched by email, and only changed BuddyBoss group values are updated.', 'cfolc-community-sync' ) . '</p>';
			},
			'cfolc-community-sync'
		);

		self::add_input_field( 'source_url', __( 'Source site URL', 'cfolc-community-sync' ), 'url', 'https://source.example.com' );
		self::add_input_field( 'source_username', __( 'Source username', 'cfolc-community-sync' ), 'text', '' );
		self::add_input_field( 'source_app_password', __( 'Application Password', 'cfolc-community-sync' ), 'password', '' );

		add_settings_section(
			'cfolc_community_sync_schedule',
			__( 'Automatic checks', 'cfolc-community-sync' ),
			static function () {
				echo '<p>' . esc_html__( 'WordPress starts each run automatically and checks one batch at a time. For the most reliable unattended operation, configure the server to request wp-cron.php regularly when page-request WP-Cron is disabled.', 'cfolc-community-sync' ) . '</p>';
			},
			'cfolc-community-sync'
		);

		add_settings_field(
			'automatic_checks',
			__( 'Enable automatic checks', 'cfolc-community-sync' ),
			array( __CLASS__, 'render_checkbox' ),
			'cfolc-community-sync',
			'cfolc_community_sync_schedule',
			array(
				'key'         => 'automatic_checks',
				'description' => __( 'Compare users and sync changed BuddyBoss group values automatically.', 'cfolc-community-sync' ),
			)
		);

		self::add_input_field( 'interval_hours', __( 'Run every', 'cfolc-community-sync' ), 'number', '', __( 'hours (1–168)', 'cfolc-community-sync' ), 'cfolc_community_sync_schedule', 1, 168 );
		self::add_input_field( 'batch_size', __( 'Batch size', 'cfolc-community-sync' ), 'number', '', __( 'users per batch (1–100)', 'cfolc-community-sync' ), 'cfolc_community_sync_schedule', 1, 100 );
	}

	private static function add_input_field( $key, $label, $type, $placeholder = '', $suffix = '', $section = 'cfolc_community_sync_source', $min = null, $max = null ) {
		add_settings_field(
			$key,
			$label,
			array( __CLASS__, 'render_input' ),
			'cfolc-community-sync',
			$section,
			array(
				'key'         => $key,
				'type'        => $type,
				'placeholder' => $placeholder,
				'suffix'      => $suffix,
				'min'         => $min,
				'max'         => $max,
			)
		);
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = wp_parse_args( get_option( self::OPTION_NAME, array() ), self::defaults() );
		$clean = self::defaults();

		$clean['source_url']      = isset( $input['source_url'] ) ? untrailingslashit( esc_url_raw( $input['source_url'] ) ) : '';
		$clean['source_username'] = isset( $input['source_username'] ) ? sanitize_text_field( $input['source_username'] ) : '';

		$new_password = isset( $input['source_app_password'] ) ? preg_replace( '/\s+/', '', (string) $input['source_app_password'] ) : '';
		$clean['source_app_password'] = $new_password !== '' ? $new_password : $old['source_app_password'];

		$clean['automatic_checks'] = empty( $input['automatic_checks'] ) ? 0 : 1;
		$clean['interval_hours']   = isset( $input['interval_hours'] ) ? max( 1, min( 168, (int) $input['interval_hours'] ) ) : 24;
		$clean['batch_size']       = isset( $input['batch_size'] ) ? max( 1, min( 100, (int) $input['batch_size'] ) ) : 50;

		return $clean;
	}

	public static function render_input( $args ) {
		$settings    = self::get();
		$key         = $args['key'];
		$type        = $args['type'];
		$name        = self::OPTION_NAME . '[' . $key . ']';
		$value       = 'password' === $type ? '' : $settings[ $key ];
		$placeholder = $args['placeholder'];

		if ( 'password' === $type && ! empty( $settings[ $key ] ) ) {
			$placeholder = __( 'Stored — leave blank to keep', 'cfolc-community-sync' );
		}

		$attributes = '';
		if ( null !== $args['min'] ) {
			$attributes .= ' min="' . esc_attr( $args['min'] ) . '"';
		}
		if ( null !== $args['max'] ) {
			$attributes .= ' max="' . esc_attr( $args['max'] ) . '"';
		}

		printf(
			'<input class="%1$s" type="%2$s" id="%3$s" name="%4$s" value="%5$s" placeholder="%6$s" autocomplete="%7$s"%8$s /> %9$s',
			'number' === $type ? 'small-text' : 'regular-text',
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			'password' === $type ? 'new-password' : 'off',
			$attributes,
			esc_html( $args['suffix'] )
		);
	}

	public static function render_checkbox( $args ) {
		$settings = self::get();
		$key      = $args['key'];
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $key ),
			checked( ! empty( $settings[ $key ] ), true, false ),
			esc_html( $args['description'] )
		);
	}

	public static function handle_run_now() {
		self::authorize_action( 'cfolc_community_sync_run_now' );
		$result = CFOLC_Community_Sync_Scheduler::start_new_run( true );
		if ( 'started' === $result && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		self::redirect_with_notice( $result );
	}

	public static function handle_test() {
		self::authorize_action( 'cfolc_community_sync_test' );
		$client = new CFOLC_Community_Sync_API_Client( self::get() );
		$result = $client->test_connection();
		self::redirect_with_notice( is_wp_error( $result ) ? 'test_failed' : 'test_ok', is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	public static function handle_cancel() {
		self::authorize_action( 'cfolc_community_sync_cancel' );
		$cancelled = CFOLC_Community_Sync_Scheduler::cancel_current_run();
		self::redirect_with_notice( $cancelled ? 'cancelled' : 'nothing_to_cancel' );
	}

	public static function handle_clear_log() {
		self::authorize_action( 'cfolc_community_sync_clear_log' );
		CFOLC_Community_Sync_Logger::clear();
		self::redirect_with_notice( 'log_cleared' );
	}

	private static function authorize_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this check.', 'cfolc-community-sync' ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect_with_notice( $notice, $detail = '' ) {
		$url = add_query_arg(
			array(
				'page'            => 'cfolc-community-sync',
				'cfolc_sync_notice' => sanitize_key( $notice ),
				'cfolc_sync_detail' => $detail,
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		$state    = CFOLC_Community_Sync_Scheduler::get_state();
		$events   = CFOLC_Community_Sync_Logger::recent( 100 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CFOLC Community Sync', 'cfolc-community-sync' ); ?></h1>
			<?php self::render_notice(); ?>
			<div class="notice notice-info inline"><p><strong><?php esc_html_e( 'BuddyBoss group sync:', 'cfolc-community-sync' ); ?></strong> <?php esc_html_e( 'users are matched by email. Groups tagged Local Chapter or Virtual Chapter are written to the BuddyBoss Groups ACF field; users are never created.', 'cfolc-community-sync' ); ?></p></div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'cfolc_community_sync' );
				do_settings_sections( 'cfolc-community-sync' );
				submit_button( __( 'Save settings', 'cfolc-community-sync' ) );
				?>
			</form>

			<h2><?php esc_html_e( 'Check status', 'cfolc-community-sync' ); ?></h2>
			<?php self::render_status( $settings, $state ); ?>
			<p>
				<?php self::render_action_button( 'cfolc_community_sync_test', __( 'Test connection', 'cfolc-community-sync' ), 'secondary' ); ?>
				<?php self::render_action_button( 'cfolc_community_sync_run_now', __( 'Start check now', 'cfolc-community-sync' ), 'primary' ); ?>
				<?php if ( 'running' === $state['status'] ) { self::render_action_button( 'cfolc_community_sync_cancel', __( 'Cancel current check', 'cfolc-community-sync' ), 'secondary' ); } ?>
				<?php self::render_action_button( 'cfolc_community_sync_clear_log', __( 'Clear recent log', 'cfolc-community-sync' ), 'secondary' ); ?>
			</p>

			<h2><?php esc_html_e( 'Recent results', 'cfolc-community-sync' ); ?></h2>
			<?php self::render_log( $events ); ?>

			<h2><?php esc_html_e( 'Optional terminal commands', 'cfolc-community-sync' ); ?></h2>
			<pre>wp cfolc-community test
wp cfolc-community check
wp cfolc-community status
wp cfolc-community log
wp cfolc-community cancel</pre>
			<p><?php esc_html_e( 'The automatic scheduler does not require these commands. A manual terminal check performs the same comparison and ACF field updates while printing its progress.', 'cfolc-community-sync' ); ?></p>
		</div>
		<?php
	}

	private static function render_notice() {
		$notice = isset( $_GET['cfolc_sync_notice'] ) ? sanitize_key( wp_unslash( $_GET['cfolc_sync_notice'] ) ) : '';
		$detail = isset( $_GET['cfolc_sync_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['cfolc_sync_detail'] ) ) : '';
		$messages = array(
			'started'         => array( 'success', __( 'The background check was started.', 'cfolc-community-sync' ) ),
			'already_running' => array( 'warning', __( 'A background check is already running.', 'cfolc-community-sync' ) ),
			'cancelled'       => array( 'success', __( 'The current background check was cancelled. You can start a new check now.', 'cfolc-community-sync' ) ),
			'nothing_to_cancel' => array( 'warning', __( 'There is no running check to cancel.', 'cfolc-community-sync' ) ),
			'test_ok'         => array( 'success', __( 'Connection successful. The source user list and BuddyBoss groups are accessible.', 'cfolc-community-sync' ) ),
			'test_failed'     => array( 'error', sprintf( __( 'Connection failed: %s', 'cfolc-community-sync' ), $detail ) ),
			'log_cleared'     => array( 'success', __( 'The recent result log was cleared.', 'cfolc-community-sync' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	private static function render_status( array $settings, array $state ) {
		$stats = wp_parse_args( $state['stats'], CFOLC_Community_Sync_Service::empty_stats() );
		$wp_cron_status = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
			? __( 'Page-request spawning disabled; server cron required', 'cfolc-community-sync' )
			: __( 'Page-request spawning enabled', 'cfolc-community-sync' );
		$rows  = array(
			__( 'Automatic checks', 'cfolc-community-sync' ) => empty( $settings['automatic_checks'] ) ? __( 'Disabled', 'cfolc-community-sync' ) : __( 'Enabled', 'cfolc-community-sync' ),
			__( 'WP-Cron', 'cfolc-community-sync' )           => $wp_cron_status,
			__( 'Status', 'cfolc-community-sync' )           => ucfirst( $state['status'] ),
			__( 'Batch progress', 'cfolc-community-sync' )   => sprintf( '%1$d / %2$d', $state['completed_batches'], $state['total_pages'] ),
			__( 'Pending batch fallback', 'cfolc-community-sync' ) => self::format_time( CFOLC_Community_Sync_Scheduler::get_pending_batch_timestamp() ),
			__( 'Started', 'cfolc-community-sync' )          => self::format_time( $state['started_at'] ),
			__( 'Last activity', 'cfolc-community-sync' )    => self::format_time( $state['last_activity_at'] ),
			__( 'Automatic restarts', 'cfolc-community-sync' ) => sprintf( '%1$d / %2$d', $state['stall_retry'], CFOLC_Community_Sync_Scheduler::MAX_STALL_RETRIES ),
			__( 'Finished', 'cfolc-community-sync' )         => self::format_time( $state['finished_at'] ),
			__( 'Next automatic run', 'cfolc-community-sync' ) => self::format_time( $state['next_run_at'] ),
			__( 'Source total', 'cfolc-community-sync' )     => number_format_i18n( $state['total_source'] ),
			__( 'Fetched', 'cfolc-community-sync' )          => number_format_i18n( $stats['fetched'] ),
			__( 'Examined', 'cfolc-community-sync' )         => number_format_i18n( $stats['examined'] ),
			__( 'Matched', 'cfolc-community-sync' )          => number_format_i18n( $stats['found'] ),
			__( 'Fields updated', 'cfolc-community-sync' )   => number_format_i18n( $stats['updated'] ),
			__( 'Fields unchanged', 'cfolc-community-sync' ) => number_format_i18n( $stats['unchanged'] ),
			__( 'Not found', 'cfolc-community-sync' )        => number_format_i18n( $stats['not_found'] ),
			__( 'Invalid', 'cfolc-community-sync' )          => number_format_i18n( $stats['invalid'] ),
			__( 'Failures', 'cfolc-community-sync' )         => number_format_i18n( $stats['failed'] ),
		);

		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th style="width:220px">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		if ( $state['last_error'] !== '' ) {
			printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Last error', 'cfolc-community-sync' ), esc_html( $state['last_error'] ) );
		}
		echo '</tbody></table>';
	}

	private static function render_log( array $events ) {
		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'No checks have been logged yet.', 'cfolc-community-sync' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'cfolc-community-sync' ) . '</th><th>' . esc_html__( 'Result', 'cfolc-community-sync' ) . '</th><th>' . esc_html__( 'Message', 'cfolc-community-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td></tr>',
				esc_html( self::format_time( isset( $event['timestamp'] ) ? $event['timestamp'] : 0 ) ),
				esc_html( strtoupper( str_replace( '_', ' ', isset( $event['level'] ) ? $event['level'] : '' ) ) ),
				esc_html( isset( $event['message'] ) ? $event['message'] : '' )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_action_button( $action, $label, $class ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
		printf( '<a class="button button-%1$s" href="%2$s">%3$s</a> ', esc_attr( $class ), esc_url( $url ), esc_html( $label ) );
	}

	private static function format_time( $timestamp ) {
		return $timestamp ? wp_date( 'Y-m-d H:i:s T', (int) $timestamp ) : '—';
	}
}
