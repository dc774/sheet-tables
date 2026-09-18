=== Sheet Tables ===
Contributors: TODO-wordpress-org-username
Tags: google sheets, spreadsheet, table, csv, shortcode
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a Google Sheet as an accessible table. Only the columns you choose are stored or shown, and a broken sheet never empties the page.

== Description ==

Sheet Tables turns a spreadsheet into a table on your site. The sheet stays the place where the data is edited, and the site follows it.

It was built for sheets that are shared working documents, with columns the public should never see and editors who do not know which cells the website depends on.

**Only the columns you choose ever reach your site.** You list the columns a table shows. Every other column is dropped the moment the sheet is read, before anything is cached or saved. It never reaches your database, your cache or your pages, so a column of private notes in the same sheet cannot leak through a template change, a cache or a backup.

**A broken sheet does not break your page.** If the sheet cannot be read, because someone renamed a header, unpublished the sheet or emptied it, visitors keep seeing the last good copy. The table's edit screen and Tools > Sheet Tables say what went wrong and since when.

**Accessible and light.**

* Real table markup: a caption, column headers with `scope`, and escaped plain text in every cell.
* Optional sorting by any column, keyboard operable, with `aria-sort`.
* An optional filter box that announces how many rows match.
* On a phone, each row becomes a block of labelled lines.
* Prints the whole table, with the header repeated on each page.
* Works without JavaScript: visitors get the complete table in sheet order.
* No libraries and nothing loaded from a CDN. The stylesheet and script load only on pages that show a table.

**Everything else you would expect.**

* Any number of tables, each with its own sheet, columns, headings and refresh interval.
* Rename any column's heading for display.
* Finds the header row even when the sheet has title or instruction rows above it.
* A block and a shortcode, `[sheet_table id="123"]`.
* No account, no premium version, no tracking.

== Installation ==

1. Install and activate the plugin.
2. In Google Sheets, share the sheet so that anyone with the link can view it, or use File > Share > Publish to web.
3. Go to Sheet Tables > Add New. Paste the sheet's link, then list the columns to show, one per line. Once the link is saved, the edit screen lists the sheet's columns so you can copy their exact names.
4. Publish the table, then add it to any page with the Sheet Table block or its shortcode.

== Frequently Asked Questions ==

= I changed the sheet. Why hasn't the site changed? =

Each table keeps a copy of its sheet for the number of minutes set in its "Refresh every" setting, five by default. Updating the table drops the copy, so the next page view reads the sheet again.

= Does the sheet have to be public? =

It has to be readable without signing in: either shared so that anyone with the link can view it, or published to the web. Only the columns you choose are ever shown on your site, but anyone who has the sheet's link can open the whole sheet in Google, so keep the link to yourself.

= Can I use something other than Google Sheets? =

Yes. Any https link that returns a CSV file works.

= What happens if someone renames a column in the sheet? =

If it was one of your chosen columns, the table carries on without it and Tools > Sheet Tables lists it as missing. Update the column name in the table's settings to match. If the change means the header row cannot be found at all, or none of your chosen columns are left, visitors keep seeing the last good copy until it is fixed.

To make a column required, list it under "Header row" too. The header row is then only recognized when that column is present, so renaming it keeps the last good copy on the page instead.

= Is anything from the sheet stored in my database? =

Only the columns you chose: the current copy (as a transient) and the last good copy (as an option), both removed when the table or the plugin is deleted.

== External services ==

This plugin reads spreadsheets from the address you enter for each table, which is normally Google Sheets (docs.google.com). Reading the sheet is how the table gets its data.

The site's server requests that address when a table is shown and its stored copy has expired, and when the table's edit screen is opened, to list the sheet's columns. The request is an ordinary download of the sheet as CSV. No information about your visitors or your users is sent.

Google Sheets is provided by Google: [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).

== Changelog ==

= 1.0.0 =
* First release.
