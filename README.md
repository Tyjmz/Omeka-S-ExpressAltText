# ExpressAltText v1.1.9 — development release

**Author / Maintainer:** Tyler M. James  
**Affiliation:** Software engineering student, Cal Poly Humboldt Library Innovations Hub  
**Target platform:** Omeka S 4.2.1 (version constraint `^4.0.0`)  
**Dependencies:** No Common module dependency.

ExpressAltText provides a single scrollable dashboard for reviewing image media and editing Omeka S's **native media alt text**. It uses the Omeka API to save `o:alt_text`, so compatible themes can use that value in public HTML. It is not a replacement for testing rendered pages with assistive technology.

## v1.1.9 — Clearer navigation labels (October 19, 2026)

- Renamed the three source links to **View media's admin page**, **View original**, and **View page where item is located**.
- Kept existing destinations and new-tab behavior unchanged. The two new-tab links include accessible labels announcing this behavior; public item links also include their associated site names in accessible labels to distinguish destinations when multiple sites are available.
- No changes to media updates, permissions, review tracking, filters, CSS, or the Freedom theme.
- **Testing needed:** Verify all three destinations, keyboard focus and accessible link names, and links for items published on multiple sites. No live installation testing has been performed for this release.

## v1.1.8 — Source and public item navigation (October 19, 2026)

- Added an **Original media** label and MIME type (when available) to each image record.
- Added clearly labeled **Open media record**, **View original image**, and **View public item** links. The existing click-to-enlarge preview remains available.
- Public item links now use each parent item's *associated Omeka S site slug* instead of relying on the admin request's missing site context. If an item belongs to several sites, links may be shown for each. If none is resolvable, the UI explains that a public link is unavailable rather than inventing a URL.
- Kept the single collapsible image-usage guide at the top of the dashboard. No thumbnail alt text is changed automatically.
- No changes to the batch-saving controller, native `o:alt_text` writes, review settings, or Freedom theme.

**Testing:** PHP lint and ZIP integrity checks are performed when packaging. The new links have **not** been tested on a running Omeka S installation. On staging, verify media #947, confirm each public item link opens the correct item for its named site, test a media record without an associated public site, and verify native alt-text saving still works. Site-specific public URL generation depends on the Omeka S item representation's `siteUrl()` behavior and should be verified before broad deployment.

## Changes in 1.1.1 (October 15, 2026)

- Explicitly instantiate `Laminas\Validator\Csrf` (aliased `CsrfValidator`) for `getHash()` and `isValid()`. **Do not use `Laminas\Form\Element\Csrf::isValid()`**: that method does not exist. This change targets the confirmed fatal error at `src/Controller/AdminController.php:46` observed in the Omeka logs. The packaged 1.1.0 source already referenced the validator, so a stale deployed controller or mixed-version installation is also possible; verify the installed file after replacing it.
- Added **Back to Omeka S Dashboard**, using the Omeka admin route. Existing unsaved-changes browser warning also applies to this link.
- Isolated review-settings write errors from media API save errors; error feedback now distinguishes review persistence failure. Existing per-media save failure reporting remains.
- Retained dashboard statistics, search, filters, review checkboxes, and native media alt-text editing.

**Testing status:** PHP lint and ZIP integrity are checked during packaging. This build has **not** been run against a live Omeka S instance. Test on staging, especially saving review status and returning to the dashboard.

**Observed v1.1.0 bug:** Omeka Logs screenshot on October 15, 2026 shows repeated `Call to undefined method Laminas\Form\Element\Csrf::isValid()` at `modules/ExpressAltText/src/Controller/AdminController.php:46`. Another `PermissionDeniedException` for `/api/users/1` was logged separately and is **not proven** to be caused by ExpressAltText. Do not change user permissions to work around it.

## New in 1.1.0

- **Human review status** with checkboxes and UTC review dates. Status is stored separately in the Omeka S application settings (`express_alt_text_reviews`), not in native media metadata. No custom database tables or migrations.
- **Progress summary** for total image records, empty alt text, populated alt text, and reviewed images, plus a progress indicator.
- **Filters:** all images, empty alt text, with alt text, needs human review, reviewed.
- **Search:** numeric media ID or words in existing alt text. **Media title and item title are not yet searchable.**
- **Save All improvements:** disabled when nothing changed; marks changed cards; counts characters; detects changes made by another editor since page load; reports per-record failures; redirects after successful saves to avoid duplicate POSTs; warns about unsaved changes.
- **Review invalidation:** changing an image description removes its previous review status. An editor may mark the newly edited text reviewed in the same submission after checking it.
- Preserves original image previews, click-to-open full images, and medium-thumbnail fallback.

## Installation / upgrade

1. **Back up the Omeka S database and files. Test on staging first.** This is an unverified development build.
2. Extract the ZIP to `modules/ExpressAltText/`, replacing the older files. Avoid `modules/ExpressAltText/ExpressAltText/`.
3. Do not keep a second copy of the module inside `modules/`; store backups outside that directory.
4. Visit **Admin → Modules** and use **Update** if Omeka offers it.
5. Open **Express Alt Text** in the admin sidebar.
6. Test a non-critical image before bulk edits.

**Rollback:** Restore the previous module files and, if necessary, the corresponding database backup. Review flags are saved in Omeka's application settings and may persist when files are replaced.

## Tests confirmed by the site administrator (Omeka S 4.2.1, Freedom theme)

These observations were reported and documented in screenshots in the development conversation, **not reproduced in an independent test environment**:

| Feature | Evidence / result | Version observed |
| --- | --- | --- |
| Module discovered in Omeka admin | Express Alt Text appeared in sidebar and module was installed | 1.0.1 |
| Dashboard route and listing | Dashboard loaded, showing image media records and existing alt text | 1.0.1 |
| Batch saving | Admin interface reported **3 image descriptions saved** | 1.0.2 |
| Persistence after filtering | Previously edited records reappeared under **All images** with descriptions | 1.0.2 |
| Public HTML rendering | Inspecting the **public item page** showed an `<img alt="Open wooden case containing brass analytical balance weights arranged in black velvet compartments.">` | 1.0.2 / 1.0.3 testing period |
| Original image available | Media #947 showed its JPEG on the native media record; original file accessible | 1.0.2 / 1.0.3 testing period |

**Scope of confirmation:** The public HTML test confirms one image occurrence in the Freedom theme. It does not prove every theme, page block, thumbnail, or linked image uses native media alt text. The new features in **v1.1.0 have not yet been tested in a running Omeka S installation**.

## Local static checks for v1.1.0

- PHP syntax checks passed for the module PHP files and template.
- ZIP integrity checked.
- **Not performed:** end-to-end tests against a running Omeka S 4.2.1 instance, actual batch writes, review-setting persistence, performance testing with large collections, and screen-reader testing.

## Known limitations and accessibility considerations

- Empty alt text (`alt=""`) may be correct for decorative images. Empty descriptions are **not automatically WCAG failures**. v1.1.0 does not yet offer a separate decorative classification; empty entries cannot be marked reviewed.
- Review status is administrative bookkeeping, not an accessibility certification.
- Search currently covers media ID and stored alt text, not item titles or filenames.
- Image counts and review filters use an in-memory list of image IDs/alt text; performance may degrade on very large collections.
- Review state is stored in one global settings entry. Extremely large collections may need a dedicated persistence strategy in a future release.
- Original image previews may consume bandwidth when derivative thumbnails are unavailable.
- Some public templates override native media alt text; page-specific text and linked-image accessible names are not audited.
- If the public theme does not use `o:alt_text`, saving the native field alone will not fix the page's accessibility.
- Permissions are checked per media record before saving. The dashboard shows up to 30 image records per page.

## Suggested staging verification for 1.1.0

1. Open dashboard; check that counts and filters load without errors.
2. Search for `947` and confirm the expected media record appears; clear search.
3. Edit one non-critical description and save. Confirm it persists in Omeka's native media editor.
4. Mark an existing nonempty description reviewed, save, refresh, and verify the reviewed filter and progress count.
5. Edit that reviewed description, save, and verify it returns to Needs human review.
6. Change several descriptions and save once. Check each record and a public page's actual `<img alt="...">` value.
7. Test unsaved navigation warning, paging, and the empty filter after saving descriptions.
8. If errors occur, capture the Omeka/PHP error log and restore from backup.

## v1.1.2 diagnostic patch (October 15, 2026)

- Adds phase-specific PHP error logging, including exception class, exact message (including missing service), file, line and stack trace. Look in the hosting PHP error log for `ExpressAltText v1.1.2` if Omeka's Logs does not record it.
- Removes the `flashMessenger()` invocation from the successful POST path as a potential source of the save-time `ServiceNotFoundException`. **This is a hypothesis, not a confirmed root cause.** The save result is displayed immediately without a redirect; avoid refreshing a POST response (navigate back to the module instead).
- Keeps the existing CSRF validator, native media API writes, human review controls, progress dashboard, image previews, and back-to-dashboard link.
- **Unverified on a running Omeka S site.** Do not use for bulk production edits until a single-media save and review-only save have been tested on staging.
- The separately observed `PermissionDeniedException` for `/api/users/1` has not been shown to originate in this module.

## History

- **1.0.0:** Initial admin gallery (route and save issues discovered).
- **1.0.1:** Dashboard routing and API save changes; dashboard confirmed to load.
- **1.0.2:** Batch Save All; three-description save and public rendering subsequently verified.
- **1.0.3:** Original-image preview preference and full-image links; preview behavior not independently verified.
- **1.1.0:** Review status, progress, search, filters, modified-record indicators, save safety improvements.
- **1.1.1:** CSRF validator clarification, separate review-storage error reporting, admin dashboard back link.

- **1.1.2:** Diagnostic logging for save phases; removed potential flash-messenger service lookup during POST.

## v1.1.3 interface update (October 15, 2026)

- Search field and compact Search button now share one horizontal row, right-aligned on wider screens.
- Replaces red text filter links with clearly labeled, keyboard-focusable filter buttons (links styled as buttons) and a highlighted active filter.
- Responsive layout stacks/wraps controls on narrow screens.
- Search and filter routes, CSRF handling, media API saves, review bookkeeping, and pagination are **unchanged** from v1.1.2.
- **Testing:** Static PHP lint and archive integrity checked for this release. The user reported that the v1.1.2 save error disappeared; no independent server-side test of v1.1.3 has been performed.
- **Suggested test:** Search for a media ID, switch each filter, clear the search, verify keyboard focus, and save one non-critical media record on staging.

## v1.1.4 dashboard navigation update (October 15, 2026)

- The five count cards now serve as the **only filter navigation**: Total images, Empty alt text, With alt text, Needs human review, and Reviewed. The redundant smaller filter links/buttons have been removed.
- Each card is a normal keyboard-accessible link with an accessible name including its count. The selected card is highlighted and uses `aria-current="page"`. Counts come from the existing controller's `counts` values.
- Search remains right-aligned on wide screens with a smaller Search button immediately next to the input. On narrow screens the layout wraps responsively.
- Search terms are preserved when changing filters; filter changes reset to page 1.
- **No controller, persistence, CSRF, save, or review-tracking logic was modified in this release.**
- **Verification:** PHP lint and ZIP integrity checked locally. The interface and save workflow have not been independently tested against a live Omeka S installation for v1.1.4.
- **Suggested staging test:** Click each count card, confirm the correct filtered records and active highlight, test search + filter together, check keyboard navigation, and save one non-critical description.

## v1.1.5 search layout correction (October 15, 2026)

- Corrects the oversized full-width Search button and nearly invisible search input reported in the v1.1.4 admin screenshot.
- Applies targeted selectors to overcome Omeka S admin-wide input/button styling. Search is now a wide input with a fixed-width 112px button directly beside it (84px on narrow screens).
- On wider screens the search row is right-aligned and capped at 760px, matching the approved dashboard mockup.
- The five clickable statistic cards remain the only filter navigation; no additional filter buttons are introduced.
- **Scope:** CSS-only change plus version and documentation updates. Controller, saving, review tracking, and routing are unchanged.
- **Testing:** PHP syntax and ZIP integrity checked locally; browser rendering in Omeka S 4.2.1 still needs verification. After updating, hard-refresh the admin page (Ctrl+Shift+R).


## v1.1.6 image usage guidance (October 19, 2026)

- Adds a collapsible, keyboard-operable **Image usage and thumbnail guidance** section to each media editor card. This is explanatory guidance, **not an automated classification or scan**.
- Explains why linked image-only thumbnails may require a meaningful accessible name, while thumbnails accompanied by text in the *same link* may be intentionally empty.
- Warns against clearing native media alt text to remove redundant thumbnail announcements; the same record may supply the full-size image description.
- Adds an optional **View public item (new tab)** link when Omeka S can provide a public item URL in the current site context. If unavailable, the link is omitted. The URL is not a verification result.
- Leaves controller, API writes, CSRF handling, review tracking, search, filtering and batch-save logic unchanged from v1.1.5. No theme files are modified.
- **Testing:** PHP lint and ZIP integrity checks only. Public URL availability and layout should be tested on a staging Omeka S 4.2.1 installation.
- **Manual test:** Expand guidance using keyboard; verify public link points to the correct site, compare the full-size image and carousel thumbnail's rendered alt/link accessible names, then confirm saving one non-critical record still works.
- **Known limitation:** A media record can appear in multiple contexts and sites. This version does not enumerate uses, inspect public HTML, or identify thumbnail duplication automatically.


## v1.1.7 consolidated image usage guide (October 19, 2026)

- Moves **Image usage and thumbnail guidance** from every media card to a single collapsible reference panel near the top of the dashboard, above the filter statistics.
- The reference is collapsed by default, keyboard operable through the native HTML `<details>` / `<summary>` elements, and retains the same wording and cautions as v1.1.6.
- Removes the repeated guide from individual records to reduce visual clutter and repeated screen-reader content.
- No changes to native alt-text saving, review tracking, CSRF, search, media URLs, public item links, or the Freedom theme.
- **Verification:** PHP syntax and ZIP integrity checks performed locally. Browser appearance, keyboard navigation, and saving require testing on an Omeka S 4.2.1 staging installation.
- **Suggested test:** Open the reference guide with Tab then Enter or Space; confirm it appears only once and media cards retain their editors. Save one noncritical change and confirm the public item image retains the intended alt text.
