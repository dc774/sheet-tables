# Sheet Tables architecture

How the plugin works, for whoever maintains it next. `PLAN.md` records why it
was built and what was decided; this file describes what was built. Neither
ships in the release zip (see `.distignore`).

## Files

```
sheet-tables.php          Bootstrap: header, constants, requires.
uninstall.php             Deletes every table and its stored copies.
includes/post-type.php    The sheet_table post type.
includes/settings.php     Meta fields, the settings box, the save handler.
includes/fetch.php        Read, parse, whitelist, cache, fall back, faults.
includes/google.php       Service account: key, access token, Sheets API read,
                          Settings > Sheet Tables.
includes/render.php       Shortcode, block registration, table and list markup, assets.
includes/icons.php        Values shown as Font Awesome icons: parsing, shapes, symbols.
assets/fontawesome/       Font Awesome Free 7.3.1 SVGs and LICENSE.txt (CC BY 4.0).
includes/status.php       Tools > Sheet Tables.
block/                    block.json, the editor script, its asset file.
assets/                   Front-end sort/filter script and stylesheet.
```

## A table

Each table is one post of the private `sheet_table` type (`capability_type`
page, so editors and administrators manage them). Its settings are post meta,
all defined once in `sheet_tables_meta_fields()`; registration, saving and the
defaults all read that list. `sheet_tables_get_settings()` returns them parsed.

The columns setting is one line per source column, with an optional display
heading after a bar (`Room | Location`). The links setting uses the same
format: the column shown, then the column holding its URL (`Title | Public
URL`). The whitelist (`sheet_tables_column_names()`) is the columns shown plus
those URL columns; `sheet_tables_display_columns()` is only the columns shown.

The row filter setting is one condition per line, `Column = value` or
`Column != value`, parsed by `sheet_tables_parse_row_filter()`. A line it
cannot read is kept with a null operator so the read can refuse it.

A Google link with no `gid` reads the sheet's first tab. The Share button's
"Copy link" gives such a link, so the settings box says so whenever the saved
link names no tab.

## Reading a sheet

`sheet_tables_fetch( $post_id )` is the single entry point.

1. No URL or no columns: return an error without requesting anything.
2. Cached copy in the transient: return it.
3. Otherwise `sheet_tables_download()`:
   - `sheet_tables_read_sheet()` reads the sheet one of two ways, by the
     table's `access` setting, and both end in `sheet_tables_parse_rows()`
     (header detection by marker cells, rows keyed by header):
     - `link`: requests the URL with `wp_safe_remote_get` plus a `cachebust`
       parameter (Google serves stale copies otherwise). A Google Sheets
       editor link is rewritten to its CSV export URL by
       `sheet_tables_csv_url()`. A non-200, an HTML response, or a CSV with no
       findable header is an error.
     - `service_account`: `sheet_tables_google_read()` (see below).
   - `sheet_tables_filter_rows()` drops rows that fail the row filter. It runs
     on the full parsed sheet, so it can test a column that is not shown (an
     `Include?` column, say). It fails closed: an unreadable condition, or one
     naming a column the header no longer has, is an error, so the last good
     copy keeps serving instead of every row.
   - `sheet_tables_project()` applies the whitelist. **This is the only place
     unchosen columns are removed, and it runs before anything is stored.**
   - If none of the chosen columns are in the header, that is an error too,
     so an empty result never replaces a good one.
4. Success: clear the fault, store the transient (TTL from the table's
   setting) and the last good option.
5. Failure of any kind: record a fault, then serve the last good copy for one
   minute, or return the error if there is none.

`sheet_tables_read_sheet()` returns every column. Only two callers may use
it: `sheet_tables_download()`, which whitelists immediately, and the settings
box, which prints the header row and discards the rest.

## Private sheets (`includes/google.php`)

- The key is `SHEET_TABLES_GOOGLE_CREDENTIALS` (wp-config.php, JSON string)
  if defined, else the option `sheet_tables_google_credentials`, saved from
  Settings > Sheet Tables (`manage_options`, nonce, `admin-post.php`). Either
  way `sheet_tables_google_parse_key()` reduces it to `client_email`,
  `private_key` and `token_uri`, and rejects anything that is not a service
  account key whose private key OpenSSL can read. The page never prints the
  key back.
- `sheet_tables_google_token()` signs a JWT (RS256 with `openssl_sign`, no
  library) for the read-only Sheets scope, posts it to the key's `token_uri`,
  and caches only the returned access token, keyed by the account's email.
- `sheet_tables_google_read()` takes the sheet ID and gid from the pasted link
  (`sheet_tables_google_ref()`, shared with the CSV path), looks up the tab's
  title, then reads its formatted values. A 403 becomes a fault naming the
  email to share the sheet with.
- Changing a table's access mode counts as a new source: its last good copy
  and fault are dropped (`sheet_tables_source_signature()`, which also covers
  the URL and the row filter; a stored copy cannot be re-filtered because the
  filter's column is not stored).

## What is stored

| Where | Key | Holds |
|---|---|---|
| Transient | `sheet_tables_data_{ID}` | Current copy, chosen columns only |
| Option (not autoloaded) | `sheet_tables_last_good_{ID}` | Last successful copy, chosen columns only |
| Option (not autoloaded) | `sheet_tables_faults` | Post ID => first failure message and time |
| Post meta | `_sheet_tables_*` | The table's settings |
| Option (not autoloaded) | `sheet_tables_google_credentials` | Service account email, private key, token URI (unless set in wp-config.php) |
| Transient | `sheet_tables_google_token_{md5(email)}` | Short-lived Sheets API access token |

Stored copies are `{ header: string[], rows: array[], fetched: int }`. Rows
keep only non-empty chosen cells.

Keeping them honest:

- Saving a table drops its transient. A changed URL also drops the last good
  copy and the fault. An unchanged URL re-runs the whitelist over the last good
  copy, so removing a column removes its stored values too.
- Deleting a table (`before_delete_post`) removes all three.
- Uninstall deletes every table, which runs the same cleanup, then the fault
  option, the access token and the stored key (a key in wp-config.php is the
  owner's to remove). On multisite it does this on every site.

## Showing a table

`[sheet_table id="N"]` and the `sheet-tables/table` block both call
`sheet_tables_render()`. The block is dynamic (saves only the ID) and its
editor preview is `ServerSideRender`, so there is one markup.

- Only published tables render. Problems are explained in a note shown to
  users with `edit_posts`, and nothing is shown to anyone else.
- Columns that are empty in every row are left out.
- Every cell is `esc_html()`, then `nl2br()` for multi-line cells. Never
  `wp_kses_post()`: sheet editors are not site editors.
- `sheet_tables_cell_html()` wraps a linked column's text in `<a>` when its
  URL column holds an http or https address (`esc_url()` with only those
  protocols); anything else, `javascript:` included, stays plain text.
- Each cell carries `data-label`, used by the narrow-screen stacked layout.
- The table sits in a scroll box (`role="region"`, `tabindex="0"`, named by
  the caption or the table's title) so a wide table can be scrolled from the
  keyboard.
- The wrapper carries `data-sort`, `data-search`, `data-page-size` and
  `data-separator` for the tools switched on, and each filter column's `<th>`
  carries `data-facet` and `data-param` (`sheet_tables_param()`: the heading
  through `sanitize_title()`). `assets/sheet-tables.js` builds the controls
  from those attributes, so without JavaScript there are no dead controls and
  the whole table shows. The script is enqueued only when a tool is on; the
  stylesheet whenever a table renders.
- In the script one `apply()` decides each row from the search text and every
  dropdown (a cell's values split on the separator), then pages the matches.
  Filtered-out rows get `hidden`; matching rows on other pages get the class
  `sheet-tables__paged-out`, which print overrides, so print carries every
  row. Sorting returns to page 1.
- Filters are read from and written to the URL fragment
  (`#program-strategy=School%20wellness`, `#search=...`) with
  `history.replaceState`, and re-read on `hashchange`. The fragment, not the
  query string, because it never reaches the server: it cannot collide with
  WordPress query vars such as `year` or `search`, and does not split the page
  cache. Values a column does not hold are ignored.

## Appearance

Data lives on the table post; appearance lives on the block (or shortcode
attributes), so one table can look different in different places.

- `sheet_tables_display_options()` reduces block attributes or shortcode atts
  to known words with `sheet_tables_choice()`: `layout`, `heading` (2-6),
  `sticky`; table options `striped`, `lines` (theme, none, rows, all),
  `padding` (theme, compact, roomy), `narrow` (stack, scroll); list options
  `itemSpacing`, `dividers`, `labels` (beside, above), `detailSpacing`; and
  per-column `align`, `width`, `label`. Anything else falls back to the first
  value. `sheet_tables_layout_classes()` turns them into classes on the
  wrapper; only the chosen layout's options apply, and the sidebar shows only
  those. "Theme" adds no CSS, leaving the theme's own table look.
- Colour and border: block.json skips serializing text colour, background and
  border on the wrapper (`__experimentalSkipSerialization`), so the filters
  and pager keep the page's look. `sheet_tables_surface_attributes()` puts
  them on the "surface" instead (the table's scroll box, or the list): preset
  colours as WordPress's usual classes, custom values through
  `wp_style_engine_get_styles()`. Link colour, typography and spacing stay on
  the wrapper. block.json's `style` loads the stylesheet in the editor.
- `sheet_tables_render_table()` and `sheet_tables_render_list()` share
  `sheet_tables_cell_html()`. In the list, the first column not made entirely
  of icons is each item's heading, icon columns before it sit beside it, and
  the rest are `dt`/`dd` pairs. Alignment and width apply to tables only; the
  label option to lists only, so no table heading is ever hidden on phones
  alone. On small screens a table either stacks (labels from `data-label`,
  all lines start-aligned, no cell borders) or keeps its columns in its
  keyboard-scrollable box. Nothing visible on a computer is removed.
- The scroll box and the list are `position: relative`. Without that, the
  visually hidden text in rows scrolled out of a sticky-header box escaped
  it and stretched the page below the footer.
- Icons (`includes/icons.php`): the table's Icons lines map values to Font
  Awesome Free names. `sheet_tables_fa_parse()` accepts `file-pdf`,
  `regular file-pdf` or Font Awesome's own `<i class>` code (the setting's
  sanitizer keeps the class names), and only accepts a name of
  `[a-z0-9-]` words whose file exists in the bundled set.
  `sheet_tables_icons_html()` replaces each matching value with
  `<svg><use href="#..."/></svg>` plus the value as `.sheet-tables__sr` text;
  `sheet_tables_fa_symbols()` writes each shape once as a
  `<symbol>` per table (ids scoped to that table, so a render other code
  discards cannot leave another table without its shapes), built from the file's
  viewBox and path data only. Search, filters and sort read the hidden text.
  The settings box lists names it cannot find.
- The script works on "items" (table rows or list items). A column's value is
  the element with `data-col`, and column metadata comes from the wrapper's
  `data-columns` JSON, so both layouts share one code path. It marks stripes
  (`sheet-tables__alt`) and the first visible list item
  (`sheet-tables__first`, for dividers) on every change.
- Sticky header: the scroll box gets a height so the header can stick in it,
  and the table's `overflow` is set back to visible because some themes hide
  it, which stops sticking. With a surface background the header inherits it;
  otherwise `--sheet-tables-header-background`, default `Canvas`.

## Caching upstream

`sheet_tables_cache_max_age()` filters `pantheon_cache_default_max_age` to
the shortest TTL of any table in the page's content (shortcodes and nested
blocks, via `sheet_tables_ids_in_content()`). It can only shorten the site's
setting. The filter only exists on Pantheon.

## Status

Tools > Sheet Tables (`manage_options`) reports, from stored data only, each
table's state, last good read, row count, chosen columns missing from the
sheet, and any fault. A table's own edit screen shows its fault too.

"Pull fresh data" (on that screen and in the table's settings box) is a link,
because the settings box sits inside the post form, to
`admin-post.php?action=sheet_tables_refresh` with a nonce tied to the table,
checked with `edit_post`. It drops the transient and calls
`sheet_tables_fetch()`. A failed read still returns the last good copy, so the
result is told from the fault log, which every successful read clears. It
redirects back with `sheet_tables_refreshed` (row count or `failed`), shown as
a notice and then removed from the address bar.

## Hooks

- `sheet_tables_cache_ttl` (seconds, post ID): a table's cache lifetime.
- `sheet_tables_status_capability`: who sees the status screen.

## Rules

- Prefix `sheet_tables_` / `SHEET_TABLES_`. No `cwd_`, no ACF.
- No external CDN, no telemetry. Outbound requests go only to a URL an
  editor entered, or, for private sheets, to the token address in the
  administrator's key and the Google Sheets API.
