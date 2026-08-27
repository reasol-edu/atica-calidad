# Fichas de referencia rápida

Fuente de las fichas de referencia rápida («cheatsheets») de ÁTICA Calidad: una por cada función
básica ya disponible, pensadas para consultarse en el móvil, más un par para el equipo directivo /
responsable de calidad sobre tareas de configuración (curso académico nuevo, responsabilidades del
centro). Todos los comandos de esta página se ejecutan desde la raíz del repositorio con `make`, no
directamente desde esta carpeta.

Se han retirado las fichas del proyecto original ligadas a funciones que no forman parte de ÁTICA
Calidad (partes, notas, sanciones, ausencias, guardias, notificaciones y ficha de contacto del
alumnado); solo quedan las aplicables al esqueleto actual de la aplicación. Se irán añadiendo
nuevas fichas a medida que crezca.

## Ficheros

- `busqueda-rapida.md`, `instalar-app.md` — fichas [Marp](https://marp.app) por función, pensadas
  para el móvil (todavía sin capturas — ver «Regenerar las capturas» más abajo).
- `curso-nuevo.md`, `responsabilidades.md` — fichas para el equipo directivo / responsable de
  calidad, pensadas para escritorio (a diferencia de las demás), usando la clase
  `.captura-escritorio` de `theme.css`.
- Todas las fichas comparten el mismo mecanismo de versión/fecha que `docs/slides/atica-calidad.md`
  (marcadores `{{VERSION}}`/`{{PUB_DATE}}` sustituidos por `make cheatsheets`).
- `theme.css` — tema Marp compartido por las fichas (página A4 vertical, paleta de marca).
- `img/` — capturas de pantalla referenciadas desde las fichas.
- `ficha-*.pdf` y `_build.md` — salidas generadas por `make cheatsheets` (ver abajo); no se editan
  a mano.

## Generar los PDF

```bash
make cheatsheets
```

Genera un PDF independiente por ficha (`docs/cheatsheets/ficha-<nombre>.pdf`) con
[marp-cli](https://github.com/marp-team/marp-cli) (vía `npx`), usando el tema compartido
`theme.css`. Requiere Node.js/`npx`; el mensaje de error del propio comando indica cómo
instalarlo si falta. La versión y la fecha del pie se toman automáticamente de
`app.version`/`app.pub_date` en `config/services.yaml`, igual que en la presentación.

`make docs` genera también las fichas junto al manual y la presentación.

## Regenerar las capturas

Las fichas actuales no tienen capturas de pantalla todavía: la aplicación no cuenta con datos de
demostración (fixtures) para generarlas de forma reproducible. Cuando existan, el patrón a seguir
es un script Node/[Playwright](https://playwright.dev) por ficha (o grupo de fichas) en
`scripts/capture-*.mjs`, ejecutado contra un servidor local con datos sembrados — nunca contra la
base de datos real.
