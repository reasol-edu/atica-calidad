# Presentación de ÁTICA Calidad

Presentación introductoria de ÁTICA Calidad, escrita en [Marp](https://marp.app) (Markdown →
diapositivas). Todavía es un esqueleto breve, acorde al estado inicial de la aplicación; crecerá a
la vez que se vaya añadiendo la gestión documental del sistema de calidad.

## Ficheros

- `atica-calidad.md` — fuente de las diapositivas (Marp).
- `img/` — capturas del entorno de pruebas incrustadas en la presentación (todavía sin contenido).

## Generar el PDF

Desde la raíz del repositorio:

```bash
make slides
```

Genera `docs/slides/atica-calidad.pdf`. Requiere **Node.js** (usa `npx @marp-team/marp-cli`, sin
instalación global) y `--allow-local-files` para incrustar las capturas locales.

## Otros formatos

Cambia la extensión de salida al invocar marp-cli directamente:

```bash
npx --yes @marp-team/marp-cli docs/slides/atica-calidad.md --allow-local-files -o docs/slides/atica-calidad.pptx   # PowerPoint
npx --yes @marp-team/marp-cli docs/slides/atica-calidad.md --allow-local-files -o docs/slides/atica-calidad.html   # HTML
```

## Editar y previsualizar

La extensión [Marp for VS Code] ofrece vista previa en vivo.

[Marp for VS Code]: https://marketplace.visualstudio.com/items?itemName=marp-team.marp-vscode
