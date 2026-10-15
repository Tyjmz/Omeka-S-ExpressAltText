# ExpressAltText 1.0.0 (initial testing build)

A scrollable Omeka S administrator view for editing native media alt text.

Tyler M. James



## Install

1. Back up the Omeka S database and files.
2. Extract the ZIP into `modules/` so the directory is `modules/ExpressAltText/`.
3. Enable **Express Alt Text** in Admin > Modules.
4. Open **Express Alt Text** in the admin sidebar.



## Supported scope

* Targets Omeka S 4.x (requires compatibility testing on your installation).
* Shows image media records (not each generated thumbnail), 30 per page.
* Edits the native Media `altText` field. Changes are saved to Omeka's media entity.
* Supports filtering by empty or nonempty native alt text.
* Uses CSRF validation and per-media edit permission checks.



## Important limitations

* **Not yet tested against a running Omeka S instance or Freedom theme.** Test on staging first.
* Does not audit the public HTML, page-specific alt text, embedded HTML images, theme overrides, or external images.
* Empty alt text may be intentional and is not automatically an accessibility failure.
* No reviewed-status tracking yet.
* Does not create separate alt text for thumbnails and full-size images; those are contexts of one media record.
* The preview image intentionally has `alt=""` because its adjacent editing controls identify it; this does not affect public pages.
* ORM filters, controller permissions, routes and media representation URLs need staging validation.

