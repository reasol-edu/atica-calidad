# Despliegue en Plesk

Guía para centros que ya disponen de un **VPS o servidor dedicado gestionado con Plesk**. El stack
es PHP-FPM + MySQL + Apache/Nginx estándar: no requiere Docker ni FrankenPHP. Se requiere
**acceso SSH** a la suscripción.

Esta guía complementa al capítulo
[Instalación y puesta en marcha](../manual/01-instalacion-y-puesta-en-marcha.md)
del manual de usuario, donde están la tabla comparativa de modos de despliegue y la referencia de
variables de entorno.

## Requisitos previos

- **PHP 8.4** en modo **PHP-FPM** con las siguientes extensiones activas:
  `ctype`, `curl`, `dom`, `gd`, `iconv`, `intl`, `libxml`, `mbstring`, `pdo_mysql`, `xml`, `opcache`
- **MySQL 8.0+** o **MariaDB 10.6+** (o PostgreSQL 12+ si el servidor lo ofrece; ver nota al final)
- **Composer** (disponible en la mayoría de servidores Plesk vía SSH; compruébalo con `composer --version`)
- Acceso SSH a la suscripción

## 1. Obtener los archivos

> [!TIP]
> **Usa Git para facilitar las actualizaciones.** Clona el repositorio en un directorio
> **fuera de `httpdocs/`** (por ejemplo `~/apps/atica-calidad/`):
>
> ```bash
> git clone https://github.com/TU-USUARIO/atica-calidad.git ~/apps/atica-calidad
> ```
>
> Así, actualizar a una nueva versión se reduce a un `git pull` y tres comandos.

Si prefieres no usar Git, descarga el código fuente desde la
[página de Releases](https://github.com/TU-USUARIO/atica-calidad/releases) en **Assets → Source code (zip)**
y descomprímelo en el servidor.

## 2. Configurar el dominio en Plesk

En el panel de Plesk, ve a **Dominios → [tu dominio] → Configuración de alojamiento web** y cambia el
campo **«Directorio raíz del documento»** para que apunte a la carpeta `public/` del proyecto:

```
/var/www/vhosts/tudominio.es/apps/atica-calidad/public
```

(Ajusta la ruta según donde hayas colocado los archivos.)

## 3. Configurar PHP 8.4 en Plesk

En la sección **PHP** del dominio:

1. Selecciona la versión **8.4** y el modo **FPM**.
2. En **«Configuración adicional del manejador PHP»** añade o ajusta:

   ```ini
   memory_limit = 256M
   max_execution_time = 60
   ```

3. Activa las extensiones necesarias si aparecen desactivadas en la lista.

## 4. Variables de entorno

Crea el fichero `.env.local` en la raíz del proyecto con los valores mínimos obligatorios:

```bash
APP_ENV=prod
APP_SECRET=pon_aqui_64_caracteres_hexadecimales_aleatorios
DATABASE_URL="mysql://usuario:contraseña@localhost:3306/nombre_bd?serverVersion=8.0&charset=utf8mb4"
MIGRATIONS_PATH=migrations/mysql
DEFAULT_URI=https://tudominio.es
```

> [!TIP]
> **Generar APP_SECRET.** Ejecuta este comando en el servidor y copia el resultado:
>
> ```bash
> php -r "echo bin2hex(random_bytes(32));"
> ```

El resto de variables (correo, autenticación externa, registro de actividad…) son opcionales;
consulta la
[referencia de variables de entorno](../manual/01-instalacion-y-puesta-en-marcha.md#variables-de-entorno-opcionales)
del manual.

> [!NOTE]
> **PostgreSQL como alternativa.** Si el servidor dispone de PostgreSQL en lugar de MySQL, cambia
> `DATABASE_URL` al formato:
>
> ```
> postgresql://usuario:contraseña@localhost:5432/nombre_bd?serverVersion=16&charset=utf8
> ```
>
> y ajusta también `MIGRATIONS_PATH=migrations/postgresql`.

## 5. Instalar dependencias y compilar los assets

```bash
cd ~/apps/atica-calidad
composer install --no-dev --optimize-autoloader
php bin/console tailwind:build --env=prod
php bin/console asset-map:compile
```

> [!NOTE]
> **Por qué hace falta compilar los assets.** Los archivos CSS y JS generados no se incluyen en el
> repositorio. `tailwind:build` genera el CSS a partir de las plantillas (sin necesidad de Node.js)
> y `asset-map:compile` copia y versiona todos los assets en `public/assets/`.

## 6. Crear la base de datos y configurar la aplicación

1. Crea la base de datos y el usuario desde el panel de Plesk (**Bases de datos**).
2. Ajusta el usuario y la contraseña en `DATABASE_URL` del `.env.local`.
3. Por SSH, ejecuta:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:setup --no-interaction
```

`app:setup` crea únicamente el usuario `admin`, sin ningún centro educativo: tras iniciar sesión,
la pantalla de selección de centro ofrece un enlace directo para dar de alta el centro real desde
**Administración › Centros › Nuevo centro** (ver
[Comandos de consola](../manual/01-instalacion-y-puesta-en-marcha.md#appsetup)).

> [!CAUTION]
> **Cambia la contraseña por defecto.** `app:setup` crea el usuario `admin` con contraseña `admin`,
> con el cambio de contraseña obligatorio en el primer inicio de sesión: la cuenta queda confinada
> a la pantalla de cambio de contraseña hasta que se establece una nueva. Si has usado
> `--no-force-password-change`, cambia la contraseña manualmente en
> **Perfil → Cambiar contraseña** antes de que nadie más acceda.

## 7. Correos y tareas programadas (worker vía cron)

Los correos automáticos (verificación de email) y las tareas programadas (limpieza semanal del
registro de actividad, prescripción automática diaria de partes sin notificar) los procesa un
*worker* de Symfony Messenger. Sin él la aplicación funciona, pero **no se envían emails ni se
ejecutan las tareas programadas**.

Ve a **Tareas programadas** en el panel de Plesk y crea una tarea con periodicidad **cada hora**:

```
/usr/bin/php /ruta/absoluta/a/atica-calidad/bin/console messenger:consume async scheduler_default --time-limit=3540 --memory-limit=128M
```

El parámetro `--time-limit=3540` (59 minutos) hace que el proceso termine antes de que arranque la
siguiente ejecución, evitando solapamientos.

El transporte `async` lleva los correos pendientes y `scheduler_default` gestiona las tareas
programadas. Consulta
[Correos en cola (Messenger)](../manual/08-administrar-la-plataforma.md#correos-en-cola-messenger)
para los comandos de diagnóstico de la cola.

## 8. HTTPS y dominio definitivo

Activa **Let's Encrypt** desde la sección **SSL/TLS** del dominio en Plesk. Una vez habilitado el
certificado, actualiza `DEFAULT_URI` en `.env.local`:

```bash
DEFAULT_URI=https://tudominio.es
```

Y limpia la caché para que Symfony la regenere con la URL nueva:

```bash
php bin/console cache:clear
```

## Actualizar a una nueva versión

1. Descarga o actualiza los archivos (con Git: `git pull`).
2. Instala las dependencias actualizadas:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Recompila los assets:
   ```bash
   php bin/console tailwind:build --env=prod
   php bin/console asset-map:compile
   ```
4. Aplica las migraciones de base de datos:
   ```bash
   php bin/console doctrine:migrations:migrate --no-interaction
   ```
5. Regenera la caché:
   ```bash
   rm -rf var/cache/prod
   php bin/console cache:warmup --env=prod --no-debug
   ```

> [!TIP]
> **Las migraciones son seguras de re-ejecutar.** Si una versión no incluye cambios de esquema,
> `doctrine:migrations:migrate` termina en segundos sin modificar nada. Puedes ejecutarlo en cada
> actualización sin riesgo.

## Copias de seguridad

Guarda periódicamente un volcado de la base de datos:

```bash
mysqldump -u usuario -p nombre_bd > backup-$(date +%Y%m%d).sql
```

Consulta
[Copias de seguridad](../manual/08-administrar-la-plataforma.md#copias-de-seguridad)
en el manual para más contexto sobre protección de datos y política de conservación.
