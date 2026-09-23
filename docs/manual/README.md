# Manual de usuario

Fuente del manual de usuario de ÁTICA Calidad, escrito en Markdown y publicado en dos formatos: un
PDF descargable y una web navegable. Todos los comandos de esta página se ejecutan desde la raíz
del repositorio con `make`, no directamente desde esta carpeta.

## Ficheros

- `index.md` y `01-*.md` a `09-*.md` — los capítulos del manual, en el orden en que aparecen tanto
  en el PDF como en el índice de la web (ver [Cómo usar este manual](index.md#como-usar-este-manual)).
- `mkdocs.yml` — configuración de MkDocs Material para la versión web (navegación, tema, exclusiones).
- `requirements.txt` — dependencias de Python para generar la web (MkDocs Material y sus plugins).
- `assets/` — hojas de estilo (`theme.css`, `print.css`) compartidas por el PDF y la web.
- `img/` — capturas de pantalla y otras imágenes referenciadas desde los capítulos (ver
  [Regenerar las capturas](#regenerar-las-capturas)).
- `atica-calidad-manual.pdf` y `_build.html` — salidas generadas por `make docs-pdf` (ver abajo); no
  se editan a mano.

## Generar el PDF

```bash
make docs-pdf
```

Genera `docs/manual/atica-calidad-manual.pdf` a partir de todos los capítulos, usando
[pandoc](https://pandoc.org) para convertir el Markdown a HTML (fichero intermedio `_build.html`,
en esta misma carpeta para que las rutas relativas a `assets/` e `img/` resuelvan igual que en la
web) y [`pagedjs-cli`](https://pagedjs.org) (vía `npx`, sobre un Chrome/Chromium local) para
maquetar e imprimir ese HTML a PDF con el mismo tema visual que la web.

Requiere tener instalados `pandoc` y Node.js/`npx`; el mensaje de error del propio comando indica
cómo instalar lo que falte. La versión y la fecha que aparecen en la portada se toman
automáticamente de `app.version`/`app.pub_date` en `config/services.yaml` — no hace falta editar el
manual en cada release.

## Generar / previsualizar la web

```bash
make docs-web      # genera docs/manual-site/ (build estático)
make docs-serve     # sirve el manual en http://127.0.0.1:8000 con recarga en caliente
```

Ambos usan [MkDocs Material](https://squidfunk.github.io/mkdocs-material/). Requieren las
dependencias de `requirements.txt`:

```bash
pip install -r docs/manual/requirements.txt
```

`docs-serve` es la forma más rápida de revisar cambios mientras se edita: recarga el navegador
automáticamente al guardar cualquier fichero Markdown.

## Generar ambas salidas

```bash
make docs
```

Equivale a ejecutar `make docs-pdf` seguido de `make docs-web`: genera el PDF y construye la web en
un solo paso, útil antes de publicar una nueva versión.

## Regenerar las capturas

Las capturas de `img/` salen de scripts de Node/[Playwright](https://playwright.dev) en
`scripts/capture-*-shots.mjs`, uno por capítulo o grupo de pantallas: `actividades`, `arbol`,
`calendar` y `gestion` (preparar el nuevo curso, papelera y registro de actividad). Se ejecutan
contra un servidor local con una **base de datos desechable** sembrada con los datos de
demostración, **nunca contra la real**: `gestion` elimina una actividad y una entrega para que la
papelera tenga contenido.

```bash
# 1. Base de datos desechable (SQLite) con los datos de demostración
export DATABASE_URL="sqlite:///$PWD/var/capturas.db" MIGRATIONS_PATH=migrations/sqlite
rm -f var/capturas.db
php bin/console doctrine:migrations:migrate -n
php bin/console app:load-demo-data -n
php bin/console tailwind:build

# 2. Servidor en el puerto 8744 (en otra terminal, con las mismas variables exportadas)
php -d variables_order=EGPCS -S 127.0.0.1:8744 scripts/router-shots.php

# 3. Capturas: gestion, la última
node scripts/capture-actividades-shots.mjs
node scripts/capture-arbol-shots.mjs
SHOTS_OUT_DIR=docs/manual/img node scripts/capture-calendar-shots.mjs
node scripts/capture-gestion-shots.mjs
```

!!! danger "`-d variables_order=EGPCS` es imprescindible"
    El servidor integrado de PHP no pasa las variables de entorno a `$_SERVER`, así que sin esa
    opción Symfony ignora el `DATABASE_URL` exportado y usa el de `.env.local`: la base de datos
    de desarrollo real.

El calendario guarda por defecto en `img/calendario/`; el manual usa `img/`, de ahí la llamada con
`SHOTS_OUT_DIR`. Para volver a capturar, vuelve a sembrar la base de datos (paso 1): los scripts dan
por hecho los datos recién creados.

Con las capturas del manual se actualizan también las de otros documentos:

- **Presentación** (`docs/slides/img/`): copia `img/arbol-carpeta-contenido.png` como
  `arbol-documental.png` e `img/actividades-mias.png` como `actividades.png`.
- **Fichas** (`docs/cheatsheets/img/`, capturas de móvil): `node scripts/capture-cheatsheet-shots.mjs`,
  con la base recién sembrada (antes de `gestion`).
