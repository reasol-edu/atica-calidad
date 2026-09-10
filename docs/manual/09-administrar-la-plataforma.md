# Administrar la plataforma

Este capítulo es para quien tiene el rol de **administrador global** (`ROLE_ADMIN`): acceso a la
sección **Administración**, con gestión de todos los centros alojados en el servidor.

## El panel de administración

### Centros educativos

**Administración → Centros educativos** lista todos los centros del servidor. Desde aquí se crean
centros nuevos, se edita su código/nombre/localidad y se asigna su equipo directivo (docentes con
acceso a la administración de ese centro concreto, sin necesidad de ser administradores globales).

Al crear un centro (desde aquí o con `app:create-educational-centre`/`app:load-demo-data` por
consola) se le crean automáticamente tres raíces vacías en [Responsabilidades →
Listas](06-responsabilidades.md#listas): **Departamento**, **Grupo** y **Materia**, listas para
rellenar. Sus nombres salen de una única traducción (`responsibilities.lists.default_roots`, en
`translations/admin.es.yaml`) separada por punto y coma, así que se pueden añadir, quitar o
renombrar sin tocar código.

### Docentes

**Administración → Docentes** gestiona el listado global de docentes del servidor: alta, edición,
baja, modo de acceso (contraseña o autenticación externa) y forzado de cambio de contraseña.

#### Forzar cambio de contraseña

Al editar un docente, activa **«Forzar cambio de contraseña»** para que deba establecer una nueva
contraseña en su próximo acceso — útil tras crear una cuenta o si se sospecha que la contraseña
actual se ha visto comprometida. No aplica a cuentas con autenticación externa.

## Correo electrónico del servidor

### Activar el correo

Por defecto, la aplicación descarta los correos automáticos (`MAILER_DSN=null://null`). Para
activarlos, configura un transportador real en `.env.local`:

```bash
MAILER_DSN=smtp://usuario:clave@servidor:587
MAILER_FROM=no-responder@tudominio.es
```

En producción, configura también `DEFAULT_URI` para que los enlaces de los correos apunten a la URL
pública de la aplicación.

### Envío asíncrono

Los correos se encolan y los procesa el worker (Messenger) en segundo plano — ver
[Correos en cola (Messenger)](#correos-en-cola-messenger). Si el worker no está en marcha, los
correos quedan pendientes de entrega hasta que se arranque.

## Sistema de ajustes

Los ajustes de la aplicación tienen hasta tres niveles: **global** (todo el servidor, en
**Administración → Ajustes**), **de centro** (**Centro educativo → Ajustes del centro**) y, si
aplica, **personal** (**Mi perfil → Ajustes**, accesible a cualquier docente desde el menú
lateral). Un valor de un nivel más específico sobrescribe al de un nivel más general, salvo que
este último esté **bloqueado**.

### Bloqueo de ajustes

Un administrador global puede bloquear un ajuste en **Administración → Ajustes**: el valor
bloqueado se aplica entonces a todos los centros (y docentes) sin que puedan sobrescribirlo. Un
equipo directivo puede, del mismo modo, bloquear un ajuste de su centro para que no lo sobrescriba
el profesorado, si el ajuste lo permite a nivel de centro.

## Ajustes disponibles {#ajustes-disponibles}

### Avisos por correo

Cada uno de los tres avisos que se envían por correo (documento pendiente de revisar, documento
aceptado, documento rechazado) puede configurarse de forma independiente en **Desactivado**,
**Individual** (un correo al instante por cada aviso) o **Resumen diario** (un único correo, una
vez al día, con todo lo pendiente desde el último) — este último es el valor por defecto de los
tres, para no saturar el correo. Si a un docente le corresponden varios de estos avisos en modo
resumen el mismo día, recibe un solo correo con una sección por cada uno, no varios correos
seguidos.

| Ajuste | Alcance | Descripción |
| --- | :-: | --- |
| Registrar los avisos por correo | Global, centro | Activa el [registro de avisos por correo](05-administrar-el-centro.md#registro-de-avisos-por-correo) del centro. |
| Retención de los registros | Global | Días que se conservan las entradas de ese registro antes de eliminarse automáticamente (0 desactiva la eliminación). |
| Avisos por correo electrónico | Global, centro, personal | Interruptor general: sin activar, no se envía ningún aviso al docente, sea cual sea el resto de ajustes. |
| Recordatorio de actividad pendiente de completar | Global, centro, personal | Envía un aviso cuando una [actividad](08-actividades.md) esté pendiente de completar o vencida. |
| Días de preaviso del recordatorio | Global, centro, personal | Antelación, en días sobre la fecha límite, a partir de la cual se avisa de una actividad pendiente (además de las ya vencidas). |
| Aviso de documento pendiente de revisar | Global, centro, personal | Desactivado / Individual / Resumen diario — avisa a quien deba revisar una carpeta cuando se suba una versión nueva. |
| Aviso de documento aceptado | Global, centro, personal | Desactivado / Individual / Resumen diario — avisa a quien subió una revisión cuando se acepta. |
| Aviso de documento rechazado | Global, centro, personal | Desactivado / Individual / Resumen diario — avisa a quien subió una revisión cuando se rechaza. |
| Registrar la actividad de los usuarios | Global, centro | Activa el [registro de actividad](#registro-de-actividad) del centro. Los eventos sin centro (inicios y cierres de sesión) se rigen por el valor global. |
| Retención del registro de actividad | Global | Días que se conservan las entradas del registro de actividad antes de eliminarse automáticamente (0 desactiva la eliminación). |

### Visualización

- **Resultados por página** — número de elementos que se muestran en los listados paginados de la
  aplicación (entre 5 y 100; 20 por defecto). Ajustable solo a nivel personal, desde
  **Mi perfil → Ajustes**.

### Plantillas de informes

- **Plantilla PDF general (vertical / apaisada)** — un PDF de una sola página que se usa como fondo
  (membrete) de los informes que se generen en cada orientación, cuando existan. Ajustable a nivel
  de centro, desde **Centro educativo → Ajustes del centro**.

### Registro de actividad

- **Registrar la actividad de los usuarios** — activa o desactiva el [registro de
  actividad](#registro-de-actividad) para un centro. Ajustable a nivel global y de centro: cada
  equipo directivo puede activarlo o desactivarlo para su propio centro desde **Centro educativo →
  Ajustes del centro**, salvo que un administrador global haya bloqueado el ajuste. Con `APP_LOG`
  a `false` en la configuración del servidor el registro queda desactivado en todos los centros,
  independientemente de este ajuste.
- **Retención del registro de actividad** — días que se conservan las entradas antes de eliminarse
  automáticamente en la limpieza semanal (0 desactiva esa eliminación). Solo a nivel global.

## Copias de seguridad

Haz copia de seguridad con regularidad de:

- La base de datos (volcado de PostgreSQL/MySQL, o el fichero SQLite si usas ese motor). Todo lo
  que guarda la aplicación —incluidos los ficheros subidos, como las plantillas PDF de los
  ajustes— vive en la base de datos, así que su volcado es la copia completa de los datos.
- El secreto de la aplicación (`APP_SECRET` en `.env.local`, o `data/.secret` en el binario nativo).
  No lo genera la copia del comando `app:backup`; guárdalo aparte.

### El comando `app:backup`

```bash
php bin/console app:backup [carpeta-o-fichero.zip]
```

Vuelca toda la base de datos a un único fichero ZIP. Sin argumento lo deja en `var/backups/` con
un nombre con la fecha y la hora; también acepta una carpeta de destino o la ruta completa de un
`.zip`. El volcado es lógico e independiente del motor (una tabla por fichero NDJSON, con los
valores binarios en base64 y un `manifest.json` con la versión de la aplicación, el motor y el
número de filas de cada tabla), de modo que una copia hecha con SQLite se puede leer con
PostgreSQL y al revés. La cola de correos y tareas (`messenger_messages`) se excluye a propósito.

> La copia **no está cifrada**: contiene los documentos de todos los centros y los hashes de
> contraseña de los docentes. Guárdala en un lugar seguro.

## Correos en cola (Messenger) {#correos-en-cola-messenger}

Los correos automáticos y las tareas programadas se procesan de forma asíncrona con
[Symfony Messenger](https://symfony.com/doc/current/messenger.html). El worker debe estar en
marcha permanentemente:

```bash
php bin/console messenger:consume async scheduler_default --time-limit=3540 --memory-limit=128M
```

En un despliegue con Docker, el worker ya se levanta como un servicio aparte (`compose.yaml`). En
un despliegue con binario nativo, se gestiona como un servicio systemd independiente — ver las
[guías de despliegue](../despliegue/).

## Actualización

Cada nueva versión se anuncia con sus cambios en el
[registro de cambios](https://github.com/reasol-edu/atica-calidad/blob/main/CHANGELOG.md) del
repositorio. Antes de actualizar, revisa si incluye cambios que requieran una intervención manual
(poco frecuentes, siempre indicados explícitamente) y haz una copia de seguridad.

Los pasos concretos dependen de cómo esté desplegada la aplicación (Docker, Plesk o binario nativo
en Ubuntu Server) y están descritos en
[Actualizar a la última versión publicada](01-instalacion-y-puesta-en-marcha.md#actualizar). En
todos los casos se aplican las migraciones de base de datos pendientes:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

## Registro de actividad {#registro-de-actividad}

ÁTICA Calidad puede guardar un **registro de auditoría** de lo que hace cada usuario, para poder
investigar un incidente de seguridad a posteriori. Se consulta en **Administración → Registro de
actividad** y solo pueden verlo los administradores globales.

Cada entrada guarda la fecha y hora, la **dirección IP**, el usuario que realizó la acción (y el
usuario real, si estaba [suplantando](#docentes) a otro), el centro al que corresponde la acción,
el tipo de acción y algunos datos de contexto. Se registran, entre otras cosas:

- **Sesión**: inicios y cierres de sesión, intentos fallidos, inicio y fin de una suplantación.
- **Lectura**: abrir una sección del árbol, abrir una carpeta o un documento, descargar una
  revisión, descargar una carpeta en ZIP, exportar el árbol o generar un informe.
- **Escritura**: crear, renombrar, mover o eliminar carpetas, secciones, listas, categorías,
  actividades o perfiles; subir un documento o una revisión; aceptar o rechazar una revisión;
  marcar una actividad como completada manualmente; subir entregas; cambiar ajustes; altas, bajas
  y modificaciones de docentes y centros.

La escritura del registro se hace **después de enviar la respuesta al navegador**, así que no
añade retardo a ninguna acción. El registro se puede activar o desactivar por centro y su
retención se configura desde [Ajustes](#ajustes-disponibles) (ver «Registro de actividad» más
arriba). Con `APP_LOG=false` en la configuración del servidor el registro queda completamente
inactivo.

### IP del usuario y proxies de confianza

Para que la IP registrada sea la del usuario y no la de un intermediario, hay que configurar los
**proxies de confianza** cuando la aplicación se ejecuta detrás de un proxy inverso, un
balanceador de carga o un túnel (nginx, Caddy, Traefik, Cloudflare Tunnel, el balanceador de un
proveedor de nube…). En ese caso todas las peticiones llegan a la aplicación desde la IP del
proxy, y la IP real del cliente viaja en la cabecera `X-Forwarded-For`, que solo debe creerse si
la petición viene de un proxy conocido.

Se configura con la variable de entorno **`SYMFONY_TRUSTED_PROXIES`**, con una o varias IPs o
rangos CIDR separados por comas:

| Despliegue | Dónde se define |
| --- | --- |
| Binario nativo / Plesk | `SYMFONY_TRUSTED_PROXIES` en `.env.local` |
| Docker | variable de entorno del servicio `app` en `compose.yaml` (o en un `compose.override.yaml`) |
| Docker + Cloudflare Tunnel | ya la fija `compose.cloudflare.yaml` con el rango de la red interna; no hay que tocar nada — ver [Cloudflare Tunnel](../despliegue/cloudflare-tunnel.md) |

Ejemplos de valor: `127.0.0.1` (proxy en la misma máquina), `10.0.0.0/8` (rango de una red
interna), `127.0.0.1,10.0.0.1` (varios). Sin proxy inverso, deja la variable sin definir.

Para comprobar que está bien configurado, revisa la columna **IP** del registro de actividad: debe
mostrar la IP real del cliente, no `127.0.0.1` ni la IP del proxy.

## Protección de datos (RGPD)

ÁTICA Calidad almacena datos personales del profesorado (nombre, usuario, correo electrónico) con
la finalidad de dar acceso a la aplicación y a la documentación del sistema de gestión de la
calidad del centro. El centro educativo es responsable del tratamiento de estos datos; la
aplicación no los comparte con terceros ni los usa con fines distintos a su propio funcionamiento.
El registro de actividad conserva la dirección IP y la actividad de cada usuario durante el
periodo de retención configurado, con la única finalidad de permitir auditorías de seguridad.
