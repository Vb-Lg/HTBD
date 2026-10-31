<?php

defined( 'ABSPATH' ) || exit;

/**
 * Self checks for the settings that break translations silently: a missing
 * table, permalinks that do not support language prefixes, a stalled cron
 * queue, or credentials Baidu rejects.
 */
class HTBD_Health_Check {
	const TRANSIENT_PREFIX = 'htbd_health_';
	const TRANSIENT_TTL    = 900;

	private $router;

	public function __construct( HTBD_Language_Router $router ) {
		$this->router = $router;
	}

	public function register() {
		add_action( 'admin_post_htbd_run_health_check', array( $this, 'run' ) );
		add_action( 'htbd_settings_page_sections', array( $this, 'render_section' ) );
	}

	public function run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'hyx-translator-for-baidu-translate' ) );
		}

		check_admin_referer( 'htbd_run_health_check' );

		set_transient( $this->transient_key(), $this->results(), self::TRANSIENT_TTL );

		wp_safe_redirect( add_query_arg( 'htbd_health', 1, admin_url( 'options-general.php?page=htbd' ) ) );
		exit;
	}

	public function render_section() {
		$results = get_transient( $this->transient_key() );
		?>
		<hr />
		<h2><?php esc_html_e( 'Diagnostics', 'hyx-translator-for-baidu-translate' ); ?></h2>
		<p><?php esc_html_e( 'Checks the settings that silently break translations: the translation table, permalinks, URL routing, scheduled tasks, and the Baidu credentials. The credentials check sends one short test request and uses a small amount of API quota.', 'hyx-translator-for-baidu-translate' ); ?></p>
		<?php if ( is_array( $results ) && ! empty( $results['checks'] ) ) : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Check', 'hyx-translator-for-baidu-translate' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'hyx-translator-for-baidu-translate' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'hyx-translator-for-baidu-translate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results['checks'] as $check ) : ?>
						<tr>
							<td><?php echo esc_html( $check['label'] ); ?></td>
							<td><?php echo $this->status_badge( $check['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static escaped markup. ?></td>
							<td><?php echo esc_html( $check['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %s: date and time of the last check. */
					esc_html__( 'Last run: %s.', 'hyx-translator-for-baidu-translate' ),
					esc_html( get_date_from_gmt( $results['generated_at'], 'Y-m-d H:i' ) )
				);
				?>
			</p>
		<?php endif; ?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="htbd_run_health_check" />
			<?php wp_nonce_field( 'htbd_run_health_check' ); ?>
			<?php submit_button( __( 'Run diagnostics', 'hyx-translator-for-baidu-translate' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * @return array{generated_at: string, checks: array<int, array{label: string, status: string, message: string}>}
	 */
	private function results() {
		$settings = HTBD_Settings::get();

		return array(
			'generated_at' => current_time( 'mysql', true ),
			'checks'       => array(
				$this->check_credentials( $settings ),
				$this->check_table(),
				$this->check_permalinks(),
				$this->check_routing( $settings ),
				$this->check_schedule(),
				$this->check_provider( $settings ),
			),
		);
	}

	private function check_credentials( $settings ) {
		if ( '' === (string) $settings['baidu_app_id'] || '' === (string) $settings['baidu_secret_key'] ) {
			return $this->check( __( 'Baidu credentials', 'hyx-translator-for-baidu-translate' ), 'fail', __( 'The application ID or the secret key is empty.', 'hyx-translator-for-baidu-translate' ) );
		}

		return $this->check( __( 'Baidu credentials', 'hyx-translator-for-baidu-translate' ), 'pass', __( 'An application ID and secret key are configured.', 'hyx-translator-for-baidu-translate' ) );
	}

	private function check_table() {
		global $wpdb;

		$table_name = HTBD_Translation_Store::table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics read of the plugin-owned table.

		if ( $table_name !== $exists ) {
			return $this->check( __( 'Translation table', 'hyx-translator-for-baidu-translate' ), 'fail', sprintf( /* translators: %s: database table name. */ __( 'The table %s does not exist. Deactivate and reactivate the plugin.', 'hyx-translator-for-baidu-translate' ), $table_name ) );
		}

		return $this->check( __( 'Translation table', 'hyx-translator-for-baidu-translate' ), 'pass', sprintf( /* translators: %s: database table name. */ __( 'The table %s exists.', 'hyx-translator-for-baidu-translate' ), $table_name ) );
	}

	private function check_permalinks() {
		$structure = (string) get_option( 'permalink_structure', '' );

		if ( '' === $structure ) {
			return $this->check( __( 'Permalinks', 'hyx-translator-for-baidu-translate' ), 'fail', __( 'Plain permalinks are enabled, so language prefixes cannot be added to URLs. Choose a pretty permalink structure under Settings > Permalinks.', 'hyx-translator-for-baidu-translate' ) );
		}

		return $this->check( __( 'Permalinks', 'hyx-translator-for-baidu-translate' ), 'pass', __( 'A pretty permalink structure is enabled.', 'hyx-translator-for-baidu-translate' ) );
	}

	private function check_routing( $settings ) {
		$languages = HTBD_Settings::target_languages();

		if ( empty( HTBD_Settings::routing_modes() ) ) {
			return $this->check( __( 'URL routing', 'hyx-translator-for-baidu-translate' ), 'fail', __( 'No URL mode is enabled, so translated pages are unreachable.', 'hyx-translator-for-baidu-translate' ) );
		}

		if ( empty( $languages ) ) {
			return $this->check( __( 'URL routing', 'hyx-translator-for-baidu-translate' ), 'fail', __( 'No target language is configured.', 'hyx-translator-for-baidu-translate' ) );
		}

		$url      = $this->router->url_for_language( home_url( '/' ), $languages[0] );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array( 'X-HTBD-Diagnostics' => '1' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->check(
				__( 'URL routing', 'hyx-translator-for-baidu-translate' ),
				'warn',
				sprintf( /* translators: 1: URL, 2: error message. */ __( 'Could not reach %1$s from the server: %2$s. This is expected when the site is password protected or not reachable from itself.', 'hyx-translator-for-baidu-translate' ), $url, $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return $this->check(
				__( 'URL routing', 'hyx-translator-for-baidu-translate' ),
				'warn',
				sprintf( /* translators: 1: URL, 2: HTTP status code. */ __( '%1$s returned HTTP %2$d.', 'hyx-translator-for-baidu-translate' ), $url, $code )
			);
		}

		return $this->check(
			__( 'URL routing', 'hyx-translator-for-baidu-translate' ),
			'pass',
			sprintf( /* translators: %s: URL. */ __( '%s responded with HTTP 200.', 'hyx-translator-for-baidu-translate' ), $url )
		);
	}

	private function check_schedule() {
		$backlog = HTBD_Cron::backlog();

		if ( ! $backlog['count'] ) {
			return $this->check( __( 'Scheduled tasks', 'hyx-translator-for-baidu-translate' ), 'pass', __( 'No translation job is queued.', 'hyx-translator-for-baidu-translate' ) );
		}

		if ( $backlog['overdue'] ) {
			return $this->check(
				__( 'Scheduled tasks', 'hyx-translator-for-baidu-translate' ),
				'warn',
				sprintf(
					/* translators: 1: queued task count, 2: next run time. */
					__( '%1$d task(s) are queued and the next one is overdue (%2$s UTC). Check that WP-Cron is running.', 'hyx-translator-for-baidu-translate' ),
					(int) $backlog['count'],
					gmdate( 'Y-m-d H:i:s', (int) $backlog['next'] )
				)
			);
		}

		return $this->check(
			__( 'Scheduled tasks', 'hyx-translator-for-baidu-translate' ),
			'pass',
			sprintf(
				/* translators: 1: queued task count, 2: next run time. */
				__( '%1$d task(s) are queued. The next one runs at %2$s UTC.', 'hyx-translator-for-baidu-translate' ),
				(int) $backlog['count'],
				gmdate( 'Y-m-d H:i:s', (int) $backlog['next'] )
			)
		);
	}

	private function check_provider( $settings ) {
		$languages = HTBD_Settings::target_languages();

		if ( empty( $languages ) || '' === (string) $settings['baidu_app_id'] || '' === (string) $settings['baidu_secret_key'] ) {
			return $this->check( __( 'Provider round trip', 'hyx-translator-for-baidu-translate' ), 'warn', __( 'Skipped until credentials and a target language are configured.', 'hyx-translator-for-baidu-translate' ) );
		}

		$provider = new HTBD_Baidu_Provider( $settings['baidu_app_id'], $settings['baidu_secret_key'] );
		$result   = $provider->translate( 'Hello', $settings['source_language'], $languages[0], 'diagnostics' );

		if ( ! $result->success ) {
			return $this->check(
				__( 'Provider round trip', 'hyx-translator-for-baidu-translate' ),
				'fail',
				sprintf( /* translators: 1: error code, 2: error message. */ __( 'Baidu Translate rejected the test request: %1$s (%2$s).', 'hyx-translator-for-baidu-translate' ), $result->error_code, $result->error_message )
			);
		}

		return $this->check(
			__( 'Provider round trip', 'hyx-translator-for-baidu-translate' ),
			'pass',
			sprintf( /* translators: 1: source language, 2: target language. */ __( 'Baidu Translate answered a test request from %1$s to %2$s.', 'hyx-translator-for-baidu-translate' ), $settings['source_language'], $languages[0] )
		);
	}

	private function check( $label, $status, $message ) {
		return array(
			'label'   => $label,
			'status'  => in_array( $status, array( 'pass', 'warn', 'fail' ), true ) ? $status : 'warn',
			'message' => $message,
		);
	}

	private function status_badge( $status ) {
		$labels = array(
			'pass' => array( __( 'Pass', 'hyx-translator-for-baidu-translate' ), '#008a20' ),
			'warn' => array( __( 'Warning', 'hyx-translator-for-baidu-translate' ), '#996800' ),
			'fail' => array( __( 'Fail', 'hyx-translator-for-baidu-translate' ), '#b32d2e' ),
		);

		$badge = isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['warn'];

		return sprintf( '<strong style="color:%s">%s</strong>', esc_attr( $badge[1] ), esc_html( $badge[0] ) );
	}

	private function transient_key() {
		return self::TRANSIENT_PREFIX . get_current_user_id();
	}
}
