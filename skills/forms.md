# ÁTICA Calidad — document models and online forms

*Read this file before touching anything under `src/Service/Form/`, `FormModel*`/`FormSubmission*`, `templates/form/`,
`assets/controllers/form_*`, or the activity's "Cómo se entrega" field.*

A **model** ("modelo") is a document of the tree whose content is a *definition*: a tree of numbered sections with texts, typed
fields and tables. An activity can be delivered by **filling a model in online** instead of uploading a file. The full
specification (phases, decisions, annex with the real centre forms) is the Claude Docs document "Modelos de documentos rellenables";
this file records what the code does and the traps already hit. Phase 1 is implemented (see the phases at the end).

## How it fits the rest of the application

- A model is a `Document` + `FormModel` (1:1). It has **no revision until the first one is sent to review**. Each revision sent is a
  normal `DocumentRevision` — pending when the folder has review profiles, approved with the usual screens — whose **file is the
  blank PDF** (so the tree, downloads, ZIPs, search and backups keep working) and whose frozen definition is its
  `FormModelRevision` (1:1, immutable). The editor works on `FormModel::draftDefinition`.
- A filled document is **not a Document while it is a draft**: `FormSubmission` is keyed by (activity, slot key, cycle year), holds
  `draftData`, and only **delivering** creates the `Document` (named and owned by the slot, exactly as an uploaded file would be, so
  `ActivitySubmissionSlotBuilder::resolveSlot()` finds it) or its next revision. The PDF is the revision's file; the data is frozen in
  a `FormSubmissionRevision`. A half-filled draft therefore never counts as delivered and never shows in the tree.
- `Activity::$submissionMode` (`file`|`form`) + `$formModel`. `Activity::isForm()` is what the templates ask. One mode per activity.
- Who may fill a slot = who may upload to it (`FormSlotAccess`); the activity's start/end window applies. Models are created and
  edited by `QualityVoter::MANAGE`; approving a revision is `FolderVoter::REVIEW` on the model's folder.

## Definition and data (JSON)

Shapes are documented in `FormDefinition` (definition) and `FormDataNormalizer` (data). **Invariant: every section, block, column, fixed
row and option has a UUID `key` that never changes between revisions of a model** — data, links, indicators and the update of a
document to a newer revision all hang on keys, never on position or title. The editor creates the keys; `FormDefinitionNormalizer`
replaces missing or repeated ones. Never reuse the key of a deleted element.

Everything the browser sends is cleaned on the server (`FormDefinitionNormalizer`, `FormDataNormalizer`): unknown properties dropped,
types coerced, texts sanitized with `app.rich_text`. `FormDataValidator` says what blocks delivery; a draft can always be saved.
`FormResolver` turns definition + data into the numbered, formatted tree that the web view (`templates/form/_document.html.twig`) and
the PDF (`templates/pdf/form_document.html.twig`) draw — keep the two templates in step.

## Repetition, conditions and references (phase 2)

- A section can **repeat** (`section.repeat`): by the rows of a table (`{mode: table}`, one copy per row, **keyed by the row's own key** so
  a copy follows its row when rows are reordered and goes with it when the row is deleted), by a number field (`{mode: field}`, capped at
  `FormDataNormalizer::MAX_COPIES`) or by hand (`{mode: manual, min, max}`). Data is **scoped**: each copy is a scope in
  `scope.copies[sectionKey]` with its own fields/texts/tables/copies; a section that does not repeat uses its parent's scope; copies nest.
  `FormWalker` is the one walk over definition + data (instances with their scope chain, visibility and copy info) that the resolver and the
  validator share. `numberCopies` lets the model number copies (2.1, 2.2…) or leave them with their title only.
- **Conditions** (`visibleIf: {field, op, value}`, on sections and blocks; `FormConditions`): hidden parts are not shown, validated or
  printed, and what was typed in them is kept. A broken condition holds (shows).
- **References** `{{label|ref}}` in titles, labels and texts (what follows the last `|` counts, so a rename of the label is harmless):
  `copy.number`, `copy.total`, `row:<column key>`, `f:<field key>`, `ctx:<centre|year|activity|slot|date>`. Resolved by `FormResolver`.
- **The rules live twice**: PHP (`FormDataNormalizer`, `FormWalker`, `FormConditions`, `FormResolver`) and JavaScript
  (`assets/utils/form_scopes.js`), because the page reacts while typing (a new row creates its section, a title follows the unit's name).
  The server cleans and validates everything again on save, so a drift only shows as the page and the saved document disagreeing: after
  changing one side, change the other and drive a model with repetition in a real browser.
- Not done yet in this phase: the "list of the centre" and "another document" sources of repetition (need the catalogues of phase 6 and the
  pre-fill of phase 5) and a minimum of manual copies as a delivery problem (it only applies when the document is created).

## Calculations, rules, relations and double-entry tables (phase 3)

- **Expression language** (`FormExpression`, closed grammar, no `eval`): numbers, texts, `+ - * /`, comparisons, `&& || !`, and
  `sum avg min max count count_if pct_if if round abs empty`, arguments separated by `;`. References are `[f:KEY]` (field), `[t:TABLE.COL]`
  (column, a list), `[r:COL]` (cell of the row being calculated), `[o:COL]` (column of the row a copy comes from), `[c:SECTION.f:KEY]` /
  `[c:SECTION.t:TABLE.COL]` (across the copies of a repeating section) and `[m:MATRIX]` `.rows` `.cols` (cells, row sums, column sums of a
  double-entry table). Empty is contagious in arithmetic and false in a comparison. **It is implemented twice**, PHP
  (`FormExpression`) and JavaScript (`assets/utils/form_expression.js`), against one shared set of cases,
  `tests/Fixtures/form-expressions.json`: add a case there when you change either, and run both (`php bin/phpunit tests/Unit/Service/Form`
  and `node --test tests/js/form-expression.test.mjs tests/js/form-scopes.test.mjs` — pass the files, not the directory).
- **Calculated fields and columns** (`fieldType: calculated`, `formula`, `decimals`, `unit`): never typed (the normalizer drops what the
  browser sends), filled by `FormCalculator` after the data is cleaned, in up to four passes so a formula can read another calculated
  value; a cycle is a model problem. The result is stored with the data, so it is frozen with each delivered revision. A column can show a
  **total at its foot** (`total`: sum, avg, min, max, count), display-only.
- **Consistency rules** (`definition.rules`: `{expr, message, severity: warning|block}`): evaluated against the whole document by
  `FormDataValidator`; a broken rule blocks the delivery or only warns, as the model says; a rule that cannot be decided is not broken.
  The save response carries `problems` (blocking) and `warnings`.
- **Relation columns** (`fieldType: relation`, `relTable`, `relLabel`, `multiple`) pick rows of another table of the document and keep only rows
  that still exist. **Double-entry tables** (`mode: matrix`, `rowTable`/`rowLabel`/`colTable`/`colLabel`, one kind of `cell`) have a row per row
  of one table and a cell per row of another, keyed by those rows' keys.
- The names of the rows and columns of a matrix and the options of a relation follow what is typed **without redrawing the form**: a redraw
  when a field loses focus swallows the click on the next button (the mousedown removes the element the mouseup lands on).
- Not done: rules inside a copy (they see the whole document, aggregating with `[c:…]`), and calculated values deciding how many copies there are.

## Approval chain, comments, comparison, edit lock and history (phase 4)

- **Approval chain** (`definition.approval`: ordered `{name, profile}` steps, profile = a `SpecificProfile` id of the centre). Delivering with a
  chain creates `FormApprovalStep`s (first `pending`, the rest `waiting`) and forces the revision to be pending whatever the folder asks.
  `FormApprovalChain::decide()` decides the current step (whoever holds its profile, or manages the folder); the last approval approves the
  revision, a rejection at any step rejects it and cancels the rest — the next delivery starts again from step 1. **`DocumentRevisionReviewer` is
  the only entry point** (the tree, the review queue and the bulk review all end in it): it routes a chain revision to the chain, so no screen
  can approve around it. A step without profile (or with a profile that does not exist in the centre) blocks sending the model to review
  (`FormModelService::problems()`); a chain imported from another centre therefore arrives unassigned.
- The page of a delivered document is `FormReviewController` (`/formularios/revision/{id}`): steps with who decided and their note, the decision
  form (a rejection needs a reason), comments anchored to a section or block (`FormComment`, by key + copy path; whoever reviews or holds a step
  or delivers the slot may comment, anyone who can see the folder reads), and links to compare with earlier revisions (`FormDiff`: value by value
  by identity keys, so it lines up across revisions of the model). The review queue lists the steps waiting for each person.
- **Edit lock: one person at a time per DRAFT, not per section** (deviation from the first spec, which wanted a lock per top-level section: the
  save protocol sends the whole draft, and merging by section needs a protocol change that belongs with the offline mode of phase 7). Opening a
  free draft takes the lock (5 minutes, renewed by saving and by a 60-second heartbeat); somebody else sees the form read-only with who has it
  and a "take control" button; the previous holder gets a 409 on their next save/heartbeat and stops. Delivering also needs the lock.
- **History** (`FormFieldChange`): every save writes one change per value that differs (identity: block, copy path, row, column; calculated
  values are not anybody's change); the same person changing the same value within two minutes amends the last change, so typing a sentence is
  one entry. Fields and texts show "Historial" with "Restaurar" (it puts the old value back as a normal edit). Ordering uses the UUID v7 id as a
  tie-break: `changedAt` has one-second resolution.
- Not done: e-mail to the people of the next step (they find the step in their review queue), and anchored comments by cell of a table.

## Updating, pre-filling and drafts for everybody (phase 5)

- **Policy of a revision**: whoever sends a model revision to review picks `FormUpdatePolicy` (optional | mandatory + optional date) — stored on
  `FormModelRevision`. The editor shows beforehand how many drafts / delivered documents of the CURRENT academic year are on earlier revisions
  (`FormUpdateService::impact`).
- **Update** (`FormUpdateService`): a submission is "behind" when the model's revision in force has a higher version than the one it is on
  (the draft's, else the last delivered's). `update()` moves a draft in place, or starts a draft from a delivered document, normalizing the data
  against the new definition: values travel by the identity KEY, so what the new revision still has is kept; `lostOnUpdate()` lists (labels)
  what has no place any more and the page asks for confirmation. A mandatory revision whose date has come makes `deliver()` fail with
  `error.update_required`; before the date it is only a warning.
- **Pre-filling** (`FormPrefiller`): a new draft starts from `Activity::formPrefillSource` (latest delivered of the SAME slot key there) or,
  failing that, from the same slot's latest earlier cycle. Same model → by key; different models → by the field's internal `name` and the
  same `fieldType` only (a number never goes into a text field). `FormSubmission::prefilledFrom` remembers the origin; the page offers "Volver
  a copiar" and "Empezar desde cero". Not done: choosing an arbitrary document as source per draft (the source is set on the activity), and the
  "origin changed" warning for pre-fills.
- **Drafts for everybody**: `Activity::formDraftsForAll` + `bin/console app:create-form-drafts` (run daily from cron; idempotent) creates the
  missing draft of every slot once the period has started. Nothing creates them lazily: without the cron they appear when each person opens.

## Front end

The editor and the form are **Stimulus controllers that build the page from the JSON** (`form_model_editor_controller.js`,
`form_fill_controller.js`, helpers in `assets/utils/form_dom.js`), not Live Components: they hold a lot of client state (nesting,
tables, offline later) and a Live round trip per keystroke would not do. They save the whole draft, debounced, with the version they
started from (`draftVersion`); a 409 means another tab saved in between. Texts use Quill, mounted on click (a page of editors is slow).

## Traps already hit

- **`this.data` and `this.context` are reserved by Stimulus** (getters on `Controller`): assigning them breaks the controller on connect
  (the second one with a confusing `Cannot read properties of undefined (reading 'data')`). Use other names (`this.state`, `this.refContext`).
- **PHP writes an empty map as `[]`** and a JS array loses string keys in `JSON.stringify`: normalise every map coming from the server
  to a plain object (`asMap()` in the fill controller).
- **The PDF response's `Content-Disposition` cannot carry accents**: `FormPdfBuilder` passes a fixed ASCII filename; only the content is
  used.
- `Folder` has no `educationalCentre` column: go through `documentSection` in DQL (the centre is the section's).
- A debounced save must retry when a change arrives while another save is in flight, not drop it.

## Verifying

Unit tests cover the engine (`tests/Unit/Service/Form/FormEngineTest.php`); `tests/Integration/Controller/FormFlowTest.php` covers the
whole path through HTTP. They do **not** run the JavaScript: after changing a controller, drive it in a real browser with the
disposable database of `skills/screenshots.md` (create a model, edit, send, link an activity, fill, deliver, check 390 px for overflow)
and read the screenshots. Migrations: all four engines with Docker, as in `skills/database.md`.

## Phases

1. **Done** — models as documents (draft, revision, approval of one step), sections with numbering, fixed/starter/fill texts, typed
   fields, free and fixed tables, activity mode "fill", autosave, delivery with PDF, web view, correction as next revision.
2. **Done** — repetition (by table rows, by number, by hand, nested), references, conditions, progress, per-field help (the sources "list of
   the centre" and "another document" wait for phases 5–6). 3. **Done** — calculations, totals, consistency rules, relation columns, double-entry tables. 4. **Done** — multi-step approval chain, anchored comments, revision comparison, edit lock (per draft), field history.
   5. **Done** — impact on publishing, update to the revision in force, pre-filling, drafts for everyone. 6. Computed indicators,
   `FormValue` index, explorer and CSV, centre catalogues. 7. ODT/DOCX import and export, JSON library, offline mode.
