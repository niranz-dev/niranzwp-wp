# NiranzWP
## Ability reference
August 2026

## What this is

Every ability the plugin registers, what it takes, and whether it can change anything. `read` never writes. `write` needs approval, previews before it acts unless told otherwise, and records what was there before.

| | |
| --- | --- |
| Abilities | 65 |
| Read-only | 38 |
| Write | 27 |
| Groups | 11 |
| Off by default | Filesystem, Runtime |

## SEO and discovery

11 abilities — 8 read, 3 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `content-refresh` | read | `post_type, older_than_years, limit` | Old, substantial posts that were never revised, ordered by how many other posts link to them. |
| `geo-check` | read | `—` | Checks how reachable this site is by AI answer engines: whether AI crawlers are allowed in robots.txt and whether a sitemap is discoverable. |
| `internal-link-suggest` | read | `id, post_type, limit` | For posts that link nowhere internally, finds related posts whose title already appears as a phrase in the text, so the link has somewhere natural to… |
| `redirect-find` | read | `post_id, url` | What redirects to this page, and what it redirects to. |
| `redirect-list` | read | `limit, offset, status, never_used` | Lists the redirects Rank Math is serving, busiest first, with how many times each has been used and when it was last used. |
| `seo-audit` | read | `post_type` | Counts SEO gaps across the whole site: posts missing meta descriptions, SEO titles or focus keywords, images missing alt text, noindex pages, and… |
| `seo-list-missing` | read | `field, post_type, limit, offset` | Returns a page of published posts that are missing a given SEO field (description, title, focus keyword, alt text or featured image), with IDs,… |
| `seo-priorities` | read | `post_type` | Joins every audit into one list in the order worth working through, with the reason and the effort for each. |
| `geo-llms-txt` | write | `write, limit` | Builds an llms.txt listing this site's pages and recent articles. |
| `media-set-alt` | write | `items*, dry_run` | Sets alt text on one or more image attachments. |
| `seo-set-meta` | write | `items*, dry_run` | Sets the SEO description, title or focus keyword on one or more posts. |

## Content quality

3 abilities — 3 read, 0 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `content-audit` | read | `post_type, thin_words` | Reports content-quality problems across the site: thin posts, duplicate titles, posts with no internal links out, and posts never updated since… |
| `content-list` | read | `problem, post_type, thin_words, limit, …` | Returns the actual posts behind a content-audit finding, with IDs, titles, URLs and the measured value, so they can be fixed one by one. |
| `schema-audit` | read | `post_type` | Reports which published posts carry structured data and which schema types are in use. |

## Elementor

12 abilities — 7 read, 5 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `elementor-find` | read | `id*, widget_type, text` | Finds elements on a page by widget type or by matching text in their settings, returning each element id so it can be edited precisely. |
| `elementor-read` | read | `id*, depth, settings` | Returns a page's Elementor layout as a readable tree of elements, widget types and their ids, without the full settings payload. |
| `elementor-settings-read` | read | `scope, id, search, keys_only` | Reads the settings that live outside a page's element tree: with scope "site", the active kit - global colours, global fonts, layout defaults, the… |
| `elementor-status` | read | `sample` | Reports whether Elementor is active, its version, how many pages use it, and which widget types this site actually uses. |
| `elementor-templates` | read | `type, id` | Lists the site's Elementor library templates - headers, footers, single and archive layouts, popups, saved sections - with the display conditions… |
| `elementor-widget` | read | `name*, responsive, search` | The settings one widget type accepts: each key, its control type, its default, and the values a select or choose control will take. |
| `elementor-widgets` | read | `search, category` | Lists the Elementor widget types registered on this site, so a layout is built only from widgets that actually exist here. |
| `elementor-move` | write | `id*, element_id*, target, where, …` | Moves one element, with everything inside it, to another place on the same page - before or after another element, or in at the start or end of a… |
| `elementor-settings-write` | write | `scope, id, settings*, dry_run` | Changes the settings outside a page's element tree - the site kit with scope "site", one page's own settings with scope "page". |
| `elementor-template-write` | write | `id, type, title, status, …` | Creates a template of a given type, or changes an existing one's title, status or display conditions. |
| `elementor-update-setting` | write | `id*, element_id*, setting*, value, …` | Changes one setting on one element of an Elementor page, found by element id. |
| `elementor-write` | write | `id*, mode, elements, target, …` | Adds, replaces or removes elements in a page's Elementor layout. |

## Gutenberg

7 abilities — 4 read, 3 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `block-find` | read | `id*, name, attribute, value, …` | Finds blocks in a post by type, by an attribute they carry, or by the text they show, and reports the path of each - which is how block-update,… |
| `block-read` | read | `id*, depth` | Returns a post's content as a parsed block tree with names and attributes, rather than raw markup. |
| `block-type` | read | `name*` | Returns one block type's attribute schema, supports, parent and ancestor constraints, so its attributes can be set correctly the first time. |
| `block-types` | read | `search, namespace` | Lists the block types registered on this site, so content is composed only from blocks that actually exist here. |
| `block-move` | write | `id*, from*, to*, position, …` | Moves one block to sit before, after, or inside another. |
| `block-update` | write | `id*, path*, attributes*, replace, …` | Changes the attributes of one block, named by its path. |
| `block-write` | write | `id*, blocks, mode, target, …` | Adds, replaces or removes blocks in a post. |

## Design rules

3 abilities — 2 read, 1 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `design-check` | read | `output*` | Checks HTML or CSS you have built against this site's palette and rules, and against the shapes generated design keeps landing on. |
| `design-read` | read | `—` | The palette, typefaces and rules this site works to. |
| `design-write` | write | `name, notes, dos, donts, …` | Sets the name, notes and rules for this site's design. |

## Snapshots

5 abilities — 2 read, 3 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `checkpoint-list` | read | `limit` | Lists saved snapshots, newest first. |
| `checkpoint-verify` | read | `checkpoint_id*` | Opens a snapshot and reports whether it could actually be restored: that every file it claims is present, that the stored bytes decode to the size… |
| `checkpoint-create` | write | `label, files, posts, options` | Takes a snapshot of the given files, posts and options so a later change can be rolled back. |
| `checkpoint-delete` | write | `checkpoint_id*` | Permanently removes a saved snapshot. |
| `checkpoint-restore` | write | `checkpoint_id*, dry_run` | Puts the files, posts and options in a snapshot back the way they were. |

## Skills

5 abilities — 3 read, 2 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `context` | read | `—` | The standing brief for this site: what is installed, which plugins own which fields, what is switched on, the rules the owner set, and the skills… |
| `skill-get` | read | `slug*` | Returns the full text of one skill by slug. |
| `skill-list` | read | `include_body` | Lists the written instructions this site keeps for anything working on it. |
| `skill-delete` | write | `slug*` | Permanently removes a skill. |
| `skill-write` | write | `slug*, title, description, body*` | Creates or replaces a skill. |

## Performance and database

4 abilities — 3 read, 1 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `asset-audit` | read | `url` | Fetches a page as a visitor would and reports what it loads: scripts, stylesheets, which of them block first paint, and which plugin or theme each… |
| `db-report` | read | `—` | What the database is carrying that nothing reads: revisions, auto-drafts, trash, spam, expired transients and orphaned meta. |
| `image-weight` | read | `limit` | The heaviest images in the library, their dimensions, and how many are wider than any screen will use. |
| `db-cleanup` | write | `only, dry_run` | Removes what db-report found. |

## Site

4 abilities — 3 read, 1 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `autoload-report` | read | `limit` | Reports total autoloaded option size and the largest entries. |
| `list-plugins` | read | `active_only` | Lists installed plugins with version and active state. |
| `site-info` | read | `—` | Returns the site name, URL, WordPress and PHP versions, active theme and locale. |
| `purge-cache` | write | `post_ids, urls, scope` | Purges LiteSpeed, W3 Total Cache and the WP object cache. |

## Filesystem

8 abilities — 2 read, 6 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `list-directory` | read | `path, recursive, pattern, max_depth, …` | Lists files and directories inside the WordPress install, optionally walking subdirectories and filtering by name. |
| `read-file` | read | `path*, offset, limit` | Reads a file inside the WordPress install, whole or in slices. |
| `create-upload-link` | write | `path*, sha256, bytes, max_bytes, …` | Mints a single-use endpoint and bearer token for uploading one file into the WordPress install. |
| `delete-file` | write | `path*, recursive, dry_run` | Deletes a file, or a whole directory when recursive is set. |
| `disable-file` | write | `path*, dry_run` | Renames a file to <name>.disabled so nothing loads it, without deleting it. |
| `edit-file` | write | `path*, old_string*, new_string*, replace_all, …` | Replaces an exact string inside an existing file. |
| `enable-file` | write | `path*, dry_run` | Removes the .disabled suffix so the file loads again. |
| `write-file` | write | `path*, content*, mode, encoding, …` | Writes a file inside the WordPress install, replacing it or appending to it. |

## Runtime

3 abilities — 1 read, 2 write.

| Ability | Kind | Takes | What it does |
| --- | --- | --- | --- |
| `wp-cli-status` | read | `—` | Reports whether WP-CLI can run on this host: shell availability, the resolved wp binary and its version. |
| `evaluate` | write | `code*` | Evaluates PHP inside the loaded WordPress runtime and returns the value it produces, anything it printed, and any warning or error it raised. |
| `run-wp-cli` | write | `command*, dry_run` | Runs a WP-CLI command against this installation and returns stdout, stderr and the exit code. |

## Reading this

| | |
| --- | --- |
| `name*` | Required |
| `dry_run` | Present on every write, and defaults to true |
| `…` | More arguments than listed; ask the site |

A site states its own abilities. `niranzwp discover` lists what one exposes; `niranzwp describe <ability>` gives its full schema.

