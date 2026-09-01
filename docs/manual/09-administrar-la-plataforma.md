# Administrar la plataforma

Este capítulo es para quien tiene el rol de **administrador global** (`ROLE_ADMIN`): acceso a la
sección **Administración**, con gestión de todos los centros alojados en el servidor.

## El panel de administración

### Centros educativos

**Administración → Centros educativos** lista todos los centros del servidor. Desde aquí se crean
centros nuevos, se edita su código/nombre/localidad y se asigna su equipo directivo (docentes con
acceso a la administración de ese centro concreto, sin necesidad de ser administradores globales).

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

### Visualización

- **Resultados por página** — número de elementos que se muestran en los listados paginados de la
  aplicación (entre 5 y 100; 20 por defecto). Ajustable solo a nivel personal, desde
  **Mi perfil → Ajustes**.

### Plantillas de informes

- **Plantilla PDF general (vertical / apaisada)** — un PDF de una sola página que se usa como fondo
  (membrete) de los informes que se generen en cada orientación, cuando existan. Ajustable a nivel
  de centro, desde **Centro educativo → Ajustes del centro**.

## Copias de seguridad

Haz copia de seguridad con regularidad de:

- La base de datos (volcado de PostgreSQL/MySQL, o el fichero SQLite si usas ese motor).
- El secreto de la aplicación (`APP_SECRET` en `.env.local`, o `data/.secret` en el binario nativo).
- Los ficheros subidos (plantillas PDF de los ajustes), almacenados en la propia base de datos.

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
[registro de cambios](https://github.com/TU-USUARIO/atica-calidad/blob/main/CHANGELOG.md) del
repositorio. Antes de actualizar, revisa si incluye cambios que requieran una intervención manual
(poco frecuentes, siempre indicados explícitamente) y haz una copia de seguridad.

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

## Protección de datos (RGPD)

ÁTICA Calidad almacena datos personales del profesorado (nombre, usuario, correo electrónico) con
la finalidad de dar acceso a la aplicación y a la documentación del sistema de gestión de la
calidad del centro. El centro educativo es responsable del tratamiento de estos datos; la
aplicación no los comparte con terceros ni los usa con fines distintos a su propio funcionamiento.
