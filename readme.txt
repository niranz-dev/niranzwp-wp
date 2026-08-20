=== NiranzWP ===
Contributors: niranjan
Tags: cli, rest-api, abilities, seo, automation
Requires at least: 6.9
Tested up to: 7.0.4
Requires PHP: 8.0
Stable tag: 5.3.14
License: MIT
License URI: https://opensource.org/licenses/MIT

MCP server that gives AI agents control of WordPress through purpose-built abilities - SEO, content, blocks, the database and files. Every write is previewed, snapshotted, and reverted automatically if the site breaks.

== Description ==

NiranzWP registers abilities through the WordPress Abilities API (core, 6.9+),
so any client that speaks that API can inspect and maintain this site.

Pair it with the NiranzWP CLI:

    npm install -g niranzwp
    niranzwp auth login yoursite.com

There is no configuration file to copy. WordPress asks you to approve the
connection in the browser, and the credential goes to your OS keychain.

= What it registers =

Forty-seven abilities in eleven groups.

Site -- site-info, list-plugins, autoload-report, purge-cache

SEO and GEO -- seo-audit, geo-check, seo-list-missing, seo-set-meta,
media-set-alt, geo-llms-txt, seo-priorities, internal-link-suggest,
content-refresh

Content -- content-audit, content-list, schema-audit

Gutenberg -- block-types, block-type, block-read, block-write

Elementor -- elementor-status, elementor-read, elementor-find,
elementor-update-setting

Design -- design-read, design-check, design-write

Performance -- db-report, db-cleanup, asset-audit, image-weight

Context and skills -- context, skill-list, skill-get, skill-write,
skill-delete

Checkpoints -- checkpoint-create, checkpoint-list, checkpoint-restore,
checkpoint-delete

Filesystem -- read-file, list-directory, write-file, delete-file

Runtime -- evaluate, run-wp-cli, wp-cli-status

Every one of them requires an administrator.

== Security ==

Nothing is exposed after activation. Three switches, all off by default and
independent of each other, control what is available:

* Abilities -- the read and content abilities
* Filesystem -- read-file, list-directory, write-file, delete-file
* Runtime -- evaluate and run-wp-cli

Access is locked to the domain it was enabled on. Restoring a database onto a
different host does not carry access with it; the switches must be thrown
again there.

= The runtime switch runs arbitrary PHP =

Turning on Runtime gives anyone holding an administrator credential the
ability to execute arbitrary PHP on this site. That is full control, and it is
the point of the ability -- but it should be a considered decision, which is
why it is off by default and separate from everything else. Leave it off
unless you need it.

= Undo =

write-file, delete-file, block-write and elementor-update-setting take a
snapshot before they change anything, and return its id with the result. A
snapshot can be restored later, dry run first.

Snapshots are held in a private custom post type rather than under uploads,
because uploads is served by the web server and a snapshot contains verbatim
theme and plugin source.

This is not a substitute for a host backup. It covers what this plugin itself
touches, and nothing else.

= Containment =

File paths resolve inside ABSPATH and nowhere else. Traversal and symlinks
that escape the root are rejected. wp-config.php is never readable, writable
or capturable, and wp-admin and wp-includes cannot be written to.

== Installation ==

1. Upload the ZIP under Plugins > Add New > Upload Plugin, and activate.
2. Open NiranzWP > Configuration and switch on what you need. Nothing is
   available until you do.
3. Check NiranzWP > Troubleshoot if anything looks wrong.

== Changelog ==

= 5.3.14 =
* Elementor: build a whole page, not one setting at a time. Write, move and edit
  layouts; read the widget catalogue this site actually has, so a layout is
  composed from real widgets and real setting names; page settings and the site
  kit, which is where global colours and fonts live; and headers, footers and
  popups with the conditions that place them.
* Elementor: writing a layout to a page that had never been opened in the editor
  stored it and rendered nothing, because Elementor only renders for a post its
  meta says the builder built. Such a page is now made an Elementor page.
* Elementor: a container's own spacing - margin, padding, width, z-index - was
  missing from the catalogue, although writing it worked. The shared control set
  is now read from the shared widget rather than guessed from the editor tab.
* Gutenberg: address one block instead of rewriting the body. Every block now
  reports its path; block-find locates blocks by type, attribute or text;
  block-update changes one block's attributes; block-move takes a block before,
  after or inside another; and block-write gained after, before, replace-block
  and delete.
* OAuth: sign in through the browser. authorization_code with PKCE, the two
  discovery documents a connector looks for, and a WWW-Authenticate header on
  the MCP endpoint so a client that only found the endpoint is told where to
  ask. The existing code-in-wp-admin flow is unchanged.
* MCP: the server offered ten tools from a list written by hand. Every ability
  added since was invisible to any MCP client. It now offers what is registered.
* Connections: one row per connection rather than one per token, and revoking a
  row ends that approval alone.
* Updates: "Check Again" reached the plugin's own update check, which had been
  serving a cached answer for up to twelve hours.
* Documentation under docs/: connecting a client, designing pages, the full
  ability reference, and what happens when a write goes wrong.

= 5.3.13 =
* Checkpoints are called snapshots everywhere they are read.

= 5.3.12 =
* The edition chip in the admin menu is as bright as the name beside it.
* "Check Again" on the Plugins screen now reaches this plugin's update check.

= 5.3.11 =
* The animated ring is on the masthead only. On the admin menu, which is on
  screen on every page, it was motion nobody asked for.

= 5.3.10 =
* The edition sits beside the plugin name, and appears in the admin menu too.

= 5.3.9 =
* Redirects can be asked about: what is being served, what redirects to a page,
  and what a page redirects to.
* Bulk SEO writes leave a snapshot, and a snapshot can be verified without
  restoring it.

= 5.3.8 =
* The protocol mark is on the plugin, and the figures carry the brand colour.
* The running edition is shown.

= 5.3.7 =
* The Plugins screen showed a changelog that ended at 1.0.0, because it was a
  block of HTML in the code rather than the list in readme.txt. It is read from
  readme.txt now, so there is one place to keep it.
* Connections shows the expiry as a date rather than "in 4 weeks", and in the
  site's timezone rather than UTC.

= 5.3.6 =
* Connections: renamed the section to Connected apps.
* Fixed: "View details" appeared twice on the Plugins screen. WordPress adds
  that link itself once an update is pending, and this plugin was adding its
  own beside it.

= 5.3.3 =
* Fixed: the update check had never worked. It fetched a URL that answers every
  unknown path with a web page, read that as "no update available", and a
  broken check is indistinguishable from an up-to-date site. It now reads the
  manifest from the newest GitHub release.

= 5.3.2 =
* The connect screen could be drawn inside another site's page, where an
  administrator could be led into approving a connection they never asked for.
  A nonce does not prevent that - it is a real request from a real user - so
  every screen now refuses to be framed.
* Switching abilities off left issued tokens working. A bearer token makes the
  request an administrator, so posts, users, media and plugins all answered to
  it. The switch now holds in authentication, which also brings tokens under
  the domain lock.
* Refresh rotation destroyed the old token before the new pair was delivered,
  so a lost response ended the connection permanently. A spent token now
  answers an honest retry for two minutes; after that it is treated as theft
  and revokes every token from the same approval.
* The device code is no longer stored in plaintext alongside the user code.
* The endpoints that must be open to strangers are rate-limited per address,
  and registration can no longer evict a working client to make room.
* No credential is issued over plain HTTP.

= 5.3.0 =
* An OAuth 2.0 device-grant server, so a terminal connects by having someone
  approve a code in wp-admin rather than by being handed a password.
* create-upload-link: one request for a file of any size, checked against a
  declared hash and parsed before it is put in place.
* Deleting the plugin now asks, at the click on Deactivate, whether the site
  brief, design notes, skills and checkpoints should go with it. They are kept
  by default.
* The file abilities gained paging, recursion, globbing, edit, and disable or
  enable without deleting.
* Fixed: three ways block-write could return success while writing markup the
  editor would not survive.

= 1.1.0 =
* Design: read the theme's palette, and check built HTML/CSS against it and
  against the shapes generated design keeps landing on.
* Performance: database report and cleanup, asset audit fetched as a visitor
  would, image weight.
* SEO planning: every audit joined into one prioritised list, internal link
  suggestions with real anchors, and old posts worth refreshing.
* Self-hosted updates, verified against a SHA-256 in the manifest.
* View details modal on the Plugins screen.
* Fixed: restoring a checkpoint of an Elementor page failed with "Invalid
  page template" when the template came from a plugin filter absent in REST.
* Fixed: Elementor abilities silently operated on revisions, because
  Elementor copies its layout meta onto them. A revision id is now refused
  by name.
* Every ability, including the destructive ones, is exercised by the sweep;
  nothing is skipped.

= 1.0.0 =
* Checkpoints: snapshot and restore files, posts and options, taken
  automatically before every destructive write.
* Elementor: read, search and update layouts.
* Gutenberg: read and write blocks, validated against the block registry.
* SEO and GEO: audits, missing-field listings, batch meta and alt text.
* Content: thin, duplicate-title, orphaned and stale content audits.
* Filesystem and runtime abilities, behind their own switches.
* Fixed: abilities crashed when called with no input, because core passes an
  empty string rather than an array in that case.
* Corrected a claim in this readme that the plugin shipped no code execution
  ability. It does, behind the Runtime switch, which is off by default.

= 0.1.0 =
* First release: four abilities, Configuration / Abilities / Troubleshoot
  screens.
