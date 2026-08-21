# Connecting a client

The site speaks two protocols and never a password.

**Abilities over REST** — `/wp-json/wp-abilities/v1/`, what the CLI uses.
**MCP** — `/mcp`, what an MCP client uses. The older
`/wp-json/mcp/niranzwp` answers too, so clients already pointed at it keep
working.

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
claude mcp add --transport http your-site https://your-site.com/mcp
```

Then authenticate it. The client registers itself, opens a browser, and the
site asks you to allow it. Nothing is stored on your machine but the token.

If your client cannot do OAuth, an application password works too:

```bash
claude mcp add --transport http your-site https://your-site.com/mcp \
  --header "Authorization: Basic $(printf 'user:app password' | base64)"
```

## A connector, in the browser

Add the MCP endpoint as a custom connector. The site handles the rest:
`authorization_code` with PKCE, dynamic client registration, and the two
discovery documents a connector looks for.

```
https://your-site.com/mcp
```

You will be asked to allow it, in wp-admin, as yourself. Allowing gives that
tool everything you can do on the site.

Requires HTTPS. A connector runs on someone else's servers and cannot reach
`http://` or a hostname only your machine resolves.

**The address matters.** claude.ai's connector backend completes the OAuth
flow and then never sends the token unless the endpoint path is exactly
`/mcp` - isolated upstream in
[#878](https://github.com/anthropics/claude-ai-mcp/issues/878) by varying only
the path against one server, and confirmed here. That is why `/mcp` is what
this plugin advertises. Give a connector that address; the `/wp-json` one is
kept so existing clients keep working, not for new connections.

If a connector signs in and then reports no tools, read **NiranzWP ->
Troubleshoot**. The Discovery chain row fetches the three discovery documents
the way a client would and says where they disagree, and the request log below
it shows what arrived and whether it carried a token. A run of `auth: Bearer`
entries answered `401` means this server is refusing a token it should accept
- a fault here, whatever the client's own error text suggests. That exact
reading is what found the last one.

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
| Authorization succeeds, then the client says it cannot connect | Give it `/mcp`, not the `/wp-json` address. |
| Connected, but "no tools available" | The server is refusing the token it was given. Troubleshoot's request log will show `auth: Bearer` answered `401`. |
