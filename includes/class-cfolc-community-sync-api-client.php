<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CFOLC_Community_Sync_API_Client {
	const ALLOWED_GROUP_TYPES = array( 'chapter', 'local-chapter', 'virtual-chapter' );

	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function validate_configuration() {
		if ( empty( $this->settings['source_url'] ) ) {
			return new WP_Error( 'cfolc_sync_missing_url', 'The source site URL is not configured.' );
		}
		if ( 0 !== stripos( $this->settings['source_url'], 'https://' ) ) {
			return new WP_Error( 'cfolc_sync_insecure_url', 'The source site URL must use HTTPS.' );
		}
		if ( empty( $this->settings['source_username'] ) || empty( $this->settings['source_app_password'] ) ) {
			return new WP_Error( 'cfolc_sync_missing_credentials', 'The source username and Application Password are required.' );
		}
		return true;
	}

	public function test_connection() {
		$result = $this->request(
			'/wp-json/wp/v2/users',
			array( 'context' => 'edit', 'per_page' => 1, '_fields' => 'id,email' ),
			true
		);
		if ( is_wp_error( $result ) || empty( $result['users'] ) ) {
			return $result;
		}
		return $this->add_buddyboss_groups( $result );
	}

	public function get_users_page( $page, $email = '', $per_page = 100 ) {
		$query = array(
			'context'  => 'edit',
			'per_page' => max( 1, min( 100, (int) $per_page ) ),
			'page'     => max( 1, (int) $page ),
			'orderby'  => 'id',
			'order'    => 'asc',
			'_fields'  => 'id,email',
		);
		if ( $email !== '' ) {
			$query['search']         = $email;
			$query['search_columns'] = array( 'email' );
		}

		$result = $this->request( '/wp-json/wp/v2/users', $query, true );
		if ( is_wp_error( $result ) || empty( $result['users'] ) ) {
			return $result;
		}
		return $this->add_buddyboss_groups( $result );
	}

	private function add_buddyboss_groups( array $result ) {
		$namespace = 'buddyboss/v1';
		foreach ( $result['users'] as &$source_user ) {
			$user_id = isset( $source_user['id'] ) ? (int) $source_user['id'] : 0;
			if ( ! $user_id ) {
				continue;
			}

			$groups = $this->request_user_groups( $namespace, $user_id );
			if ( is_wp_error( $groups ) && 404 === (int) $groups->get_error_data( 'cfolc_sync_source_response' ) && 'buddyboss/v1' === $namespace ) {
				$namespace = 'buddypress/v1';
				$groups    = $this->request_user_groups( $namespace, $user_id );
			}
			if ( is_wp_error( $groups ) ) {
				unset( $source_user );
				return new WP_Error( 'cfolc_sync_groups_request_failed', sprintf( 'BuddyBoss groups for source user %1$d could not be read: %2$s', $user_id, $groups->get_error_message() ) );
			}

			$source_user['_cfolc_buddyboss_groups'] = $this->filter_chapter_group_names( $groups );
		}
		unset( $source_user );
		return $result;
	}

	private function request_user_groups( $namespace, $user_id ) {
		$groups = array();
		$page   = 1;
		do {
			$response = $this->request(
				'/wp-json/' . $namespace . '/groups',
				array(
					'context'         => 'edit',
					'user_id'         => (int) $user_id,
					'show_hidden'     => true,
					'populate_extras' => false,
					'per_page'        => 100,
					'page'            => $page,
					'_fields'         => 'id,name,types,group_type_label,group_type',
				),
				true
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$groups      = array_merge( $groups, $response['items'] );
			$total_pages = $response['total_pages'];
			++$page;
		} while ( $page <= $total_pages );
		return $groups;
	}

	private function filter_chapter_group_names( $groups ) {
		if ( ! is_array( $groups ) ) {
			return array();
		}
		$names = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group['name'] ) ) {
				continue;
			}

			$type_values = isset( $group['types'] ) && is_array( $group['types'] ) ? $group['types'] : array();
			if ( ! empty( $group['group_type_label'] ) ) {
				$type_values[] = $group['group_type_label'];
			}
			if ( isset( $group['group_type'] ) && is_array( $group['group_type'] ) ) {
				if ( ! empty( $group['group_type']['group_type_label'] ) ) {
					$type_values[] = $group['group_type']['group_type_label'];
				}
				if ( isset( $group['group_type']['types'] ) && is_array( $group['group_type']['types'] ) ) {
					$type_values = array_merge( $type_values, $group['group_type']['types'] );
				}
			}

			$types = array_map( 'sanitize_title', array_filter( $type_values, 'is_scalar' ) );
			if ( empty( array_intersect( self::ALLOWED_GROUP_TYPES, $types ) ) ) {
				continue;
			}
			$name = trim( sanitize_text_field( (string) $group['name'] ) );
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		natcasesort( $names );
		return array_values( $names );
	}

	private function request( $path, array $query = array(), $include_pagination = false ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$url = untrailingslashit( $this->settings['source_url'] ) . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'redirection' => 0,
				'headers' => array(
					'Accept' => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( $this->settings['source_username'] . ':' . $this->settings['source_app_password'] ),
					'User-Agent' => 'CFOLC-Community-Sync/' . CFOLC_COMMUNITY_SYNC_VERSION . '; ' . home_url( '/' ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cfolc_sync_http_error', 'Could not reach the source site: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? wp_strip_all_tags( $data['message'] ) : 'Unexpected response from the source site.';
			return new WP_Error( 'cfolc_sync_source_response', sprintf( 'Source returned HTTP %1$d: %2$s', $status, $message ), $status );
		}
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'cfolc_sync_invalid_json', 'The source site returned invalid JSON.' );
		}
		if ( ! $include_pagination ) {
			return $data;
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cfolc_sync_invalid_response', 'The source endpoint returned an unexpected response.' );
		}
		return array(
			'items'       => $data,
			'users'       => $data,
			'total'       => (int) wp_remote_retrieve_header( $response, 'x-wp-total' ),
			'total_pages' => max( 1, (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' ) ),
		);
	}
}
