<?php
/**
 * La purga del historial, por cron.
 *
 * Una vez por día se borra lo que venció según las retenciones. Va por el
 * cron de WordPress y no en cada petición porque un DELETE sobre una tabla
 * grande no es algo que quiera hacer quien está esperando que cargue una
 * página.
 *
 * En una red las tablas son compartidas y el cron es de cada sitio: cualquier
 * sitio que lo corra purga por todos, con la retención que resuelva él. Con
 * los ajustes fijados en la red es la misma para todos; con sitios pisando a
 * la red, gana el que corre primero, y eso está bien: la retención es un
 * máximo, no una promesa de guardar.
 *
 * @package DiluxOneMail
 */

defined( 'ABSPATH' ) || exit;

/** Programa la purga diaria si no está programada. */
function diluxone_mail_schedule_purge(): void {
	if ( false === wp_next_scheduled( 'diluxone_mail_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'diluxone_mail_purge' );
	}
}
add_action( 'init', 'diluxone_mail_schedule_purge' );

/** La purga en sí. */
function diluxone_mail_run_purge(): void {
	diluxone_mail_log_purge();
}
add_action( 'diluxone_mail_purge', 'diluxone_mail_run_purge' );

/**
 * Al desactivar: se saca el evento del cron.
 *
 * Las tablas se quedan. Desactivar no es desinstalar, y un historial que
 * desaparece porque alguien desactivó el plugin cinco minutos para probar
 * algo no es un historial.
 */
function diluxone_mail_deactivate(): void {
	$proximo = wp_next_scheduled( 'diluxone_mail_purge' );

	if ( false !== $proximo ) {
		wp_unschedule_event( $proximo, 'diluxone_mail_purge' );
	}
}
