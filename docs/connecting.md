# Connecting a client

The site speaks two protocols and never a password.

**Abilities over REST** — `/wp-json/wp-abilities/v1/`, what the CLI uses.
**MCP** — `/wp-json/mcp/niranzwp`, what an MCP client uses.

Both are the same abilities behind the same permission check. Nothing is
available over one and not the other.

## The CLI

```bash
npm install -g niranzwp
niranzwp auth login https://your-site.com
```

A code appears in the terminal. Open the page it prints, type the code in,
approve. Tokens land in the system keyring and refresh themselves.

This is the device grant — RFC 8628, the flow built for things with no browser.

## Claude Code, or any MCP client that runs locally

```bash
claude mcp add --transport http your-site https://your-site.com/wp-json/mcp/niranzwp
```

Then authenticate it. The client registers itself, opens a browser, and the
site asks you to allow it. Nothing is stored on your machine but the token.

If your client cannot do OAuth, an application password works too:

```bash
claude mcp add --transport http your-site https://your-site.com/wp-json/mcp/niranzwp \
  --header "Authorization: Basic $(printf 'user:app password' | base64)"
```

## A connector, in the browser

Add the MCP endpoint as a custom connector. The site handles the rest:
`authorization_code` with PKCE, dynamic client registration, and the two
discovery documents a connector looks for.

```
https://your-site.com/wp-json/mcp/niranzwp
```

You will be asked to allow it, in wp-admin, as yourself. Allowing gives that
tool everything you can do on the site.

Requires HTTPS. A connector runs on someone else's servers and cannot reach
`http://` or a hostname only your machine resolves.

**As of August 2026 this does not finish on claude.ai.** The flow completes —
registration, approval, and a token issued with every field a client asked for —
and then the follow-up request to the MCP endpoint arrives with no
`Authorization` header, or not at all. The client reports that authorization
failed.

This is not something a server can fix. The same symptom is reported against
unrelated stacks — Entra ID, Clerk, n8n — and against two servers built to the
spec and tested side by side:
[#690](https://github.com/anthropics/claude-ai-mcp/issues/690),
[#393](https://github.com/anthropics/claude-ai-mcp/issues/393),
[#506](https://github.com/anthropics/claude-ai-mcp/issues/506),
[#315](https://github.com/anthropics/claude-ai-mcp/issues/315).

Before concluding you have hit it, read **NiranzWP → Troubleshoot**. A token
issued with `last_used: never`, followed by a request logged as `auth: none`,
is this and nothing else. Anything else in that log is worth reading first.

Use Claude Code instead, which authenticates against the same endpoint and
works.

## From a phone

There is no separate mobile client, and the connector is the path that would
have provided one. Remote Control provides it instead: it attaches the Claude
app to a Claude Code session already running on your machine, so the session's
MCP servers, files, and shell are the ones it reaches.

```bash
claude --remote-control
```

Or `/remote-control` inside a session that is already open. Scan the QR code
the terminal prints, or open the app, tap **Code**, and pick the session.

Your machine has to stay awake. The session lives there; the phone is only a
window onto it. Run `/remote-control` again to disconnect, and see
**Trusted devices** in claude.ai account settings to revoke a device outright.

## What the site publishes

| Document | Says |
| --- | --- |
| `/.well-known/oauth-authorization-server` | Where to register, authorize and get a token |
| `/.well-known/oauth-protected-resource` | Which authorization server covers the MCP endpoint |
| `WWW-Authenticate` on a 401 | The same, for a client that only found the endpoint |

Grants: `authorization_code` (PKCE, S256 only), `urn:ietf:params:oauth:grant-type:device_code`,
and `refresh_token`.

## What a token is, and is not

A token acts as the person who approved it and expires in an hour. Its refresh
token lasts thirty days and rotates on every use.

Approval is the whole boundary. Everything before it is unauthenticated
bookkeeping; everything after it acts as that user. Registering a client grants
nothing on its own.

End a connection from **NiranzWP → Connections** in wp-admin. Revoking one
approval does not touch the others.

## If a client cannot connect

| It says | It means |
| --- | --- |
| `Needs authentication` | Registration worked. Nobody has approved it yet. |
| `Dynamic Client Registration rejected` | The redirect address was refused. It must be `https`, or `http` on `localhost` / `127.0.0.1`. |
| `MCP endpoint not found` | Wrong URL, or abilities are switched off on the site. |
| `401` with no `WWW-Authenticate` | An older version. Update the plugin. |
| The server is missing from `claude mcp list` | It was added to one directory. `claude mcp add --scope user` puts it in every directory instead. |
| Authorization succeeds and the client still says it failed | The claude.ai connector bug above. Confirm it in Troubleshoot before spending time on it. |
