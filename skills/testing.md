# ÁTICA Calidad — tests and verification

*Read this file before writing or debugging any test, or before considering a task
that touches production code done.*

## Commands

```bash
php vendor/bin/phpstan analyse --memory-limit=1G   # max level over src/; must report 0 errors
php bin/phpunit                                     # or: make test
```

Run both before considering any task done. The project requires PHPStan level
`max` and the PHPUnit suite green **regardless of the day the tests happen to run
on** (see next section).

## Use Clock, never `new \DateTimeImmutable()` for "now"/"today"

Explicit project preference: inject `Symfony\Component\Clock\ClockInterface` (the
`clock` service, autowireable) into container-managed classes, and use
`$this->clock->now()`. In entities (not instantiated by the container), use the
free function `Symfony\Component\Clock\now()`.

- **Don't** use `new \DateTimeImmutable()`, `new \DateTimeImmutable('today')`, or
  relative modifiers (`'+1 hour'`, etc.) to represent the current instant. It's
  still fine to use `\DateTimeImmutable` to **parse user-supplied dates** (forms,
  imports) — that's not "now", it's an input value.
- In tests, mock time with
  `Symfony\Component\Clock\Test\ClockSensitiveTrait::mockTime('2024-01-10')`
  instead of depending on the real execution date. Any test helper that computes
  "today" must read from the same mocked clock (`Clock::get()->now()`), not
  `new \DateTimeImmutable()` — otherwise the helper and the code under test
  diverge as soon as one respects the mock and the other doesn't.
- In Unit tests that instantiate a service manually (`new XxxService(...)` instead
  of via the container), pass `new \Symfony\Component\Clock\MockClock()` as a
  constructor argument — there's no DI container to resolve it for you.

## Test structure

- `tests/Integration/` — tests that boot the kernel (`ControllerTestCase`,
  `WebTestCase`), hit the real database via Doctrine, and cover
  Controller/Command/Component/EventSubscriber/MessageHandler/Repository/Security.
- `tests/Unit/` — pure tests without a kernel, with manual stubs/mocks. Doesn't
  exist yet in this repo — create it under `tests/Unit/` the same way, mirroring
  `src/`, when the first pure unit test is needed.

There are no actual test cases in the repository yet, only the shared harness
(`ControllerTestCase`, `RepositoryTestCase`) inherited from the base scaffold —
this project is at an early stage.

### `ControllerTestCase` (`tests/Integration/ControllerTestCase.php`)

Common base for integration tests:

- `setUp()` creates the client and **disables kernel reboot**
  (`$this->client->disableReboot()`) — necessary because with SQLite `:memory:`
  every kernel reboot opens a fresh connection (and an empty database); without
  this the schema created in `setUp()` wouldn't survive across requests within a
  single test.
- Creates the full schema with `SchemaTool::createSchema()` and drops it in
  `tearDown()` with `dropSchema()` — every test starts from a clean database. This
  is slow but deliberate: always use this base class for controller tests instead
  of building the schema by hand.
- Seeds default settings (`seedDefaultSettings()`) so that pages reading
  `AppSettingsInterface` don't fail for lack of a definition.

## Known gotchas

- **`EXTRA_LAZY` + `contains()` after `$em->clear()`**: on a Doctrine `EXTRA_LAZY`
  collection, calling `contains()` after `$em->clear()` may not reflect the
  expected state, because the collection doesn't reinitialize the same way a
  regular collection does — if a test fails unintuitively after a `clear()`
  followed by a `contains()`, suspect this before assuming a business-logic bug.
- **Double `MessageEvent` (queued + sent) when testing the async mailer**: Symfony
  Messenger dispatches email asynchronously; tests that capture mailer events may
  see the event both in "queued" and "sent" state for the same send. If your
  assertion counts events, account for both, don't assume just one.
- **Unit tests that instantiate services with `new`**: if you add a new parameter
  to a service's constructor (e.g. `ClockInterface $clock`), grep for every
  `new ServiceName(` under `tests/Unit/` — there's no DI to catch the mismatch for
  you, and PHPStan doesn't always flag it obviously; the failure shows up at
  PHPUnit runtime.
