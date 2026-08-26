# ÁTICA Calidad — frontend

*Read this file before touching any Twig template, Stimulus controller
(`assets/controllers/`), Live Component, or adding/editing an SVG icon or any
asset.*

No external bundler: native Symfony **Asset Mapper**. Stimulus via
`assets/controllers/*.js` + `assets/controllers.json`. Tailwind via
`symfonycasts/tailwind-bundle`. Interactive components with **Symfony UX Live
Components** (`src/Twig/Components/`), not a separate SPA.

## Asset Mapper: the `public/assets/` cache can go stale

In environments where the app is already running (e.g. a disposable database and
server spun up to verify something), `public/assets/` may hold a **stale,
precompiled** build. After adding or editing any asset (a Stimulus controller,
JS, etc.), run:

```bash
php bin/console asset-map:compile --memory-limit=1G
```

If you see an error about a half-rebuilt cache (e.g. messages about static
analysis library classes "not found"), don't read it as a missing dependency —
it's likely exactly this caching issue.

## Autocomplete dropdowns (Tom Select / `symfony/ux-autocomplete`)

Always use `dropdownParent: "body"` in the Tom Select configuration — otherwise
the dropdown gets visually clipped inside cards with `overflow-hidden`. Search
requires a minimum of 2 characters. The autocompleters under `src/Autocomplete/`
(`TeacherAutocompleter`, `TeacherCentreAutocompleter`) need a centre selected in
session (`TenantContext`) to resolve meaningful results.

## Live Components: `checked` state on radios/checkboxes

On selection radios or checkboxes inside a Live Component, always use
`data-model` on the input. **Don't** rely on the
`data-action="live#action" data-live-id-param="..."` pattern alone to keep the
checked state in sync — it desyncs (the visual `checked` doesn't always reflect
the component's real state). `data-model` is the only reliable pattern here.

## Forms with `submit`: watch out for microtasks

If a `submit` event listener uses `queueMicrotask()`, that microtask can fire
**before** another listener on the same `submit` event gets a chance to run
(microtasks interleave between listeners, not just at the end of the event cycle).
If you need to guarantee your code runs after all `submit` listeners have
finished, use `setTimeout(0)`, not `queueMicrotask()`.

## Icons

SVGs under `assets/icons/heroicons/` are **not copied by hand**. Before committing
a new or modified icon, regenerate it with:

```bash
php bin/console ux:icons:import
```

Manually copying the SVG without going through this command produces
inconsistencies with the rest of the set (names, registered paths).

## Radio vs. checkbox for a binary scope choice

For a binary choice that has its own "name" on each side (e.g. general vs.
group-restricted school event), use a pair of radio cards (see the school event
form, `templates/school_event/new.html.twig`) rather than a plain checkbox — it
reads more clearly when both states carry explicit meaning, instead of one state
simply being "switch an option on".

## Non-working days in date pickers

There's no date-picker library: everything is a native `<input type="date">`,
which doesn't allow disabling individual days in the browser's native dropdown.
The adopted pattern: when a non-working date is picked (weekend or a holiday
declared in `NonWorkingDay`), the Stimulus controller reverts to the last valid
value with a warning, and the server **always** validates authoritatively on
submit (via `NonWorkingDayChecker`) — never rely on client-side validation alone
for this. Shared logic lives in `assets/utils/non_working_days.js`
(`isNonWorkingDate`/`countSchoolDays`/`addSchoolDays`), consumed by
`non_working_day_controller.js`.
