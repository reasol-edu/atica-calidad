# Instructions for code agents

This file orients any code agent (Claude Code, Codex, Cursor, Copilot, or other)
working on **ÁTICA Calidad**, a multi-tenant Symfony 8.1 / PHP 8.4+ web
application for document management supporting a school's quality management
system (QMS), across educational centres.

**Before making any change, read [`skills/README.md`](skills/README.md)** and the
`skills/` file for the area you're about to touch (database, tests, frontend,
translations, or commits/release). Those files document project conventions and
several already-debugged gotchas that aren't derivable just by reading the code —
repeating them costs real time.

Quick summary (full detail in `skills/overview.md`):

- Backend: Symfony 8.1, Doctrine ORM (PostgreSQL/MySQL/SQLite), PHPStan level `max`.
- Frontend: Symfony UX (Live Components, Stimulus), Tailwind, Asset Mapper — no external bundler.
- Verification before considering a task done:
  ```bash
  php vendor/bin/phpstan analyse --memory-limit=1G
  php bin/phpunit   # or: make test
  ```
- Commit message format and allowed scopes: `CONTRIBUTING.md` (summarized in `skills/release.md`).
- The application's UI and commit messages are in **Spanish** (the project's
  target users and contributors) — this is deliberate, not something to "fix".

## Demo data and manual screenshots

`php bin/console app:load-demo-data` builds the demo centre («IES Ada Lovelace») that **every
screenshot of the manual is taken from** — the manual's captions name what the fixtures contain. Each
piece of the demo data exists to make one concept visible (an inherited association, an unassigned
subprofile, a failed e-mail, a teacher who left the course...). **Before changing the command, or
adding a screenshot, read [`skills/screenshots.md`](skills/screenshots.md)**: it catalogues the
accounts and fixtures, maps each script to its images and chapter, and has the runbook (build the
throwaway database from the *migrations*, serve it with `-t public`, run order, mutating scripts).
Reusable accounts: `admin` (platform), `direccion` (management), `calidad` (quality manager),
`multicentro` (two centres), the rank-and-file teachers (password `prueba`).

## Project stage

This is an early, deliberately minimal scaffold, reused and adapted from a
sibling project (GestConv+) rather than built from scratch. Most sections are
thin skeletons today — expect to build actual QMS features on top of this base,
not to find them already implemented. If something seems unfinished or empty
(e.g. `Informes`), that's expected, not a bug to silently "fix" by inventing
content.

Other reference files in the repo root: `CONTRIBUTING.md` (commits, issues),
`CHANGELOG.md` (change history), `docs/manual/` (end-user documentation, not
development documentation).
