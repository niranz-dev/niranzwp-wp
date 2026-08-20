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

async function authorize(params) {
	const q = new URLSearchParams(params).toString();
	const r = await fetch(`${SITE}/wp-json/niranzwp/v1/oauth/authorize?${q}`, { redirect: 'manual' });
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

{
	const doc = await (await fetch(`${SITE}/.well-known/oauth-authorization-server`)).json();
	const methods = doc.code_challenge_methods_supported || [];
	check('PKCE offers S256 and not plain', methods.includes('S256') && !methods.includes('plain'), methods.join(', '));
	check('authorization_code is offered', (doc.grant_types_supported || []).includes('authorization_code'), '');
}

{
	const r = await fetch(`${SITE}/wp-json/mcp/niranzwp`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
	const h = r.headers.get('www-authenticate') || '';
	check('401 says where to authenticate', r.status === 401 && h.includes('resource_metadata'), `${r.status} ${h || '(no header)'}`);
}

/* ---------------------------------------------------------- registration */

{
	const r = await post('/wp-json/niranzwp/v1/oauth/register', { client_name: 'sweep', redirect_uris: ['http://evil.example/cb'] });
	check('a non-https redirect address is refused', r.status === 400, `${r.status}`);
}
{
	const r = await post('/wp-json/niranzwp/v1/oauth/register', { client_name: 'sweep', redirect_uris: ['http://localhost:3118/callback'] });
	check('loopback over http is allowed', r.status === 201, `${r.status}`);
}

const reg = await post('/wp-json/niranzwp/v1/oauth/register', { client_name: 'sweep probe', redirect_uris: [REDIRECT] });
check('registration', reg.status === 201 && !!reg.body?.client_id, `${reg.status}`);
const clientId = reg.body?.client_id;

/* ------------------------------------------------------------- authorize */

if (clientId) {
	const base = { client_id: clientId, response_type: 'code', code_challenge: 'x', code_challenge_method: 'S256' };

	const stranger = await authorize({ ...base, redirect_uri: 'https://evil.example/cb' });
	check(
		'an unregistered address is refused without redirecting to it',
		stranger.status === 400 && !stranger.location,
		`${stranger.status}${stranger.location ? ' redirected to ' + stranger.location : ''}`,
	);

	const plain = await authorize({ ...base, redirect_uri: REDIRECT, code_challenge_method: 'plain' });
	check('plain PKCE is refused', plain.location.includes('error=invalid_request'), plain.location.slice(0, 60));

	const wrongType = await authorize({ ...base, redirect_uri: REDIRECT, response_type: 'token' });
	check('only response_type=code is allowed', wrongType.location.includes('unsupported_response_type'), wrongType.location.slice(0, 60));

	const state = randomBytes(8).toString('hex');
	const good = await authorize({ ...base, redirect_uri: REDIRECT, state });
	check('a sound request reaches the approval screen', good.status === 302 && /wp-admin|wp-login/.test(good.location), good.location.slice(0, 70));
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
console.log('\nThe approval itself is not checked here: it needs a person in wp-admin.');
process.exit(failed ? 1 : 0);
