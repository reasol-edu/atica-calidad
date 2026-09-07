# Instalación manual en Ubuntu Server 26.04

Guía paso a paso para instalar ÁTICA Calidad en un **VPS o servidor dedicado con Ubuntu Server 26.04
LTS**, usando el binario de FrankenPHP con **PostgreSQL nativo** y dos servicios **systemd**, sin
Docker. Se requiere acceso SSH con sudo.

> [!TIP]
> Estos son exactamente los pasos que automatiza el script
> [`dist/install-ubuntu.sh`](../../dist/install-ubuntu.sh). Sigue esta guía solo si prefieres
> controlar cada paso o adaptar la instalación a tu entorno; en caso contrario, usa la
> [instalación automatizada](../manual/01-instalacion-y-puesta-en-marcha.md#despliegue-en-ubuntu-server-2604)
> descrita en el manual.

## 1. Instalar PostgreSQL

```bash
sudo apt-get update && sudo apt-get install -y postgresql postgresql-client curl
sudo -u postgres psql -c "CREATE USER atica WITH PASSWORD 'contraseña_segura';"
sudo -u postgres psql -c "CREATE DATABASE atica OWNER atica;"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE atica TO atica;"
```

## 2. Configurar el cortafuegos

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 443/udp   # HTTP/3 (QUIC)
sudo ufw enable
```

> [!NOTE]
> Si vas a poner ÁTICA Calidad **detrás de un proxy inverso propio** (ver el
> [paso 5](#5b-variante-detras-de-un-proxy-inverso)), no abras 80/443: FrankenPHP servirá HTTP
> plano en un puerto local (p. ej. `8080`). Si el proxy corre en el mismo servidor no hace falta
> abrir nada más que SSH; si está en otra máquina, abre ese puerto **solo** para la IP del proxy:
> `sudo ufw allow from 10.0.0.5 to any port 8080 proto tcp`.

## 3. Crear el usuario del sistema y el directorio de instalación

```bash
sudo useradd -r -d /opt/atica-calidad -s /usr/sbin/nologin aticacalidad
sudo mkdir -p /opt/atica-calidad
sudo chown aticacalidad:aticacalidad /opt/atica-calidad
```

## 4. Descargar el binario

Desde la [página de Releases](https://github.com/reasol-edu/atica-calidad/releases), copia el enlace
del archivo `atica-calidad-vVERSION-linux-x86_64.tar.gz` (o `linux-aarch64` para ARM) y extráelo:

```bash
VERSION=X.Y.Z   # reemplaza por la versión actual
sudo -u aticacalidad bash -c "
  curl -fsSL https://github.com/reasol-edu/atica-calidad/releases/download/v${VERSION}/atica-calidad-v${VERSION}-linux-x86_64.tar.gz \
  | tar xzf - -C /opt/atica-calidad --strip-components=1
"
```

## 5. Crear el fichero de configuración

```bash
sudo -u aticacalidad nano /opt/atica-calidad/.env.local
```

Contenido mínimo obligatorio:

```bash
SERVER_ADDR=atica.tudominio.es
DEFAULT_URI=https://atica.tudominio.es
DATABASE_URL=postgresql://atica:contraseña_segura@localhost:5432/atica?serverVersion=16&charset=utf8
MIGRATIONS_PATH=migrations/postgresql
MAILER_DSN=null://null
MAILER_FROM=no-responder@tudominio.es
```

`SERVER_ADDR` con el nombre de dominio (sin puerto) activa el **HTTPS automático** de
FrankenPHP/Caddy vía Let's Encrypt.

### 5b. Variante: detrás de un proxy inverso {#5b-variante-detras-de-un-proxy-inverso}

Si el TLS lo va a terminar un **proxy inverso propio** (nginx, Apache, HAProxy, Traefik, el
balanceador de un proveedor de nube…) en vez de FrankenPHP, cambia dos cosas en `.env.local`:

```bash
# HTTP plano en un puerto local; sin nombre de dominio -> Caddy NO pide certificado
SERVER_ADDR=127.0.0.1:8080          # o  :8080  si el proxy está en otra máquina
DEFAULT_URI=https://atica.tudominio.es
DATABASE_URL=postgresql://atica:contraseña_segura@localhost:5432/atica?serverVersion=16&charset=utf8
MIGRATIONS_PATH=migrations/postgresql
MAILER_DSN=null://null
MAILER_FROM=no-responder@tudominio.es
# IP (o rango CIDR) del proxy inverso: sin esto la aplicación registraría la IP
# del proxy en vez de la del usuario y no reconocería el https:// original
SYMFONY_TRUSTED_PROXIES=10.0.0.5
```

- **No** hay Let's Encrypt ni certificado en este servidor: `SERVER_ADDR` con puerto y sin
  dominio desactiva el HTTPS automático de Caddy. El certificado del dominio lo gestiona el proxy.
- `SYMFONY_TRUSTED_PROXIES` es imprescindible para que el [registro de
  actividad](../manual/09-administrar-la-plataforma.md#registro-de-actividad) y los límites de
  intentos guarden la IP real del usuario y no la del proxy. Acepta una IP, un rango CIDR o varios
  valores separados por comas.
- El proxy debe reenviar las cabeceras `X-Forwarded-For`, `X-Forwarded-Proto` y `X-Forwarded-Host`.

**Ejemplo con nginx** (en el servidor del proxy):

```nginx
server {
    listen 443 ssl http2;
    server_name atica.tudominio.es;

    ssl_certificate     /etc/letsencrypt/live/atica.tudominio.es/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/atica.tudominio.es/privkey.pem;

    client_max_body_size 25m;   # subidas de documentos (límite de la app: 20 MB)

    location / {
        proxy_pass http://10.0.0.10:8080;      # IP:puerto de este servidor
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;
    }
}
```

**Ejemplo con Apache** (`mod_proxy` + `mod_ssl` + `mod_headers`):

```apache
<VirtualHost *:443>
    ServerName atica.tudominio.es
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/atica.tudominio.es/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/atica.tudominio.es/privkey.pem

    LimitRequestBody 26214400
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"
    ProxyPass        / http://10.0.0.10:8080/
    ProxyPassReverse / http://10.0.0.10:8080/
</VirtualHost>
```

El script [`dist/install-ubuntu.sh`](../../dist/install-ubuntu.sh) ofrece esta variante como
opción 2 («Detrás de un proxy inverso propio») y pregunta el puerto local y la IP del proxy.

## 6. Crear los scripts de arranque

Los scripts `atica-calidad-start.sh` y `atica-calidad-worker.sh` los genera `install-ubuntu.sh`; en la
instalación manual, copia su contenido desde el propio script (sección «Crear scripts de arranque»
de [`dist/install-ubuntu.sh`](../../dist/install-ubuntu.sh)).

Los scripts leen `.env.local`, generan `APP_SECRET` automáticamente en el primer arranque
(guardado en `data/.secret`), escriben el fichero `app/.env` que necesita Symfony y lanzan en
primer plano el servidor o el worker respectivamente.

## 7. Instalar los servicios systemd

```bash
sudo tee /etc/systemd/system/atica-calidad.service > /dev/null << 'UNIT'
[Unit]
Description=ÁTICA Calidad (FrankenPHP)
After=network-online.target postgresql.service
Wants=network-online.target
Requires=postgresql.service

[Service]
Type=simple
User=aticacalidad
Group=aticacalidad
WorkingDirectory=/opt/atica-calidad
ExecStart=/opt/atica-calidad/atica-calidad-start.sh
Restart=on-failure
RestartSec=5
TimeoutStopSec=30
LimitNOFILE=65536
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
UNIT

sudo tee /etc/systemd/system/atica-calidad-worker.service > /dev/null << 'UNIT'
[Unit]
Description=ÁTICA Calidad Worker (Messenger + Scheduler)
After=atica-calidad.service
Requires=atica-calidad.service

[Service]
Type=simple
User=aticacalidad
Group=aticacalidad
WorkingDirectory=/opt/atica-calidad
ExecStart=/opt/atica-calidad/atica-calidad-worker.sh
Restart=always
RestartSec=10
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now atica-calidad atica-calidad-worker
```

> [!NOTE]
> La directiva `AmbientCapabilities=CAP_NET_BIND_SERVICE` permite que el proceso (ejecutado como
> `aticacalidad`, sin privilegios de root) escuche en los puertos 80 y 443.

## Comandos útiles

```bash
# Estado de los servicios
sudo systemctl status atica-calidad atica-calidad-worker

# Seguir los logs en tiempo real
sudo journalctl -u atica-calidad -f
sudo journalctl -u atica-calidad-worker -f

# Reiniciar tras cambiar .env.local
sudo systemctl restart atica-calidad atica-calidad-worker
```

## Después de instalar

- La aplicación queda accesible en `https://tudominio.es` con `admin` / `admin`.
  **Cambia la contraseña inmediatamente** en **Perfil → Cambiar contraseña**.
- Si usas un [proxy inverso](#5b-variante-detras-de-un-proxy-inverso), comprueba en el
  [registro de actividad](../manual/09-administrar-la-plataforma.md#registro-de-actividad) que la
  IP que se registra es la del cliente y no la del proxy; si no, revisa `SYMFONY_TRUSTED_PROXIES`
  y que el proxy envíe `X-Forwarded-For`.
- Para automatizar las actualizaciones a nuevas versiones, ver la guía de
  [despliegue continuo](despliegue-continuo.md).
- Incluye en tus copias de seguridad el volcado de la base de datos, el secreto (`data/.secret`) y
  la configuración (`.env.local`):

  ```bash
  sudo -u postgres pg_dump atica > backup-$(date +%Y%m%d).sql
  ```
