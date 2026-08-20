/**
 * Check what the OAuth server refuses, against a real site.
 *
 *   node tests/oauth.mjs http://uae.local
 *
 * The half that needs a person - opening wp-admin and pressing Allow - is not
 * here, because it cannot be. What is here is every refusal, which is where the
 * security of the grant actually lives: a code that reaches the wrong address,
 * a challenge that protects nothing, a verifier that does not match. Each of
 * those is a way in if it is ever allowed, and none of them needs approval to
 * test.
 *
 * Exits non-zero if anything is allowed that should not be.
 */
import { createHash, randomBytes } from 'node:crypto';

const SITE = (process.argv[2] || 'http://uae.local').replace(/\/$/, '');
const REDIRECT = 'https://example.org/callback';
const results = [];

const check = (name, pass, detail) => results.push({ name, pass, detail });

async function post(path, body, json = true) {
	const r = await fetch(SITE + path, {
		method: 'POST',
		headers: { 'Content-Type': json ? 'application/json' : 'application/x-www-form-urlencoded' },
		body: json ? JSON.stringify(body) : new URLSearchParams(body).toString(),
	});
	let parsed = null;
	try { parsed = await r.json(); } catch { /* some answers have no body */ }
	return { status: r.status, body: parsed, headers: r.headers };
}

/*
 * Whatever the metadata advertises, not a path assumed here - the endpoint
 * moved from REST to wp-admin once, and a test that knows the old address
 * would have gone on passing while every client failed.
 */
let AUTHORIZE = `${SITE}/wp-json/niranzwp/v1/oauth/authorize`;

async function authorize(params) {
	const sep = AUTHORIZE.includes('?') ? '&' : '?';
	const r = await fetch(`${AUTHORIZE}${sep}${new URLSearchParams(params).toString()}`, { redirect: 'manual' });
	return { status: r.status, location: r.headers.get('location') || '' };
}

/* ------------------------------------------------------------- discovery */

for (const [path, wants] of [
	['/.well-known/oauth-authorization-server', ['authorization_endpoint', 'token_endpoint', 'code_challenge_methods_supported']],
	['/.well-known/oauth-protected-resource', ['resource', 'authorization_servers']],
]) {
	const r = await fetch(SITE + path);
	const doc = r.ok ? await r.json().catch(() => null) : null;
	const missing = doc ? wants.filter((k) => !(k in doc)) : wants;
	check(`discovery ${path}`, r.ok && missing.length === 0, missing.length ? `missing ${missing.join(', ')}` : `${r.status}`);
}

/*
 * Both RFCs build the discovery URL by putting the well-known segment in front
 * of the resource's own path, and every current connector asks that way. The
 * bare path alone answered 404 to all of them, which is a connector that cannot
 * start - so both forms are checked, and a path that is not this server's is
 * checked to still be refused.
 */
{
	const resource = new URL(`${SITE}/wp-json/mcp/niranzwp`).pathname;
	for (const doc of ['oauth-protected-resource', 'oauth-authorization-server']) {
		const suffixed = await fetch(`${SITE}/.well-known/${doc}${resource}`);
		check(`discovery ${doc} with the resource path appended`, suffixed.ok, `${suffixed.status}`);
	}
	const stranger = await fetch(`${SITE}/.well-known/oauth-protected-resource/wp-json/mcp/not-ours`);
	check('a path this server does not serve is not answered for', stranger.status === 404, `${stranger.status}`);

	/*
	 * The issuer has to be the identifier the document was asked for. With the
	 * path inserted that is the origin plus the resource's path, and returning
	 * the bare origin there is a mismatch a client throws the document away
	 * over - quietly, before it ever reaches the authorize endpoint.
	 */
	const suffixed = await (await fetch(`${SITE}/.well-known/oauth-authorization-server${resource}`)).json();
	const bare = await (await fetch(`${SITE}/.well-known/oauth-authorization-server`)).json();
	check('one issuer, whichever URL was used', suffixed.issuer === SITE && bare.issuer === SITE,
		`${suffixed.issuer} / ${bare.issuer}`);

	const pr = await (await fetch(`${SITE}/.well-known/oauth-protected-resource${resource}`)).json();
	check('the resource names that same issuer', (pr.authorization_servers || [])[0] === SITE,
		(pr.authorization_servers || []).join(', '));
}

{
	const doc = await (await fetch(`${SITE}/.well-known/oauth-authorization-server`)).json();
	const methods = doc.code_challenge_methods_supported || [];
	check('PKCE offers S256 and not plain', methods.includes('S256') && !methods.includes('plain'), methods.join(', '));
	check('authorization_code is offered', (doc.grant_types_supported || []).includes('authorization_code'), '');

	/*
	 * The authorization endpoint has to be somewhere a cookie counts. Under
	 * /wp-json it does not without a nonce, so a browser arriving from a
	 * connector reads as logged out however long its owner has been in
	 * wp-admin, and is sent to log in again every time.
	 */
	AUTHORIZE = doc.authorization_endpoint || AUTHORIZE;
	check('the authorization endpoint is in the cookie context, not REST',
		!!AUTHORIZE && !AUTHORIZE.includes('/wp-json/'), AUTHORIZE.replace(SITE, ''));
}

{
	const r = await fetch(`${SITE}/wp-json/mcp/niranzwp`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
	const h = r.headers.get('www-authenticate') || '';
	check('401 says where to authenticate', r.status === 401 && h.includes('resource_metadata'), `${r.status} ${h || '(no header)'}`);
}

/* ---------------------------------------------------------- registration */

/*
 * Registration is rate limited per address, and this file registers three
 * clients per run. Running it repeatedly trips that limit, which is the
 * endpoint working, not failing - so a 429 is reported as what it is rather
 * than counted against the server.
 */
let throttled = false;
async function register(name, uris) {
	const r = await post('/wp-json/niranzwp/v1/oauth/register', { client_name: name, redirect_uris: uris });
	if (r.status === 429) throttled = true;
	return r;
}

{
	const r = await register('sweep', ['http://evil.example/cb']);
	check('a non-https redirect address is refused', r.status === 400 || throttled, throttled ? 'rate limited, not checked' : `${r.status}`);
}
{
	const r = await register('sweep', ['http://localhost:3118/callback']);
	check('loopback over http is allowed', r.status === 201 || throttled, throttled ? 'rate limited, not checked' : `${r.status}`);
}

const reg = await register('sweep probe', [REDIRECT]);
check('registration', (reg.status === 201 && !!reg.body?.client_id) || throttled, throttled ? 'rate limited, not checked' : `${reg.status}`);
const clientId = reg.body?.client_id;

/* ------------------------------------------------------------- authorize */

if (clientId) {
	const base = { client_id: clientId, response_type: 'code', code_challenge: 'x', code_challenge_method: 'S256' };

	/*
	 * Nobody is signed in here, so an admin page sends every one of these to
	 * wp-login before it can judge them. What is checked is that none of them
	 * is bounced to the client's address instead, because an anonymous caller
	 * must never be able to make this endpoint redirect a browser anywhere.
	 */
	for (const [name, params] of [
		['an unregistered address', { ...base, redirect_uri: 'https://evil.example/cb' }],
		['plain PKCE', { ...base, redirect_uri: REDIRECT, code_challenge_method: 'plain' }],
		['response_type other than code', { ...base, redirect_uri: REDIRECT, response_type: 'token' }],
		['a sound request', { ...base, redirect_uri: REDIRECT, state: randomBytes(8).toString('hex') }],
	]) {
		const r = await authorize(params);
		const wentToLogin = /wp-login|wp-admin/.test(r.location) || r.status === 302 || r.status === 200 || r.status === 403;
		const leaked = r.location.startsWith('https://evil.example') || r.location.startsWith(REDIRECT);
		check(`${name}: an anonymous caller is not redirected to a client`, wentToLogin && !leaked,
			`${r.status} ${r.location.slice(0, 48)}`);
	}
}

/* ----------------------------------------------------------------- token */

if (clientId) {
	const verifier = randomBytes(48).toString('base64url');
	const challenge = createHash('sha256').update(verifier).digest('base64url');
	check('the verifier hashes to the challenge', challenge.length === 43, `${challenge.length} chars`);

	const r = await post('/wp-json/niranzwp/v1/oauth/token', {
		grant_type: 'authorization_code', code: randomBytes(32).toString('hex'),
		client_id: clientId, redirect_uri: REDIRECT, code_verifier: verifier,
	}, false);
	check('a fabricated code is refused', r.status === 400 && r.body?.error === 'invalid_grant', `${r.status} ${r.body?.error}`);

	const bad = await post('/wp-json/niranzwp/v1/oauth/token', { grant_type: 'password', username: 'a', password: 'b' }, false);
	check('an unsupported grant is refused', bad.status === 400 && bad.body?.error === 'unsupported_grant_type', `${bad.status} ${bad.body?.error}`);
}

/* ---------------------------------------------------------------- report */

const width = Math.max(...results.map((r) => r.name.length));
for (const r of results) {
	console.log(`[${r.pass ? '  ok  ' : ' FAIL '}] ${r.name.padEnd(width)}  ${r.detail}`);
}
const failed = results.filter((r) => !r.pass).length;
console.log(`\n${results.length} checks -- ${results.length - failed} ok, ${failed} failed`);
if (throttled) console.log('\nRegistration was rate limited, so the checks that need it were skipped.');
console.log('\nThe approval itself is not checked here: it needs a person in wp-admin.');
process.exit(failed ? 1 : 0);
