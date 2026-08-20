<div align="center">

# NiranzWP

**An MCP server for WordPress that an agent can be trusted with.**

Sixty-five purpose-built abilities instead of a shell and a hope.
Every write previews before it runs, is snapshotted first, and puts itself back if it takes the site down.

[![Release](https://img.shields.io/github/v/release/niranz-dev/niranzwp-wp?label=release&color=7c3aed)](https://github.com/niranz-dev/niranzwp-wp/releases)
[![npm](https://img.shields.io/npm/v/niranzwp?label=CLI&color=7c3aed)](https://www.npmjs.com/package/niranzwp)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-7c3aed)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-7c3aed)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-7c3aed)](LICENSE)

</div>

---

```bash
npm install -g niranzwp
niranzwp auth login https://your-site.com
```

```
Open this page and enter the code:
  https://your-site.com/wp-admin/admin.php?page=niranzwp-connect&code=BFME-6QUV
  code: BFME-6QUV

Waiting...
Connected "your-site" -> https://your-site.com via OAuth
Tokens stored in macOS Keychain; they refresh automatically.
```

Type the code into wp-admin, approve, done. No password is copied anywhere, and
nothing is stored on the site that the site could hand back.

---

## The problem this exists for

Most tools in this space hand an agent one very sharp instrument: run arbitrary
PHP, write arbitrary files. It works beautifully until it doesn't, and then the
site is down at three in the morning and nobody is sure which of the last forty
commands did it.

The usual answer is to lock the agent into a sandbox directory, which solves the
problem by making the tool useless on a real site.

NiranzWP takes the third position. Give the agent abilities that *know what they
are for*, so the dangerous operation is not the default one — and make every
write reversible so being wrong is survivable.

## Four things that are always true

**A write is checked before it lands.**
A PHP file that does not parse is never written. A block whose markup would not
survive a round trip through the editor is refused rather than saved and
regretted. An upload only moves into place once its declared size and SHA-256
match what actually arrived.

**A write leaves a checkpoint.**
Every change records what was there before. `checkpoint-restore` puts it back.

**A write that breaks the site undoes itself.**
A must-use plugin watches the next request. If the site has stopped answering,
the change is reverted — without anyone being awake to notice.

**The dangerous switches are off out of the box.**
Filesystem access and PHP execution are opt-in, on a screen that says plainly
what they mean.

`evaluate` is still there for what nothing else covers. It just isn't the first
thing reached for, and its own description tells the agent so.

## What it can do

<table>
<tr><td width="150"><b>SEO</b></td><td>Audit titles and descriptions · find what is missing · rank what to fix first · write meta · schema audit · <code>llms.txt</code> · internal link suggestions · redirects · GEO checks</td></tr>
<tr><td><b>Content</b></td><td>List, audit and refresh posts · set image alt text · find thin and stale pages</td></tr>
<tr><td><b>Elementor</b></td><td>The live widget catalogue · write, move and edit layouts · page settings · the site kit, global colours and fonts · headers, footers, popups and the conditions that place them</td></tr>
<tr><td><b>Gutenberg</b></td><td>Read the block tree · find, update and move one block by its path · write without corrupting the markup · inspect registered block types</td></tr>
<tr><td><b>Files</b></td><td>Read (paged) · list (recursive, globbed) · write · edit · delete · disable or re-enable a file without deleting it</td></tr>
<tr><td><b>Database</b></td><td>Size and bloat report · transient and revision cleanup · autoloaded options report</td></tr>
<tr><td><b>Operations</b></td><td>Site info · plugin list · cache purge · WP-CLI · snapshots · uploads of any size</td></tr>
<tr><td><b>Skills</b></td><td>Store reusable instructions on the site itself, so every client that connects reads the same brief</td></tr>
</table>

```bash
niranzwp discover                      # everything this site exposes
niranzwp run niranzwp/seo-audit        # and run any of it
```

## Documentation

| | |
| --- | --- |
| [Connecting a client](docs/connecting.md) | The CLI, an MCP client, a browser connector |
| [Designing pages](docs/designing.md) | Elementor and Gutenberg, end to end |
| [Ability reference](docs/abilities.md) | All 65, by group |
| [When a write goes wrong](docs/safety.md) | Snapshots, self-recovery, the switches that are off |

## Moving files

`create-upload-link` mints a single-use bearer token and takes the bytes as the
body of one request. Use it for anything large or binary — a plugin ZIP, a
theme, media, a generated file.

The alternative most tools are left with is base64 inside a PHP payload: a third
larger than the file, and shaped exactly like an attack to every firewall in
front of it.

Measured on an 803 KB archive against a production host behind a WAF:

| | chunked base64 | `create-upload-link` |
|---|---|---|
| requests | 19 | **1** |
| on the wire | ~1.1 MB | **822 KB** |
| result | failed twice, never completed | **HTTP 201 in 3.9s** |

## Security

**Access is granted by a person clicking a button in wp-admin. There is no other
path in.**

| | |
|---|---|
| **Standards** | OAuth 2.0 device grant (RFC 8628), dynamic client registration (RFC 7591), authorization server metadata (RFC 8414) |
| **Storage** | Device codes and both token types kept as SHA-256. The site cannot hand back a credential it does not have |
| **Rotation** | Refresh tokens rotate, with a two-minute grace window so a client that lost a response is not locked out. After that window, presenting a spent token revokes every token descended from the same approval |
| **The off switch** | Turning abilities off stops issued tokens *authenticating*, not merely the abilities — and covers a domain lock, so a database restored elsewhere does not arrive with working credentials |
| **Transport** | No credential is issued over plain HTTP. Loopback and `.local` / `.test` are allowed so development still works |
| **Framing** | Every admin screen sends `X-Frame-Options: DENY` and `frame-ancestors 'none'`. A nonce does not stop clickjacking — it borrows a real request from a real user — and an approval button is exactly what is worth borrowing |
| **Metering** | The endpoints that must be open to strangers are rate-limited per address |
| **Registration** | A stranger cannot evict a working client to make room for themselves |

Found something? Open an issue, or write to security@niranz.dev.

## Requirements

WordPress 6.9+ (for the [Abilities API](https://developer.wordpress.org/plugins/abilities-api/)) · PHP 8.0+

## Install

1. Download the latest `niranzwp-wp-*.zip` from [Releases](https://github.com/niranz-dev/niranzwp-wp/releases)
2. **Plugins → Add New Plugin → Upload Plugin**
3. **NiranzWP → Configuration** — turn on the abilities you want

Updates appear in wp-admin like any other plugin from then on.

## The CLI

```bash
npm install -g niranzwp
```

| | |
|---|---|
| `auth login <url>` | Connect, via the device flow or a browser |
| `discover` | Every ability this site exposes |
| `run <ability>` | Run one |
| `file read/list/write/edit/delete` | Work with files directly |
| `auth list` / `auth logout` | Manage connections |

Credentials go to the macOS Keychain, Windows DPAPI, or `secret-tool` on Linux —
never a plaintext file, and never a command-line argument.

## Uninstalling

Deleting the plugin removes what belongs to the plugin: its settings, the domain
lock, and the recovery guard.

It does not remove what *you* wrote — the site brief, design notes, skills, and
every checkpoint, which are the only record of what a write replaced. Those go
only if you ask, and it asks at the click on **Deactivate**, where a person is
still present, rather than leaving the decision buried in a settings screen.

## Contributing

Issues and pull requests welcome. The CLI lives in its own repository and ships
with 104 end-to-end tests that run against a real WordPress install — please
keep them passing.

## Licence

MIT.

<div align="center"><sub>Built by <a href="https://niranz.dev">Niranjan</a></sub></div>
