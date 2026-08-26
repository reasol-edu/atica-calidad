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
- `img/` — capturas de pantalla y otras imágenes referenciadas desde los capítulos (todavía sin
  contenido: los capítulos actuales son esqueletos sin capturas).
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
