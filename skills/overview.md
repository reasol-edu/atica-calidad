# ÁTICA Calidad — general orientation

*Read this file when starting any task in this repository, before touching code,
to understand where the change fits and which other `skills/` file to consult
depending on the kind of task.*

Web application (Symfony 8.1 / PHP 8.4+) for document management supporting a
school's **quality management system (QMS)**: documentation, deadlines and
responsible roles, at secondary school, high school, and vocational training
centres. It is **multi-tenant**: a single server hosts several centres with
completely separate data.

The project is at an early, deliberately minimal stage: it was scaffolded from a
sibling project (GestConv+, a school coexistence-management app) reusing its
generic infrastructure (auth, multi-tenant model, settings engine, PDF rendering)
while stripping everything specific to that other domain. Most sections are thin
skeletons today (e.g. **Informes** is an empty placeholder) — expect to build
actual QMS features on top of this base rather than finding them already done.

Depending on the area you're touching, also consult:

- Migrations, repositories, data model → [`database.md`](database.md)
- Tests, PHPStan, PHPUnit → [`testing.md`](testing.md)
- Stimulus, Live Components, Tom Select, icons, Tailwind → [`frontend.md`](frontend.md)
- Translations → [`i18n.md`](i18n.md)
- Commits, CHANGELOG, releases, documentation generation → [`release.md`](release.md)

## Stack

- **Backend**: Symfony 8.1, PHP 8.4+, Doctrine ORM (PostgreSQL / MySQL·MariaDB / SQLite),
  Symfony Messenger (async queues), Symfony Clock, Symfony Security (voters).
- **Frontend**: Symfony UX (Live Components, Autocomplete, Icons) + Stimulus, Tailwind CSS
  via `symfonycasts/tailwind-bundle`, native Asset Mapper (no external bundler).
- **PDF**: mPDF, with per-centre configurable header/footer templates — infrastructure
  is in place, but no report actually renders one yet (see `Informes`).
- **Deployment**: FrankenPHP (Docker or self-contained native binary).
- PSR-4 autoload: `App\` → `src/`, `App\Tests\` → `tests/`.

## Multi-tenant model and academic year

- `App\Service\TenantContext` (`src/Service/TenantContext.php`) resolves the
  **currently selected educational centre** (stored in session) and the
  **academic year being viewed** (`getViewYear()`, which can differ from the
  centre's active year — a "historical lookup" mode). It memoizes the resolved
  centre per request; don't assume `getSelectedCentre()` hits the database every
  time.
- The `#[CurrentCentre]` attribute (`src/Attribute/CurrentCentre.php`) +
  `CurrentCentreResolver` (`src/ValueResolver/`) injects the current centre as a
  controller argument; throws `NoCentreSelectedException` if there's no centre in
  session.
- Almost the entire domain model hangs off `EducationalCentre` and, within it,
  `AcademicYear`. When writing a new query, explicitly decide whether it should
  filter by academic year or not (see `database.md`).
- Entity IDs are `Uuid` (v7), not auto-incrementing integers.

## Domain glossary

| Term | What it is |
|---|---|
| Centre | `EducationalCentre` — the top-level tenant; a school |
| Academic year | `AcademicYear` — a yearly period of a centre; always one **active** per centre |
| Course | `Course` — an educational level within an academic year (e.g. "1º ESO") |
| Group | `Group` — a class group within a course, with teachers (by subject) and tutors |
| Quality manager | Profile (`EducationalCentre::qualityManagers`) coordinating a centre's QMS |
| Internal auditor | Profile (`EducationalCentre::internalAuditors`) performing internal QMS audits |
| School event | `SchoolEvent` — a general or group-restricted event shown on the calendar |
| Non-working day | A declared holiday (`NonWorkingDay`) or weekend; see `NonWorkingDayChecker` |
| Setting | `SettingDefinition` + a value at global/centre/teacher scope, with lock inheritance |

## Map of `src/`

| Directory | Contents |
|---|---|
| `Controller/` | One controller per functional area (calendar, reports, admin…) |
| `Entity/` | Doctrine model |
| `Repository/` | Repositories with named methods (see `database.md`, PHPStan rule) |
| `Service/` | Business logic not tied to HTTP (builders, settings resolution, PDF…) |
| `Twig/Components/` | Live Components (calendar, live-filtered listings, settings) |
| `Security/Voter/` | Per-entity authorization voters (EducationalCentre…) |
| `MessageHandler/` / `Message/` | Symfony Messenger handlers (currently: email log purge) |
| `EventSubscriber/` | Subscribers (tenant context, forced password change) |
| `Command/` | Console commands (`app:setup`, `app:create-admin`, `app:create-educational-centre`) |
| `Autocomplete/` | `symfony/ux-autocomplete` autocompleters |
| `Attribute/` + `ValueResolver/` | `#[CurrentCentre]` and its resolver |
| `Doctrine/` | DBAL middleware (SQLite pragmas) |
| `PHPStan/Rules/` | Project-specific PHPStan rules |

`templates/` is organized in parallel by area (`calendar/`, `admin/`, `email/`,
`pdf/`…). `config/packages/*.yaml` follows the standard Symfony Flex convention.
Note that the application's **UI text and translation files are in Spanish** —
that reflects the project's target audience and isn't something to change; see
`i18n.md`.

## Common commands

```bash
make test        # php bin/phpunit
make migrate      # doctrine:migrations:migrate
make setup        # app:setup (creates admin, default settings)
php vendor/bin/phpstan analyse --memory-limit=1G   # max level
```

`CONTRIBUTING.md` defines the commit message format (allowed types and scopes) —
see `release.md` before committing.
