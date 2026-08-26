# Despliegue continuo (CD) en Ubuntu Server

Con despliegue continuo, el servidor se actualiza solo cada vez que se publica una nueva versión
de ÁTICA Calidad, sin intervención manual. Esta guía asume una instalación en Ubuntu Server como la
descrita en el
[manual](../manual/01-instalacion-y-puesta-en-marcha.md#despliegue-en-ubuntu-server-2604)
(automatizada con `install-ubuntu.sh` o [manual paso a paso](ubuntu-manual.md)).

La base es siempre el mismo script de actualización; la diferencia está en cómo se activa:
**sondeo periódico** (más sencillo, sin puertos extra) o **webhook** (instantáneo, requiere un
puerto adicional).

## Script de actualización compartido

El script `dist/update-ubuntu.sh` del repositorio hace exactamente esto: compara la versión
instalada (fichero `.version`) con la última publicada en GitHub Releases y, si difieren, para los
servicios, descarga y extrae el paquete nuevo, y los vuelve a arrancar. Si ya está en la última
versión no hace nada y termina en `0`, así que es seguro invocarlo repetidamente desde un timer o
un webhook.

Descárgalo a la instalación:

```bash
sudo curl -fsSL https://raw.githubusercontent.com/TU-USUARIO/atica-calidad/main/dist/update-ubuntu.sh \
  -o /opt/atica-calidad/atica-calidad-update.sh
sudo chmod +x /opt/atica-calidad/atica-calidad-update.sh
```

Para una actualización puntual, sin automatizar nada, basta con ejecutarlo directamente:

```bash
sudo bash /opt/atica-calidad/atica-calidad-update.sh
```

Admite `--force` para reinstalar aunque la versión publicada parezca igual a la instalada (por
ejemplo, tras una re-release que mueve la misma etiqueta a otro commit):

```bash
sudo bash /opt/atica-calidad/atica-calidad-update.sh --force
```

## Opción A — Sondeo periódico con systemd timer

El timer comprueba si hay nueva versión cada 15 minutos. No requiere abrir ningún puerto extra ni
configurar el repositorio remoto.

El script `dist/setup-update-timer.sh` del repositorio hace todo esto: descarga
`atica-calidad-update.sh`, crea el servicio y el timer de systemd, y activa el timer. Es idempotente
(puede volver a ejecutarse sin duplicar nada, p. ej. para refrescar `atica-calidad-update.sh` a su
última versión):

```bash
curl -fsSL https://raw.githubusercontent.com/TU-USUARIO/atica-calidad/main/dist/setup-update-timer.sh \
  | sudo bash
```

Verifica que el timer está activo:

```bash
systemctl list-timers atica-calidad-update.timer
```

Para forzar una comprobación inmediata sin esperar al siguiente disparo:

```bash
sudo systemctl start atica-calidad-update.service
journalctl -u atica-calidad-update.service -n 20
```

## Opción B — Webhook desde GitHub

El webhook recibe la señal de GitHub en el momento exacto en que se publica la release, sin ningún
retardo de sondeo. Requiere abrir el puerto elegido en el cortafuegos y configurar un secreto
compartido en GitHub.

**1. Instala `webhook`:**

```bash
sudo apt-get install -y webhook
```

**2. Crea la configuración del receptor:**

```bash
sudo -u aticacalidad tee /opt/atica-calidad/webhook.json > /dev/null << 'EOF'
[
  {
    "id": "atica-calidad-update",
    "execute-command": "/usr/bin/sudo",
    "pass-arguments-to-command": [
      { "source": "string", "name": "/opt/atica-calidad/atica-calidad-update.sh" }
    ],
    "command-working-directory": "/opt/atica-calidad",
    "response-message": "Actualización iniciada",
    "trigger-rule": {
      "and": [
        {
          "match": {
            "type": "payload-hmac-sha256",
            "secret": "WEBHOOK_SECRET",
            "parameter": { "source": "header", "name": "X-Hub-Signature-256" }
          }
        },
        {
          "match": {
            "type": "value",
            "value": "release",
            "parameter": { "source": "payload", "name": "action" }
          }
        }
      ]
    }
  }
]
EOF
```

Sustituye `WEBHOOK_SECRET` por una cadena aleatoria larga (p. ej. `openssl rand -hex 32`).

**3. Crea el servicio systemd para el receptor:**

El propio proceso `webhook` se ejecuta sin privilegios, como `aticacalidad`; solo el script de
actualización necesita root, así que se le concede permiso justo para ese único comando:

```bash
sudo tee /etc/sudoers.d/atica-calidad-update > /dev/null << 'EOF'
aticacalidad ALL=(root) NOPASSWD: /opt/atica-calidad/atica-calidad-update.sh
EOF
sudo chmod 440 /etc/sudoers.d/atica-calidad-update

sudo tee /etc/systemd/system/atica-calidad-webhook.service > /dev/null << 'UNIT'
[Unit]
Description=ÁTICA Calidad — receptor de webhooks
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=aticacalidad
ExecStart=/usr/bin/webhook -hooks /opt/atica-calidad/webhook.json -port 9000 -verbose
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now atica-calidad-webhook
```

**4. Abre el puerto en el cortafuegos:**

```bash
sudo ufw allow 9000/tcp comment 'ÁTICA Calidad webhook'
```

**5. Configura el webhook en GitHub:**

En el repositorio ve a **Settings → Webhooks → Add webhook**:

| Campo | Valor |
|-------|-------|
| Payload URL | `https://tudominio.es:9000/hooks/atica-calidad-update` |
| Content type | `application/json` |
| Secret | el mismo valor que `WEBHOOK_SECRET` |
| Events | «Let me select individual events» → **Releases** |

> [!TIP]
> **HTTPS para el webhook.** Si prefieres no exponer el puerto 9000 directamente, configura un
> proxy inverso en FrankenPHP (o Caddy) que enrute `/hooks/` al receptor local en
> `localhost:9000`. Así el webhook llega por el mismo puerto 443 ya abierto y el tráfico queda
> cifrado con tu certificado TLS existente.
