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

**Lists** (Responsabilidades › Catálogo del centro, `06-responsabilidades.md`)

| Fixture | Shows |
|---|---|
| `Departamento` (9), `Grupo` (15), `Materia` (14) | Flat lists, one subprofile per element |
| **`Enseñanza`** — ESO / Bachillerato / Formación Profesional → DAM, DAW → courses (16 elements, 3 levels) | The only nested list: intermediate nodes, profile on a non-root element, inheritance |
| `Enseñanza › ESO › Programa de Mejora (extinguido)` | An **inactive** element (eye-slash; its subprofile is inactive too) |
| `Enseñanza › Formación Profesional` associated to *Departamento › Informática* | An association **inherited** by the whole branch (pale link icon); `Enseñanza` is associated with the lists «Departamento» and «Grupo» |
| `Enseñanza › 2º DAW` associated to *Grupo › 2º DAW* | An **own** association that **adds to** the inherited one (it has both: own in the selector and as a chip in the tree, inherited in grey below) |
| `Materia › Tecnología` associated to two departments (Tecnología + Informática) | **Several** associations on one element |
| **`Evaluación`** (flat: 1ª, 2ª, Ord., Extr., Final 1, Final 2), associated with `Materia` (`associateEvaluaciones()`): ESO/Bachillerato subjects → 1ª, 2ª, Ord., Extr.; the five FP modules → 1ª, 2ª, Final 1, Final 2; **Educación Física and FFEOE → none** | **Associated lists**: `Materia` declares «Departamento» and «Evaluación» (its «Listas asociadas» card), the picker grouped by list, the counter «2 sin Evaluación» and its filter. Phase 0e2 builds the deliveries per subject and evaluation on it |
| `Materia` teachers (`associateMateriaTeachers()`): Programación → Noelia Bravo (`n.bravo`); Bases de Datos → Noelia Bravo + Marcos Pardo (`m.pardo`, a split group); Desarrollo Web en Entorno Cliente → Marcos Pardo; Desarrollo Web en Entorno Servidor → Rocío Serrano (`r.serrano`); **Sistemas Informáticos → nobody**; FFEOE → Ricardo Fuentes (`r.fuentes`, **out of the course**) | The catalogue's **Docentes** (panel, tree badge, «Sin docente», the out-of-course teacher in red with «Quitar docentes fuera de curso (1)»). They change nothing in an activity until its box is checked |

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
| `seneca-materias.csv` (new roots «Materias por grupo» and, by subject, «Materias por materia») | A two-level tree, all "se añadirán"; its «Profesor/a» column: 8 teachers of the course found, «Gómez Pérez, Ana» not found (nobody has that name), «Fuentes Mora, Ricardo» not in the course (`r.fuentes`) |
| `seneca-docentes.csv` | register (3), add existing user (`r.fuentes`), fill e-mail (2), unchanged (7) |

## Screenshot matrix

| Script | Images (`docs/manual/img/`) | Chapter | Mutates the DB? |
|---|---|---|---|
| `capture-acceso-shots.mjs` | `acceso-login`, `acceso-seleccion-centro`, `acceso-cambiar-centro` | `index.md`, `05` | no |
| `capture-centro-shots.mjs` | `centro-inicio`, `-cursos`, `-docentes`, `-docentes-importar`, `-docentes-previsualizacion`, `-docentes-retirar`, `-perfiles`, `-dias-no-lectivos`, `-registro-avisos`, `-ajustes` | `02`, `05` | no |
| `capture-informes-shots.mjs` | `informes-indice`, `-cumplimiento`, `informes-pdf-*` (first PDF page via `pdftoppm`) | `04` | no |
| `capture-plataforma-shots.mjs` | `plataforma-panel`, `-centros`, `-centro-editar`, `-docentes`, `-docente-editar`, `-suplantacion`, `plataforma-ajustes-*` (one per settings category), `plataforma-mantenimiento-{aviso,pagina,login}` | `10` | **yes, at the end**: turns maintenance mode on and puts it back off |
| `capture-copias-shots.mjs` | `plataforma-copias`, `plataforma-copias-restaurar`, `plataforma-copias-restaurando` (the panel image `plataforma-panel` shows its card too) | `10` | **yes**: saves 5 backups in a throwaway folder and writes `var/restore/` by hand (removed at the end). Needs `APP_BACKUP_DIR` = `SHOTS_BACKUP_DIR` (an empty, disposable folder) set on the server **and** on the script, and the same `DATABASE_URL` env as the server (it runs `app:backup --password`). Never confirms a restore |
| `capture-evaluaciones-shots.mjs` | `actividades-una-entrega-por-tarjeta` (the card of «Revisión de la programación»: «Plazos de cada «Evaluación»», «2ª: 0/13», the periods, the 2ª open «En curso»), `actividades-una-entrega-por-formulario` (its form: «Una entrega por» with «Evaluación», «Según cada elemento», «Marca los plazos», the lock and the table of dates) `actividades-una-entrega-por-tareas` («Tus próximos pasos» of `n.bravo`: only the 2ª), `actividades-una-entrega-por-copia` (her draft of «Programación · 2ª», «Se ha rellenado con lo que se entregó en «1ª»») and `actividades-una-entrega-por-informe` (the table «Revisión de las materias» of the report of Informática for the 1ª, as `c.núñez`: «solo las de «1ª»», her six subjects, two delivered). Needs the `sqlite3` CLI and `SHOTS_DB` (it reads the ids of the pages it opens). Needs **`app:load-demo-module-programs` and then `php bin/console app:load-demo-evaluations`** after `app:load-demo-data` (an optional command, kept apart so the other chapters' activities do not change): «Revisión de la programación» (Materia × Evaluación, the evaluation marking the dates, delivered by filling the model «Revisión de la programación» in, teachers delivering, auto-complete; the 1ª — worked out from today, always over — delivered in Programación and Bases de Datos by `n.bravo` and Matemáticas by `p.sánchez`; the 2ª — always open — with a draft of Programación), «Informe de evaluación del departamento» (Materia gathered by head of department × Evaluación: Educación Física has none; filled with the model «Informe de evaluación del departamento», whose table «Revisión de las materias» reads the review — the compliance and an own assessment column) and «Acta trimestral del departamento» (no element × the new list «Trimestre», free). Read-only | `08` | no |
| `capture-responsabilidades-shots.mjs` | `responsabilidades-*` (lists, tree, mobile, element panels, the list's «Listas asociadas» card `responsabilidades-listas-asociadas`, the filter «2 sin Evaluación» `responsabilidades-sin-evaluacion`, «Marcar por nombre» `responsabilidades-marcar-por-nombre`, move, profiles, assign, picker, Séneca previews) | `06` | **yes**, at the end (Séneca setup) |
| `capture-modelos-shots.mjs` | the fillable-models chapter's images (`modelos-*`): the models list, the editor choosing a list as the source of options, the settings of a field «Adjuntos» (`modelos-editor-adjuntos`), the form filled in, its section 3 with the rating marked and two files attached (`modelos-escala-adjuntos`; it uploads `scripts/fixtures/pizarra-reunion.png` and `acta-coordinacion.pdf`), the delivered document and its annexes (`modelos-anexos`), the rows and copies taken from lists (`modelos-editor-filas-lista`, `modelos-editor-repetir-lista`, `modelos-filas-lista`, `modelos-copias-lista`), the formula editor (`modelos-editor-formula`, `modelos-editor-funciones`: it adds two columns to the draft's «Resultados por materia», writes the percentage with the «Calcular %» assistant and a slip «+ suma(2)», then opens the list of functions — only the draft changes, the activity fills the revision in force), the data explorer, the indicator condition picker, and the delete / move confirmations of Responsabilidades that count the delivered documents. Needs **`php bin/console app:load-demo-form-models` after `app:load-demo-data`** (an optional command, kept apart so the counts quoted by the other chapters do not change: it adds the model «Memoria de departamento» in force (five sections; the third, «Satisfacción y evidencias», has a rating scale and a field «Adjuntos»; the fourth a fixed table with a row per item of «Materia»; the fifth is repeated once per course of «Enseñanza › Formación Profesional» — no association is added: the other chapters show them), the profile «Responsable de memorias» (teacher `calidad`), the folders «Modelos rellenables» / «Memorias de departamento» and the activity «Memoria final de departamento») and the `sqlite3` CLI | `12` | **yes**: it fills in and delivers the form as `calidad` (run it after the read-only scripts and before `gestion`; rebuild the database to run it again) |
| `capture-docentes-shots.mjs` | `responsabilidades-docentes-panel` (the panel of «Bases de Datos»: two teachers), `responsabilidades-docentes-arbol` (the «Materia» tree with the badges, «Sin docente» and «Quitar docentes fuera de curso (1)»), `actividades-docentes-casilla` (the form of the activity with the box checked) and `actividades-docentes-entregas` (the «Entregas que gestionas» of `c.núñez`, with the teachers under each name and «Sin docente asociado»). Needs **`php bin/console app:load-demo-module-programs` after `app:load-demo-data`** (an optional command, kept apart like `app:load-demo-form-models` so the models and activities the other chapters show do not change; **run it after `capture-modelos-shots.mjs`**, whose models list would otherwise show a second model). It adds the folder «Programaciones de los módulos» (in 8.1, next to «Programaciones didácticas»; the heads of department upload), the model «Programación didáctica del módulo» (chain: «Jefatura del departamento», limited to the element, then «Jefatura de estudios»; the folder has no review profile of its own) and the activity «Entrega de programaciones de los módulos» (element `Materia`, box checked), with «Programación» **delivered by `n.bravo`** (waiting for its first step, in the queue of `c.núñez` and not of `p.sánchez`), «Sistemas Informáticos» **delivered by `c.núñez`** (her step approved by delivering, waiting for `g.ortiz`, Jefatura de Estudios) and «Desarrollo Web en Entorno Servidor» **a draft of `r.serrano`**; the FFEOE is still to do, `c.núñez`'s own. Read-only (it only logs in as `direccion`, `n.bravo` and `c.núñez`). The script also re-takes nothing else: the tool bar of every list screen gained the «Sin docente» button, so a change to the tree screens means re-running `capture-responsabilidades-shots.mjs` | `06`, `08` | no |
| `capture-actividades-shots.mjs`, `-arbol-`, `-calendar-`, `-mejora-` | the older chapters' images (`calendar` also makes `calendario-no-lectivos` and `calendario-dia-no-lectivo`; `arbol` also makes `arbol-busqueda-contenido`, the ⌘K palette finding «tutoría acogida» inside the demo PDFs: run `app:index-documents` after loading the demo data, because no worker reads their text) | `07`, `08`, `03`, `09` | no (`calendar` writes the feed token) |
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
   php -d memory_limit=1G bin/console app:index-documents   # the demo files are real PDFs (LoadDemoDataCommand::demoPdf()); their text is what the search finds
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
