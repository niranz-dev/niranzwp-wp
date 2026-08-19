=== NiranzWP ===
Contributors: niranjan
Tags: cli, rest-api, abilities, seo, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 5.3.1
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
