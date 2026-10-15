=== Sheet Tables ===
Contributors: TODO-wordpress-org-username
Tags: google sheets, spreadsheet, table, csv, shortcode
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a Google Sheet as an accessible table. Only the columns you choose are stored or shown, and a broken sheet never empties the page.

== Description ==

Sheet Tables turns a spreadsheet into a table on your site. The sheet stays the place where the data is edited, and the site follows it.

It was built for sheets that are shared working documents, with columns the public should never see and editors who do not know which cells the website depends on.

**Only the columns you choose ever reach your site.** You list the columns a table shows. Every other column is dropped the moment the sheet is read, before anything is cached or saved. It never reaches your database, your cache or your pages, so a column of private notes in the same sheet cannot leak through a template change, a cache or a backup.

**Private sheets stay private.** A table can read a sheet that is shared with nobody but your site's Google service account, so the sheet's working columns are not visible to anyone who comes across its link.

**Only the rows you choose, too.** A table can show only the rows that meet conditions such as `Include? = Yes`. The other rows are dropped as soon as the sheet is read, just like unchosen columns, and the column being tested does not have to be shown. If that column is renamed or deleted, the table keeps its last good copy rather than showing every row.

**A broken sheet does not break your page.** If the sheet cannot be read, because someone renamed a header, unpublished the sheet or emptied it, visitors keep seeing the last good copy. The table's edit screen and Tools > Sheet Tables say what went wrong and since when.

**Accessible and light.**

* Real table markup: a caption, column headers with `scope`, and escaped plain text in every cell.
* Optional sorting by any column, keyboard operable, with `aria-sort`.
* An optional filter box that announces how many rows match.
* Optional dropdown filters for chosen columns. A cell such as "School, Worksite" counts under each of its values.
* Optional paging for long tables.
* Links that open a table already filtered, such as `/library/#program-strategy=School%20wellness`, for buttons on a landing page.
* On a phone, each row becomes a block of labelled lines.
* Prints the whole table, with the header repeated on each page.
* Works without JavaScript: visitors get the complete table in sheet order.
* No libraries and nothing loaded from a CDN. The stylesheet and script load only on pages that show a table.

**Looks the way you want, without a design tool.**

* Show a table as a table, or as a list: each row a heading (linked, if you like) with its details beneath, as a resource library usually looks.
* Table options: striped rows, lines (none, between rows, around every cell), cell padding, a sticky header row, and on small screens either stacked rows or sideways scrolling.
* List options: space between items, divider lines, labels beside or above their values, and spacing between details.
* WordPress's own colour and border settings colour the table or list itself, never the filters around it; typography and spacing settings too.
* Per column, in the block sidebar: alignment and width (tables) and whether its label shows (lists).
* Show any value as an icon from the Font Awesome Free set bundled with the plugin, such as a PDF icon for "PDF". Only the icons a page uses are sent, with no font to download. The word is still read out by screen readers, and search and filters still find it.
* On a phone, everything visible on a computer is still there: rows reflow, nothing is hidden.

**Everything else you would expect.**

* Any number of tables, each with its own sheet, columns, headings and refresh interval.
* Rename any column's heading for display.
* Turn a column's text into a link using a web address from another column, such as a title linking to its file.
* Finds the header row even when the sheet has title or instruction rows above it.
* A block and a shortcode, `[sheet_table id="123"]`. The shortcode also takes the block's appearance options by name: `layout="list"`, `heading="2"` to `"6"`, `sticky="1"`; for tables `striped="1"`, `lines="none|rows|all"`, `padding="compact|roomy"`, `narrow="scroll"`; for lists `itemspacing="compact|roomy"`, `dividers="1"`, `labels="above"`, `detailspacing="compact"`.
* No account, no premium version, no tracking.

== Installation ==

1. Install and activate the plugin.
2. In Google Sheets, either share the sheet so that anyone with the link can view it, or keep it private and set up a service account (see the FAQ).
3. Go to Sheet Tables > Add New. Paste the sheet's link, choose how the sheet is accessed, then list the columns to show, one per line. Once the link is saved, the edit screen lists the sheet's columns so you can copy their exact names.
4. Publish the table, then add it to any page with the Sheet Table block or its shortcode.

== Frequently Asked Questions ==

= I changed the sheet. Why hasn't the site changed? =

Each table keeps a copy of its sheet for the number of minutes set in its "Refresh every" setting, five by default. To see a change straight away, press **Pull fresh data** on the table's edit screen or on Tools > Sheet Tables. Updating the table also drops the copy, so the next page view reads the sheet again. If your host caches whole pages, the page itself may take a few more minutes to change.

= Does the sheet have to be public? =

No. A table can read a sheet in one of two ways, chosen per table:

* **Shared by link.** The sheet is shared so that anyone with the link can view it, or published to the web. Nothing else to set up, but anyone who has the link can open the whole sheet in Google, so keep the link to yourself.
* **Private.** The sheet is shared only with your site's Google service account. Nobody without access in Google can open it.

Either way, only the columns you choose are ever stored or shown on your site.

= How do I set up a service account for private sheets? =

Once per site:

1. In the Google Cloud console (console.cloud.google.com), create a project, or pick an existing one.
2. Under APIs & Services, enable the **Google Sheets API**.
3. Under IAM & Admin > Service Accounts, create a service account. It needs no roles.
4. Open the service account, go to Keys, and add a key of type JSON. A file downloads.
5. Either paste the whole file into Settings > Sheet Tables, or, to keep the key out of the database, add it to wp-config.php: `define( 'SHEET_TABLES_GOOGLE_CREDENTIALS', '...contents of the file...' );`

Then, for each private sheet, open Share in Google Sheets and add the service account's email address (shown on Settings > Sheet Tables and on each table's edit screen) as a Viewer. Set the table's Sheet access to Private.

The key lets anyone who holds it read every sheet shared with that service account, so treat the file like a password and delete the downloaded copy once it is in place. If your organization's Google Workspace blocks sharing with addresses outside it, ask its administrator to allow the service account.

= Can I use something other than Google Sheets? =

Yes. Any https link that returns a CSV file works.

= What happens if someone renames a column in the sheet? =

If it was one of your chosen columns, the table carries on without it and Tools > Sheet Tables lists it as missing. Update the column name in the table's settings to match. If the change means the header row cannot be found at all, or none of your chosen columns are left, visitors keep seeing the last good copy until it is fixed.

To make a column required, list it under "Header row" too. The header row is then only recognized when that column is present, so renaming it keeps the last good copy on the page instead.

= I can't find the Sheet Table block in the editor =

Some themes limit the editor to an approved list of blocks. If yours does, add `sheet-tables/table` to that list to use the Sheet Table block, or ask whoever maintains the theme to add it. In the meantime the shortcode works in any paragraph: type `[sheet_table id="123"]`, using the id shown on the table's edit screen.

= How do icons work? =

In the table's Icons setting, add one line per value: the value, a bar, then a Font Awesome icon name, for example `PDF | file-pdf` or `Video | video`. Any cell, in any column, holding that value then shows the icon instead of the word; in a cell with several values, each is matched on its own. Letter case is ignored, and values with no line stay as words.

Find names with Font Awesome's free icon search (fontawesome.com/search?ic=free). Solid icons are used unless the line says `regular` or `brands`, as in `Web page | brands chrome`, and you can paste the code Font Awesome gives you, such as `<i class="fa-regular fa-file-pdf"></i>`. If a name is not found, the table's edit screen says so.

In the list layout, a column of icons listed before the title column appears beside each heading.

= Is anything from the sheet stored in my database? =

Only the columns you chose: the current copy (as a transient) and the last good copy (as an option), both removed when the table or the plugin is deleted.

== External services ==

This plugin reads spreadsheets from the address you enter for each table, which is normally Google Sheets. Reading the sheet is how the table gets its data.

The site's server reads the sheet when a table is shown and its stored copy has expired, and when the table's edit screen is opened, to list the sheet's columns. No information about your visitors or your users is sent.

* A table whose sheet is **shared by link** downloads it as CSV from the address entered (normally docs.google.com).
* A table whose sheet is **private** uses the service account set up under Settings > Sheet Tables. The site sends a request signed with the service account's key to Google's token service (oauth2.googleapis.com, or the token address in the key) to get a short-lived access token, then reads the sheet from the Google Sheets API (sheets.googleapis.com). The key itself is never sent.

Google Sheets is provided by Google: [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).

== Credits ==

Icons are from Font Awesome Free 7.3.1 by Fonticons, Inc. (https://fontawesome.com), licensed CC BY 4.0. Their licence is included in `assets/fontawesome/LICENSE.txt`.

== Changelog ==

= 1.2.0 =
* List layout: each row as a heading with labelled details, with a "Sort by" menu.
* Table options (striped rows, lines, cell padding, small-screen behaviour, sticky header) and list options (item spacing, dividers, label position, detail spacing).
* WordPress colour and border settings apply to the table or list itself; typography and spacing settings too.
* Per-column alignment, width and label settings in the block sidebar.
* Icons for values from the bundled Font Awesome Free set, mapped in the table settings.
* Fixed: empty space could appear below the page footer when a table scrolled inside its box.

= 1.1.0 =
* Private sheets: read a sheet shared only with the site's Google service account.
* Row filter: show only rows that meet conditions such as "Include? = Yes". Other rows are never stored.
* Link columns: link a column's text to a web address held in another column.
* Dropdown filters, paging, and links that open a table already filtered.
* "Pull fresh data" button on each table's edit screen and on Tools > Sheet Tables.
* The edit screen says when a Google link names no tab, so the first tab is being read.

= 1.0.0 =
* First release.
