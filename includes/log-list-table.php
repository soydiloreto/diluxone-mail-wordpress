<?php
/**
 * La tabla del historial global.
 *
 * Es la única clase del plugin, y es porque WP_List_Table es una clase: la
 * paginación, los filtros y el aspecto de las tablas del escritorio salen de
 * extenderla, y reescribirlos en funciones sería copiar WordPress.
 *
 * Se carga a demanda desde admin-log.php, y no con el resto de includes/,
 * porque WP_List_Table sólo existe en el escritorio.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * La lista.
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
	 * Las columnas.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		$columnas = array(
			'sent_at'  => __( 'Date', 'diluxone-mail' ),
			'email'    => __( 'To', 'diluxone-mail' ),
			'subject'  => __( 'Subject', 'diluxone-mail' ),
			'status'   => __( 'Status', 'diluxone-mail' ),
			'provider' => __( 'Provider', 'diluxone-mail' ),
			'source'   => __( 'Sent by', 'diluxone-mail' ),
		);

		if ( is_multisite() && is_super_admin() ) {
			$columnas['site_id'] = __( 'Site', 'diluxone-mail' );
		}

		return $columnas;
	}

	/** Los datos de la página actual. */
	public function prepare_items(): void {
		$por_pagina = 30;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filtros de lectura de una lista; no cambian nada.
		$estado = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$busca  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$todos  = isset( $_GET['all'] ) && is_multisite() && is_super_admin();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$consulta = diluxone_mail_log_query(
			array(
				'status'   => $estado,
				'search'   => $busca,
				'site_id'  => $todos ? null : get_current_blog_id(),
				'page'     => $this->get_pagenum(),
				'per_page' => $por_pagina,
			)
		);

		$this->items = $consulta['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $consulta['total'],
				'per_page'    => $por_pagina,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Una celda cualquiera.
	 *
	 * @param array<string, mixed> $item
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'sent_at':
				return esc_html( get_date_from_gmt( (string) $item['sent_at'], (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ) );
			case 'status':
				$estados = diluxone_mail_log_statuses();
				$estado  = (string) $item['status'];
				$texto   = $estados[ $estado ] ?? $estado;

				if ( 'failed' === $estado && '' !== (string) $item['error'] ) {
					$texto .= ' — ' . (string) $item['error'];
				}

				if ( 'intercepted' === $estado && '' !== (string) $item['response'] ) {
					$texto .= ' — ' . (string) $item['response'];
				}

				return sprintf( '<span class="diluxone-mail-status diluxone-mail-status--%s">%s</span>', esc_attr( $estado ), esc_html( $texto ) );
			case 'provider':
				$key = (string) $item['provider'];

				if ( 'observer' === $key ) {
					return esc_html__( 'another plugin', 'diluxone-mail' );
				}

				return esc_html( '' !== $key ? (string) diluxone_mail_provider( $key )['name'] : '—' );
			case 'source':
				return esc_html( str_replace( array( 'plugin:', 'theme:' ), '', (string) $item['source'] ) );
			case 'site_id':
				$sitio = get_site( (int) $item['site_id'] );

				return esc_html( $sitio instanceof WP_Site ? (string) $sitio->blogname : (string) $item['site_id'] );
			default:
				return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
		}
	}

	/**
	 * El asunto, con las acciones de la fila.
	 *
	 * @param array<string, mixed> $item
	 */
	protected function column_subject( $item ): string {
		$id       = (int) $item['id'];
		$acciones = array(
			'view'   => sprintf( '<a href="%s">%s</a>', esc_url( diluxone_mail_admin_url( 'diluxone-mail-log', array( 'view' => $id ) ) ), esc_html__( 'Details', 'diluxone-mail' ) ),
			'resend' => sprintf( '<a href="%s">%s</a>', esc_url( diluxone_mail_resend_url( $id ) ), esc_html__( 'Resend', 'diluxone-mail' ) ),
		);

		$asunto = (string) $item['subject'];

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( diluxone_mail_admin_url( 'diluxone-mail-log', array( 'view' => $id ) ) ),
			esc_html( '' !== $asunto ? $asunto : __( '(no subject)', 'diluxone-mail' ) ),
			$this->row_actions( $acciones )
		);
	}

	/**
	 * El destinatario, con el tipo si no es «para».
	 *
	 * @param array<string, mixed> $item
	 */
	protected function column_email( $item ): string {
		$kind = (string) $item['kind'];

		return esc_html( (string) $item['email'] ) . ( 'to' !== $kind ? ' <span class="description">(' . esc_html( strtoupper( $kind ) ) . ')</span>' : '' );
	}

	/**
	 * Los filtros arriba de la tabla.
	 *
	 * @param string $which
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Sólo marca qué filtro está activo.
		$estado = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$todos  = isset( $_GET['all'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<label for="diluxone-mail-status" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'diluxone-mail' ); ?></label>
			<select name="status" id="diluxone-mail-status">
				<option value=""><?php esc_html_e( 'All statuses', 'diluxone-mail' ); ?></option>
				<?php foreach ( diluxone_mail_log_statuses() as $clave => $nombre ) : ?>
					<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $estado, $clave ); ?>><?php echo esc_html( $nombre ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( is_multisite() && is_super_admin() ) : ?>
				<label><input type="checkbox" name="all" value="1" <?php checked( $todos ); ?>> <?php esc_html_e( 'All sites', 'diluxone-mail' ); ?></label>
			<?php endif; ?>
			<?php submit_button( __( 'Filter', 'diluxone-mail' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/** Cuando no hay nada. */
	public function no_items(): void {
		esc_html_e( 'No messages logged yet.', 'diluxone-mail' );
	}
}
