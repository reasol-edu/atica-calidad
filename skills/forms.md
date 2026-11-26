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
  `FormModelRevision` (1:1, immutable). The editor works on `FormModel::draftDefinition`. «Aprobar nueva revisión» (send panel, shown
  when the folder requires review and `FolderVoter::REVIEW` holds — today every model editor, as admins and quality managers manage any
  folder) posts `approve=1` to the same send action: `sendToReview(..., notify: false)` then `DocumentRevisionReviewer::approve()`, the
  single approval entry point (version in force, blank PDF remade with its date, log, outcome e-mail).
- A filled document is **not a Document while it is a draft**: `FormSubmission` is keyed by (activity, slot key, cycle year), holds
  `draftData`, and only **delivering** creates the `Document` (named and owned by the slot, exactly as an uploaded file would be, so
  `ActivitySubmissionSlotBuilder::resolveSlot()` finds it) or its next revision. The PDF is the revision's file; the data is frozen in
  a `FormSubmissionRevision`. A half-filled draft therefore never counts as delivered and never shows in the tree.
- `Activity::$submissionMode` (`file`|`form`) + `$formModel`. `Activity::isForm()` is what the templates ask. One mode per activity.
- Who may fill a slot = who may upload to it (`FormSlotAccess`, which delegates to `SlotResponsibility::canFill()`); the activity's
  start/end window applies. Models are created and edited by `QualityVoter::MANAGE`; approving a revision is `FolderVoter::REVIEW`
  on the model's folder.
- **`SlotResponsibility` is the one place that answers «may fill» (`canFill`) and «whose task is it» (`owns`)** — never repeat «the
  slot's teacher, otherwise `holdsProfile()`» (grep `holdsProfile($teacher, $slot->profile` must find only it). With the activity's box
  `teachersDeliver` checked, the teachers associated with the slot's element (own + ancestors, `ListItem::getEffectiveTeachers()`,
  kept to those active and in the centre's active course by `LeafTeachers`) fill it and own it; the head holding the profile fills it
  and owns it only when the element has no such teacher. The teachers never change `slotKey` or the set of slots. `LeafTeachers` and
  `ActivityLeafResolver` exist apart so that `DocumentTreeAccessChecker` (which `SlotResponsibility` depends on) can use them for
  `canViewDocument()` / `canManageDocumentAsUploader()` without a circular dependency.

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
  `copy.number`, `copy.total`, `copy.label` (the item of a section repeated by a list), `row:<column key>`, `f:<field key>`,
  `ctx:<centre|year|activity|slot|date>`. Resolved by `FormResolver`.
- **The rules live twice**: PHP (`FormDataNormalizer`, `FormWalker`, `FormConditions`, `FormResolver`) and JavaScript
  (`assets/utils/form_scopes.js`), because the page reacts while typing (a new row creates its section, a title follows the unit's name).
  The server cleans and validates everything again on save, so a drift only shows as the page and the saved document disagreeing: after
  changing one side, change the other and drive a model with repetition in a real browser.
- Not done yet in this phase: the "another document" source of repetition (the "list of the centre" one is done: «Sections repeated by
  a list», below) and a minimum of manual copies as a delivery problem (it only applies when the document is created).

### Tables of deliveries («Datos de otras entregas», phase 1 done 2026-11-25, phases 2 and 3 2026-11-26)

A table with `mode: delivered` has **a row per delivery of another activity** (spec «Especificación: datos de otras entregas» in Claude
Docs). The model says which model those deliveries fill (`sourceModel`), whose (`sourceScope` own|all) and `showStatus`; a column with
`sourceField` (an internal `name` of the source model, document level only: `FormDeliveredRows::namedFields()`) reads it, typed by
`columnTypeFor()` and never typed in (normalizer drops required/limits/options; `displayDecimals` null|0–6, twin in `form_scopes.js`). Each
activity picks the source per table: `ActivityDeliverySource` (activity, blockKey, sourceActivity; a trashed source counts as none — the
trash filter makes loading it throw, `Activity::getDeliverySources()` catches it).
- **Server only reads**: `FormDeliveredRows::read()` (source slots + 2 queries, no query per row; scope `own` keeps slots whose element
  `FormListOptions::belongsToSlot()`, except when the source is delivered by the same profile of a list: then the slot's own ROW must be
  who delivers — a subject shared by two departments shows each head their own) → `inject()` (pure: keeps own cells by `_key`, rows in read
  order) → stored in `data.tables` + `data.sources[tableKey]`. Row key = `Uuid::v5(NAMESPACE, slotKey)`.
- **Shared dimensions** (phase 0e3): when both activities split by a list with the same ROOT (`sharedValues()`), only the source slots
  with this document's value at that position are candidates, whatever the scope; `sources` gains `match` {rootId: valueId} and `within`
  (the values' names, shown in the header «solo las de «1ª»»), and with no match the table is EMPTY (`inScope(…, fallBack: false)`, never
  `assumedAll`). Row label (`rowLabel()`): the slot's name without the shared values, plus « — ownerLabel» only when two rows of the
  table would read the same (a grouped source slot, whose name is only values, is named after its owner).
- **The definition gets its rows only from the data** (`rowsFromData()`), called in `FormListOptions::expand()` and `delivered()`: every
  path that expands a draft or a revision sees them. `FormDefinition::hasGivenRows()` = fixed or delivered (normalizer rows, resolver).
- `FormDataNormalizer` keeps a shape-checked `sources` (`clean()`); **`save()` replaces the browser's `sources` and source cells with the
  saved draft's BEFORE normalizing** (`keepSaved()`), so formulas use what was read and a forged POST changes nothing. Saving never reads.
- Opening the fill page reads and stores (`FormSubmission::refreshDraft()`: new version, no history, `updatedBy` untouched) only if the
  opener holds the lock and something changed; `POST …/actualizar-entregas` (same CSRF header, lock and version as saving) reads again and
  answers tables, sources, rows and the changed cells (blink `.delivered-changed` in app.css). `FormFlatData` skips source cells (no history).
- Validator and `progress()` (JS): an own required column only in rows with `status: delivered`.
- Model problems (`FormModelService::problems(…, $model)`): `delivered_without_model`, `_model_gone` (no revision in force), `_without_columns`,
  `_field_gone`, `_cycle` (models reading each other), `_in_repeat` (not in the spec: a table in a repeating section is never filled).
- Editor: `GET /modelos/{id}/campos-con-nombre` (QualityVoter::MANAGE) fills the boxes; `deliveryModels` = other models with a revision in force.
- **Delivering re-reads** (phase 2): `deliver()` calls `refreshDelivered($submission, true)` FIRST (before update/validation); any
  changed row → `FormException('error.delivered_rows_changed', [], $changed)` with the draft already stored. The controller does NOT
  flash an error: it adds the flash `form_delivered_changed` (the details) and the fill page turns it into the amber banner
  (`data-testid="delivered-rows-changed"`) and `editor.changed` (the cells blink once on load).
- **Outputs**: `FormResolver::delivered()` (only `mode: delivered`, reading `data.sources` kept in `$this->sources` during resolve()):
  «—» in an empty SOURCE cell (own columns stay empty), the column «Estado» prepended when `showStatus` (columnKeys `_status`, totals
  shifted) and `deliveredFrom {title, at, text}` — `text` already translated because `FormOfficeWriter` has no translator. PDF, the
  revision page and Office print the note under the table. `FormDiff` treats «—» as empty in those tables (rows keep their `_key` and
  label). A unit test of a delivered table that only checks decimals must set `showStatus: false` (the normalizer defaults it to true).
- **Porter**: `export()` writes `sourceModel` as the source model's document NAME; `import()` maps a name back (case-insensitive, first
  match in the centre) or '' and returns `unsourced` (table labels) → flash `models.import_unsourced`. `duplicate()` keeps the id.
- **Outdated** (phase 3): `FormDeliveredRows::outdated($filled, &$cache)` re-reads with `read()` and counts current rows `delivered` whose
  `revision` differs from the one stored in `data.sources` (or that were not there): drafts, approvals and teachers never count.
  `read()` takes the same by-ref `$cache` (keyed `sourceId|cycle`) so a list loads each source activity once. Shown on the delivered
  fill page (`data-testid="delivered-outdated"`, button = POST `app_form_correct`, the draft then reads live) and as the badge
  `submission-outdated` from `ActivitySubmissions::isFormOutdated()` (deliveries per activity+cycle and the source cache kept for the
  render). No notification, by design.
- Phase 4 only on request.

### Rich text: one Tiptap editor everywhere (migrated from Quill, 2026-11-23)

Every rich-text editor is `assets/utils/rich_text_editor.js` (`mountRichText(el, {html, placeholder, onChange, onBlur})`, returns
`{editor, focus, insertText, html, destroy}`): the `rich-editor` Stimulus controller (settings, folder description, management review,
`Form:RichEditor`), the text blocks of the model editor and the fill form (mounted on click) and the «texto largo» fields. The header
and footer editor (`doc_layout_editor_controller.js`) is a separate Tiptap setup because it needs tables. The look is `.rich-*` in
`app.css` (the toolbar buttons are Heroicons, except the two heading levels, which are the text «T1» and «T2»; imported in `assets/icons/heroicons` and repeated as path data in the module's `ICONS`
because a script cannot read them: add a new icon in both places; the Spanish labels are in `LABELS`, overridable with `labels`).
- The stored HTML is the `app.rich_text` allow-list (p, br, strong, b, em, i, u, ol, ul, li, h2, h3, blockquote, a, `text-align`).
  Two heading levels: h2 («Título (nivel 1)») and h3 («Título (nivel 2)»).
  Tiptap writes `<li><p>…</p></li>` (the plain-text reader `FormOfficeWriter::paragraphs()` copes) and links with `rel` only.
- **Legacy**: Quill 2 stored bullet lists as `<ol><li data-list="bullet">` with a `.ql-ui` span; `legacyHtml()` turns them into real
  `<ul>`/`<ol>` when an editor loads old content. Pages that only *render* old HTML (not edit it) still show it as the sanitizer leaves it.
- An empty editor gives `''` (never `<p></p>`). `onChange` only fires for edits of the person, not for the initial load.
- Headless tests cannot type ASCII into it with Chrome's `type` tool (only non-ASCII reaches ProseMirror): drive it with
  `document.execCommand('insertText', false, …)` after focusing `.rich-content`.

## Calculations, rules, relations and double-entry tables (phase 3)

- **Expression language** (`FormExpression`, closed grammar, no `eval`): numbers, texts, `+ - * /`, comparisons, `&& || !`, and
  `sum avg min max count count_if pct_if if round abs empty`, arguments separated by `;`. References are `[f:KEY]` (field), `[t:TABLE.COL]`
  (column, a list), `[r:COL]` (cell of the row being calculated), `[o:COL]` (column of the row a copy comes from), `[c:SECTION.f:KEY]` /
  `[c:SECTION.t:TABLE.COL]` (across the copies of a repeating section) and `[m:MATRIX]` `.rows` `.cols` (cells, row sums, column sums of a
  double-entry table). Empty is contagious in arithmetic and false in a comparison. **It is implemented twice**, PHP
  (`FormExpression`) and JavaScript (`assets/utils/form_expression.js`), against one shared set of cases,
  `tests/Fixtures/form-expressions.json`: add a case there when you change either, and run both (`php bin/phpunit tests/Unit/Service/Form`
  and `node --test tests/js/form-expression.test.mjs tests/js/form-scopes.test.mjs` — pass the files, not the directory).
- **«Texto largo» fields are rich text** (blocks only; table cells stay plain): `FormDataNormalizer::richText()` stores sanitized HTML
  (plain text from before becomes paragraphs, see `FormRichText`); `FormResolver::format()` returns it as plain text (`value`: diff, Word,
  index, explorer) and the field item also carries `html` for `_document.html.twig`. The fill form mounts a rich editor per field (`richEl()`).
- **Number fields and columns** (`fieldType: number`): `min`, `max`, `unit` and `maxDecimals` — `null` any (the default, and every model
  from before it existed), `0` whole numbers, `1–6` at most that many. Not `decimals`: that key is the calculated type's and the
  normalizer has always stored `0` there for a number, so it could not mean «whole». `FormDataValidator` reports `number_integer` /
  `number_decimals` (blocking, with a short form for the field, like `number_min`); `decimalsOf()` counts them on the number as written
  (25.0 is whole). The fill box takes `step` 10^-N and `inputmode="numeric"` for whole numbers, and marks the box at once.
- **Calculated fields and columns** (`fieldType: calculated`, `formula`, `decimals`, `unit`): never typed (the normalizer drops what the
  browser sends), filled by `FormCalculator` after the data is cleaned, in up to four passes so a formula can read another calculated
  value; a cycle is a model problem. The result is stored with the data, so it is frozen with each delivered revision. A column can show a
  **total at its foot** (`total`: sum, avg, min, max, count, or `custom` with a `totalFormula`, always a field and shown from its value;
  an `avg` shows exactly `totalDecimals` decimals — 0–6, default 2, a calculated column's own `decimals` if none was given — in
  `FormResolver::totals()` and its twin `totals()` of `form_scopes.js`, via `number($v, $column, $fixed)`; the other totals keep
  «as many as they have, up to six»; the named total field of an `avg` gets the same `decimals`),
  display-only unless it has a `totalName`: then `totalKey` is also the key of
  a calculated field (`fn([t:TABLE.COL])`, `foot: true`) that `FormDefinitionNormalizer::footFields()` writes right after the table every
  time (stored `foot` blocks are dropped and remade; the editor hides them). `FormWalker` / `walk()` mark it not visible, so nothing
  renders or validates it, but calculation, indicators, rules and the explorer see it as a field.
- **Attachments** (`fieldType: attachment`, `maxFiles` 1–10 default 5, `printPages`; fields only — a column or matrix cell is coerced
  to `short`). Files are `FormAttachment` rows of the SUBMISSION (not of a revision; never deleted while it exists), content in the
  deduplicated `DocumentFile` store (so `DocumentFileGarbageCollector` and `DocumentFileRepository::deleteOrphans()` count them; add any
  new owner of files there too). The value is a list of `{id, name, size, mime}`: the normalizer checks the shape and caps at 10;
  `FormAttachmentService::restrict()` (save AND deliver) keeps only the submission's own attachments, rewrites name/size/mime from
  the rows and applies the field's `maxFiles` (after dropping strangers, or a forged id pushes a real file off). `FormAttachments`
  walks the values through copies of repeating sections (`map()`, `ids()`) and lists a resolved document's annexes (`fromResolved()`).
  Upload: `POST …/formulario/{slot}/adjuntos` (multipart `file` + `field`, CSRF header `form_fill_<sid>`), type by CONTENT
  (`FormDefinition::ATTACHMENT_TYPES`; office files read as ZIP are accepted by extension only if they start with `PK`), size ≤
  min(10 MB, `UploadLimits::maxFileBytes()`). Download `GET /formularios/adjunto/{id}` (`FormAttachmentController`): whoever can fill
  the slot, or whoever sees the folder IF the file is in a delivered revision's data (a draft-only file stays private).
  The resolver numbers them «Anexo N» across the document (`$annex`, reset in `resolve()`); `FormPdfBuilder` draws pictures ≤ 5 MB as
  data URIs, embeds the originals (`PdfRenderer` `embeddedFiles` → mPDF `SetAssociatedFiles`, up to `EMBED_MAX_BYTES` 40 MB), and
  with `printPages` appends the pages of PDFs FPDI can read (`PdfRenderer::pageCount()` decides first; free FPDI cannot read some
  compressed PDFs: those are only listed). ZIP of a delivered revision: `GET /formularios/revision/{id}/con-anexos.zip`. Office
  exports (`FormOfficeWriter::build(…, $files)`, the controller passes `FormAttachmentService::contents()`) print the references and
  draw the PNG/JPEG pictures under their field as `Layout\Figure` blocks (≤ 9 × 7 cm, never enlarged; ODT `Pictures/anexo-N.*` +
  manifest, DOCX `word/media/anexo-N.*` + a `rIdFigN` relationship of document.xml.rels). GIF/WebP and the rest stay references. The fill form uploads one file per request and then saves the field like any other value.
- **Rating scale** (`fieldType: scale`, `points` 3–10 default 5, `lowLabel`/`highLabel` ≤ 60 chars): the value is an int 1..points
  (`FormDataNormalizer::coerce` drops 0, beyond the top, 3.5, texts); a number everywhere a number is (`FormDefinition::NUMERIC_TYPES`:
  totals at the foot, the row summary, `[f:]`/`[r:]`/`[t:]` refs, indicators — the editor's indicator link proposes `avg` —,
  `FormValueIndexer` stores it in `num`). Fields and free/fixed table columns only; a matrix cell is coerced to number. Reads «4 / 5»
  (+ the end's word on 1 and N) in `FormResolver::format()` and its twin `format()` in `form_scopes.js`. Unanswered, the resolver item
  carries `scale` (`FormResolver::scalePoints()`: «Nada ○ 1 … ○ 5 Mucho»), drawn by the blank web view, the blank PDF and the office
  exports. The fill form draws the usual Likert row: native radio buttons (visible, `accent-forest-600`, arrow keys move between
  them) with the number under each and the words of the ends at the sides, in a fieldset; «Borrar la respuesta» for an optional one; a
  drop-down in a table cell.
- **«Resumen de la fila»** (editor only, no grammar change): a calculated table column gets a box that writes `fn([r:A];[r:B])` from a
  function (`ROW_SUMMARY`: sum avg min max count pct) and ticked columns (`rowSummary()` / `readRowSummary()` in `form_expression.js`,
  tested in `form-expression.test.mjs`). It recognises only formulas of exactly that shape; anything else is left alone until a box is
  ticked. Counting offers any column, the rest only number/calculated ones. `pct` is not a function of the language: it writes
  `[r:A] / [r:B] * 100` from two drop-downs (A, B; order matters) and relies on division by 0 or by an empty cell giving null in both
  engines (cases in `form-expressions.json`).
- **Moving**: a block goes to another section with the editor's «Mover a otra sección…» (`moveBlockTo()`: same key, end of the target;
  the draft's checks flag references that are out of scope from there). A whole model goes to another folder of the centre with
  `POST /modelos/{id}/mover` (`FormModelLibraryController::move`, logged as `document.move`): the folder only decides the review.
- **Consistency rules** (`definition.rules`: `{expr, message, severity: warning|block}`): evaluated against the whole document by
  `FormDataValidator`; a broken rule blocks the delivery or only warns, as the model says; a rule that cannot be decided is not broken.
  The save response carries `problems` (blocking) and `warnings`.
- **Relation columns** (`fieldType: relation`, `relTable`, `relLabel`, `multiple`) pick rows of another table of the document and keep only rows
  that still exist. **Double-entry tables** (`mode: matrix`, `rowTable`/`rowLabel`/`colTable`/`colLabel`, one kind of `cell`) have a row per row
  of one table and a cell per row of another, keyed by those rows' keys.
- **Counted tables** (`mode: counted`, `rowsField` = key of a typed `number` field, `maxRows` ≤ `FormDefinition::MAX_COUNTED_ROWS` = 100) have as
  many rows as the field says, sized in `FormDataNormalizer::relatedData()` → `countedRows()` (PHP) and `related()` → `countedRows()` (JS; keep
  the twins in step): the first rows keep their keys and what was typed, blank ones are appended, the last ones are dropped when the number
  drops. The field is read through the scope chain, so it may live in an outer section. It cannot be a calculated field (calculations run
  after the rows exist): `counted_table_invalid` says so. The fill screen shows them numbered, like a fixed table, and asks before a lowered
  number drops rows with data (`rowsLost()`; the server drops them regardless, so offline syncs converge). Blank rows are not stripped at delivery.
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
- **Step limited to the element** (`step.scope` ∈ `any|element`, default `any`; only offered for a profile of a list). `start()` copies it to
  `FormApprovalStep::elementScoped` (so editing the model never alters a chain under way). `FormApprovalChain::holdsStepProfile()` is the
  rule: for a limited step, the element is `ActivitySubmissionSlot::leafOf()` of the submission's slot key, and only whoever holds the step's
  profile with one of the subprofiles that element is associated with (own or inherited elements of the step profile's list,
  `ListItemOwners::linkedUnder()`) counts. No
  such association → `isLimitedWithoutElement()`: anyone with the profile, and the document page says so. `canDecide()` = manages the folder
  or `holdsStepProfile()`; `pendingFor()` (the review queue) and `FormReviewController::canParticipate()` follow from it.
- **Steps approved by delivering**: after `start()`, `FormSubmissionService::approveOwnSteps()` approves, through
  `DocumentRevisionReviewer::approve()` (which approves the revision too if it was the last step), every step from the current one that
  whoever delivered holds by profile — never by managing the folder — with the note `approval.self_approved`.
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
- **Pre-filling from the previous value** (`FormPrefiller::previousValue()`, first of all when `Activity::formPrefillPrevious`, default
  true): with dimensions, the same slot key with the dated dimension's (else the first's) value replaced by each earlier value in the
  list's order, this cycle, the first one delivered (`from.kind` = `previous_value`, label = the value's name). Values the element lacks
  have no submission, so they skip themselves. Looking in `formPrefillSource`, a source WITHOUT dimensions is matched on the key's first
  four parts (the 1ª of a subject's review from its programme).
- **Pre-filling** (`FormPrefiller`): a new draft starts from `Activity::formPrefillSource` (latest delivered of the SAME slot key there) or,
  failing that, from the same slot's latest earlier cycle. Same model → by key; different models → by the field's internal `name` and the
  same `fieldType` only (a number never goes into a text field). `FormSubmission::prefilledFrom` remembers the origin; the page offers "Volver
  a copiar" and "Empezar desde cero". Not done: choosing an arbitrary document as source per draft (the source is set on the activity), and the
  "origin changed" warning for pre-fills.
- **Drafts for everybody**: `Activity::formDraftsForAll` + `bin/console app:create-form-drafts` (run daily from cron; idempotent) creates the
  missing draft of every slot once the period has started. Nothing creates them lazily: without the cron they appear when each person opens.

## Data, computed indicators and group findings (phase 6)

- **`FormValue`** is the derived index of scalar values (field/column key, copy path, row key, num/text/flag) of each delivered revision, written
  by `FormValueIndexer` in `deliver()` and rebuilt with `bin/console app:forms:reindex`. It is never the source of truth; indicators and the
  explorer read it, never whole documents. Query by activity+cycle (`FormValueRepository::findByActivityCycleAndFields`): do NOT pass arrays of
  UUID entities/objects to IN() — binary UUID columns do not match on SQLite/MySQL.
- **Computed indicators**: `IndicatorFormSource` (1:1 with `Indicator`: activity, field, aggregation sum|avg|min|max|count|count_if|percent_if,
  condition, optional denominator = ratio ×100, group by none|slot|field:KEY). `FormIndicatorCalculator` counts only the latest APPROVED
  revision of each slot, writes `Measurement` with `computed`, `sampleCount`, `expectedCount` and `MeasurementBreakdown` rows, and skips
  measurements typed by hand or already reviewed (frozen). Run `app:forms:calculate-indicators` from cron (daily); saving the indicator form
  recalculates at once. A group below the alert threshold shows "Abrir hallazgo de este grupo" (`IndicatorService::openGroupFinding`), which
  does not change the global measurement.
- **Explorer** (Informes › «Datos de modelos», `ReportsController::formData`/`formDataExport`, `FormDataExplorer`): flat rows (one per value) of the
  latest APPROVED revision of each slot for an activity and cycle; on-screen text filter (`table_filter` Stimulus controller, first 500 rows) and
  full export to CSV (`;`, BOM, formula-injection guard) and XLSX. Same permission as the other reports (`EducationalCentreVoter::REPORTS`).
- **"Indicador con este campo"** in the model editor: links to `/mejora/indicadores/nuevo?fuente=ACTIVITY|FIELD&agg=` for each number/yes-no/
  choice/calculated field of the activities that use the model (`FormSourceChoices::forCentre($centre, $model)`).
- **Recalculation on approval**: an approved delivery (at once when nobody reviews the folder, or via `FormReviewController::decide`) calls
  `FormIndicatorCalculator::recalculateForActivity()`. A delivery approved through another path (e.g. the generic document review screens)
  waits for cron / saving the indicator form.
- "Pass this period to manual": `IndicatorService::makeManual()` behind the button of the measurement screen
  (`app_quality_measurement_make_manual`).
- Not done: a single activity per indicator (the spec allows several) and the "approval date" period mode.

## Options fed from the centre's lists (live)

A choice field or table column can have `list: <ListItem uuid>` (and empty `options`) instead of typed options. **The options are never
stored in the model**: `FormListOptions` expands them from the list every time, and EVERYTHING that reads `options` must read a definition
that went through it (the resolver, `FormDataNormalizer::optionKeys`, the validator, the JS of the fill page and of the editor). It is the
trap: a list-fed field normalized against the raw definition has no options, so every save would wipe the choice, silently.

- **Draft side** (`live()` / `expand($definition, $centre, $data)`): options = active leaves under the item (the item itself when it has none),
  key = the item's UUID, label = path below the chosen item joined by ` › ` (what activities do). A value in `$data` that is no longer
  offered (deactivated, moved out, deleted) is added as an option with `gone: true`, so the normalizer keeps it; `FormDataValidator` reports
  `option_gone` (blocking) for it. `FormSubmissionService::definitionOf($submission)` is the entry point for a draft; pass the data being
  normalized (`$raw` on save), not the saved draft's. Only UUID-shaped values are kept as gone, max 100 per field (data comes from a browser).
- **Delivered side** (`delivered($filledRevision)`): the options a delivery froze. `deliver()` stores `snapshot($definition, $data)` in
  `FormSubmissionRevision::optionsSnapshot` (JSON, nullable: `{fieldKey: [{key,label}]}` of what was SELECTED). Resolver output of a
  delivered revision (review page, DOCX/ODT, compare), the explorer and the indicator group labels (`optionLabels`) read it. Never render a
  delivered revision from `getModelRevision()->getDefinition()` if the model may have list-fed fields.
- A model whose list is missing or has no active item cannot be sent to review (`choice_list_empty`, `FormModelService::problems`).
- **JSON library**: `toPaths()` replaces `list` by `listPath` (names from the list's root), `fromPaths()` resolves it in the destination
  centre; unresolved ones are left without list (→ `choice_without_options`) and reported (`FormModelPorter::import` returns
  `{model, unlinked}`). Duplicate keeps ids (same centre).
- `ListItemRepository` caches the centre's tree for the whole request/process (`reset()` clears it): tests that change a list and read it
  again in the same process must call `reset()` (see `FormListOptionsTest::change`).
- Responsabilidades: the delete confirmations of `ListItemTreeComponent` show `FormValueRepository::countDocumentsUsingItems()` (delivered
  documents (not revisions) with a value equal to / containing the item id), for a single item, a whole list (it is the same component:
  "Editar la lista" → delete) and the bulk delete. "Move to another list" (`getMoveImpact()`) asks for confirmation when the moved items are
  in delivered documents or change profiles; the Séneca import (`SenecaListImporter::formUsage()`, shown in the preview) treats an item
  that delivered documents use as IN USE, so it is deactivated, never deleted. A moved item stops being offered by the fields of the list
  it left, and drafts that had it keep it marked as gone.
- A multiple choice is indexed as ONE `FormValue` ROW PER OPTION CHOSEN (`text` = the option's key), however many: nothing is cut, "which
  documents chose this item" is an exact `IN` match (`countDocumentsUsingItems`, `findItemIdsInUse`), and the explorer joins the rows of a
  field back into one cell. Rows indexed before this (one comma-joined row) are fixed by `bin/console app:forms:reindex`.
- **Queries**: everything above reads the centre's list tree ONCE (`ListItemRepository::indexByCentre()` / the cached tree), never per field,
  per value or per item; the explorer and the indicators read the latest approved revision of every slot in ONE query
  (`FormSubmissionRevisionRepository::findLatestApprovedByActivityCycle`), and `FormIndicatorCalculator` keeps what a recalculation pass
  has read (slots, latest revisions) for the next indicator of the same activity and cycle. `FormQueryCountTest` compares a small and a big
  case for each and fails if the count grows: add a case there when adding a screen or a service that loops.
- The condition (`count_if` / `percent_if`) of an indicator on a choice field is picked in a searchable Tom Select
  (`indicator_condition_controller.js`, fed by `FormSourceChoices` with the options as the lists are now); the stored value is the option's key.
- **Rows of a fixed table from a list** (`rowsList` = item id, `rowsOwn` default true; `rows` is then never stored): expanded like
  options by `FormListOptions` (`usesLists()` is the gate — every early return checks it, not `sources()`; `mapTables()` visits table
  blocks). Row key = the item's id, label = its path below the chosen item. **Filtered by who delivers** (`rowsOwn`):
  `ActivitySubmissionSlot::deliveredBy($slotKey)` reads `{profile, subprofile}` from the slot key (no slot building on every save) and
  `FormListOptions::forSlot()` keeps the leaves whose EFFECTIVE associations (`ListItem::getEffectiveAssociations()`: own plus
  inherited) are the subprofile or one of its ancestors (plain profile, no subprofile: that profile; `ListItemOwners::belongsTo()`);
  none matching → all. The associations come in the same query as the tree (`groupChildrenByParent`): no query per item.
  EVERY place that expands a draft's definition passes the slot (`FormSubmissionService::definitionOf()`, the initial data of a new,
  restarted or discarded draft, correction, `FormPrefiller`, `FormUpdateService`); the blank model (send, `refreshBlankPdf`, previews,
  office export) expands with none → all items. A row with typed data (ignoring `_*` keys and calculated columns) whose item is not
  offered any more is kept as `gone` (validator `row_gone`, blocking; the fill page strikes it out with «vaciar la fila»); without
  data it is dropped. `snapshot()` freezes ALL the rows of the table (not only filled ones) under the table's key; `delivered()` puts
  them back. JSON library: `rowsList` ↔ `rowsListPath`. Tested in `FormListRowsTest`. Note: the delete/move confirmations of
  Responsabilidades (and the Séneca «in use») count values, not row keys or copy paths: they under-count documents that use an item as
  a row or copy (harmless: delivered rows and copies keep their names). **Optional, parked by the user 2026-11-24 («no merece la
  pena»)**: a table of the list items each delivered revision uses (written from `optionsSnapshot` at delivery, backfilled by
  `app:forms:reindex`) for an exact, indexed count of options, rows and copies — needs a migration on every engine.
- **Sections repeated by a list** (`repeat: {mode: list, list, own}`; `items` is never stored): `FormListOptions` (`mapRepeats()`)
  fills `repeat.items` with the same filter and gone rule as the rows (a copy "holds something" when a non-calculated field, a text
  that is not an unchanged starter, a non-calculated cell or a nested copy has a value: `copiesWithData()`). Copies keyed by the item
  id with `_source` (PHP `FormDataNormalizer::copies()` case `list`, JS twin in `sync()`); the walker gives each copy `label` (the
  item's name) for `{{…|copy.label}}` (`FormResolver::reference()` / `references()` in JS; the editor offers it as «Elemento de la
  lista» inside such a section). A gone copy is validator `copy_gone` (its content is not checked); the fill page shows «Vaciar esta
  parte». Snapshot/delivered under the SECTION's key; JSON library `repeat.list` ↔ `repeat.listPath`; `repeat_list_invalid` when no list
  is picked. Gone rows and copies of an item still active read «(ya no corresponde)» (`options.gone_other`), of an inactive one
  «(ya no disponible)».
- Not done: a list-fed field in a double-entry table's cells.

## Files in and out, and offline (phase 7)

- **JSON library** (`FormModelPorter`, `FormModelLibraryController`, routes under `/modelos`): `GET /modelos/{id}/exportar.json` →
  `{"format":"atica-modelo","version":1,name,code,definition}`; `POST /modelos/importar` creates a NEW model (draft) in a chosen folder.
  The approval chain is not exported (it names profiles of one centre). Keys (UUIDs) are kept: they only need to be unique inside a model,
  and keeping them lets the data of two models with the same field names be pre-filled by key.
- **Duplicate** (`POST /modelos/{id}/duplicar`): the draft as a new model in the same folder, "Copia de …".
- **DOCX / ODT export** (`FormOfficeWriter`): hand-made ZIP of XML from the output of `FormResolver` (same sections, texts, field lists and
  tables as web and PDF). Available for a model (blank, `/modelos/{id}/exportar.docx|odt`) and for a delivered revision
  (`/formularios/revision/{revisionId}/exportar.docx|odt`, same access as viewing it). No library: the format needed (headings, paragraphs,
  tables) is small. ODF `mimetype` must be the first entry and stored (not compressed).
- **DOCX / ODT import** (`FormOfficeReader`): creates the *first draft* of a model: headings (levels 1–5) → nested sections, paragraphs →
  fixed texts, tables → free tables whose columns are the first row. What is to be filled in (fields, types) is added in the editor — a word
  document does not say it. Hardened: only `word/document.xml` / `content.xml`, ≤ 8 MB, `LIBXML_NONET`, uploads ≤ 8 MB. PHP's `DOMDocument`
  does not accept `'*'` as a namespace in `getAttributeNS`: use the namespace URI.
- **Offline mode**: `public/formularios-sw.js` (service worker, scope `/`) keeps the last copy of the form page
  (`/actividades/<a>/formulario/<slot>`, network first) and the `/assets/` files (cache first, refreshed), never answers anything but GET and
  deletes the cached pages on `/logout` (they hold centre data). `form_fill_controller.js` keeps what is typed in `localStorage`
  (`atica-form:<saveUrl>`, with the draft version it started from) until a save succeeds; on opening, a local copy is restored and saved
  only if its version equals the server's (otherwise someone else saved in between and the server wins). Saving, delivering and every other
  action still need the connection. **Verified in Chromium (Playwright, 2026-11-24) against `127.0.0.1`** (a secure context for service
  workers): the worker controls the page after the first load and caches the page and ~95 assets; offline, a reload opens the form from
  the copy, typing shows «Sin conexión…» and keeps a copy in `localStorage`; back online the page saves on its own and drops the copy; a
  copy typed offline in a page closed before reconnecting is saved when the form is opened again; `/logout` deletes the pages cache
  (the assets one stays: it holds no data). Offline, the header's Live Components fail their polling with an uncaught `Failed to
  fetch` in the console: harmless, not the form's. Attachments need the connection (the control says so).

## Page layout: header, footer, orientation, font scale

- **Where it lives.** `definition.layout = {header, footer, orientation, fontScale}` (`FormLayout`), normalised by `FormDefinitionNormalizer`
  (so it travels with every revision, the JSON export/import and duplicates, and an old revision without it gets the defaults). `header`/
  `footer` `null` = the centre's → the platform's → the built-in tables (settings `forms.document_header|footer`, type `layout`, seeded by
  migration `Version20261123100000` in the three engines; `FormLayoutResolver::inherited()`). `FormModelService::changes()` has a `layout`
  part (the editor's "Ajustes" tab dot). `fontScale` is clamped to 50–150 and the orientation whitelisted server-side.
- **HTML and tokens.** Header and footer are HTML from Tiptap (`doc_layout_editor_controller.js`, shared by the settings screen and the
  tab through `components/_doc_layout_editor.html.twig`), sanitized with `app.doc_layout` (tables, colspan/rowspan, a whitelist of CSS
  declarations in `DocLayoutStyleAttributeSanitizer`). The fields are plain-text tokens `{centro}`… (no custom node: the sanitizer stays
  trivial); `FormLayout::fill()` escapes and substitutes all but the page ones, which each output turns into its own field.
- **One reader for three outputs.** `Layout\LayoutHtml::parse()` turns the HTML into `Paragraph`/`Table` (a grid: `Table::placement()`
  resolves merged cells once) and writes the PDF HTML; `Office\OdtDocument` / `Office\DocxDocument` draw the same objects (ODT:
  `covered-table-cell`, `number-*-spanned`, master-page header/footer; DOCX: `gridSpan`, `vMerge restart/continue`, `header1.xml`/
  `footer1.xml`, `fldSimple PAGE/NUMPAGES`). Body styling lives in `Office\Theme` (colours of the app, Arial — a sans-serif every system has, declared `swiss` in the ODT so a missing font still falls back to a sans — and sizes × font scale).
- **mPDF quirks.** The text alignment of a table cell comes from the **cell**, not from the `<p>` in it (`LayoutHtml` copies the first
  paragraph's alignment onto the `<td>`); a `<col>` takes its width from the `width="%"` **attribute**, not from `style`, and with merged cells it is not enough either — every `<td>` also
  gets `width: X%` (the share of the columns it covers) or mPDF sizes the columns by their content and a column of an empty logo collapses; the header and
  footer are outside the document's stylesheet (inline styles only); `setAutoTopMargin='stretch'` (`PdfHeader::$stretch`) makes room for a
  tall header, and a custom footer goes in `PdfRenderer`'s `$footerHtml`.
- **Logos** are settings of type `image` (`SettingType::Image`, keys `centre.logo` → `{logo}`, `centre.education_authority_logo` →
  `{logo2}`; global and centre, locks work as for any setting). `SettingImageController` (routes `/ajustes/imagenes/{global|centre}/{key}/…`
  and `/logotipo/{1|2}` for the one a centre ends up with) accepts only PNG/JPEG recognised by content (`getimagesizefromstring`), ≤ 2 MB,
  ≤ 4000 px a side, stores them in `SettingFile` and serves them `nosniff` + a sandbox CSP. The upload form reuses `Form:PdfDropzone`
  (`accept`, `hint`, `thumbs` props; `file_drop_controller.js` fills the `<img data-role="thumb">`). `Layout\Image` fits a logo in
  1.6 × 5 cm; `{logo}`/`{logo2}` are `Run`s of their own (PDF: data-URI `<img>`; ODT: `draw:frame` + `Pictures/`; DOCX: `wp:inline` + a
  relationship per part). Every output has to get the images through `DocumentLayout::$images`.
- **`{fecha_aprobacion}`** comes from `DocumentRevision::getApprovedAt()` (new column `approved_at`, set in `approve()`; null in the column
  = the revision's own date, so both dates match when no review was needed). The blank model PDF is made at *send* time, before any
  approval: with a review pending it is empty there (`FormLayoutResolver::values(..., pendingApproval: true)`). On approval
  `DocumentRevisionReviewer::approve()` calls `FormModelService::refreshBlankPdf()`, which makes it again and swaps the revision's file
  (no change if the header does not use the token: the file is deduplicated by hash). With an approval chain, only the last step does it.
- **Margins** (`layout.margins` or the settings `forms.margin_top|bottom|left|right`, mm; top/bottom are the distance from the paper's edge
  to the header/footer). The PDF does not use mPDF's auto-stretch: `PdfRenderer::heightMm()` measures the header, footer and first-page
  header and `FormPdfBuilder` sets the real margins. **First-page header**: `layout.firstHeader` (null = the normal one). mPDF: both are
  `<htmlpageheader>`s inside the template's `<body>` (before `<html>` they are ignored) switched with `<sethtmlpageheader show-this-page>`;
  `@page :first {header}` and `@page {margin-top}` did not work, so a taller first header pushes the body down with a spacer
  (`PdfHeader::$firstExtraMm`). ODT: a `First_20_Page` master page (`style:next-style-name="Standard"`) that a 1 pt first paragraph
  (`FirstPageStart`) opens; DOCX: `titlePg` + `header2.xml`/`footer2.xml`.
- **Draft watermark**: the PDF preview of a draft (`/modelos/{id}/vista.pdf`) passes `draft: true` → `PdfRenderer`'s `draftWatermark`
  (translation `pdf.watermark.draft`). The office exports have none.
- **Delivered PDFs are frozen files**; DOCX/ODT are generated on request, so a model with no header of its own shows the centre's *current*
  one there. The body repeats the title only when the document has a name of its own (`showTitle`/`$heading`): the header carries the model's.
- **Not verified in Word/LibreOffice here** (no office suite on the dev machine): the XML is checked for well-formedness and structure in
  `FormOfficeLayoutTest`, and `pandoc` reads both files; open a sample in both before a release. `LAYOUT_PDF_OUT=<dir>` makes
  `FormLayoutPdfTest`/`FormOfficeLayoutTest` write their files there.

## Front end

**Formula and reference editor** (`assets/utils/formula_editor.js`, ProseMirror straight from `@tiptap/pm/*`, no Tiptap `Editor`):
every formula of the model editor (`formulaEditor()`: calculated fields and columns, custom totals, rules) and every section title
(`mountTemplateEditor`) is a one-line ProseMirror doc (`doc > line > (text | ref)*`, `ref` an inline atom drawn as a pill by a NodeView).
**What it stores is the same text as before** (`[r:key]…`, `{{label|ref}}`): `splitLine()` / `joinLine()` in `formula_text.js` convert
both ways; the server never sees the editor. Texts (Tiptap) get the same pills through `rich_text_refs.js` (`refToken` node; `htmlIn`
turns `{{label|ref}}` into `<span data-ref-token>` on load, `htmlOut` back before `onChange` — `mountRichText` gained `extensions`,
`htmlIn`, `htmlOut`); the preview of a text shows pills via `htmlWithPills()`. Pieces:
- Refs come from `formulaRefs(table)` / `templateRefs(section)` as `{id, label, kind, group, hint, token?}`; `kind` picks the colour
  (`.fx-kind-*`: field row column copies matrix copy origin ctx); an id not in the list is a red pill (deleted or out of scope). A
  formula never lists its own field/column (self-reference). `token` is the readable part a template writes (`Campo: X`).
- Colouring, mistakes and the line under it: `diagnose()` runs the **lenient** `tokens(s, true)` and `parse()` of `form_expression.js`
  on the serialized text and maps offsets to positions (`serialize()` segs, `toPos`/`toOffset`). `parse()` now returns `at` {start,end}
  and `data` with each error code (codes unchanged), calls carry `s`/`e`; `arityProblems()` (amber, not an error: the language accepts
  any count) and `callAt()` (signature line) are new — all tested in `tests/js/formula-text.test.mjs`. While focused, what is only
  incomplete (end, unclosed paren/string/bracket, a name being typed at the cursor) is grey, not red.
- Menus: `openMenu()` (fixed, appended to `<body>`, one at a time; with `search` it takes the focus, without it the editor drives it).
  Gotchas found the hard way: never `scrollIntoView()` inside it (before `place()` it sits at the end of the page and drags the document
  down — scroll the list by hand); scrolling re-places it rather than closing it; the suggestion's range lives in a mutable object
  updated as one types (closing runs `onClose` before `onPick`).
- Keys: Backspace/Delete remove a whole pill; Home/End are handled (Chrome's native End next to pills jumped the page);
  `handleScrollToSelection` only keeps the editor in sight; a press on the empty part of the line places the caret itself (with pills,
  the browser left it at the start); the focus redraw is deferred a tick (an immediate transaction carried the old selection).
- Function texts (description, values, Spanish keywords) are translations `editor.fx.fn.*`; `formula_text.js#suggestFunction` maps
  Spanish names and typos to the function for «¿Querías decir…?» + its fix button.
- The editors of a render are kept (`keep()`) and destroyed on the next `render()` (each ProseMirror view listens to the document).

**Fill page layout** (`templates/form/fill.html.twig` + `form_fill_controller.js`): a sticky bar under the app header (`top-14`)
with the save status, «N pendientes» (`goToProblems()`) and, under `lg`, a «Secciones» dropdown; on `lg` a sticky index at the left
(`renderIndex()`: every visible top-level instance, copies included, with the count of server problems whose `path` is the instance's or
starts with it + `/`). Sections have `scroll-mt-32` so jumps land below the bars. Copies of repeating sections fold (`this.collapsed`,
by path, kept across `render()`; jumping to a problem or an index entry unfolds). History is a clock button next to the label
(`historyEl()` returns `{button, panel}`, `headRow()`). Tables: number-like columns `w-36`; > 10 rows → `max-h-[32rem]` box with a
sticky `thead` and `tfoot`; fixed/counted tables show «Rellenas X de N» (a `calcEls` update). Tables whose rows come from a source (`rowsList` or counted: `automatic` in `tableEl()`) get a filter input in the header of the first column: it hides rows (`hidden`) whose name contains the text (`fold()` from `formula_text.js`: no accents/case), the counter adds «Mostrando X de Y filas», the text lives in `this.rowFilters` (by `path|block`) so a redraw keeps it, and clicking a problem clears every filter before scrolling (a hidden row could hold it). Attachments: XHR upload with progress,
image thumbnails, «Anexo N» labels renumbered in DOM order (`renumberAnnexes()`). The page draws its own Heroicons from `ICON_PATHS`
(the same files as `assets/icons/heroicons`; add new ones in both places).

The editor and the form are **Stimulus controllers that build the page from the JSON** (`form_model_editor_controller.js`,
`form_fill_controller.js`, helpers in `assets/utils/form_dom.js`), not Live Components: they hold a lot of client state (nesting,
tables, offline later) and a Live round trip per keystroke would not do. They save the whole draft, debounced, with the version they
started from (`draftVersion`); a 409 means another tab saved in between. A body over `FormDefinition::MAX_REQUEST_BYTES` (4 MB) is
refused with 413 before it is decoded (both endpoints); the page shows the message and does not retry on its own (the fill form keeps
the local copy). Texts use the rich editor, mounted on click (a page of editors is slow).

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
   5. **Done** — impact on publishing, update to the revision in force, pre-filling, drafts for everyone. 6. **Done (with gaps listed
   above)** — computed indicators, `FormValue` index, explorer and CSV/XLSX. 7. **Done** — ODT/DOCX import and export, JSON
   library, duplicate, offline mode.
