# Ability reference

65 abilities, in 11 groups. `read` never changes anything. `write` needs approval, previews first unless told otherwise, and leaves a snapshot.

Ask any site what it actually exposes, and what one ability takes:

```bash
niranzwp discover
niranzwp describe niranzwp/elementor-write
```


## SEO and discovery

Audits, what is missing, what to fix first, and writing meta back. 11 abilities, 8 read and 3 write.

| Ability | | What it does |
| --- | --- | --- |
| `content-refresh` | read | Old, substantial posts that were never revised, ordered by how many other posts link to them. |
| `geo-check` | read | Checks how reachable this site is by AI answer engines: whether AI crawlers are allowed in robots.txt and whether a sitemap is discoverable. |
| `internal-link-suggest` | read | For posts that link nowhere internally, finds related posts whose title already appears as a phrase in the text, so the link has somewhere natural to go. |
| `redirect-find` | read | What redirects to this page, and what it redirects to. |
| `redirect-list` | read | Lists the redirects Rank Math is serving, busiest first, with how many times each has been used and when it was last used. |
| `seo-audit` | read | Counts SEO gaps across the whole site: posts missing meta descriptions, SEO titles or focus keywords, images missing alt text, noindex pages, and content bloat. |
| `seo-list-missing` | read | Returns a page of published posts that are missing a given SEO field (description, title, focus keyword, alt text or featured image), with IDs, titles and URLs so they can be fixed. |
| `seo-priorities` | read | Joins every audit into one list in the order worth working through, with the reason and the effort for each. |
| `geo-llms-txt` | write | Builds an llms.txt listing this site's pages and recent articles. |
| `media-set-alt` | write | Sets alt text on one or more image attachments. |
| `seo-set-meta` | write | Sets the SEO description, title or focus keyword on one or more posts. |

## Content quality

Thin pages, duplicate titles, missing structured data. 3 abilities, 3 read and 0 write.

| Ability | | What it does |
| --- | --- | --- |
| `content-audit` | read | Reports content-quality problems across the site: thin posts, duplicate titles, posts with no internal links out, and posts never updated since publication. |
| `content-list` | read | Returns the actual posts behind a content-audit finding, with IDs, titles, URLs and the measured value, so they can be fixed one by one. |
| `schema-audit` | read | Reports which published posts carry structured data and which schema types are in use. |

## Gutenberg

Read and write the block tree, one block at a time or all of it. 7 abilities, 4 read and 3 write.

| Ability | | What it does |
| --- | --- | --- |
| `block-find` | read | Finds blocks in a post by type, by an attribute they carry, or by the text they show, and reports the path of each - which is how block-update, block-move and block-write name a block. |
| `block-read` | read | Returns a post's content as a parsed block tree with names and attributes, rather than raw markup. |
| `block-type` | read | Returns one block type's attribute schema, supports, parent and ancestor constraints, so its attributes can be set correctly the first time. |
| `block-types` | read | Lists the block types registered on this site, so content is composed only from blocks that actually exist here. |
| `block-move` | write | Moves one block to sit before, after, or inside another. |
| `block-update` | write | Changes the attributes of one block, named by its path. |
| `block-write` | write | Adds, replaces or removes blocks in a post. |

## Elementor

The widget catalogue, page layouts, site settings and theme templates. 12 abilities, 7 read and 5 write.

| Ability | | What it does |
| --- | --- | --- |
| `elementor-find` | read | Finds elements on a page by widget type or by matching text in their settings, returning each element id so it can be edited precisely. |
| `elementor-read` | read | Returns a page's Elementor layout as a readable tree of elements, widget types and their ids, without the full settings payload. |
| `elementor-settings-read` | read | Reads the settings that live outside a page's element tree: with scope "site", the active kit - global colours, global fonts, layout defaults, the whole Site Settings panel; with scope "page", one… |
| `elementor-status` | read | Reports whether Elementor is active, its version, how many pages use it, and which widget types this site actually uses. |
| `elementor-templates` | read | Lists the site's Elementor library templates - headers, footers, single and archive layouts, popups, saved sections - with the display conditions that decide where each one appears. |
| `elementor-widget` | read | The settings one widget type accepts: each key, its control type, its default, and the values a select or choose control will take. |
| `elementor-widgets` | read | Lists the Elementor widget types registered on this site, so a layout is built only from widgets that actually exist here. |
| `elementor-move` | write | Moves one element, with everything inside it, to another place on the same page - before or after another element, or in at the start or end of a container. |
| `elementor-settings-write` | write | Changes the settings outside a page's element tree - the site kit with scope "site", one page's own settings with scope "page". |
| `elementor-template-write` | write | Creates a template of a given type, or changes an existing one's title, status or display conditions. |
| `elementor-update-setting` | write | Changes one setting on one element of an Elementor page, found by element id. |
| `elementor-write` | write | Adds, replaces or removes elements in a page's Elementor layout. |

## Snapshots

What every write leaves behind, and how to put it back. 5 abilities, 2 read and 3 write.

| Ability | | What it does |
| --- | --- | --- |
| `checkpoint-list` | read | Lists saved snapshots, newest first. |
| `checkpoint-verify` | read | Opens a snapshot and reports whether it could actually be restored: that every file it claims is present, that the stored bytes decode to the size and hash recorded, that PHP still parses, and where… |
| `checkpoint-create` | write | Takes a snapshot of the given files, posts and options so a later change can be rolled back. |
| `checkpoint-delete` | write | Permanently removes a saved snapshot. |
| `checkpoint-restore` | write | Puts the files, posts and options in a snapshot back the way they were. |

## Skills

Instructions stored on the site, so every client reads the same brief. 5 abilities, 3 read and 2 write.

| Ability | | What it does |
| --- | --- | --- |
| `context` | read | The standing brief for this site: what is installed, which plugins own which fields, what is switched on, the rules the owner set, and the skills available. |
| `skill-get` | read | Returns the full text of one skill by slug. |
| `skill-list` | read | Lists the written instructions this site keeps for anything working on it. |
| `skill-delete` | write | Permanently removes a skill. |
| `skill-write` | write | Creates or replaces a skill. |

## Design rules

A house style the site can state and check output against. 3 abilities, 2 read and 1 write.

| Ability | | What it does |
| --- | --- | --- |
| `design-check` | read | Checks HTML or CSS you have built against this site's palette and rules, and against the shapes generated design keeps landing on. |
| `design-read` | read | The palette, typefaces and rules this site works to. |
| `design-write` | write | Sets the name, notes and rules for this site's design. |

## Performance and database

What the site loads, what the database holds. 4 abilities, 3 read and 1 write.

| Ability | | What it does |
| --- | --- | --- |
| `asset-audit` | read | Fetches a page as a visitor would and reports what it loads: scripts, stylesheets, which of them block first paint, and which plugin or theme each came from. |
| `db-report` | read | What the database is carrying that nothing reads: revisions, auto-drafts, trash, spam, expired transients and orphaned meta. |
| `image-weight` | read | The heaviest images in the library, their dimensions, and how many are wider than any screen will use. |
| `db-cleanup` | write | Removes what db-report found. |

## Filesystem

Off by default. Reads, writes and edits inside the install. 8 abilities, 2 read and 6 write.

| Ability | | What it does |
| --- | --- | --- |
| `list-directory` | read | Lists files and directories inside the WordPress install, optionally walking subdirectories and filtering by name. |
| `read-file` | read | Reads a file inside the WordPress install, whole or in slices. |
| `create-upload-link` | write | Mints a single-use endpoint and bearer token for uploading one file into the WordPress install. |
| `delete-file` | write | Deletes a file, or a whole directory when recursive is set. |
| `disable-file` | write | Renames a file to <name>.disabled so nothing loads it, without deleting it. |
| `edit-file` | write | Replaces an exact string inside an existing file. |
| `enable-file` | write | Removes the .disabled suffix so the file loads again. |
| `write-file` | write | Writes a file inside the WordPress install, replacing it or appending to it. |

## Runtime

Off by default. PHP and WP-CLI, for what nothing else covers. 3 abilities, 1 read and 2 write.

| Ability | | What it does |
| --- | --- | --- |
| `wp-cli-status` | read | Reports whether WP-CLI can run on this host: shell availability, the resolved wp binary and its version. |
| `evaluate` | write | Evaluates PHP inside the loaded WordPress runtime and returns the value it produces, anything it printed, and any warning or error it raised. |
| `run-wp-cli` | write | Runs a WP-CLI command against this installation and returns stdout, stderr and the exit code. |

## Site

Plugins, caches, and what this install actually is. 4 abilities, 3 read and 1 write.

| Ability | | What it does |
| --- | --- | --- |
| `autoload-report` | read | Reports total autoloaded option size and the largest entries. |
| `list-plugins` | read | Lists installed plugins with version and active state. |
| `site-info` | read | Returns the site name, URL, WordPress and PHP versions, active theme and locale. |
| `purge-cache` | write | Purges LiteSpeed, W3 Total Cache and the WP object cache. |
