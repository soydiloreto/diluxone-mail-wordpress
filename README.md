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
  su ficha y no en una lista de diez mil filas.
- **Diagnóstico de DNS en castellano.** Cero plugins leen el SPF, el DKIM y el
  DMARC del dominio para explicar qué está roto. Éste cuenta los lookups de SPF
  contra el límite de 10 del estándar, expande los `include` recursivamente,
  señala los proveedores declarados que ya no se usan, sondea los selectores
  DKIM conocidos y explica qué implica de verdad la política DMARC puesta.
- **Modo observador.** Si al activarse encuentra otro plugin gestionando el
  correo, no pelea: registra y diagnostica sin tocar el envío, y lo dice. Un
  sitio al que le anda el correo no tiene por qué romperse para probar esto.

Lo que no hace y no va a hacer: mandar el correo por su cuenta. Eso es un
servicio de envío, con su infraestructura y su reputación de IP. Acá se conecta
el proveedor que ya tiene el sitio.

## La configuración sale del entorno, no de la base

Es la decisión de diseño que más conviene entender antes de usarlo.

Cada valor del transporte se resuelve en tres capas, de mayor a menor
precedencia:

| Orden | De dónde sale | Ejemplo |
|---|---|---|
| 1 | Constante de PHP en `wp-config.php` | `define( 'DILUXONE_MAIL_HOST', 'in-v3.mailjet.com' );` |
| 2 | Variable de entorno con el mismo nombre | `DILUXONE_MAIL_HOST=in-v3.mailjet.com` |
| 3 | Option de la base, editable desde el admin | `diluxone_mail_host` |

Las ocho variables son `DILUXONE_MAIL_` más `HOST`, `PORT`, `USER`, `PASS`,
`ENCRYPTION`, `FROM`, `FROM_NAME` y `PROVIDER`.

Esto compra dos cosas concretas:

- **Las credenciales de producción nunca tocan la base de datos ni el repo.**
  Viven en las variables del hosting, que es donde van. Un volcado de la base
  para llevarse a la máquina local no arrastra la contraseña del SMTP.
- **El mismo código sirve para local y para producción** sin que nadie entre al
  admin a cambiar nada en cada despliegue: la máquina de desarrollo apunta a
  Mailpit por su entorno, el servidor apunta al proveedor real por el suyo.

Cuando un valor viene del entorno, el formulario lo muestra de sólo lectura con
la leyenda «definido por el entorno» y el nombre exacto de dónde sale — y el
guardado ni siquiera escribe esa option, para que la base no quede con una
credencial vieja que nadie usa pero que cualquiera puede leer.

Una variable exportada pero vacía cuenta como no definida: `DILUXONE_MAIL_HOST=`
en un script de despliegue es un renglón a medio escribir, no la decisión de
dejar el sitio sin host.

## Estado

En construcción. Lo que ya está en su lugar:

- [x] Andamio, herramientas de calidad y `make check` en verde
- [x] Precedencia entorno/base, con la procedencia de cada valor
- [x] Esquema del historial
- [ ] Transporte y perfiles de proveedor
- [ ] Historial y la vista por persona
- [ ] Diagnóstico de DNS
- [ ] Modo observador

## Desarrollo

```bash
make install      # dependencias de desarrollo
make check        # lint + phpstan nivel 8 + psalm taint + tests
make env-up       # WordPress local con wp-env
make deploy-test  # copia el plugin a un sitio real para probarlo a mano
```

Todo corre adentro de Docker por defecto, así que la máquina no necesita PHP con
las extensiones del caso. `DOCKER=0` usa los binarios locales.

Más detalle en [`docs/development.md`](docs/development.md) y
[`docs/testing-and-quality.md`](docs/testing-and-quality.md). Las reglas sobre
uso de IA en el proyecto están en [`docs/ai-policy.md`](docs/ai-policy.md) y
rigen para cualquiera que abra un PR.

## Licencia

GPLv2 o posterior.
