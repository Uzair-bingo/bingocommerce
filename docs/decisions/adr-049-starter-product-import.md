# ADR-049 — Starter product catalog and one-time Hall import

- Date: 2026-10-19
- Status: **Approved** by the user on 2026-10-19. **Not implemented.** The
  decisions below were confirmed by the user in discussion between 2026-10-17
  and 2026-10-19, and the written record was reviewed and approved on
  2026-10-19.
- Scope: BingoCommerce only (`bingocommerce-catalog`). No Portal, provisioning,
  theme or core change.
- Business rule: BR-086 (DECISIONS.md) records the business rules in short form.
- Table, option, class, action and setting names in this record are proposals.
  They may be renamed during implementation without changing the rules.

## 1. Decisions confirmed by the user

Confirmed in discussion between 2026-10-17 and 2026-10-19:

1. Starter products are **not** copied during Portal Enable. Enable keeps
   creating an empty ready store (ADR-042 unchanged).
2. A Hall Manager may **optionally** import Bingo Innovation's starter
   products **once**, from Products → Starter Products. New, existing and
   legacy Halls may all use it.
3. Only ordinary WooCommerce products are imported, always as **drafts**. Hall
   Managers create Bingo Play Products themselves afterwards. No Game Types,
   Play Products, Packages, add-ons or Sessions are imported.
4. The master catalog lives on the network **main site** and is edited with the
   normal WooCommerce product screens. A Platform Admin **publishes** it as a
   numbered version that never changes. Halls import only published versions.
5. A dashboard notice points Hall Managers to the import.
6. No repeat import, reset, selective import or later sync.
7. An SKU the Hall already uses is left blank on the copy and reported.
8. No edit locks on unfinished copies; warnings are shown instead.
9. Super Admin **Resolve** settles the rare cases the importer cannot decide.
   Resolve has no "create it" action.
10. **Simple and variable** products are supported. Both may use
    **product-level attributes only**; global attributes (`pa_*`) are out of v1
    and Publish rejects them on either type.
11. Text verification and Resolve eligibility use the wording in sections 11
    and 13 of this record.

## 2. Terms used in this record

| Term | Meaning |
|---|---|
| Master catalog | The products on the main site marked "Include in starter catalog" |
| Version | A frozen copy of the master catalog, made by Publish, with its own image files |
| Import | One Hall's one-time copy of one version |
| Item | One product, or one variation, inside an import |
| Recovery identifier | A short random value saved for an item before it is created and used as that post's `post_name` in its first save |
| Resolve | A Super Admin action that settles an item the importer cannot decide |

## 3. Scope

Included:

- Ordinary WooCommerce simple and variable products, with any number of
  products (24 today; 24 is not a limit).
- One optional import per Hall, for new, existing and legacy Halls.
- Hall-owned products and image files, independent of the master.
- Continue, Retry and a limited Super Admin Resolve.

Excluded:

- Bingo Play Products, Packages, add-ons, Game Types and Sessions.
- Global attributes, downloads, shipping data, sale prices, product links and
  plugin-specific data.
- Selective import, repeat import, reset and ongoing sync.

Portal Enable continues creating an empty ready store. The import takes no part
in provisioning or readiness. Portal webhooks and conflict policy, Package hard
deletion, Hall-wide projection scanning and network-account lifecycle remain
separate scopes.

## 4. Who does what

| Person | Responsibility |
|---|---|
| Platform Admin / Network Super Admin | Maintain the master products, publish versions, turn new imports on or off, and Resolve interrupted imports |
| Portal Global Admin | Enable, suspend and re-enable the Hall through the existing process |
| Hall Manager | Optionally import, Continue or Retry, then manage the drafts |
| Game Operator | No access |

Super Admin imports follow the same Hall availability rule as Hall Manager
imports. A suspended Hall cannot import.

## 5. Master catalog (main site)

- Platform Admins use the normal WooCommerce product editor on the network main
  site.
- Each product has an **Include in starter catalog** checkbox:
  - stored as ordinary product metadata `bc_starter_catalog_include`;
  - editable only by Network Super Admins, enforced on the server;
  - never copied to a Hall's product.
- Included products must be **Draft, Pending or Private**.

**Network Admin → Starter Catalog** shows:

- the number of included products;
- the latest published version, its product count, who published it and when;
- a link to the main site's Products list;
- **Publish New Version**;
- **Resume** and **Discard** for an interrupted build;
- **Allow new Hall imports: On/Off**.

There is no extra protection blocking every possible way of publishing a master
product. Publish checks the selected products.

## 6. Publish

Publish runs in small steps:

1. Take the network publish lock.
2. Record the selected product IDs in ascending ID order.
3. Create a version with status **Building** and save the main site's currency
   and "prices include tax" setting.
4. Capture product fields, attributes, variations, categories, tags and
   independent copies of the referenced images.
5. Check that every selected item and required image was captured. Re-check
   currency and the tax setting.
6. Change the version to **Published** only after those checks pass.

- Resume uses the saved selection and order.
- An interrupted build is never offered to Halls.
- The previous published version stays available while another builds.
- The Platform Admin should avoid editing selected products during Publish. A
  last-modified check on each product and variation detects ordinary changes
  during capture; it is not a guarantee against every concurrent edit.

**Recovery rules** (agreed for stage 4 on 2026-10-19):

- The whole selection is saved with one multi-row INSERT after the Building
  version row. A Building version with no selection rows can only come from an
  interrupted Start; it is never resumed or published, only discarded.
- Each source image has one image row and one file per version. A retry reuses
  the same row (its ID never changes) and overwrites its fixed path.
- A product, a variation (added, removed or edited) or a frozen image that
  changed since capture stops the final check with "<product> changed during
  publication. Select Resume to capture it again." The product is marked
  pending; Resume checks and captures it again, and shows its problem if it
  has become invalid. There is no automatic re-capture loop.
- A changed currency or "prices include tax" setting stops the final check;
  the version must be discarded.
- Discard marks the version Discarded before removing its rows and files, and
  can be repeated until cleanup is complete. A Discarded version is never
  resumed or published.
- Start, Continue, publication and Discard take the publish lock and re-check
  Super Admin authorization; the admin requests also check the nonce and the
  main site.

**Publish blocks when:**

- no products are selected;
- a selected product is missing, is not a top-level simple or variable
  product, or is downloadable;
- its status is not Draft, Pending or Private;
- its cleaned name is empty;
- its regular price is neither blank nor a valid decimal;
- a required image is missing, unsupported, or over the agreed file-size or
  dimension limits;
- a description contains embedded images, links to the main site or
  shortcodes;
- a variable product has no variations;
- a simple or variable product uses a global attribute (WooCommerce's
  `is_taxonomy` flag is set). The message names the product and the attribute;
- a variation is downloadable, has a regular price that is neither blank nor a
  valid decimal, is not enabled or disabled (`publish` / `private`), or has an
  image outside the limits.

**Cleaning.** Names and descriptions use WordPress's normal field-appropriate
cleaning. Products whose content lost something are listed as warnings. The
earlier proposal for an extra cleaning pipeline is excluded.

**Slug.** Use the source slug when present. Otherwise make it from the cleaned
name. If that is empty, use `starter-product-<source-product-ID>`.

**A version saves:**

- for each product: source ID, display order, type, cleaned name and
  descriptions, slug, regular price, SKU, category and tag slugs, tax status,
  tax class, stock tracking on/off, featured and gallery image references in
  order;
- for each simple and variable product: each product-level attribute (name,
  options, visible on the product page, used for variations, position);
- for a variable product: the default attributes;
- for each variation: source ID, parent source ID, display order, attribute
  values (blank means "Any"), regular price, SKU, stock tracking on/off,
  enabled or disabled, tax class (including "Same as parent"), cleaned
  description and image reference;
- the category tree, the tag list, currency and the "prices include tax"
  setting;
- for each image: its own frozen file copy, checksum, type, dimensions, size
  and alt text.

Not saved: sale prices, weight, dimensions and shipping class, for products or
variations.

Published versions never change. Publishing a new version affects future
imports only. Versions and their files are kept in v1.

## 7. What gets copied

| Field | Rule |
|---|---|
| Product type | Simple or variable |
| Name and descriptions | The version's cleaned values (see section 11) |
| Regular price | The stored decimal value, without rounding; blank stays blank |
| Sale price and sale dates | Not copied |
| SKU | Copied when present. If the Hall already uses it, the copy gets a blank SKU and a note |
| Featured and gallery images | Hall-owned files and image records; order and alt text kept |
| Categories and tags | Reuse a Hall term with the same taxonomy and slug without changing it; otherwise create the needed terms |
| Category hierarchy | New terms keep the source hierarchy; an existing Hall term keeps its own parent and a difference is noted |
| No categories in the version | The Hall's default product category |
| The master's default category | An ordinary captured category; no special mapping |
| Stock | Tracking on/off is kept; tracked products start at 0 and out of stock; untracked products start in stock; backorders off |
| Tax | Tax status copied; tax class matched by slug; a missing required class stops the import with a clear message |
| Product status | Always Draft |
| Author | The user who creates the product; resuming does not change the author |
| Product-level attributes | Copied exactly: name, options, visibility, used for variations, order |
| Default attributes | Copied |
| Variations | One per source variation, in source order: attribute values, regular price, SKU (Hall conflict: blank plus a note), stock tracking kept (tracked starts at 0), enabled/disabled kept, tax class, description, image |
| Parent price range and stock status | Not copied; WooCommerce calculates them from the variations with `WC_Product_Variable::sync()` |
| Shipping data, downloads, history and product links | Not copied |
| BingoCommerce data | The importer creates no Bingo relationships or classification metadata |
| Other fields | WooCommerce defaults, checked against the actual starter products before implementation |

Products are never merged with existing Hall products by name or SKU.

"No BingoCommerce data" means the importer does not create those associations
or copy master-specific metadata. It does not strip metadata or bypass normal
platform behaviour.

## 8. Hall page and notices

The page is **Products → Starter Products**, available on Hall sites to users
with `bc_manage_products` and to Super Admins.

| State | Display and action |
|---|---|
| Not started | "24 starter products available." **Import Starter Products** |
| Importing | Saved progress, including imported and skipped counts, and the current variation, for example "Importing product 2 of 24 (variation 9 of 15)" |
| Interrupted | Saved progress. **Continue Import** |
| Failed | Plain explanation and the affected item. **Retry Remaining Products** |
| Needs attention | A support reference; Super Admins see **Resolve** |
| Complete | Date, imported and skipped counts, notes and **View Products** |
| Unavailable | A clear reason; normal manual product creation still works |

Example completion: "Import finished: 22 products imported, 2 skipped."

- A dashboard notice links to the page. Dismissal is per user, per Hall.
  Completed imports no longer show it.
- The import page and the edit screen of an identifiable unfinished product
  show: "This product is still being imported. Changes may be replaced."
- No broad edit locks.

## 9. Starting and running an import

**The Start request:**

1. Checks the signed-in user, capability, nonce and the Hall resolved on the
   server.
2. Refuses the main site and checks Hall access.
3. Takes the Hall import lock and checks the importer's storage and existing
   history.
4. If the import is already complete, returns the receipt. If it already
   started, uses that import.
5. For a new import, checks the availability switch and picks the latest
   published version as a candidate.
6. Checks currency, "prices include tax", every required tax class and the
   estimated image storage.
7. Only after these checks pass, records the durable start marker and the
   import receipt with the chosen version.

- A failed check does not bind the Hall to that version and creates no product.
- The separate start marker is written before any product is created. If the
  marker exists but the receipt is missing or unreadable, the import stops for
  repair and no new import is offered.
- Once started, the import always continues with its recorded version.

**Processing requests** use admin-ajax actions and a small script that sends
one request at a time. Every request:

- re-checks authorization, nonce and Hall access;
- takes the Hall import lock;
- re-checks currency and the tax setting;
- confirms the required tax class before applying an unfinished product;
- does a small amount of work and saves checkpoints.

About five seconds of work per request is a target, not a guaranteed maximum
for an image step. Closing the browser stops further requests; a request
already running may finish; returning to the page offers Continue. Start,
Continue, Retry and Resolve use the same Hall lock; a second request gets
"already running". Lost or uncertain lock ownership stops further processing.

If REST is used instead of admin-ajax, every route needs a
`permission_callback` and a `sanitize_callback` on every parameter (AGENTS.md
rule 5).

## 10. Copying a product

**A new item:**

1. Save the item identity and a short recovery identifier that does not depend
   on the product name.
2. Record **Creation starting**.
3. Create a minimal draft through normal WooCommerce APIs, with the recovery
   identifier as its `post_name`. WooCommerce passes the slug in the first
   insert (`class-wc-product-data-store-cpt.php:224`) and WordPress keeps a
   draft's slug unchanged (`wp-includes/post.php:5464`).
4. Save its product ID as soon as creation returns.
5. Apply the remaining fields, categories, tags and images.
6. Save the resolved decisions, including any omitted SKU or image, so retries
   stay consistent.
7. Verify the saved result (section 11), set the normal slug and mark the item
   complete.

A minimal first save reduces the work done before the ID is recorded. It
carries no "2 ms" or single-query guarantee.

**Retry rules**, applied before changing an existing item:

| Saved situation | Action |
|---|---|
| Complete or deliberately skipped | Left untouched |
| Never started | Create |
| Product ID saved; deleted or trashed | Skip with a note |
| Product ID saved; linked to a Play Product or Package | Skip with a note |
| Product ID saved; no longer Draft | Skip with a note |
| Product ID saved; ordinary, unlinked Draft | Finish the unfinished item |
| Creation started but no product ID saved | Needs attention |
| Database or read error | Stop; never treated as "not found" |

Catalog membership comes from the Hall's authoritative `bingo_packages` and
`bingo_play_products` tables, not from classification metadata alone. Edits to
unfinished ordinary drafts may be replaced, as the warning says. Completed
products are never refreshed from the master.

**Variable products:**

1. The parent is created as above, with its attributes and default attributes.
2. Each variation is its own item, linked to its parent item: claim; create it
   with the recovery identifier as `post_name` (WooCommerce passes the slug in
   the first insert, `class-wc-product-variation-data-store-cpt.php:151`); save
   its ID; apply its fields and image; verify; mark complete. Variation slugs
   are not renamed because variations have no public address.
3. After every variation is complete or deliberately skipped, run
   `WC_Product_Variable::sync()` on the parent
   (`class-wc-product-variable.php:710`), verify the parent, set its normal
   slug and mark it complete with the omission notes.

Variation retry rules:

| Saved situation | Action |
|---|---|
| Variation ID saved; it still exists under the saved parent | Finish it |
| Variation ID saved; deleted, or under a different parent | Skip with a note |
| Creation started but no variation ID saved | Needs attention |
| Parent skipped | All its variations are skipped |
| Stopped after the last variation, before the parent sync | Retry syncs and completes the parent |

## 11. Text verification

**Products (name, description, short description):**

1. In the same request that applies the item's text (including a Retry that
   re-applies it), compute each field's expected stored value with WordPress's
   own save sanitization:
   `wp_unslash( sanitize_post_field( $field, $frozen_value, $product_id, 'db' ) )`
   for `post_title`, `post_content` and `post_excerpt`. This repeats what
   `wp_insert_post()` does to the same input under the filters already active
   for the current user. No filter is added, removed or forced.
2. If the expected name is empty, stop the item before saving it (Failed,
   naming the product).
3. Save through WooCommerce.
4. Clear that product's post cache (`clean_post_cache()`) and reload it
   (`get_post()`).
5. Each reloaded field must equal its expected value exactly. A blank frozen
   value must reload as blank.
6. Any difference: the item is Failed ("Saved text differs from what WordPress
   should store") and is not completed. Retry re-applies and re-verifies in a
   new request.
7. Text saved by an earlier request is never verified on its own. It is
   re-applied first, because the user context can differ (Hall Manager or
   Super Admin).

**Variations:**

- The variation title is generated by WooCommerce from the parent name and
  attribute values (`class-wc-product-variation-data-store-cpt.php:121`), so it
  is checked to be non-empty, not compared with the version.
- The variation description is stored as metadata through `update_post_meta()`
  (`class-wc-product-variation-data-store-cpt.php:599`), which first removes
  one level of slashes and then runs `sanitize_meta()`
  (`wp-includes/meta.php:222–223`). Its expected value follows that order:
  `sanitize_meta( '_variation_description', wp_unslash( $value ), 'post', 'product_variation' )`,
  read back and compared exactly.

**Structured fields** keep exact verification against the saved import
decisions: Draft status, type, regular price string, SKU (or the decided
blank), stock settings, tax status and class, category and tag IDs, image IDs
and gallery order, attributes and default attributes, and no Package or Play
Product membership. For each variation: parent ID, display order, attribute
values, regular price, SKU decision, stock tracking flag, enabled status, tax
class and image ID. Stock is checked by tracking: a tracked variation must have
quantity 0 and be out of stock; an untracked variation has no quantity, because
WooCommerce clears it when tracking is off (`abstract-wc-product.php:1517–1523`),
and must have its intended stock status (in stock).

Evidence checked in the installed source:

- WooCommerce stores product text unchanged through its setters and passes it
  unslashed to `wp_update_post()` outside `save_post`
  (`abstract-wc-product.php:760`, `:844`, `:855`;
  `class-wc-product-data-store-cpt.php:330–332`, `:365`).
- `wp_update_post()` adds slashes only to object input (`post.php:5230`).
- `wp_insert_post()` runs `sanitize_post( 'db' )`, then `wp_insert_post_data`,
  then `wp_unslash()`, then writes (`post.php:4539`, `:4885`, `:4888`); the
  `'db'` context applies `pre_post_*` and `*_save_pre` (`post.php:3087`).
- Kses filters attach for users without `unfiltered_html` (`kses.php:2468`,
  `:2482–2483`), re-checked on `init` and `set_current_user`
  (`default-filters.php:591`); in Multisite only Super Admins have
  `unfiltered_html` (`capabilities.php:599`).
- `convert_invalid_entities` always runs on content and excerpt saves;
  `balanceTags` runs only when `use_balanceTags` is on
  (`default-filters.php:157`, `formatting.php:2564`), and it is off on the
  local main site.
- WooCommerce's own `wp_insert_post_data` hook does not change the text of a
  draft product saved outside the product edit form
  (`class-wc-post-data.php:357`).

Assumptions the tests must prove: no other active plugin changes product text
after sanitization (if one does, verification fails safely); one level of
backslashes is removed on save, and the expected value includes that; entity
and quote results are computed, not predicted.

## 12. Images

For each distinct version image:

1. Save its planned Hall-owned path and recovery information.
2. Check image type, dimensions, size and available storage.
3. Write the file and verify its checksum.
4. Record "attachment creation starting", create the attachment with the
   recovery identifier as its `post_name`, and save its ID.
5. Create image sizes with WordPress's own image functions, including
   `wp_update_image_subsizes()` to resume missing sizes
   (`wp-admin/includes/image.php:159`).
6. Refresh the affected storage-size cache and mark the image complete.

- An image shared by several imported products, or by a product's gallery and
  its variations, is copied once per Hall import.
- Only the importer's own unfinished file may be overwritten during recovery.
- Completed media is reused without being rewritten.
- Once the attachment ID is saved, it is used to follow WordPress's `-scaled`
  image paths.
- Media the Manager deleted after it was completed is omitted with a saved
  note.
- Missing originals or processing errors stop that work with a clear reason.
- An uncertain attachment creation uses Resolve.
- The Multisite upload quota is enforced **only when quota checking is
  enabled**. Writes must still succeed when quotas are off. The directory-size
  cache is kept accurate after file changes.

## 13. Resolve

Resolve is available to Super Admins on the Hall's Starter Products page, under
the Hall import lock, with the item's state re-read when the button is clicked.
There is no "Create it and continue" action. Resolve records the administrator's
action and reason.

**Products:**

1. Search the current Hall's `product` posts of **every status** whose
   `post_name` is the item's saved recovery identifier, or that identifier plus
   `__trashed`.
2. Offer **Use it and continue** only when **exactly one** product matches and
   it:
   - has `post_name` equal to the recovery identifier and status `draft`;
   - is still a simple or variable product of the item's saved type;
   - has **no row** for its `wc_product_id` in the Hall's `bingo_play_products`
     or `bingo_packages`, including archived rows; classification metadata is
     ignored.

   Then its product ID is saved and the "Product ID saved" rules finish it.
3. Zero matches, more than one match, a trashed match, any other status, a
   different type, or Catalog membership: **Skip this item** only; the found
   product is left untouched.
4. A database error, or Catalog tables that are not current: stop. Nothing is
   offered and the result is never treated as "not found".

**Variations:**

- Search the current Hall's `product_variation` posts of every status whose
  `post_name` is the recovery identifier.
- **Use it** only when exactly one matches, its parent is the saved parent
  product ID, its status is `publish` or `private`, and that parent is still
  the importer's own Draft.
- Anything else: **Skip this variation** only, with a note
  ("Variation Blue skipped"). A database error stops.

**Images:**

1. Search the current Hall's `attachment` posts of every status whose
   `post_name` is the image's saved recovery identifier or that identifier plus
   `__trashed`.
2. Offer **Use this image** only when exactly one matches and:
   - its status is `inherit` and its `post_name` equals the recovery
     identifier;
   - `wp_get_original_image_path()` equals the saved planned path, which also
     covers `-scaled` copies;
   - that file exists and its checksum equals the version image's checksum.

   Then its attachment ID is saved and the image steps continue.
3. Anything else: **Skip this image** only, with a saved omission note. Nothing
   is recreated, and the found attachment, its file and all other media stay
   unchanged.
4. A database error stops.

After a skipped product, the Manager can check existing products and create
anything still needed. The import continues with the remaining items.

Evidence checked in the installed source: trashing renames a slug to
`…__trashed` (`post.php:4810`, `:8399`); without `MEDIA_TRASH` attachments are
deleted permanently instead (`post.php:6702`); attachments are forced to
`inherit` unless `private`, `trash` or `auto-draft` (`post.php:4612`);
`wp_insert_attachment()` passes its arguments, including `post_name`, to
`wp_insert_post()`, and the attached-file record is written after the insert
(`post.php:5033`); `wp_get_original_image_path()` resolves the original from
`original_image` metadata (`post.php:8460`; set at `image.php:223`).

## 14. Storage and schema

Tables owned by `bingocommerce-catalog`:

| Scope | Table | Holds |
|---|---|---|
| Network | `bingo_starter_catalog_versions` | Version state, settings, counts and publication information |
| Network | `bingo_starter_catalog_version_products` | Captured product and variation fields and order; `parent_source_id` (empty for top-level products) and `product_type` (simple / variable / variation) |
| Network | `bingo_starter_catalog_version_images` | Frozen image references, checksums and image information |
| Hall | `bingo_starter_import` | One-time receipt, chosen version, counts, status and errors |
| Hall | `bingo_starter_import_products` | Item progress, `parent_item_id`, recovery identifier, destination ID, resolved decisions and notes |
| Hall | `bingo_starter_import_images` | Image progress, planned path, destination attachment ID and recorded omissions |

- Network tables use `$wpdb->base_prefix`. Hall tables use the resolved Hall's
  `$wpdb->prefix`, without a redundant `blog_id` (AGENTS.md rule 6).
- Indexed identities and unique keys for version products and import items.
  Every product and every variation is one row. Position is display order, not
  identity.
- A separate Hall option, `bc_starter_import_started`, holds the import
  identity. The marker and the receipt are kept for the Hall's lifetime.

Schema behaviour:

- Separate network and Hall importer schema versions, not part of Catalog's
  `bc_catalog_db_version` and not part of the readiness check.
- Network tables are created or upgraded on the Starter Catalog admin page.
- Hall tables are created or upgraded when Starter Products is opened
  (including completed Halls) and before processing.
- Missing earlier history means repair, never a silent reset.
- An importer schema failure affects importing only.
- No importer migration runs during Enable or in the Catalog readiness check.

This is a deliberate exception to the "provision new sites automatically" rule
in `docs/architecture/plugin-architecture.md` section 3, so that an optional
feature can never affect Enable.

Frozen files use one storage location chosen by the server. Production shared
storage or S3 compatibility (ADR-009) must be confirmed before release.

## 15. Security, dependency, suspension and off switch

- Every action checks its specific permission, nonce and current state.
  Publish and Resolve are Super Admin only.
- The browser cannot choose Hall IDs, source sites, file paths or download
  URLs. SQL is parameterized; output is escaped; public errors omit SQL,
  secrets and internal paths.
- Hall access uses a guarded, read-only call to
  `BingoCommerce\PortalIntegration\HallCommerceAccess::evaluate()`. If the
  evaluator is unavailable, importing is unavailable; the rest of Catalog keeps
  working. The access policy is not copied, and an unavailable evaluator is
  never read as legacy access. This narrow dependency is documented in
  `plugin-architecture.md`.
- Suspension pauses importing for all users, including Super Admins.
  Re-enable allows Continue. An expired session or nonce requires signing in
  or refreshing before continuing.
- The availability switch blocks new imports only. Started imports may
  Continue or Resolve. It never deletes products or resets history.

## 16. Performance and audit

- Scripts load only on the relevant admin pages. No importer work runs on
  storefront, cart or checkout requests.
- Publish works in pages; imports run one request at a time; progress lookups
  use indexes. No Hall-wide product scans, no cross-Hall scans, and no long
  transactions around WooCommerce saves or image work.
- The existing audit service records version publication, and import start,
  failure, Needs attention, Resolve and completion. Network events use
  `blog_id = null`; Hall events use the Hall's ID. Action names are namespaced,
  for example `starter_catalog.version_published` and
  `starter_import.completed`, with payloads that pass the existing
  sensitive-data rules.
- The existing audit idempotency support is reused. An audit failure never
  causes a product to be created again. No new audit viewer.

## 17. Accepted limits

- Edits to an unfinished draft may be replaced.
- An ambiguous creation can require support (Resolve).
- SKU uniqueness follows WooCommerce's own limit: two products saved at the
  same moment can share an SKU.
- A rare interrupted category save can leave an unused term record.
- Published versions are kept in v1.
- A request that stops between a version's publication and its audit record
  leaves that one audit event unrecorded.

## 18. Changes to existing decisions (in effect since approval on 2026-10-19)

- **BR-047:** amended and narrowed by BR-086. Starter Bingo Play Products and
  Game Types are removed; "copy at provisioning" becomes an optional import the
  Hall starts; BR-047's Hall ownership and no-live-sync rules stay.
- **ADR-027:** narrowed. Starter products are no longer copied at provisioning;
  the master is stored as published versions on the main site.
- **ADR-026:** its line "`bingocommerce-platform` triggers
  `bingocommerce-catalog`'s starter-catalog copy mechanism during hall
  provisioning" is replaced. Catalog still owns starter-product logic; the
  platform plugin has no role in it.
- **ADR-042:** unchanged. Enable still creates an empty store.
- **`plugin-architecture.md`:** section 3 gains the schema exception in
  section 14 of this record; the Catalog plugin gains one guarded, read-only
  dependency on `HallCommerceAccess`.
- **AGENTS.md rule 8:** the six importer tables are workflow tables, not
  Catalog or Session authority; ADR-031's locked table list is unchanged.

## 19. Tests

All tests live in `tests/` with isolated fixtures.

| Area | Required verification |
|---|---|
| Optional import | Manual product creation works without importing |
| Catalog size | Nothing published, one product, 24 products and a larger catalog |
| Starting checks | A failed check creates no permanent import; a corrected attempt can start |
| Recovery | Interruptions before and after product and attachment ID saving, during fields and images, and before completion |
| Repeat requests | Double clicks, two tabs and lost responses do not repeat completed work |
| Resolve | Verified reuse and Skip; uncertain identity never causes automatic creation |
| Hall ownership | Completed edits remain; removed, linked and non-draft unfinished products are skipped |
| History | A missing receipt with an existing start marker cannot allow another import |
| Fields | SKU conflict, missing tax class, category reuse, empty-slug fallback and recorded image omissions |
| Storage | Quotas on and off, write failures, partial images and native image-size resumption |
| Access | Wrong role, invalid nonce, expired session, suspension, unavailable access dependency and main-site refusal |
| Isolation | Two Halls importing at the same time cannot mix records or files |
| Schema | Importer failures do not break Enable, readiness or other Catalog features; completed Halls upgrade |
| Completion | Accurate imported and skipped counts; no repeat-import action |
| Regression | Provisioning, Portal sync, Catalog screens, cart and checkout keep working |

Text verification:

| Case | Must happen |
|---|---|
| Allowed HTML (`p`, `strong`, `ul/li`, `a href`) | Stored equals expected; item completes |
| Disallowed markup (`onclick`, `iframe`) saved by a Hall Manager | Removed; stored equals expected, not the frozen text; completes. The same item run by a Super Admin keeps it and still equals expected |
| Entities: `&amp;`, `&nbsp;`, `&#8217;`, `&#150;`, a bare `&` in the name | Stored equals expected |
| Straight, curly and attribute quotes; backslashes | Stored equals expected |
| Blank description or short description | Reloads blank; completes |
| Old non-blank text where blank is expected | Failed |
| Name that cleans to empty | Stopped before save |
| Simulated database failure on update | Failed, not completed; Retry completes |
| Stopped after the minimal draft | Retry re-applies and verifies |
| A test-only hook rewrites the text after save | Failed with a clear reason |
| Hall Manager saves, Super Admin continues | Re-applied and verified in the Super Admin's request; completes |
| Variation description with HTML, quotes and backslashes | Reloaded value equals the expected value |
| Structured fields | Price, SKU decision, stock, tax, term IDs, image IDs and order, attributes and Draft all match exactly |

Resolve eligibility:

| Case | Must happen |
|---|---|
| One draft match, right type, no Catalog row | Use is offered; the item completes |
| No match, two matches, or a trashed match | Skip only |
| One match that is pending, private, published or scheduled | Skip only |
| Product type changed | Skip only |
| A row in `bingo_play_products`, or in `bingo_packages` (including archived) | Skip only |
| `bc_product_kind` set but no Catalog row | Still eligible |
| A Catalog row but no metadata | Skip only |
| Database error in the search or membership check; Catalog schema not current | Stops; nothing offered |
| State changes between page view and click | The server re-check refuses |
| Product Skip | The found product is unchanged; the import continues |
| One variation match under the saved parent, `publish` or `private` | Use is offered |
| Variation under another parent, or no or two matches | Skip only |
| One `inherit` image match, original path equals the planned path, checksum matches | Use is offered; image sizes resume |
| The same image after a `-scaled` copy was made | Use is offered |
| Image file missing, checksum different, or path different | Skip only |
| No image match, two matches, or trashed (`MEDIA_TRASH` on) | Skip only |
| Image Skip | A note is saved; no attachment is created or changed; other media untouched |
| Database error in the image search | Stops |

Variable products:

| Case | Must happen |
|---|---|
| 1on Bingo Paper (15 variations) | Draft parent plus 15 enabled variations; attributes and default attribute match; WooCommerce shows $36.15 and out of stock (all tracked at 0) |
| Dab O Ink (13 variations, Assorted untracked) | 12 variations out of stock and Assorted in stock; parent price range $1.06–$13.12 |
| Untracked variation (Assorted) verification | Saved with no quantity and in stock; verification passes |
| 12 variations complete and one deliberately skipped | Parent is synced, verified and completed with an omission note |
| Global attribute (`pa_color`) on a simple or variable product | Publish blocked, naming the product |
| Variable product with no variations | Publish blocked |
| Variation SKU conflict (`S0101BSP9` at Star Bingo Hall) | That variation without an SKU plus a note; others keep their SKUs |
| Crash between variation creation and saving its ID | Needs attention; Resolve rules apply |
| Crash after all variations, before the parent sync | Retry syncs and completes |
| Manager deletes a variation during the import | Skipped with a note, not recreated |
| Parent skipped | Its variations are skipped |
| "Any" variation; disabled variation | Copied as "Any"; stays disabled |
| Images shared between the gallery and variations | Each copied once per import |

## 20. Stage 1 findings and open checks

Checked read-only on the local installation on 2026-10-18 and 2026-10-19:

- The main site (`bingocommerce.test`, site 1) runs WooCommerce 11.1.2
  network-active, sells nothing, uses USD with prices excluding tax and taxes
  off, has the Reduced rate and Zero rate tax classes, and `use_balanceTags` is
  off. Its only administrator is a Super Admin.
- All seven local Halls use USD with prices excluding tax and have the same tax
  classes.
- Network settings: `upload_space_check_disabled` is on (quota off), so
  WordPress's per-site quota and per-file limit do not apply; `MEDIA_TRASH`
  and `DISALLOW_UNFILTERED_HTML` are not defined. Publish's own image limits
  are therefore the only real limits.
- No active plugin hooks product saves in a way that changes product text.
  WPForms Lite (active on one Hall) hooks `save_post` for its own tracking and
  returns early for other post types.
- Two sample master products exist: "1on Bingo Paper By The Case 9,000
  Sheets" (variable, 15 colours, $36.15, **Published** — must become Draft or
  Private before Publish) and "Dab O Ink 3 Ounce By the Bottle" (variable, 13
  colours, $1.06 and $13.12, Draft). Both use one product-level attribute,
  "Color". Their 28 PNG images total about 6.3 MB; the largest is 1.34 MB and
  2000×2000.
- Star Bingo Hall already uses SKU `S0101BSP9`, which the 1on Blue variation
  also uses.

Still to confirm (release checks):

- the production main site's settings, administrators and storage;
- the actual starter products' fields, content and image limits;
- production storage integration (shared disk or S3);
- measured image-processing time;
- whether WooCommerce's attribute lookup table indexes product-level
  attributes (affects only attribute filter widgets);
- that random recovery identifiers on enabled or disabled variations never
  collide (a collision fails safely to Skip).

Answered in stage 6 (2026-10-20, isolated fixture, not production):

- WooCommerce's attribute lookup table indexes global attributes only
  (`LookupDataStore::get_attribute_taxonomies()` skips product-level ones), so
  the starter products' product-level attributes, such as Color, are not in it.
  Only attribute filter widgets are affected; the product pages and variation
  choices work as usual.
- Image processing measured locally with GD and nine image sizes: 28 test
  images shaped like the real samples (2000 x 2000 PNG, 6.1 MB in all) took a
  median of 0.30 s each (slowest 0.35 s); every request ended within the 5 s
  budget plus one image. Production's image library and timing remain a
  release check.

## 21. Order of work

| Stage | Work | Done when |
|---|---|---|
| 1 | Inspect the actual starter products and source site | Supported fields, content and image limits confirmed |
| 2 | Update the architecture and requirement documents | This scope and its narrow exceptions are recorded |
| 3 | Build the storage and recovery proof | Product and image interruption tests pass before any UI work |
| 4 | Build master selection and version publication | Publish, Resume, Discard and validation work |
| 5 | Build the Hall page and importer | Import, Continue, Retry, Resolve, notices and receipts work |
| 6 | Integration and browser verification | New and legacy Hall scenarios pass; image timing measured |
| 7 | Release | Test-environment imports pass before new imports are turned on in production |

Administration, publication, import processing, storage, migrations and locking
stay as focused responsibilities inside Catalog. No generic import framework.

The documentation stage also updates: `business-requirements.md` (starter
section), `plugin-architecture.md` (ownership, dependency and the schema
exception), `catalog-session-persistence-design.md` and
`portal-integration-architecture.md` (the "deferred starter copy" lines),
AGENTS.md (Platform Admin and Hall Manager summaries), and `PROJECT_STATUS.md`,
without claiming implementation or production deployment.
