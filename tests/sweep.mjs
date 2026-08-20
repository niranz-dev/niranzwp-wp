/**
 * Exercise every ability this plugin registers, against a throwaway site.
 *
 * The CLI got a test suite and it immediately found a bug. The plugin has had
 * none, so this walks all of them: read-only abilities are called for real,
 * writing ones are called with dry_run where they support it and skipped where
 * they do not.
 *
 *   node tests/sweep.mjs --site local
 *
 * Exits non-zero if anything failed, so it can gate a release.
 */
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const run = promisify(execFile);
const CLI = process.env.NIRANZWP_CLI || 'niranzwp';
const SITE = process.argv.includes('--site') ? process.argv[process.argv.indexOf('--site') + 1] : 'local';

async function cli(args) {
	try {
		const { stdout } = await run(process.execPath, [CLI, ...args, '--site', SITE], { timeout: 60_000, maxBuffer: 8 << 20 });
		return { ok: true, out: stdout };
	} catch (e) {
		return { ok: false, out: (e.stdout ?? '') + (e.stderr ?? '') };
	}
}

/**
 * Input per ability. `null` means "cannot be exercised safely here" and is
 * reported as skipped rather than quietly passed.
 */
const INPUTS = {
	'niranzwp/site-info': {},
	'niranzwp/list-plugins': { active_only: true },
	'niranzwp/autoload-report': { limit: 5 },
	'niranzwp/purge-cache': {},

	'niranzwp/seo-audit': {},
	'niranzwp/geo-check': {},
	// Needs an SEO plugin (Rank Math / Yoast / SEOPress) on the site. On an
	// install without one the ability correctly refuses, which is not a bug --
	// so it is only exercised where one is present.
	'niranzwp/seo-list-missing': { field: 'description', limit: 5 },
	'niranzwp/seo-set-meta': { items: [{ id: 1, field: 'description', value: 'sweep test' }], dry_run: true },
	'niranzwp/media-set-alt': { items: [{ id: 1, alt: 'sweep test' }], dry_run: true },
	'niranzwp/geo-llms-txt': { dry_run: true },

	'niranzwp/content-audit': {},
	'niranzwp/content-list': { problem: 'thin', limit: 5 },
	'niranzwp/schema-audit': {},

	'niranzwp/block-types': {},
	'niranzwp/block-type': { name: 'core/paragraph' },
	'niranzwp/block-read': { id: 1 },
	'niranzwp/block-write': '@roundtrip',

	'niranzwp/elementor-status': {},
	// Resolved at runtime to a page that actually has _elementor_data, since a
	// hard-coded id would silently pass on one install and fail on the next.
	'niranzwp/elementor-read': { id: '@elementor', depth: 2 },
	'niranzwp/elementor-find': { id: '@elementor', widget_type: 'heading' },
	'niranzwp/elementor-update-setting': '@roundtrip',
	'niranzwp/elementor-widgets': {},
	'niranzwp/elementor-widget': { name: 'heading' },
	// Both settings scopes are read. Writing either is left alone: the site
	// scope is the whole site's colours and fonts, and a dry run that proves
	// nothing is not worth the risk of a typo in this file.
	'niranzwp/elementor-settings-read': { scope: 'site' },
	'niranzwp/elementor-settings-write': null,

	'niranzwp/read-file': { path: 'wp-config-sample.php' },
	'niranzwp/list-directory': { path: 'wp-content/themes' },
	'niranzwp/write-file': { path: 'niranzwp-sweep.txt', content: 'sweep', dry_run: true },
	'niranzwp/delete-file': '@roundtrip',

	'niranzwp/seo-priorities': {},
	'niranzwp/internal-link-suggest': { limit: 3 },
	'niranzwp/content-refresh': { limit: 5 },

	'niranzwp/db-report': {},
	'niranzwp/db-cleanup': { dry_run: true },
	'niranzwp/asset-audit': {},
	'niranzwp/image-weight': { limit: 5 },

	'niranzwp/design-read': {},
	'niranzwp/design-check': { output: '<style>.a{color:#111111}</style><h1>A real headline</h1>' },
	'niranzwp/design-write': { name: 'sweep probe', dos: ['keep it plain'], donts: [] },

	'niranzwp/context': {},
	'niranzwp/skill-list': { include_body: false },
	'niranzwp/skill-get': { slug: 'alt-text' },
	'niranzwp/skill-write': { slug: 'sweep-probe', title: 'Sweep probe', description: 'created by the sweep', body: 'temporary' },
	'niranzwp/skill-delete': '@roundtrip',

	'niranzwp/checkpoint-create': { label: 'sweep', options: ['blogname'] },
	'niranzwp/checkpoint-list': { limit: 3 },
	// Exercised for real below, against a checkpoint this sweep created and
	// then removes -- restoring an arbitrary one would undo unrelated work.
	'niranzwp/checkpoint-restore': '@roundtrip',
	'niranzwp/checkpoint-delete': '@roundtrip',

	'niranzwp/evaluate': { code: 'return 1 + 1;' },
	'niranzwp/run-wp-cli': { command: 'option get blogname' },
	'niranzwp/wp-cli-status': {},
};

/** Find a page built with Elementor, so those abilities get a real target. */
async function elementorPageId() {
	const r = await cli(['run', 'niranzwp/evaluate', '--input', JSON.stringify({
		code: "global $wpdb; return (int) $wpdb->get_var( \"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND p.post_type IN ('page','post') AND p.post_status = 'publish' ORDER BY pm.post_id ASC LIMIT 1\" );",
	}), '--yes']);
	if (!r.ok) return null;
	try {
		return JSON.parse(r.out).return_value || null;
	} catch {
		return null;
	}
}

const discovered = (await cli(['discover'])).out
	.split('\n')
	.map((l) => l.trim().split(/\s+--\s+/)[0])
	.filter((n) => n.startsWith('niranzwp/'));

if (!discovered.length) {
	console.error('No niranzwp abilities found. Is the plugin active and switched on?');
	process.exit(2);
}

const elementorId = await elementorPageId();
const results = [];

for (const name of discovered) {
	if (!(name in INPUTS)) {
		results.push({ name, status: 'UNTESTED', detail: 'ability exists but this sweep has no input for it' });
		continue;
	}
	let input = INPUTS[name];
	if (input === '@roundtrip') {
		continue; // covered by the dedicated round trip below
	}
	if (input === null) {
		results.push({ name, status: 'skip', detail: 'not safe to call unattended' });
		continue;
	}

	if (input.id === '@elementor') {
		if (!elementorId) {
			results.push({ name, status: 'skip', detail: 'no Elementor page on this install' });
			continue;
		}
		input = { ...input, id: elementorId };
	}

	const r = await cli(['run', name, '--input', JSON.stringify(input), '--yes']);

	// server_unsupported means the ability declined because the host lacks
	// something (no WP-CLI binary, no SEO plugin). That is the ability working,
	// not failing, so it must not gate a release.
	const declined = !r.ok && r.out.includes('server_unsupported');

	results.push({
		name,
		status: r.ok ? 'ok' : (declined ? 'env' : 'FAIL'),
		detail: r.ok ? `${r.out.trim().split('\n').length} lines` : r.out.replace(/^error \[[a-z_]+\]: /m, '').trim().split('\n')[0],
	});
}

/*
 * Checkpoint round trip: change an option, restore it, confirm the old value is
 * back, then clean up. Testing restore against a checkpoint the sweep did not
 * create would undo whatever else was going on.
 */
{
	const created = await cli(['run', 'niranzwp/checkpoint-create', '--input',
		JSON.stringify({ label: 'sweep round trip', options: ['blogname'] }), '--yes']);

	if (!created.ok) {
		results.push({ name: 'niranzwp/checkpoint-restore', status: 'FAIL', detail: 'could not create a checkpoint to restore' });
	} else {
		const id = JSON.parse(created.out).checkpoint_id;
		const before = JSON.parse((await cli(['settings', 'get', '--json'])).out).title;

		await cli(['settings', 'set', 'title', 'sweep clobbered this', '--yes']);
		const applied = await cli(['run', 'niranzwp/checkpoint-restore', '--input',
			JSON.stringify({ checkpoint_id: id, dry_run: false }), '--yes']);
		const after = JSON.parse((await cli(['settings', 'get', '--json'])).out).title;

		results.push({
			name: 'niranzwp/checkpoint-restore',
			status: applied.ok && after === before ? 'ok' : 'FAIL',
			detail: applied.ok && after === before ? `round trip restored "${after}"` : `expected "${before}", got "${after}"`,
		});

		if (after !== before) await cli(['settings', 'set', 'title', before, '--yes']);

		const removed = await cli(['run', 'niranzwp/checkpoint-delete', '--input', JSON.stringify({ checkpoint_id: id }), '--yes']);
		results.push({ name: 'niranzwp/checkpoint-delete', status: removed.ok ? 'ok' : 'FAIL', detail: removed.ok ? 'removed' : removed.out.split('\n')[0] });
	}
}


/*
 * The three destructive abilities.
 *
 * These were skipped for as long as there was no way to put back what they
 * changed, which meant the three most dangerous things in the plugin were the
 * three least tested. Checkpoints and the recovery guard removed that excuse:
 * each one now runs for real against a target the sweep creates, and is undone
 * through the checkpoint the ability itself returned -- which tests the undo at
 * the same time.
 */
{
	const json = (r) => { try { return JSON.parse(r.out); } catch { return null; } };

	const restore = async (id) =>
		id ? (await cli(['run', 'niranzwp/checkpoint-restore', '--input', JSON.stringify({ checkpoint_id: id, dry_run: false }), '--yes'])).ok : false;

	/* ---------------------------------------------------------- block-write */
	{
		const made = await cli(['post', 'create', '--title', 'sweep block-write target', '--yes']);
		const id = made.out.match(/created post (\d+)/)?.[1];

		if (!id) {
			results.push({ name: 'niranzwp/block-write', status: 'FAIL', detail: 'could not create a post to write to' });
		} else {
			const before = json(await cli(['run', 'niranzwp/block-read', '--input', JSON.stringify({ id: +id })]));

			const wrote = await cli(['run', 'niranzwp/block-write', '--input', JSON.stringify({
				id: +id,
				mode: 'replace',
				dry_run: false,
				blocks: [{ name: 'core/paragraph', attributes: {}, innerHTML: '<p>written by the sweep</p>' }],
			}), '--yes']);

			const after = json(await cli(['run', 'niranzwp/block-read', '--input', JSON.stringify({ id: +id })]));
			const changed = JSON.stringify(before?.blocks) !== JSON.stringify(after?.blocks);
			const ckpt = json(wrote)?.checkpoint_id;

			const undone = await restore(ckpt);
			const back = json(await cli(['run', 'niranzwp/block-read', '--input', JSON.stringify({ id: +id })]));
			const restored = JSON.stringify(back?.blocks) === JSON.stringify(before?.blocks);

			results.push({
				name: 'niranzwp/block-write',
				status: wrote.ok && changed && undone && restored ? 'ok' : 'FAIL',
				detail: wrote.ok && changed && undone && restored
					? 'wrote, verified, restored from its own checkpoint'
					: `wrote=${wrote.ok} changed=${changed} undone=${undone} restored=${restored}`,
			});

			await cli(['post', 'delete', id, '--force', '--yes']);
		}
	}

	/* ---------------------------------------------------------- delete-file */
	{
		const path = 'niranzwp-sweep-delete-me.txt';
		const body = 'the sweep put this here';

		await cli(['run', 'niranzwp/write-file', '--input', JSON.stringify({ path, content: body, dry_run: false }), '--yes']);

		const gone = await cli(['run', 'niranzwp/delete-file', '--input', JSON.stringify({ path, dry_run: false }), '--yes']);
		const missing = !(await cli(['run', 'niranzwp/read-file', '--input', JSON.stringify({ path })])).ok;

		const ckpt = json(gone)?.checkpoint_id;
			const undone = await restore(ckpt);
		const readBack = json(await cli(['run', 'niranzwp/read-file', '--input', JSON.stringify({ path })]));
		const restored = readBack?.content === body;

		results.push({
			name: 'niranzwp/delete-file',
			status: gone.ok && missing && undone && restored ? 'ok' : 'FAIL',
			detail: gone.ok && missing && undone && restored
				? 'deleted, verified gone, restored byte-for-byte'
				: `deleted=${gone.ok} gone=${missing} undone=${undone} restored=${restored}`,
		});

		await cli(['run', 'niranzwp/delete-file', '--input', JSON.stringify({ path, dry_run: false }), '--yes']);
	}

	/* ----------------------------------------- elementor-update-setting */
	if (!elementorId) {
		results.push({ name: 'niranzwp/elementor-update-setting', status: 'skip', detail: 'no Elementor page on this install' });
	} else {
		// elementor-find returns `elements[]` carrying `element_id`; `matches` is
		// a count, not the list. Reading the wrong one silently skipped this
		// test rather than failing it, which is how it stayed unnoticed.
		const found = json(await cli(['run', 'niranzwp/elementor-find', '--input', JSON.stringify({ id: elementorId, widget_type: 'heading' })]));
		const target = (found?.elements ?? [])[0];

		if (!target?.element_id) {
			results.push({ name: 'niranzwp/elementor-update-setting', status: 'skip', detail: 'no heading widget to change' });
		} else {
			/*
			 * elementor-read reports which settings a widget has, not their
			 * values, so comparing that tree never shows a change however
			 * thoroughly the layout was rewritten. The update ability returns
			 * the value it replaced and the one it wrote, and a dry run reads
			 * the current value back without touching anything -- so the
			 * assertions are on values rather than on shape.
			 */
			const value = `sweep ${Date.now()}`;

			const set = json(await cli(['run', 'niranzwp/elementor-update-setting', '--input', JSON.stringify({
				id: elementorId, element_id: target.element_id, setting: 'title', value, dry_run: false,
			}), '--yes']));

			const wrote = set?.after === value && set?.before !== value;
			const ckpt = set?.checkpoint_id ?? null;

			const undo = ckpt
				? await cli(['run', 'niranzwp/checkpoint-restore', '--input', JSON.stringify({ checkpoint_id: ckpt, dry_run: false }), '--yes'])
				: { ok: false, out: 'no checkpoint id was returned' };
			const undone = undo.ok;

			const now = json(await cli(['run', 'niranzwp/elementor-update-setting', '--input', JSON.stringify({
				id: elementorId, element_id: target.element_id, setting: 'title', value: 'probe', dry_run: true,
			}), '--yes']));

			const restored = now?.before === set?.before;

			results.push({
				name: 'niranzwp/elementor-update-setting',
				status: wrote && undone && restored ? 'ok' : 'FAIL',
				detail: wrote && undone && restored
					? `set "${set.before}" to "${set.after}", restored it`
					: `wrote=${wrote} ckpt=${ckpt ?? 'none'} restored=${restored} — undo said: ${undo.out.trim().split('\n')[0]}`,
			});
		}
	}
}

/*
 * Skill round trip: the sweep's own skill-write created `sweep-probe` above, so
 * delete that rather than whatever the site actually keeps.
 */
{
	const removed = await cli(['run', 'niranzwp/skill-delete', '--input', JSON.stringify({ slug: 'sweep-probe' }), '--yes']);
	const gone = !(await cli(['run', 'niranzwp/skill-get', '--input', JSON.stringify({ slug: 'sweep-probe' })])).ok;
	results.push({
		name: 'niranzwp/skill-delete',
		status: removed.ok && gone ? 'ok' : 'FAIL',
		detail: removed.ok && gone ? 'removed, and skill-get no longer finds it' : removed.out.split('\n')[0],
	});
}

// An ability registered but absent from INPUTS is the failure mode that matters
// most: it ships untested and nobody notices.
for (const name of Object.keys(INPUTS)) {
	if (!discovered.includes(name)) {
		results.push({ name, status: 'MISSING', detail: 'expected by the sweep, not registered by the site' });
	}
}

const width = Math.max(...results.map((r) => r.name.length));
for (const r of results.sort((a, b) => a.name.localeCompare(b.name))) {
	const icon = { ok: '  ok  ', skip: ' skip ', env: ' env  ', FAIL: ' FAIL ', UNTESTED: 'UNTEST', MISSING: 'MISSNG' }[r.status];
	console.log(`[${icon}] ${r.name.padEnd(width)}  ${r.detail}`);
}

const bad = results.filter((r) => r.status === 'FAIL' || r.status === 'UNTESTED' || r.status === 'MISSING');
const count = (s) => results.filter((r) => r.status === s).length;
console.log(`\n${results.length} abilities -- ${count('ok')} ok, ${count('skip')} skipped, ` +
	`${count('env')} declined (host missing something), ${bad.length} need attention`);
process.exit(bad.length ? 1 : 0);
