# What happens when a write goes wrong

Four things hold, on every write, without being asked for.

## A write is checked before it lands

A PHP file that does not parse is never written. A block carrying an attribute
its type does not declare is refused rather than saved. An Elementor layout is
checked against the widgets this site actually has, and the reply names every
setting key it did not recognise — because the builder would have dropped them
silently and rendered the element blank.

An upload only moves into place once its declared size and SHA-256 match what
arrived.

## A write leaves a snapshot

Every change records what was there before it.

```bash
niranzwp run niranzwp/checkpoint-list
niranzwp run niranzwp/checkpoint-restore --yes \
  --input '{"checkpoint_id":123,"dry_run":false}'
```

A snapshot of a post holds its whole meta table, not just the field that
changed, so restoring one undoes everything that write touched.

Snapshots are visible in wp-admin under **NiranzWP → Snapshots**.

## A write that breaks the site undoes itself

A must-use plugin watches the next request. If the site has stopped answering,
the change is reverted — with nobody awake to notice.

## The sharp things are off by default

Filesystem access and PHP execution are opt-in, on a screen that says what they
mean. A fresh install cannot write a file or run PHP until someone turns that
on.

`evaluate` exists for what nothing else covers. Its own description tells a
client to prefer a dedicated ability, and lists which one, because everything
reached through it bypasses the guards above.

## Previews

Every write ability takes `dry_run`, and it defaults to **true**. Calling one
and reading the reply changes nothing. Most report what would change; a few act
straight away, and say so.

Over MCP, every tool declares `readOnlyHint` or `destructiveHint`, so a client
knows which is which before calling it.

## Approving a connection

A token acts as the person who approved it. Registering a client grants
nothing; approval is the whole boundary.

End one from **NiranzWP → Connections**. Each approval is its own row and
revoking one does not touch the rest.

## What this does not protect against

Someone who approves a connection has handed that tool everything they can do
on the site. Snapshots make a mistake reversible; they do not make a decision
reversible.

A site with filesystem and runtime switched on, connected to a chat client, can
be changed by anyone with access to that chat. Decide that deliberately.
