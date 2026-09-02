# Instalación y puesta en marcha

Este capítulo es para quien va a instalar ÁTICA Calidad o mantener el servidor donde se ejecuta. Si
tu centro ya tiene la aplicación en marcha, puedes saltártelo por completo.

## Requisitos

- PHP 8.4 o superior, con las extensiones `ctype`, `curl`, `dom`, `gd`, `iconv`, `libxml` y `zip`.
- Una base de datos: PostgreSQL, MySQL/MariaDB o SQLite.
- Opcionalmente, Docker y Docker Compose para el despliegue en contenedores.

## Despliegue con Docker

`compose.yaml` levanta la aplicación (servidor [FrankenPHP](https://frankenphp.dev)), el worker
(tareas programadas) y la base de datos. Copia `.env.example` a `.env.local`, ajusta al menos
`APP_SECRET` y las credenciales de base de datos, y arranca con:

```bash
docker compose up -d
```

### Datos persistentes

El volumen `/data` dentro del contenedor contiene la base de datos (si usas SQLite) y los ficheros
subidos. Haz copia de seguridad de ese volumen con regularidad — ver
[Copias de seguridad](09-administrar-la-plataforma.md#copias-de-seguridad).

### Arranque automático al reiniciar el servidor

Con Docker Compose y `restart: unless-stopped` (ya configurado en `compose.yaml`), los contenedores
se recuperan solos tras un reinicio del servidor siempre que el propio Docker esté configurado para
arrancar al inicio del sistema (`systemctl enable docker`).

## Despliegue en Plesk

Ver la [guía de despliegue en Plesk](../despliegue/plesk.md) para instalar la aplicación como
binario nativo en un panel de hosting compartido, sin Docker.

## Despliegue en Ubuntu Server 26.04

Ver la [guía de despliegue en Ubuntu Server](../despliegue/ubuntu-manual.md) para instalar el
binario nativo con systemd en un VPS o servidor dedicado, incluida la actualización
[automatizada](../despliegue/despliegue-continuo.md) y la exposición sin abrir puertos con
[Cloudflare Tunnel](../despliegue/cloudflare-tunnel.md).

## Variables de entorno {#variables-de-entorno-opcionales}

Todas las variables admitidas están documentadas, con su valor por defecto, en `.env.example` en la
raíz del repositorio. Las más habituales:

| Variable | Para qué sirve |
| --- | --- |
| `DATABASE_URL` | Cadena de conexión a la base de datos. |
| `MAILER_DSN` | Transporte de correo saliente (por defecto, descarta los correos). |
| `APP_EXTERNAL_ENABLED` / `APP_EXTERNAL_URL` | Autenticación externa del profesorado (iSéneca). |
| `SYMFONY_TRUSTED_PROXIES` | IPs de proxies de confianza, si la app va detrás de uno. |

## Desarrollo local

```bash
# 1. Clona el repositorio y copia el entorno
cp .env.example .env.local

# 2. Edita .env.local y rellena APP_SECRET con un valor aleatorio
php -r 'echo bin2hex(random_bytes(32));'

# 3. Instala las dependencias
composer install

# 4. Levanta la base de datos e inicialízala
make dev
make migrate
make setup

# 5. Arranca el servidor de desarrollo (si no usas "make dev")
symfony serve
```

## Comandos de consola

### app:setup

Inicializa la aplicación con un usuario administrador (`admin` / `admin`, con cambio de contraseña
forzado en el primer acceso) si la base de datos está vacía. Es seguro ejecutarlo varias veces: si
ya hay datos, no hace nada.

```bash
php bin/console app:setup
```

### app:create-educational-centre

Crea un nuevo centro educativo con su primer curso académico, junto con las tres raíces por
defecto de Responsabilidades → Listas (Departamento, Grupo, Materia — ver
[Administrar la plataforma](09-administrar-la-plataforma.md#centros-educativos)).

```bash
php bin/console app:create-educational-centre <código> <nombre> <localidad>
```

### app:create-admin

Crea una cuenta de docente con privilegios de administrador global.

```bash
php bin/console app:create-admin <usuario> <contraseña>
```
