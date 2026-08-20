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
