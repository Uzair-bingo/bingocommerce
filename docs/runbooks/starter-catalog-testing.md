# Starter catalog: manual test checklist

Date: 2026-10-20. This describes how to check ADR-049 stage 4 (master
selection and Publish) in a browser on the local development network. It is
not a deployment or production-readiness claim.

## Boundary

- Covered here: the "Include in starter catalog" checkbox, Network Admin →
  Starter Catalog, Publish New Version, Resume, Discard, Finish Discard and the
  "New Hall imports" switch.
- Hall side (stage 5): Products → Starter Products is covered below. The
  dashboard notice and Super Admin Resolve arrive in later slices.
- Importing creates real draft products in the Hall you test with. Use a
  development Hall you can tidy up afterwards.
- Published versions are kept in v1 and cannot be deleted. A version you
  publish while testing stays on the development network. Its frozen image
  copies stay in `wp-content/uploads/bingo-starter-catalog/v<version>/` (or the
  folder set by `BINGOCOMMERCE_STARTER_CATALOG_DIR`).
- Automated suites cover what is hard to do by hand (crashes at every step,
  products edited during publication, failed database reads). They run only on
  the isolated fixture database, never the development database.

## Before you start

- Sign in as a **Network Super Admin**.
- On the main site, the two sample products exist: "1on Bingo Paper By The
  Case 9,000 Sheets" (currently **Published**) and "Dab O Ink 3 Ounce By the
  Bottle" (Draft).
- Optional: a main-site user who is a shop manager but not a Super Admin, to
  check that they cannot change the flag or see the page.

## Checklist

| # | Do this | Expect this |
|---|---|---|
| 1 | Open "Dab O Ink" on the main site (Products → edit). | The Publish box shows **Include in starter catalog**. |
| 2 | Tick it and select **Save Draft**. Repeat for "1on Bingo Paper" (select **Update**). | Both stay ticked after the page reloads. |
| 3 | As the optional shop manager, open the same product. | No checkbox. |
| 4 | Open any product on a Hall site. | No checkbox. |
| 5 | On the main site, **Duplicate** "Dab O Ink". | "Dab O Ink (Copy)" is a Draft and is **not** ticked. Move the copy to the trash afterwards. |
| 6 | Open **Network Admin → Starter Catalog**. | "Included products: 2", a link to the main site's products, "Latest published version: None published yet.", "New Hall imports: Off", and **Publish New Version**. |
| 7 | Select **Publish New Version** while "1on Bingo Paper" is still Published. | "Publishing stopped. Fix these on the main site, then try again:" and the line *"1on Bingo Paper By The Case 9,000 Sheets" is Published; it must be Draft, Pending or Private.* After a reload the page still says "None published yet." (nothing was created). |
| 8 | Change "1on Bingo Paper" to **Draft** (or Private), then select **Publish New Version** again. | Progress such as "Capturing products: 0 of 2 done." (two products usually finish in one request), then "Version 1 is published with 2 products." Any content WordPress removed is listed under the warning heading; it does not stop publication. If a real product's content is refused (for example a link to the main site), the problem is listed instead; fix it on the main site and select **Publish New Version** or **Resume** again. |
| 9 | Select **Reload this page**. | "Latest published version: Version 1: 2 products, published MM/DD/YYYY h:mm am/pm by <your name>." |
| 10 | Look in `wp-content/uploads/bingo-starter-catalog/v1/`. | One folder per source image (28 for the two samples), each holding one image file. No `.part` files. |
| 11 | Make Resume appear on purpose: open the browser's developer tools → Network, select **Publish New Version**, and as soon as the first request finishes switch the network to **Offline**. | "The request did not complete. Reload the page and try again." **Resume** and **Discard** appear. |
| 12 | Switch the network back **Online** and select **Resume**. | Publication continues and finishes: "Version 2 is published with 2 products." |
| 13 | Repeat step 11, then select **Discard** and confirm. | The confirmation reads "Discard this version? Its captured products and image copies are removed. Published versions are not affected." Then "The version was discarded." After a reload, the latest published version is unchanged and no `v<that version>/` folder remains. |
| 14 | Open the page in two tabs. In the first, start a Publish and go offline after the first request (as in step 11). In the second, select **Publish New Version**. | The second tab says "A version is already being published. Resume or Discard it first." Discard it from either tab. |
| 15 | Select **Turn on new Hall imports**, then **Turn off new Hall imports**. | "New Hall imports are turned on." then "...turned off." Leave it **Off**. |
| 16 | As the optional shop manager (or any user who is not a Super Admin), try to open Network Admin → Starter Catalog. | Network Admin is not available to them, and the page address refuses them. |
| 17 | Disable JavaScript in the browser and open the page. | "Publishing needs JavaScript in your browser." and the Publish buttons are disabled. The imports switch still works. |

If a Discard is interrupted, the page shows "Version N was discarded, but some
of its rows or image files remain." with **Finish Discard**. Selecting it
completes the cleanup and can be repeated safely.

## Hall side: Products → Starter Products

Do this after a version is published (step 8 above).

| # | Do this | Expect this |
|---|---|---|
| H1 | With new Hall imports **Off**, sign in to a development Hall as its Hall Manager and open **Products → Starter Products**. | "Starter product imports are turned off right now." and "You can still add products by hand." No Import button. |
| H2 | In Network Admin → Starter Catalog, select **Turn on new Hall imports**, then reload the Hall's page. | "2 starter products are available." and **Import Starter Products**. |
| H3 | Select **Import Starter Products**. | Progress such as "Importing product 1 of 2 (variation 3 of 15).", then "Import finished on MM/DD/YYYY: 2 products imported, 0 skipped." |
| H4 | Select **Reload this page**. | The finish line, any notes (at Star Bingo Hall: the 1on Blue variation's SKU `S0101BSP9` was left blank), and **View Products**, which lists the new drafts. No Import button. |
| H5 | On a second development Hall, open the page, open the browser's developer tools → Network, select **Import Starter Products**, and switch to **Offline** once the second request has finished (the first only records the import; the next ones copy products). | "The request did not complete. Reload the page and try again." and **Continue Import**. |
| H6 | While still stopped, open the product that was being imported (Products → Drafts; the one whose variations or images are incomplete). If the stop came between products, none is unfinished; repeat H5 with a slightly earlier stop. | "This product is still being imported. Changes may be replaced." Products already finished, and products the import did not make, show no warning. |
| H7 | Switch back **Online** and select **Continue Import**. | The import finishes; reloading shows the finish line. |
| H8 | As a Game Operator of that Hall, open Products. | No Starter Products item. |
| H9 | As a Super Admin on the **main site**, open Products. | No Starter Products item. |
| H10 | Turn new Hall imports **Off** again when you are done. | |

## Automated suites

Run each from the repository root. They refuse to run unless the isolated
fixture database is selected.

```bash
BC_TEST_DB=bingocommerce_state_recon php tests/starter-catalog-master.php
```

```bash
BC_TEST_DB=bingocommerce_state_recon php tests/starter-catalog-publish.php
```

```bash
BC_TEST_DB=bingocommerce_state_recon php tests/starter-catalog-admin.php
```

```bash
BC_TEST_DB=bingocommerce_state_recon php tests/starter-import-page.php
```
