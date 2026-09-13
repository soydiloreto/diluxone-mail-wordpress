<?php
/**
 * Plugin Name:       DiluxOne Mail – SMTP, Email Log & Deliverability Diagnostics
 * Plugin URI:        https://pablodiloreto.com
 * Description:       Connect WordPress to any SMTP provider, keep a log of every message — including one per person, on their user profile — and find out whether the domain's SPF, DKIM and DMARC records actually let that mail arrive.
 * Version:           1.0.0
 * Author:            Pablo Ariel Di Loreto
 * Author URI:        https://pablodiloreto.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       diluxone-mail
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 *
 * @package DiluxOneMail
 *
 * ---------------------------------------------------------------------------
 * Por qué existe
 *
 * WordPress manda el correo con la función de PHP que le pide al sistema
 * operativo que lo despache, y en un hosting de hoy eso no llega a ningún
 * lado: sin un servidor que lo autentique, Gmail y Outlook lo descartan sin
 * avisarle a nadie. El sitio funciona, el formulario dice «gracias», y el
 * correo no existió nunca.
 *
 * Conectar un servidor SMTP lo hacen ocho plugins y lo hacen bien. Este
 * también lo hace, aburrido y sólido, porque es el piso. Pero el piso no es
 * el producto: las dos cosas por las que este plugin existe son las que
 * ninguno de esos ocho hace.
 *
 * La primera es que el historial de correo cuelga de la ficha de cada
 * persona. Todos muestran una lista global de envíos; ninguno deja abrir un
 * usuario y ver qué se le mandó a él. Cuando alguien escribe «no me llegó el
 * mail», la respuesta está en su ficha, no en una lista de diez mil filas.
 *
 * La segunda es que lee el DNS del dominio y explica en castellano qué está
 * roto: cuántos lookups consume el SPF de los diez que permite el estándar,
 * qué proveedores declara que ya no usa, si el DKIM está publicado, y qué
 * implica de verdad la política DMARC que tiene puesta.
 *
 * Y una decisión de entrada: si al activarse encuentra otro plugin ya
 * gestionando el correo, no pelea. Se pone a registrar y a diagnosticar sin
 * tocar el envío, y lo dice. Un sitio al que le anda el correo no tiene por
 * qué romperse para probar esto.
 *
 * Lo que no hace y no va a hacer: mandar el correo por su cuenta. Eso es un
 * servicio de envío, con su infraestructura, su reputación de IP y su soporte
 * de rebotes. Acá se conecta el proveedor que ya tiene el sitio, y punto.
 * ---------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

define( 'DILUXONE_MAIL_VERSION', '1.0.0' );
define( 'DILUXONE_MAIL_DIR', plugin_dir_path( __FILE__ ) );
define( 'DILUXONE_MAIL_URL', plugin_dir_url( __FILE__ ) );
define( 'DILUXONE_MAIL_FILE', __FILE__ );

/**
 * Las traducciones.
 *
 * Las cadenas del código están en inglés y las traducciones viajan con el
 * plugin, en languages/. WordPress carga solas las de wordpress.org, que acá
 * no existen todavía.
 */
function diluxone_mail_load_textdomain(): void {
	load_plugin_textdomain(
		'diluxone-mail',
		false,
		dirname( plugin_basename( DILUXONE_MAIL_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'diluxone_mail_load_textdomain' );

/**
 * Cada archivo de includes/ es independiente y sólo registra hooks. Se cargan
 * por orden alfabético a propósito: si alguno necesitara a otro para arrancar,
 * eso sería un acoplamiento que hay que resolver con un hook, no con el orden.
 */
foreach ( (array) glob( DILUXONE_MAIL_DIR . 'includes/*.php' ) as $diluxone_mail_archivo ) {
	require_once (string) $diluxone_mail_archivo;
}

/**
 * Al activar: las tablas del historial.
 *
 * La creación vive en includes/log.php junto al esquema, porque también hace
 * falta cuando el plugin cambia de versión —donde este hook no corre— y dos
 * copias del mismo CREATE TABLE se desincronizan a la primera columna nueva.
 */
register_activation_hook( __FILE__, 'diluxone_mail_install' );
