# Exposición a Internet con Cloudflare Tunnel (sin abrir puertos)

Guía para publicar ÁTICA Calidad en Internet cuando el servidor está detrás de un **NAT o un
cortafuegos sobre el que no tienes control** (red de centro sin IP pública, sin posibilidad de
pedir la apertura de los puertos 80/443). **Cloudflare Tunnel** (`cloudflared`) resuelve esto: un
proceso instalado en el servidor abre una conexión **saliente** hacia la red de Cloudflare —eso sí
atraviesa cualquier NAT/cortafuegos sin abrir nada— y Cloudflare enruta el tráfico público hacia
esa conexión, terminando el certificado TLS en su borde. El servidor nunca necesita un puerto
entrante abierto.

Aplica tanto a la [instalación en Ubuntu Server](ubuntu-manual.md) como al
[despliegue con Docker](../manual/01-instalacion-y-puesta-en-marcha.md#despliegue-con-docker).

## 1. Qué es y cuándo usarlo

Usa Cloudflare Tunnel si el servidor:

- Está en una red sin IP pública fija (NAT, red doméstica, ADSL/fibra residencial).
- Está detrás de un cortafuegos de centro/organización en el que no puedes o no quieres pedir la
  apertura de los puertos 80/443.
- Ya tiene IP pública y puertos abiertos, pero prefieres no exponerlos directamente (Cloudflare
  añade una capa de protección adicional delante del servidor).

Si el servidor ya tiene un dominio apuntando a una IP pública accesible y puedes abrir 80/443,
la vía más sencilla sigue siendo la exposición directa con **HTTPS automático vía Let's Encrypt**,
ya descrita en el manual y en la [instalación manual en Ubuntu Server](ubuntu-manual.md). Esta guía
es la alternativa cuando eso no es posible.

## 2. Requisitos previos

- Una cuenta de **Cloudflare** con **Zero Trust** habilitado (gratis hasta 50 usuarios; no hace
  falta ningún plan de pago para lo que necesita ÁTICA Calidad).
- Un **dominio o subdominio bajo control de Cloudflare DNS**. Hay dos escenarios según de qué
  disponga el centro; el punto 3 detalla cómo dar de alta cada uno:
  - El centro tiene su **propio dominio completo** (p. ej. `iesejemplo.es`).
  - El centro solo tiene, o solo puede pedir, un **subdominio dentro del dominio de una
    organización mayor** que no gestiona (Consejería, ayuntamiento, universidad…) — p. ej.
    `atica.instituto-mayor.es` — y no puede ni quiere migrar ese dominio entero a Cloudflare.
    Este es el caso más habitual en centros educativos públicos.

## 3. Elegir el dominio/subdominio y añadirlo a Cloudflare

### Dominio propio completo

En el dashboard de Cloudflare, **Add a Site** con el dominio raíz (`iesejemplo.es`). Cloudflare da
dos nameservers que hay que configurar en el registrador del dominio. Es el flujo estándar de
Cloudflare, documentado ampliamente en su [propia guía de onboarding](https://developers.cloudflare.com/dns/zone-setups/full-setup/setup/).

### Subdominio dentro de un dominio ajeno

Cloudflare permite dar de alta **un subdominio como si fuera su propia zona**, sin tocar el resto
del dominio padre. En **Add a Site**, en vez del dominio raíz, escribe el subdominio completo:

```
atica.instituto-mayor.es
```

Cloudflare genera un par de nameservers específicos para esa zona (distintos de los del dominio
padre). El único cambio que hay que pedirle a quien administra el DNS de `instituto-mayor.es` es
**un único registro NS** delegando `atica.instituto-mayor.es` a esos nameservers — no toca el
resto del dominio, no requiere migrar nada más y suele ser una petición sencilla de aprobar para un
departamento de TI, ya que no cede control sobre ningún otro subdominio existente.

Una vez pedida la delegación, comprueba que se ha propagado antes de continuar:

```bash
dig NS atica.instituto-mayor.es
```

Debe devolver los nameservers de Cloudflare que te asignó al dar de alta la zona (puede tardar
desde minutos hasta unas horas en propagarse, según el TTL del registro NS anterior).

A partir de aquí, el resto de esta guía usa ese subdominio exactamente igual que un dominio propio
en todos los pasos siguientes (creación del túnel, Public Hostname, campo `DOMAIN` de
`install-ubuntu.sh`, etc.).

## 4. Crear el túnel

Común a ambos despliegues (Ubuntu Server y Docker) y a los dos escenarios de dominio del punto 3.
En el dashboard de Cloudflare:

1. **Zero Trust → Networks → Tunnels → Create a tunnel**.
2. Elige el conector **Cloudflared**.
3. Dale un nombre (p. ej. `atica-calidad`).
4. En la pantalla de instalación se muestra un comando del tipo:

   ```
   cloudflared service install eyJhIjoixxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx...
   ```

   Copia solo el **token** (la cadena larga tras `service install`) — es lo único que necesitarás
   en los pasos siguientes, tanto en Ubuntu Server como en Docker. No hace falta ejecutar ese
   comando a mano en ningún sitio: `install-ubuntu.sh` y el sidecar de Docker lo hacen por ti.

## 5. Ubuntu Server

### Instalación nueva

Si vas a instalar desde cero, responde «sí» a la pregunta que hace
[`install-ubuntu.sh`](../../dist/install-ubuntu.sh) sobre si el servidor está detrás de un NAT o
cortafuegos sin apertura de puertos, y ten el token del punto 4 a mano cuando lo pida. El script
instala `cloudflared`, lo registra como servicio con tu token, deja FrankenPHP escuchando solo en
local y ajusta `SYMFONY_TRUSTED_PROXIES` automáticamente — no hay ningún paso manual adicional.

### Retrofit sobre una instalación ya existente

Si el servidor ya tiene ÁTICA Calidad instalado con exposición directa (HTTPS/Let's Encrypt) y quieres
pasarlo a Cloudflare Tunnel:

**1. Para los servicios:**

```bash
sudo systemctl stop atica-calidad atica-calidad-worker
```

**2. Edita `.env.local`** ([instalación manual](ubuntu-manual.md#5-crear-el-fichero-de-configuración))
para que FrankenPHP escuche solo en local y confíe en el proxy interno de `cloudflared`:

```bash
sudo -u aticacalidad nano /opt/atica-calidad/.env.local
```

```dotenv
SERVER_ADDR=127.0.0.1:8080
SYMFONY_TRUSTED_PROXIES=127.0.0.1
```

`DEFAULT_URI` se mantiene igual (sigue siendo la URL pública real, usada en los enlaces de los
correos).

**3. Instala `cloudflared`** (mismos comandos que ejecuta `install-ubuntu.sh`):

```bash
sudo mkdir -p --mode=0755 /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg \
  | sudo tee /usr/share/keyrings/cloudflare-main.gpg > /dev/null
echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared $(lsb_release -cs) main" \
  | sudo tee /etc/apt/sources.list.d/cloudflared.list > /dev/null
sudo apt-get update
sudo apt-get install -y cloudflared
sudo cloudflared service install "<token del punto 4>"
```

**4. Puedes cerrar los puertos 80/443** en el cortafuegos, ya que dejan de ser necesarios:

```bash
sudo ufw delete allow 80/tcp
sudo ufw delete allow 443/tcp
sudo ufw delete allow 443/udp
```

**5. Reinicia los servicios de ÁTICA Calidad:**

```bash
sudo systemctl start atica-calidad atica-calidad-worker
```

`cloudflared` ya queda arrancado y habilitado como servicio systemd propio desde el paso 3.

## 6. Docker

El repositorio incluye el overlay [`compose.cloudflare.yaml`](../../compose.cloudflare.yaml), que
añade el sidecar `cloudflared` sin tocar `compose.yaml`:

**1. Define el token en `.env.local`** (ver `CLOUDFLARE_TUNNEL_TOKEN` en
[`.env.example`](../../.env.example)):

```dotenv
CLOUDFLARE_TUNNEL_TOKEN=<token del punto 4>
```

**2. Arranca combinando los dos ficheros:**

```bash
docker compose -f compose.yaml -f compose.cloudflare.yaml up -d
```

El sidecar vive en la misma red interna de Compose que el servicio `app`, así que el Public
Hostname del túnel (punto 7) debe apuntar a `http://app:80` (el nombre del servicio Docker), no a
un puerto del host. `compose.cloudflare.yaml` ya fija `SYMFONY_TRUSTED_PROXIES` al rango de esa red
interna, así que no hace falta tocar nada más.

No es necesario quitar los `ports:` de `app` en `compose.yaml`: con el túnel activo simplemente no
hace falta que nada externo llegue a esos puertos, pero dejarlos publicados no interfiere si en
algún momento combinas este overlay con una exposición directa adicional.

## 7. Configurar el Public Hostname

De vuelta en el dashboard del túnel (**Zero Trust → Networks → Tunnels → (tu túnel)**), pestaña
**Public Hostname → Add a public hostname**:

| Campo | Valor |
|-------|-------|
| Domain / Subdomain | el dominio o subdominio dado de alta en el punto 3 |
| Type | `HTTP` |
| URL | `localhost:8080` (Ubuntu Server) o `app:80` (Docker) |

Sin este paso el túnel está activo pero el dominio no responde: es la única parte de la
configuración que **no** es scriptable de forma no interactiva, porque vive en la cuenta de
Cloudflare del centro, no en el servidor.

## 8. Verificación

```bash
# Ubuntu Server
sudo systemctl status cloudflared

# Docker
docker compose -f compose.yaml -f compose.cloudflare.yaml logs cloudflared
```

Ambos deben mostrar la conexión establecida con la red de Cloudflare (`Registered tunnel
connection`). Comprueba después que el dominio responde desde fuera de la red del centro (por
ejemplo, desde datos móviles) y que los
[registros del servidor](../manual/07-administrar-la-plataforma.md)
muestran IPs de clientes reales y no `127.0.0.1` — confirma que `SYMFONY_TRUSTED_PROXIES` se está
aplicando correctamente.

## 9. Opcional: Cloudflare Access

Además del propio inicio de sesión de ÁTICA Calidad, se puede añadir una capa extra de autenticación
(SSO, o simplemente un código de un solo uso por email) **delante de toda la aplicación o de rutas
concretas**, desde **Zero Trust → Access → Applications**. Es un control de red adicional —útil,
por ejemplo, para restringir el acceso a las direcciones de correo del propio centro— pero no
sustituye el login ni los permisos internos de ÁTICA Calidad. Detalles en la
[documentación oficial de Cloudflare Access](https://developers.cloudflare.com/cloudflare-one/policies/access/).

## 10. Actualizar/desinstalar

**Actualizar `cloudflared`:**

```bash
# Ubuntu Server
sudo apt-get update && sudo apt-get install -y cloudflared
sudo systemctl restart cloudflared

# Docker
docker compose -f compose.yaml -f compose.cloudflare.yaml pull cloudflared
docker compose -f compose.yaml -f compose.cloudflare.yaml up -d cloudflared
```

**Desinstalar y volver a la exposición directa:**

```bash
# Ubuntu Server
sudo cloudflared service uninstall
```

Después, revierte `SERVER_ADDR`/`SYMFONY_TRUSTED_PROXIES` en `.env.local` (punto 5) a la
exposición directa habitual y reabre los puertos 80/443 en el cortafuegos.

En Docker, basta con dejar de incluir `compose.cloudflare.yaml` al arrancar (`docker compose -f
compose.yaml up -d`) y quitar `CLOUDFLARE_TUNNEL_TOKEN` de `.env.local`.
