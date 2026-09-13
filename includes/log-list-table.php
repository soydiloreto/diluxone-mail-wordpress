<?php
/**
 * The global log table.
 *
 * It is the plugin's only class, and that is because WP_List_Table is a
 * class: pagination, filters and the look of dashboard tables all come from
 * extending it, and rewriting them in functions would be copying WordPress.
 *
 * It is loaded on demand from admin-log.php, and not with the rest of
 * includes/, because WP_List_Table only exists in the dashboard.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The list.
 */
class DiluxOne_Mail_Log_Table extends WP_List_Table {

	/** Constructor. */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'diluxone_mail_message',
				'plural'   => 'diluxone_mail_messages',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		$columns = array(
			'sent_at'  => __( 'Date', 'diluxone-mail' ),
			'email'    => __( 'To', 'diluxone-mail' ),
			'subject'  => __( 'Subject', 'diluxone-mail' ),
			'status'   => __( 'Status', 'diluxone-mail' ),
			'provider' => __( 'Provider', 'diluxone-mail' ),
			'source'   => __( 'Sent by', 'diluxone-mail' ),
		);

		if ( is_multisite() && is_super_admin() ) {
			$columns['site_id'] = __( 'Site', 'diluxone-mail' );
		}

		return $columns;
	}

	/** The current page's data. */
	public function prepare_items(): void {
		$per_page = 30;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters of a list; they change nothing.
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$all_sites = isset( $_GET['all'] ) && is_multisite() && is_super_admin();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query = diluxone_mail_log_query(
			array(
				'status'   => $status,
				'search'   => $search,
				'site_id'  => $all_sites ? null : get_current_blog_id(),
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
			)
		);

		$this->items = $query['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $query['total'],
				'per_page'    => $per_page,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Any cell.
	 *
	 * @param array<string, mixed> $item
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'sent_at':
				return esc_html( get_date_from_gmt( (string) $item['sent_at'], (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ) );
			case 'status':
				$statuses = diluxone_mail_log_statuses();
				$status   = (string) $item['status'];
				$text     = $statuses[ $status ] ?? $status;

				if ( 'failed' === $status && '' !== (string) $item['error'] ) {
					$text .= ' — ' . (string) $item['error'];
				}

				if ( 'intercepted' === $status && '' !== (string) $item['response'] ) {
					$text .= ' — ' . (string) $item['response'];
				}

				return sprintf( '<span class="diluxone-mail-status diluxone-mail-status--%s">%s</span>', esc_attr( $status ), esc_html( $text ) );
			case 'provider':
				$key = (string) $item['provider'];

				if ( 'observer' === $key ) {
					return esc_html__( 'another plugin', 'diluxone-mail' );
				}

				return esc_html( '' !== $key ? (string) diluxone_mail_provider( $key )['name'] : '—' );
			case 'source':
				return esc_html( str_replace( array( 'plugin:', 'theme:' ), '', (string) $item['source'] ) );
			case 'site_id':
				$site = get_site( (int) $item['site_id'] );

				return esc_html( $site instanceof WP_Site ? (string) $site->blogname : (string) $item['site_id'] );
			default:
				return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
		}
	}

	/**
	 * The subject, with the row's actions.
	 *
	 * @param array<string, mixed> $item
	 */
	protected function column_subject( $item ): string {
		$id      = (int) $item['id'];
		$actions = array(
			'view'   => sprintf( '<a href="%s">%s</a>', esc_url( diluxone_mail_admin_url( 'diluxone-mail-log', array( 'view' => $id ) ) ), esc_html__( 'Details', 'diluxone-mail' ) ),
			'resend' => sprintf( '<a href="%s">%s</a>', esc_url( diluxone_mail_resend_url( $id ) ), esc_html__( 'Resend', 'diluxone-mail' ) ),
		);

		$subject = (string) $item['subject'];

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( diluxone_mail_admin_url( 'diluxone-mail-log', array( 'view' => $id ) ) ),
			esc_html( '' !== $subject ? $subject : __( '(no subject)', 'diluxone-mail' ) ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * The recipient, with the kind when it is not "to".
	 *
	 * @param array<string, mixed> $item
	 */
	protected function column_email( $item ): string {
		$kind = (string) $item['kind'];

		return esc_html( (string) $item['email'] ) . ( 'to' !== $kind ? ' <span class="description">(' . esc_html( strtoupper( $kind ) ) . ')</span>' : '' );
	}

	/**
	 * The filters above the table.
	 *
	 * @param string $which
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- It only marks which filter is active.
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$all_sites = isset( $_GET['all'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<label for="diluxone-mail-status" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'diluxone-mail' ); ?></label>
			<select name="status" id="diluxone-mail-status">
				<option value=""><?php esc_html_e( 'All statuses', 'diluxone-mail' ); ?></option>
				<?php foreach ( diluxone_mail_log_statuses() as $key => $name ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( is_multisite() && is_super_admin() ) : ?>
				<label><input type="checkbox" name="all" value="1" <?php checked( $all_sites ); ?>> <?php esc_html_e( 'All sites', 'diluxone-mail' ); ?></label>
			<?php endif; ?>
			<?php submit_button( __( 'Filter', 'diluxone-mail' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/** When there is nothing. */
	public function no_items(): void {
		esc_html_e( 'No messages logged yet.', 'diluxone-mail' );
	}
}
