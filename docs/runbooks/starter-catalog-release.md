# Starter catalog: release checklist

Date: 2026-10-21. This is the ADR-049 stage 7 checklist for turning on
starter product imports outside development. ADR-049 section 21: stage 7 is
done when **test-environment imports pass before new imports are turned on
in production**. Nothing here claims a deployment has happened.

Run each part in order: A and B on the test environment first, then A again,
C and D on production. Write the result next to each check (pass, or what
you saw).

## Open blocker: storage (decide before anything else)

Publish saves frozen copies of the starter images on the server's own disk,
in `wp-content/uploads/bingo-starter-catalog/v<version>/` (or the folder set
by `BINGOCOMMERCE_STARTER_CATALOG_DIR` in `wp-config.php`). Every Hall import
reads them from that folder, and each Hall's own images are written into its
normal uploads folder.

| Production setup | What it means |
|---|---|
| One web server | Works as built. |
| Several web servers sharing one disk for uploads | Works as built, if the frozen folder is on the shared disk. |
| Several web servers, uploads only in S3 (or another object store) | **Does not work as built.** A Publish on one server leaves the frozen images where other servers cannot read them, and Hall images written to local disk would not reach S3. This needs new work and an ADR-049 change (stage 7.2) before release. |

As of 2026-10-21 the production hosting provider and the S3 provider are not
chosen (AGENTS.md, "Provider / Infrastructure Still Open"). **Do not turn on
new Hall imports in production until this is decided and, if needed, 7.2 is
done.**

## A. Server and network checks

Do these as a Network Super Admin. "Site Health" means Tools → Site Health →
Info on the main site.

| # | Check | How | Pass when |
|---|---|---|---|
| A1 | Storage decision | See the blocker above. | One server, or a shared disk that holds the frozen folder. |
| A2 | Frozen folder is writable | Site Health → Info → Filesystem permissions, and confirm the folder from A1 exists or can be created by the web server user. | Writable by the web server; not publicly listable. |
| A3 | Image library | Site Health → Info → Media handling: the active editor (Imagick or GD) and its supported formats. | PNG and JPEG are supported (the real samples are PNG). Write down which editor is active; development uses GD. |
| A4 | PHP limits | Site Health → Info → Server: PHP memory limit and max execution time. | Memory 256M or more (2000×2000 images); max execution time 30 s or more (a request works about 5 s, plus one image). |
| A5 | Upload quota | Network Admin → Settings → "Site upload space". | Either off, or each Hall has room for the images plus the sizes WordPress makes from them. The import checks for 3 times the original image bytes: about 19 MB for the current samples (6.3 MB of originals). With the quota on, imports stop before copying when space runs out. |
| A6 | `MEDIA_TRASH` and `DISALLOW_UNFILTERED_HTML` | Read `wp-config.php`. | Write down both values. Neither blocks release. `MEDIA_TRASH` changes only how Resolve sees trashed images. In Multisite, Hall Managers' product text is always filtered; `DISALLOW_UNFILTERED_HTML` also filters it for Super Admins. |
| A7 | No page caching on admin | Check the hosting cache rules. | `/wp-admin/` and `/wp-admin/admin-ajax.php` are never page-cached (AGENTS.md rule 11). |
| A8 | Object cache is per Hall | If Redis object caching is on, check its Multisite key prefixing. | Keys include the site; clearing one Hall never clears another (AGENTS.md rule 10). |
| A9 | Main site settings | Main site → WooCommerce → Settings → General and Tax. | Currency and "prices entered with tax" are the same as the Halls use (development: USD, excluding tax). Every tax class a starter product uses exists in the Halls. |
| A10 | Who can publish | Network Admin → Users → Super Admins. | Only the people who should publish starter versions are Super Admins. |
| A11 | Plugins and schema | Network Admin → Plugins, then open Network Admin → Starter Catalog once. | BingoCommerce Catalog and Portal Integration are network-active; the page loads without a "repair" message. |

## B. Test-environment run

Use a test Hall you are happy to keep as "imported" (an import is one-time
per Hall, with no reset). Follow `docs/runbooks/starter-catalog-testing.md`
for the details of each screen.

| # | Do this | Pass when |
|---|---|---|
| B1 | On the main site, tick **Include in starter catalog** on the real starter products and make each one Draft, Pending or Private. | Starter Catalog shows the right "Included products" count. |
| B2 | **Publish New Version.** Note how many requests it takes and any warnings. | "Version N is published with M products."; the frozen folder for that version has one folder per image. |
| B3 | Turn on **New Hall imports**. On the test Hall, as its Hall Manager, open the dashboard and Products → Starter Products. | The "available" notice and "M starter products are available." |
| B4 | Select **Import Starter Products**. While it runs, interrupt it once (close the tab, or go offline in the browser's developer tools), then reopen the page and select **Continue Import**. | The import finishes: "Import finished on MM/DD/YYYY: M products imported, 0 skipped."; no duplicate products. |
| B5 | Measure image timing: in the browser's developer tools → Network, note each `admin-ajax.php` request's time. | Every request ends in about 5 s plus one image. Record the longest request and the active image editor (A3) in PROJECT_STATUS.md. |
| B6 | Check the result in Products → Drafts and the media library. | Prices, variations, stock and images look right; notes (for example an SKU already used in that Hall) are listed on the Starter Products page. |
| B7 | Turn **New Hall imports** off again. | The switch reads Off. |

If anything fails, stop and fix it before part C. Keep the test Hall: its
import record is the evidence that B passed.

## C. Go-live in production

Only after A passes on production and B passed on the test environment.

| # | Do this | Pass when |
|---|---|---|
| C1 | Repeat B1 and B2 on the production main site. | The production version is published. |
| C2 | Turn on **New Hall imports**. | The switch reads On. |
| C3 | Ask one Hall to import first (or import into a Hall you look after), then check it as in B6. | The first Hall imports cleanly. |
| C4 | Record the go-live in PROJECT_STATUS.md: date, version number, first Hall and its result. | Recorded. |

## D. Off switch and recovery

| Situation | What to do |
|---|---|
| Something looks wrong after go-live | Turn **New Hall imports** off. New imports stop at once; imports already started can still continue, and no product is changed or deleted. |
| One Hall's import stops with a failure | The Hall Manager selects **Retry Remaining Products** once the cause (named on the page) is fixed. |
| One Hall's import shows "needs attention" | A Super Admin opens that Hall's Starter Products page and uses **Resolve** (see the testing runbook). |
| A Hall wants the drafts gone | The Hall deletes them like any products. The import itself stays recorded; there is no second import. |
| A bad version was published | Fix the products on the main site and publish a new version. Only imports that have not started use the new version; an import already started, finished or not, keeps its original version. Example: a Hall part-way through importing Version 1 still finishes Version 1 after Version 2 is published. Published versions are kept in v1. |
