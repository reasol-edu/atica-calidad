# Manual screenshots and the demo data behind them

Read this before **adding or regenerating a screenshot** of the manual (`docs/manual/img/`), and
before **changing `app:load-demo-data`** (`src/Command/LoadDemoDataCommand.php`): the demo data is
the fixture set of every screenshot, and each piece of it exists to make one thing in the manual
*visible*. Changing it silently breaks the match between a picture and its caption.

## Rule for new fixtures

A screenshot must show **the concept the prose is explaining**, not just "a screen with data".
Before taking one, write down (in the matrix below) the section it illustrates, the fixture that
makes the concept visible, and what the caption must point at. If the demo data lacks the case
(an inherited association, an unassigned subprofile, a failed e-mail...), **add it to
`LoadDemoDataCommand`** and register it here — don't fake it by editing the database in a script.

Keep additions *additive*: new lists, profiles and teachers rather than changes to the existing
ones, and nothing that touches the folders, activities or teachers (`direccion`, `calidad`) that the
other chapters' images show. The 61 images that predate this file depend on them.

## Accounts

Centre «IES Ada Lovelace» (code `29700456`, active course 2026-2027, plus 2025-2026), all
passwords equal to the username unless noted:

| User | Password | What it is |
|---|---|---|
| `admin` | `admin` | Platform administrator (`ROLE_ADMIN`): the `/admin` screens, "Acceder como" |
| `direccion` | `direccion` | Management team of the centre (Javier Morales Peña, profile Director/a) — the default for centre screens |
| `calidad` | `calidad` | Quality manager (Laura Jiménez Soto) |
| `multicentro` | `multicentro` | Belongs to **two centres**: the only account shown the centre selection screen |
| `a.ruiz`, `p.sánchez`... | `prueba` | The 30 rank-and-file teachers (username = initial + first surname) |
| `r.fuentes` | `prueba` | **Former** teacher: exists, is *not* in the 2026-2027 course, and still holds the profile «Coordinador/a TIC» |

The second centre is «CEIP Miguel Hernández» (`18700123`), empty, only so `multicentro` has to choose.

## Fixture catalogue (what each piece of the demo data is for)

**Lists** (Responsabilidades › Listas, `06-responsabilidades.md`)

| Fixture | Shows |
|---|---|
| `Departamento` (9), `Grupo` (15), `Materia` (14) | Flat lists, one subprofile per element |
| **`Enseñanza`** — ESO / Bachillerato / Formación Profesional → DAM, DAW → courses (16 elements, 3 levels) | The only nested list: intermediate nodes, profile on a non-root element, inheritance |
| `Enseñanza › ESO › Programa de Mejora (extinguido)` | An **inactive** element (eye-slash; its subprofile is inactive too) |
| `Enseñanza › Formación Profesional` associated to *Jefe/a de Departamento Informática* | An association **inherited** by the whole branch (pale link icon) |
| `Enseñanza › 2º DAW` associated to *Tutor/a 2º DAW* | An **own** association that **replaces** the inherited one |
| `Materia › Tecnología` associated to two subprofiles (Tecnología + Informática) | **Several** associations on one element |

**Specific profiles** (`06-responsabilidades.md`)

| Fixture | Shows |
|---|---|
| `Tutor/a` (list Grupo), `Jefe/a de Departamento` (list Departamento) | Subprofiles from a flat list |
| **`Coordinador/a de Nivel`** (list Enseñanza root) | 11 subprofiles; «2º DAW» has **no teacher**; «Programa de Mejora» is inactive; the "(todos)" intermediate options in the permission picker |
| **`Coordinador/a de FFEOE`** (list element *Formación Profesional*, a non-root) | Only the leaves of that branch become subprofiles |
| `Coordinador/a TIC` (no list; Adrián Gil + the former teacher) | Direct assignment; a teacher outside the course (red in Asignar perfiles; counter on «Quitar docentes fuera de curso») |
| `Coordinador/a del Programa ALDEA` (inactive, one teacher) | An inactive profile that keeps its teacher («Mostrar inactivos») |
| Noelia Bravo Escudero (Nivel 2º DAM + FFEOE 2º DAM + Tutor/a 2º DAM) | One teacher with several profiles (Asignar perfiles › Docentes) |

**Centre** (`02-preparar-el-curso-academico.md`, `05-administrar-el-centro.md`)

| Fixture | Shows |
|---|---|
| 16 non-working days (holidays + Christmas break, all **outside October**, the month the plain calendar image shows) | Días no lectivos; `calendario-no-lectivos` (December) and `calendario-dia-no-lectivo` (8 Dec) |
| Folders «Política de Calidad» (responsible: *Responsable de calidad*) and «Actas del ETCP» (*Secretario/a*); the ETCP document's next review **10 days ago**, the policy's **in 20 days** | Listado maestro and Revisiones de documentos reports (red = past, amber = soon), the «Revisar antes del…» chip in the tree |
| 8 rows in the e-mail log, 6 event types, **one failed** with its error message | Registro de avisos por correo |
| Only 2 teachers are offered for removal in the teacher import (everyone else holds a profile) | "Retirar del curso" never offers someone with a responsibility |

**CSV fixtures** (`scripts/fixtures/`, uploaded by the scripts, **never confirmed**)

| File | Outcome each row produces |
|---|---|
| `seneca-grupos.csv` (into the existing root «Grupo») | add (1º ESO C, 3º ESO C), reactivate (4º ESO A, deactivated by the script), delete (2º PMAR, added by the script, unused), deactivate (1º Bach. B, 2º Bach. B: in use) |
| `seneca-materias.csv` (new root «Materias por grupo») | A two-level tree, all "se añadirán" |
| `seneca-docentes.csv` | register (3), add existing user (`r.fuentes`), fill e-mail (2), unchanged (7) |

## Screenshot matrix

| Script | Images (`docs/manual/img/`) | Chapter | Mutates the DB? |
|---|---|---|---|
| `capture-acceso-shots.mjs` | `acceso-login`, `acceso-seleccion-centro`, `acceso-cambiar-centro` | `index.md`, `05` | no |
| `capture-centro-shots.mjs` | `centro-inicio`, `-cursos`, `-docentes`, `-docentes-importar`, `-docentes-previsualizacion`, `-docentes-retirar`, `-perfiles`, `-dias-no-lectivos`, `-registro-avisos`, `-ajustes` | `02`, `05` | no |
| `capture-informes-shots.mjs` | `informes-indice`, `-cumplimiento`, `informes-pdf-*` (first PDF page via `pdftoppm`) | `04` | no |
| `capture-plataforma-shots.mjs` | `plataforma-panel`, `-centros`, `-centro-editar`, `-docentes`, `-docente-editar`, `-suplantacion`, `plataforma-ajustes-*` (one per settings category), `plataforma-mantenimiento-{aviso,pagina,login}` | `10` | **yes, at the end**: turns maintenance mode on and puts it back off |
| `capture-responsabilidades-shots.mjs` | `responsabilidades-*` (lists, tree, mobile, element panels, move, profiles, assign, picker, Séneca previews) | `06` | **yes**, at the end (Séneca setup) |
| `capture-actividades-shots.mjs`, `-arbol-`, `-calendar-`, `-mejora-`, `-utilidades-` | the older chapters' images (`calendar` also makes `calendario-no-lectivos` and `calendario-dia-no-lectivo`) | `07`, `08`, `03`, `09`, `12` | no (`utilidades` creates a calendar; `calendar` writes the feed token) |
| `capture-gestion-shots.mjs` | `curso-preparar`, `papelera`, `lectura-*`, `registro-actividad`... | `02`, `07` | **yes: deletes data — run it last** |
| `capture-curso-nuevo-shots.mjs`, `capture-cheatsheet-shots.mjs` | cheat sheets (`docs/cheatsheets/img`) | — | `curso-nuevo` needs **its own database** |

No screenshot exists, on purpose, for first start, backups and updates: they are command-line only
(`app:setup`, `app:backup`, `app:restore`, the update steps), there is no screen to show.

## Runbook

1. **Playwright** is not a dependency of the repo. Install a version whose Chromium revision is
   already cached (1.61.0 matched the cached 1228) in a scratch directory outside the repo, copy the
   script and `scripts/fixtures/` next to it (the scripts resolve fixtures relative to themselves),
   and run it with `SHOTS_OUT_DIR=<repo>/docs/manual/img node capture-xxx-shots.mjs`. `pdftoppm`
   (poppler) is needed for the PDF images, and the `sqlite3` CLI (plus `SHOTS_DB`, default
   `var/capturas.db`) for `capture-responsabilidades`.
2. **Build a throwaway database from scratch** every time — a script that has mutated it makes the
   next run differ (`informes` must run **before** `gestion`: it counts the activity `gestion`
   deletes). Use the **migrations**, not `doctrine:schema:create`: the settings catalogue is
   seeded by a migration and without it every settings screen is empty.
   ```bash
   rm -f var/capturas.db   # gitignored
   export DATABASE_URL='sqlite:///%kernel.project_dir%/var/capturas.db' APP_ENV=dev MIGRATIONS_PATH=migrations/sqlite
   php -d memory_limit=1G bin/console doctrine:migrations:migrate -n
   php -d memory_limit=1G bin/console app:load-demo-data -n
   ```
3. **Serve it** with `-t public` and the router, and `-d variables_order=EGPCS` (otherwise PHP ignores
   the exported `DATABASE_URL` and uses `.env.local`, i.e. **your real database**):
   ```bash
   php -d variables_order=EGPCS -S 127.0.0.1:8744 -t public scripts/router-shots.php
   ```
   Without `-t public` the built-in server answers 404 to `/static/...`, and every image shows a
   **broken logo** (the images that predate this file have it).
4. If a page fails with `PHPStan\PhpDocParser\Lexer not found`, it is the stale dev cache after
   editing `src/`: `php -d memory_limit=1G bin/console cache:clear --env=dev`.
5. After adding any Tailwind class, `php bin/console tailwind:build` (the dev server serves what was
   built). If the Tailwind binary is killed (signal 9) on macOS, its signature is invalid:
   `codesign --force --sign - <path to the binary>`.
6. **Run order**: the read-only scripts in any order, then `capture-responsabilidades`, and
   `capture-gestion` **last** (it deletes an activity and a submission the others expect).
7. **Read every PNG** before committing it: a clipped dropdown, a half-rendered Live Component or a
   misplaced fixed sidebar are easy to miss. Hard-won rules in the scripts: call
   `window.scrollTo(0, 0)` before a full-page capture (`hideToolbar()` does it); after a click on a
   Live Component, wait for the new state (`waitFor`/`waitForSelector` on *visible* elements), not
   just `networkidle`; reload instead of switching between items in place (a second Tom Select
   picker can be left behind); use a wider viewport for wide tables (the e-mail log needs 1700px).
8. Each caption must name what is visible in the fixture («Coordinador/a de Nivel 2º DAW», «1 de
   ...») — if you change a fixture, grep `docs/manual/` for its name.
9. Two more traps already hit: the Stimulus dropdown closes on **any scroll**, so scroll the card
   into view *before* clicking its menu (`capture-gestion`), and a click right after load can hit a
   Live Component/Stimulus controller that has not connected yet — wait a moment first. The
   cheat-sheet images (`capture-cheatsheet-shots.mjs`) and the two slide images are copies/outputs of
   the same data: regenerate them with the rest (README of the manual, "Regenerar las capturas").
10. A change that affects screens also updates `docs/slides/atica-calidad.md` (see `release.md`).
