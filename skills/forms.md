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
   the centre" and "another document" wait for phases 5–6). 3. Calculations and consistency rules, double-entry tables,
   relation columns. 4. Multi-step approval chain, anchored comments, revision comparison, co-authors with section locks, field history.
   5. Impact on publishing, update wizard, pre-filling (last year, another document), drafts for everyone. 6. Computed indicators,
   `FormValue` index, explorer and CSV, centre catalogues. 7. ODT/DOCX import and export, JSON library, offline mode.
