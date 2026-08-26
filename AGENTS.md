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
