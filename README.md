# ExpressAltText 1.0.2 (development build)

**Author / Maintainer:** Tyler M. James  
**Affiliation:** Software engineering student; Cal Poly Humboldt Library Innovations Hub  



For Omeka S 4.x (target: 4.2.1). Provides a 30-image page with batch editing of native media alt text.

## Installation
Back up database and files. Test on staging. Replace the existing `modules/ExpressAltText` directory with the extracted package and update the module in Admin > Modules if offered. Do not nest the ExpressAltText folder twice.

## Testing
First load the dashboard. Edit one non-critical media record and press Save All Changes. Verify the native alt text in the Omeka media editor and the rendered public image HTML. Then test multiple edits.

## Limitations
Not yet tested on a running Omeka S instance. Saving uses Omeka's API manager `update` with `o:alt_text`; individual failures are logged and displayed by media ID. There is no guarantee a theme uses the native alt text in every image context. The module does not handle page-specific overrides, decorative-image review status, or independently verify public HTML. The image preview falls back to the original media URL when a medium thumbnail is unavailable. Do not edit valuable records before confirming save behavior.
