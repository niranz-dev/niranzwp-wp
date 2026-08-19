# NiranzWP

An MCP server for WordPress. It gives an AI agent — Claude Code, Cursor, Codex,
or anything else that speaks MCP — a set of purpose-built abilities for working
on a site, instead of a shell and a hope.

Every write previews before it runs, is snapshotted first, and is put back
automatically if it takes the site down.

```bash
npm install -g niranzwp
niranzwp auth login https://your-site.com
```

A code appears in the terminal. Type it into wp-admin, approve, and the terminal
is connected. No passwords are copied around and nothing is stored on the site
that the site can hand back.

---

## Why not just run PHP

Most tools in this space give an agent one very sharp instrument: run arbitrary
PHP, write arbitrary files. That works right up until it doesn't, and the site
is down at three in the morning with nobody sure which of the last forty
commands did it.

NiranzWP takes the opposite position. Fifty-one abilities, each of which knows
what it is for, refuses input that would corrupt what it touches, and can be
undone:

- **A write is checked before it lands.** A PHP file that does not parse is
  never written. A block whose markup would not survive a round trip through
  the editor is refused, not saved and regretted.
- **A write leaves a checkpoint.** Every change records what was there before,
  and `checkpoint-restore` puts it back.
- **A write that breaks the site undoes itself.** A must-use plugin watches the
  next request; if the site has stopped answering, the change is reverted
  without anyone being awake to notice.
- **Both dangerous switches are off out of the box.** Filesystem access and PHP
  execution have to be turned on deliberately, on a screen that says what they
  mean.

`evaluate` is still there for the cases nothing else covers. It just isn't the
first thing reached for.

## What it can do

| | |
|---|---|
| **SEO** | Audit titles and descriptions, find what is missing, rank what to fix first, write meta, check schema, `llms.txt`, internal link suggestions |
| **Content** | List, audit, and refresh posts; set image alt text; find thin and stale pages |
| **Blocks & design** | Read and write blocks safely, inspect registered block types, read and write theme.json design tokens |
| **Files** | Read (paged), list (recursive, globbed), write, edit, delete, and disable or re-enable a file without deleting it |
| **Database** | Report on size and bloat, clean up transients and revisions, report on autoloaded options |
| **Operations** | Site info, plugin list, cache purge, WP-CLI, checkpoints, uploads of any size |
| **Skills** | Store reusable instructions on the site itself, so every client that connects reads the same brief |

Run `niranzwp discover` for the full list on your own site.

## Uploading files

`create-upload-link` mints a single-use bearer token and takes the bytes as the
body of one request. Use it for anything large or binary — a plugin ZIP, a
theme, media, a generated file.

The alternative most tools are left with is base64 inside a PHP payload, which
is a third larger than the file and arrives looking like an attack to every
firewall. Measured on an 803 KB archive against a production host: the chunked
approach failed twice and never completed; this took one request and 3.9
seconds.

The upload only moves into place once its declared size and SHA-256 match what
arrived, and once PHP parses it. Nothing is written on failure.

## Security

Access is granted by a person clicking a button in wp-admin. There is no other
path in.

- **OAuth 2.0 device grant** (RFC 8628) with dynamic client registration
  (RFC 7591) and metadata (RFC 8414). Registering grants nothing; a `client_id`
  is a name to poll under.
- **Nothing is stored in plaintext.** Device codes and both token types are kept
  as SHA-256. The site cannot hand back a credential it does not have.
- **Refresh tokens rotate**, with a two-minute grace window so a client that
  lost a response is not locked out — and after that window, presenting a spent
  token revokes every token descended from the same approval.
- **The off switch holds.** Turning abilities off stops issued tokens
  authenticating, not just the abilities. It also covers a domain lock, so a
  database restored elsewhere does not arrive with working credentials.
- **No credential is issued over plain HTTP**, because every one of them is a
  bearer token.
- **The admin screens refuse to be framed** (`X-Frame-Options: DENY`,
  `frame-ancestors 'none'`). A nonce does not stop clickjacking — it borrows a
  real request from a real user — and the approval screen is exactly the kind of
  button worth borrowing.
- **The open endpoints are metered**, per address, so a script cannot fill the
  options table of a site whose owner never connected anything.

Found something? Open an issue, or write to security@niranz.dev.

## Requirements

WordPress 6.9 or newer (for the Abilities API), PHP 8.0 or newer.

## Install

Download the latest `niranzwp-wp-*.zip` from
[Releases](https://github.com/niranz-dev/niranzwp-wp/releases), then
**Plugins → Add New Plugin → Upload Plugin**. Updates after that appear in
wp-admin like any other plugin.

Then, in **NiranzWP → Configuration**, turn on the abilities you want. Filesystem
and PHP execution are off until you say otherwise.

## Uninstalling

Deleting the plugin removes what belongs to the plugin: its settings, the domain
lock, and the recovery guard.

It does not remove what you wrote — the site brief, design notes, skills, and
every checkpoint, which are the only record of what a write replaced. Those go
only if you ask, and it asks when you click Deactivate rather than leaving the
decision buried in a settings screen.

## Licence

MIT.
