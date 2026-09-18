# Sheet Tables: a free GPL plugin for wordpress.org

## Context

The ISMP conference plugin solved a problem the commercial Google Sheets table
plugins have not. A survey of FlexTable, Ninja Tables, wpDataTables, Sheetable
and Sheets to Table found that none of them does both of the things that kept
the conference site honest for six weeks:

- **A server-side column whitelist.** Most import every column and then *hide*
  some in the display, which usually means the values still ship in the page
  HTML or sit in the database. Ninja Tables can select fields at import, but
  only in its paid tier.
- **A last known good fallback with a fault report.** Only wpDataTables keeps
  serving when the source fails, and none of them tells an editor *why* a sheet
  stopped parsing.

David is building one, releasing it free with no premium tier, and submitting it
to wordpress.org under his own account.

**Decisions taken:** name **Sheet Tables** (slug `sheet-tables`); each table is a
post in a single custom post type; the full feature set in 1.0; personal
authorship, GPLv2 or later; work starts now.

**ISMP is not touched.** Not the plugin, not the site, not the repo. The
conference runs through September 19 and `cwd-conference-schedule` keeps its own
copy of the shared code forever. This is a fork, not a refactor.

## Environment

Development and testing happen on **cd-atlas**, never on moral-psychology.

| | |
|---|---|
| Test site | `/Volumes/DC774-Work-Archive/Sites/lando/cd-atlas`, Lando app `cd-atlas`, Pantheon recipe, WP 7.0, already running |
| Repo on disk | `/Volumes/DC774-Work-Archive/Sites/github/dc774/sheet-tables` (the `dc774` folder exists and is empty) |
| Repo on GitHub | `github.com/dc774/sheet-tables`, public. `gh` is authenticated as dc774 with `repo` scope |
| Pantheon | not involved. Nothing is pushed to any Pantheon site |

**Step 0, before any code:**

1. `lando stop` in `/Sites/lando/moral-psychology`. The ISMP site stays down for
   the duration. (`officeofpostdoctoralaffairs` is also up and is left alone.)
2. Create the repo: `git init` in the new folder, then `gh repo create
   dc774/sheet-tables --public --source=. --remote=origin`.
3. Mount it into the test site by adding to `cd-atlas/.lando.yml`:

   ```yaml
   services:
     appserver:
       overrides:
         volumes:
           - /Volumes/DC774-Work-Archive/Sites/github/dc774/sheet-tables:/app/wp-content/plugins/sheet-tables
   ```

   Then `lando rebuild -y`. **Verify the mount before building on it:** `lando
   ssh -c "ls -la /app/wp-content/plugins/sheet-tables"` must show the files, and
   a file created on the host must appear inside the container. If the mount does
   not resolve, stop and say so rather than falling back silently.

cd-atlas has ACF Pro active. Sheet Tables must not use it, and the check in
verification step 1 confirms it does not.

## What gets forked from the conference plugin

Roughly 450 lines of ISMP's 4,572 are genuinely general purpose. Each is **copied
and renamed**, read out of the ISMP working tree without modifying it.

| From `cwd-conference-schedule` | Becomes | What it is |
|---|---|---|
| `cwd_schedule_fetch()` `schedule.php:790-878` | `sheet_tables_fetch()` | Cache-bust, transient, last-good option, fault recording, projection callback |
| `cwd_schedule_parse_csv()` `:1044-1099` | `sheet_tables_parse_csv()` | Locates the header by marker cells rather than by row number |
| `cwd_schedule_map_row()` `:377-389` | `sheet_tables_map_row()` | The whitelist itself: builds a row from wanted columns only |
| `cwd_schedule_used_columns()` `:1385-1404` | `sheet_tables_used_columns()` | Drops configured columns that are empty in every row |
| Cache registry `:885-914` | dropped | Keys derive from the table's post ID, so no registry option is needed |
| Faults `:929-987` | `sheet_tables_record_fault()` etc. | Keyed by post ID instead of by cache key |
| `cwd_schedule_cache_ttl()` / `cache_max_age()` `:2580-2629` | same, new prefix | TTL from the table's own setting, plus the CDN max-age cap |

**The projection callback is the whole point.** In `cwd_schedule_fetch()` it runs
at line 871, *before* `set_transient()` and `update_option()`, with the comment
"so whatever is dropped here is never written anywhere." Here that callback is
the column whitelist. Unselected columns never reach the database, the cache, or
the page. That is the feature no competitor offers.

Nothing conference-specific crosses over: sessions, panels, posters, floor plans,
verdicts, day tabs, People linking, banner image sizes.

## New code

```
sheet-tables/
  sheet-tables.php          bootstrap, headers, constants, requires
  includes/post-type.php    register the sheet_table CPT
  includes/settings.php     the meta box and its save handler
  includes/render.php       shortcode and block
  includes/status.php       Tools > Sheet Tables
  assets/sheet-tables.js    sort and filter, no library
  assets/sheet-tables.css   responsive stacking and print
  readme.txt  LICENSE  .distignore  .gitignore
```

1. **`sheet-tables.php`** follows the shape of `cwd-conference-schedule.php`:
   ABSPATH guard, `SHEET_TABLES_DIR` / `_URL` constants, four requires.
   `Requires at least: 6.0`, `Requires PHP: 7.4`, `License: GPLv2 or later`.

2. **`includes/post-type.php`** registers **one** post type, `sheet_table`, with
   `public => false`, `show_ui => true`, `capability_type => page`. Every table
   on the site is a post of that one type, so a site with ten tables has one
   admin menu holding ten entries, each with its own settings and shortcode id.
   No front end of its own: a table renders where its shortcode is placed.

3. **`includes/settings.php`** uses core meta boxes plus `register_post_meta`,
   **not ACF** (it cannot be a dependency of a directory plugin, and cd-atlas
   having it installed must not mask an accidental use). Fields: published CSV
   URL, ordered column list with optional relabeling, header marker cells, cache
   duration, caption, sort on/off, search on/off. Nonce plus
   `current_user_can( 'edit_post', $post_id )` on save; `esc_url_raw` for the
   URL, `sanitize_text_field` for the rest. Reject any URL that is not https.

4. **`includes/render.php`** provides `[sheet_table id="128"]` and a block that
   wraps it. Semantic markup: `<table>` with `<caption>`, `<thead>`, `scope="col"`.
   Every cell escaped with `esc_html()`. **Never `wp_kses_post()` on sheet data:**
   a cell is untrusted input from anyone with edit access to the spreadsheet,
   which is a wider circle than the site's editors.

5. **`includes/status.php`** is the Schedule Health idea generalized, under Tools
   and gated on `manage_options`. Per table: last fetch time, serving fresh or
   stale, any parse fault and its message, configured columns missing from the
   sheet, and the row count.

6. **`assets/sheet-tables.js`** does sorting and filtering by hand, maintaining
   `aria-sort`. No library: guideline 8 bars loading scripts from a CDN, and
   bundling DataTables would dwarf the plugin. The table renders server-side, so
   both features degrade to a plain readable table with JavaScript off.

7. **`assets/sheet-tables.css`** stacks rows into labeled blocks below roughly
   40em using `data-label` attributes written at render time, and carries print
   styles. Both are already solved in ISMP's `assets/schedule.css`.

## What wordpress.org requires

From the Detailed Plugin Guidelines, the ones that bear on this plugin:

- **1, GPL.** Everything in the directory must be GPL or GPL-compatible. No
  premium tier, which is the intent anyway.
- **6, third-party services.** The Google Sheets dependency must be clearly
  documented in `readme.txt`, with links to Google's terms and privacy policy.
- **7, no contacting external servers without consent.** The only outbound
  request is to the sheet URL an administrator entered, which is the consent. No
  telemetry, no update pings.
- **8, no external CDN** for JavaScript or CSS. Everything ships locally.
- **11, admin notices** limited in scope and dismissible.
- **17, trademarks** may not be the sole or initial term of a slug, so the slug
  cannot lead with a mark. `sheet-tables` leads with a generic word and has no
  plugin page today, though availability is only settled at submission.

Process: submit a complete zip; review takes up to 14 business days; approval
grants an SVN repo with `trunk`, `tags` and `assets`; `readme.txt` needs a
`Stable tag`. The official **Plugin Check** plugin should run clean first.

Every identifier takes the new prefix. No `cwd_` anywhere.

## Verification

All on cd-atlas.

1. **Clean fork.** `grep -rc "cwd_\|acf_\|get_field(" sheet-tables/` returns zero
   in every file. The plugin activates with no notices at `WP_DEBUG = true`.
2. **The whitelist, which is the one that matters.** Build a test sheet with a
   deliberate "Confidential" column and leave it unselected. Confirm that string
   appears nowhere in the rendered page source, the transient, the last-good
   option, or `wp_options` at large (`lando wp db query`). If it appears
   anywhere, the plugin is not ready.
3. **Break the sheet three ways.** Rename a header cell (fault recorded, last
   good still served), set the sheet back to private (transport failure, last
   good served), empty it entirely. The page must never go blank.
4. **Degradation.** Load a table with JavaScript disabled: the full table
   renders in sheet order with no dead controls.
5. **Accessibility and print.** Keyboard-operable sort headers carrying
   `aria-sort`, and a print preview that carries the whole table.
6. **Plugin Check** clean. Note cd-atlas runs WP 7.0, so the declared 6.0 floor
   is asserted rather than tested; either lower the claim to what is tested or
   spin a 6.0 site before submitting.

## Not in this plan

- Any change to `cwd-conference-schedule`, the moral-psychology site, or its
  repo. The site is stopped, not modified.
- Anything on Pantheon.
- The submission itself, which comes after 1.0 is built and Plugin Check is clean.
