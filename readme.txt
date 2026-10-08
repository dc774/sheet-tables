=== Sheet Tables ===
Contributors: TODO-wordpress-org-username
Tags: google sheets, spreadsheet, table, csv, shortcode
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
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

**Everything else you would expect.**

* Any number of tables, each with its own sheet, columns, headings and refresh interval.
* Rename any column's heading for display.
* Turn a column's text into a link using a web address from another column, such as a title linking to its file.
* Finds the header row even when the sheet has title or instruction rows above it.
* A block and a shortcode, `[sheet_table id="123"]`.
* No account, no premium version, no tracking.

== Installation ==

1. Install and activate the plugin.
2. In Google Sheets, either share the sheet so that anyone with the link can view it, or keep it private and set up a service account (see the FAQ).
3. Go to Sheet Tables > Add New. Paste the sheet's link, choose how the sheet is accessed, then list the columns to show, one per line. Once the link is saved, the edit screen lists the sheet's columns so you can copy their exact names.
4. Publish the table, then add it to any page with the Sheet Table block or its shortcode.

== Frequently Asked Questions ==

= I changed the sheet. Why hasn't the site changed? =

Each table keeps a copy of its sheet for the number of minutes set in its "Refresh every" setting, five by default. Updating the table drops the copy, so the next page view reads the sheet again.

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

= Is anything from the sheet stored in my database? =

Only the columns you chose: the current copy (as a transient) and the last good copy (as an option), both removed when the table or the plugin is deleted.

== External services ==

This plugin reads spreadsheets from the address you enter for each table, which is normally Google Sheets. Reading the sheet is how the table gets its data.

The site's server reads the sheet when a table is shown and its stored copy has expired, and when the table's edit screen is opened, to list the sheet's columns. No information about your visitors or your users is sent.

* A table whose sheet is **shared by link** downloads it as CSV from the address entered (normally docs.google.com).
* A table whose sheet is **private** uses the service account set up under Settings > Sheet Tables. The site sends a request signed with the service account's key to Google's token service (oauth2.googleapis.com, or the token address in the key) to get a short-lived access token, then reads the sheet from the Google Sheets API (sheets.googleapis.com). The key itself is never sent.

Google Sheets is provided by Google: [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).

== Changelog ==

= 1.1.0 =
* Private sheets: read a sheet shared only with the site's Google service account.
* Row filter: show only rows that meet conditions such as "Include? = Yes". Other rows are never stored.
* Link columns: link a column's text to a web address held in another column.
* Dropdown filters, paging, and links that open a table already filtered.

= 1.0.0 =
* First release.
