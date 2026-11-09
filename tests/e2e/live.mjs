#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet) with a
 * real SUPERTEXT_API_KEY. The editor opens each English sample page in the CMS and translates it
 * on the Supertext tab into every other locale, then the database is checked: every page and
 * content block has its own draft in each locale (nothing published), the texts are really in
 * the target language (de-CH without "ß"), formatting and links survived, no protocol leftovers,
 * and translating again (with "Overwrite existing translations") updates the same records
 * instead of duplicating them. See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8081 DATABASE_URL=mysql://root:root@127.0.0.1:3306/ss_live \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… node tests/e2e/live.mjs
 */
import { chromium } from 'playwright';
import mysql from 'mysql2/promise';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.BASE_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const SOURCE = 'en_US';
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}
const note = (text) => console.log(`note ${text}`);

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was'],
	fr: ['le', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que'],
	it: ['il', 'lo', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ');
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;

const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-', 'data-st-'];
// Formatting, links and Silverstripe shortcodes ([sitetree_link,id=…], [image …]) must survive.
const KEPT = [['links', '<a '], ['bold', '<strong'], ['italics', '<em'], ['lists', '<li'], ['paragraphs', '<p'], ['shortcodes', '[sitetree_link'], ['image shortcodes', '[image']];
// Columns of Fluent's *_Localised tables that aren't translated text (Translator::$exclude_fields and keys).
const NOT_TEXT = new Set(['ID', 'RecordID', 'Locale', 'Created', 'LastEdited', 'Version', 'URLSegment', 'ExtraMeta', 'ReportClass', 'ExtraClass', 'Style']);

const db = await mysql.createConnection(need('DATABASE_URL'));
const q = async (sql, params = []) => (await db.query(sql, params))[0];
async function tableExists(name) {
	return (await q('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [name]))[0].n > 0;
}
/** Fluent's rows of a localised table for these records in a locale: RecordID → row. */
async function localised(table, ids, locale) {
	if (!ids.length) return new Map();
	const rows = await q(`SELECT * FROM \`${table}\` WHERE RecordID IN (?) AND Locale = ?`, [ids, locale]);
	return new Map(rows.map((r) => [r.RecordID, r]));
}

// --- Setup -------------------------------------------------------------------------------
const targets = (await q('SELECT Locale, Title FROM Fluent_Locale WHERE Locale != ? ORDER BY Sort', [SOURCE])).map((l) => ({ locale: l.Locale, title: l.Title }));
const pages = await q("SELECT ID, Title FROM SiteTree WHERE ClassName = 'Page' ORDER BY Sort");
const areaColumn = (await tableExists('Page')) ? 'Page' : 'SiteTree';
const areas = pages.length ? await q(`SELECT ID, ElementalAreaID FROM \`${areaColumn}\` WHERE ID IN (?)`, [pages.map((p) => p.ID)]) : [];
const areaIds = areas.map((a) => a.ElementalAreaID).filter(Boolean);
const elements = areaIds.length ? await q('SELECT ID, ClassName, ParentID FROM Element WHERE ParentID IN (?)', [areaIds]) : [];
const pageIds = pages.map((p) => p.ID);
const elementIds = elements.map((e) => e.ID);
const elementTables = [];
for (const table of ['Element_Localised', 'ElementContent_Localised']) {
	if (await tableExists(table)) elementTables.push(table);
}

/** Everything localised in a locale: { table: Map(RecordID → row) }. */
async function snapshot(locale) {
	const out = { SiteTree_Localised: await localised('SiteTree_Localised', pageIds, locale) };
	for (const table of elementTables) out[table] = await localised(table, elementIds, locale);
	return out;
}
const source = await snapshot(SOURCE);
const sourceElements = elements.filter((e) => source.Element_Localised?.has(e.ID));
console.log(`English: ${pages.length} pages (${pages.map((p) => p.Title).join(', ')}), ${sourceElements.length} content blocks; targets: ${targets.map((t) => t.locale).join(', ')}`);
check('demo has target locales', targets.length > 0);
check('demo has English sample pages', pages.length > 0 && pages.every((p) => source.SiteTree_Localised.has(p.ID)));
check('demo has English content blocks', sourceElements.length > 0 && elementTables.length > 0, elementTables.join(', '));
check('fresh demo (nothing translated yet)', (await q('SELECT COUNT(*) AS n FROM SiteTree_Localised WHERE Locale != ?', [SOURCE]))[0].n === 0);

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, locale: 'en-US' });
page.setDefaultTimeout(30_000);

/** Opens the page in English, ticks every target locale on the Supertext tab and translates. */
async function translatePage(p, label, overwrite) {
	const tag = `${label}: "${p.Title}"`;
	await page.goto(`${B}/admin/pages/edit/show/${p.ID}?l=${SOURCE}`);
	await page.waitForLoadState('networkidle');
	await page.locator('.cms-edit-form .nav-link', { hasText: 'Supertext' }).first().click();
	const targetsField = page.locator('.supertext-targets').first();
	await targetsField.waitFor();
	const warnings = await page.locator('.supertext-intro .alert, .cms-edit-form .alert-danger').allTextContents();
	check(`${tag}: Supertext tab without warnings`, warnings.length === 0, warnings.join(' | '));
	for (const t of targets) {
		const box = targetsField.locator(`input[value="${t.locale}"]`);
		const usable = (await box.count()) === 1 && !(await box.isDisabled());
		check(`${tag}: ${t.locale} is mapped to a Supertext language`, usable);
		if (usable) await box.check();
	}
	if (overwrite) {
		const box = page.locator('input[name=SupertextOverwrite]');
		await box.waitFor({ state: 'visible' });
		await box.check();
	}
	const started = Date.now();
	const [response] = await Promise.all([
		page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/EditForm') && (r.request().postData() ?? '').includes('doSupertextTranslate'), { timeout: 900_000 }),
		page.locator('button.supertext-translate').click(),
	]);
	const status = decodeURIComponent(response.headers()['x-status'] ?? '').replace(/\+/g, ' ');
	await page.waitForLoadState('networkidle');
	await page.waitForTimeout(1000);
	const slug = `${label}-${p.ID}`;
	await page.screenshot({ path: `${artifacts}${slug}-result.png`, fullPage: true });
	console.log(`${tag}: ${Math.round((Date.now() - started) / 1000)} s, HTTP ${response.status()}: ${status}`);
	check(`${tag}: no error from Supertext`, response.status() === 200 && !/Failed|Fehlgeschlagen|Échec|Non riuscit/i.test(status), status);
	const expected = targets.map((t) => t.title);
	check(`${tag}: every locale translated`, expected.every((title) => status.includes(title)) && /Translated into/.test(status), status);
	const toasts = await page.locator('.toast.toast--error, .toast-error, .notice-item.error, .message.error, .message.bad').allTextContents();
	check(`${tag}: no error message in the CMS`, toasts.length === 0, toasts.join(' | '));
}

// --- Log in as the editor ----------------------------------------------------------------
await page.goto(`${B}/Security/login?BackURL=${encodeURIComponent('/admin/pages/')}`);
await page.fill('input[name=Email]', need('EDITOR_EMAIL'));
await page.fill('input[name=Password]', need('EDITOR_PASSWORD'));
await page.click('[type=submit]');
await page.waitForURL(/\/admin\//, { timeout: 30_000 }).catch(() => {});
if (!check('editor can log in (DEMO_EDITOR_*)', new URL(page.url()).pathname.startsWith('/admin/'), page.url())) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}

// --- First translation -------------------------------------------------------------------
for (const p of pages) await translatePage(p, 'first-run', false);
const first = {};
for (const t of targets) first[t.locale] = await snapshot(t.locale);

for (const t of targets) {
	const lang = t.locale.split(/[-_]/)[0].toLowerCase();
	const tag = `${t.title} (${t.locale})`;
	const got = first[t.locale];
	check(`${tag}: every page translated`, pageIds.every((id) => got.SiteTree_Localised.has(id)), `${got.SiteTree_Localised.size}/${pageIds.length}`);
	for (const table of elementTables) {
		const want = [...source[table].keys()];
		check(`${tag}: every content block translated (${table})`, want.every((id) => got[table].has(id)), `${want.filter((id) => got[table].has(id)).length}/${want.length}`);
	}
	for (const table of ['SiteTree_Localised', ...elementTables]) {
		const live = `${table}_Live`;
		if (!(await tableExists(live))) { note(`${live} doesn't exist`); continue; }
		const n = (await q(`SELECT COUNT(*) AS n FROM \`${live}\` WHERE Locale = ?`, [t.locale]))[0].n;
		check(`${tag}: nothing published (${live})`, n === 0, `${n} published`);
	}
	for (const id of pageIds) {
		const seg = got.SiteTree_Localised.get(id)?.URLSegment ?? '';
		check(`${tag}: page #${id} has an ASCII URL segment`, /^[a-z0-9-]+$/.test(seg), seg);
	}

	// Field by field: filled, changed, no protocol leftovers, formatting kept.
	let fields = 0;
	let changed = 0;
	let text = '';
	for (const table of ['SiteTree_Localised', ...elementTables]) {
		for (const [id, s] of source[table]) {
			const row = got[table].get(id);
			if (!row) continue;
			for (const [f, value] of Object.entries(s)) {
				if (NOT_TEXT.has(f) || typeof value !== 'string' || !/\p{L}/u.test(plain(value))) continue;
				fields++;
				const v = String(row[f] ?? '');
				const where = `${table.replace('_Localised', '')}#${id}.${f}`;
				if (!check(`${tag}: ${where} filled`, plain(v).trim() !== '')) continue;
				if (v !== value) changed++;
				// A single word can be the same in the target language ("Home"), so it only counts against the ratio.
				else note(`${tag}: ${where} unchanged ("${plain(value).trim().slice(0, 60)}")`);
				text += ` ${plain(v)}`;
				const left = LEFTOVERS.filter((l) => v.includes(l));
				check(`${tag}: ${where} has no protocol leftovers`, left.length === 0, left.join(', '));
				for (const [what, needle] of KEPT) {
					if (count(value, needle)) check(`${tag}: ${where} keeps its ${what}`, count(v, needle) === count(value, needle), `${count(v, needle)}/${count(value, needle)}`);
				}
				for (const href of value.match(/href="[^"]*"/g) ?? []) check(`${tag}: ${where} keeps the link ${href}`, v.includes(href));
			}
		}
	}
	check(`${tag}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);

	if (STOPWORDS[lang]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${tag}: text is in the target language`, best === lang, JSON.stringify(scores));
	}
	if (/^de[-_]ch$/i.test(t.locale)) check(`${tag}: Swiss German spelling (no "ß")`, !text.includes('ß'));
}
const errors = await q("SELECT TargetLocale, Message FROM SupertextTranslation WHERE Status = 'error'");
check('no errors in the Supertext translation log', errors.length === 0, errors.map((e) => `${e.TargetLocale}: ${e.Message}`).join(' | '));

// --- Translating again updates in place ---------------------------------------------------
for (const p of pages) await translatePage(p, 'second-run', true);
for (const t of targets) {
	const tag = `${t.title} (${t.locale})`;
	const again = await snapshot(t.locale);
	for (const table of Object.keys(again)) {
		const ids = (s) => [...s.values()].map((r) => r.ID).sort((a, b) => a - b).join(',');
		check(`${tag}: translating again updates ${table} in place (no duplicates)`, ids(first[t.locale][table]) === ids(again[table]), `${first[t.locale][table].size} → ${again[table].size}`);
	}
	const total = (await q('SELECT COUNT(*) AS n FROM SiteTree_Localised WHERE Locale = ?', [t.locale]))[0].n;
	check(`${tag}: no extra pages`, total === pageIds.length, `${total}/${pageIds.length}`);
}
const log = await q("SELECT Status, COUNT(*) AS n FROM SupertextTranslation GROUP BY Status");
console.log(`Translation log: ${log.map((r) => `${r.Status} ${r.n}`).join(', ')}`);
check('translation log: every run translated', log.find((r) => r.Status === 'translated')?.n === pages.length * targets.length * 2 && !log.some((r) => r.Status !== 'translated'), JSON.stringify(log));

await browser.close();
await db.end();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
