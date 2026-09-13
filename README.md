# DiluxOne Mail

Arregla el correo de un sitio de WordPress: lo conecta contra el proveedor SMTP
que ya tiene, registra todo lo que se manda, y lee el DNS del dominio para
decirle al dueño qué está mal configurado y por qué no llegan sus correos.

Conectar un servidor SMTP lo hacen ocho plugins y lo hacen bien. Acá también se
hace, sólido y aburrido, porque es el piso. Las dos cosas que no hace ninguno
son la razón de que este exista.

## Qué hace distinto

- **El historial cuelga de la ficha de cada persona.** Todos los competidores
  muestran una lista global de envíos. Ninguno deja abrir un usuario en el
  escritorio y ver qué se le mandó a *él*: fecha, asunto, estado y un botón de
  reenviar. Cuando alguien escribe «no me llegó el mail», la respuesta está en
  su ficha y no en una lista de diez mil filas. En una red, muestra lo de todos
  los sitios: la persona es de la red.
- **Diagnóstico de DNS en castellano.** Cero plugins leen el SPF, el DKIM y el
  DMARC del dominio para explicar qué está roto. Éste cuenta los lookups de SPF
  contra el límite de 10 del estándar, expande los `include` recursivamente,
  señala los proveedores declarados que ya no se usan, sondea los selectores
  DKIM conocidos, explica qué implica la política DMARC y si el correo alinea,
  y verifica si los reportes a otro dominio están autorizados o se están
  tirando. Con `dns_get_record()` deshabilitado cae a DNS-over-HTTPS solo.
- **Modo observador.** Si al activarse encuentra otro plugin gestionando el
  correo —por `wp_mail()` reemplazada, por `phpmailer_init` o por
  `pre_wp_mail`— no pelea: registra y diagnostica sin tocar el envío, lo dice,
  y ofrece un botón para tomar el control. Un sitio al que le anda el correo no
  tiene por qué romperse para probar esto.

Lo que no hace y no va a hacer: mandar el correo por su cuenta. Se conecta el
proveedor que ya tiene el sitio.

## Proveedores

Un desplegable rellena host, puerto, cifrado y usuario; sólo queda pegar la
clave. Cada perfil se verificó contra la documentación oficial vigente —y
Mailjet y Mailpit, además, contra el servidor de verdad—:

Mailpit y MailHog (locales, sin auth ni TLS y con el autoTLS de PHPMailer
apagado, que es lo que hace que funcionen de entrada) · Mailtrap (sandbox y
envío) · Microsoft 365 · Azure Communication Services · Google Workspace ·
Amazon SES · Brevo · SendGrid · Mailjet · Postmark · Resend · **SMTP genérico**
para cualquier otro.

## La configuración sale del entorno, no de la base

Es la decisión de diseño que más conviene entender antes de usarlo.

Cada valor del transporte se resuelve en capas, de mayor a menor precedencia:

| Orden | De dónde sale | Ejemplo |
|---|---|---|
| 1 | Constante de PHP en `wp-config.php` | `define( 'DILUXONE_MAIL_HOST', 'in-v3.mailjet.com' );` |
| 2 | Variable de entorno con el mismo nombre | `DILUXONE_MAIL_HOST=in-v3.mailjet.com` |
| 3 | Option del sitio, editable desde el admin | `diluxone_mail_host` |
| 4 | En una red, option de la red | la misma, guardada por el superadministrador |

Las ocho variables son `DILUXONE_MAIL_` más `HOST`, `PORT`, `USER`, `PASS`,
`ENCRYPTION`, `FROM`, `FROM_NAME` y `PROVIDER`.

Esto compra dos cosas concretas:

- **Las credenciales de producción nunca tocan la base de datos ni el repo.**
  Viven en las variables del hosting. Un volcado de la base para llevarse a la
  máquina local no arrastra la contraseña del SMTP.
- **El mismo código sirve para local y para producción** sin que nadie entre al
  admin en cada despliegue: la máquina de desarrollo apunta a Mailpit por su
  entorno, el servidor apunta al proveedor real por el suyo.

Cuando un valor viene del entorno, el formulario lo muestra de sólo lectura con
«definido por el entorno» y el nombre exacto de dónde sale — y el guardado ni
siquiera escribe esa option, para que la base no quede con una credencial vieja
que nadie usa pero que cualquiera puede leer. Una variable exportada pero vacía
cuenta como no definida.

### En una red

El servidor de correo es de la red. Los ajustes se editan en **Ajustes de la
red → DiluxOne Mail** y valen para todos los sitios; cada sitio los ve de sólo
lectura salvo que la red prenda «dejar que cada sitio los pise». Las tablas del
historial son una sola para la red, con la columna del sitio.

## Historial

Una fila por destinatario —no por mensaje— en una tabla propia, indexada por
dirección y fecha. Se guarda: fecha, destinatario, remitente, asunto, resultado
(enviado, fallido con el error SMTP completo, entregado a otro plugin,
suprimido), proveedor, y qué plugin o tema originó el envío. El `Message-ID`
del correo es el id del historial, para poder casar rebotes por webhook después.

- **Historial común** — prendido. Es la tabla de la ficha de cada persona.
- **Historial extendido** — apagado. Suma cabeceras, nombres de adjuntos y el
  diálogo SMTP completo de cada envío.
- **Cuerpo del mensaje** — apagado, a propósito: es dato personal. Sin cuerpo no
  se puede reenviar, y la ficha lo dice.

Retención por cron, con la del cuerpo y el diálogo más corta. Exportador y
borrador de datos personales de WordPress registrados.

## Seguridad

La contraseña nunca vuelve al navegador. Se tapa en el historial, en el estado,
en el diálogo SMTP y en toda exportación — también en base64, que es como viaja
en `AUTH LOGIN`. Si viene del entorno no se guarda en la base. Nonce y
capacidad en toda acción; el historial de una persona lo ve quien puede editar
a esa persona.

## WP-CLI

```bash
wp diluxone-mail test alguien@ejemplo.com     # con el diálogo SMTP
wp diluxone-mail status
wp diluxone-mail dns [dominio] [--fresh] [--format=json]
wp diluxone-mail log list [--email=] [--status=] [--limit=] [--format=]
```

## Desarrollo y tests

```bash
make install && npm install
make env-up            # WordPress local con wp-env
make check             # lint + phpstan nivel 8 + psalm taint + unitarios + cobertura
make test-all          # unitarios + integración + E2E (Mailpit) + multisitio
make deploy-test       # copia el plugin a un sitio real para probarlo a mano
```

Cuatro suites, cada una atrapa lo que las otras no: **unitarios** (SPF, DKIM,
DMARC, perfiles, precedencia), **integración** contra WordPress y MySQL reales,
**E2E** —un `wp_mail()` de verdad hasta un buzón de Mailpit, verificado por su
API, más el fallo a propósito y el diagnóstico contra un dominio real— y
**multisitio** contra el sitio convertido a red. Todo corre en CI en cada push. Los unitarios cubren más del 95 % de `includes/`, con un umbral que sólo sube.

Todo corre adentro de Docker por defecto; `DOCKER=0` usa los binarios locales.
Detalle en [`docs/development.md`](docs/development.md) y
[`docs/testing-and-quality.md`](docs/testing-and-quality.md). Las reglas sobre
uso de IA están en [`docs/ai-policy.md`](docs/ai-policy.md) y rigen para
cualquiera que abra un PR.

## Licencia

GPLv2 o posterior.
