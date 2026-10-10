// Headless Chromium proof of slice 1 (docs/build-specs/bases-core.md "Proof", the last bullet): at 375 x 740 and 1280 x 800 — the template
// cards stack on a phone, the first run in three taps (name, template, Done) landing on Add documents, the tabs only the built slices' (Overview,
// Share, Settings now), the share table scrolling inside the page, a person shared through the record picker, the field error in #flash, every
// control >= 44 px, scrollWidth = the viewport, no console errors; with JavaScript off a base is created, shared with a department and its
// settings saved. Run through tests/phase3/slice1/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8501';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/kn-shots-s1';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.knowledge.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const sql = (q) => execFileSync('psql', ['-At', '-h', process.env.DB_HOST, '-p', process.env.DB_PORT, '-U', process.env.DB_USER, '-d', process.env.DB_NAME, '-c', q],
  { env: { ...process.env, PGPASSWORD: process.env.DB_PASSWORD } }).toString().trim();
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });   // a refused save answers 422 by design
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const small = (page, scope = '#page-content') => page.evaluate((s) => [...document.querySelectorAll(`${s} a.btn, ${s} button, ${s} input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ${s} select, ${s} summary, ${s} .nav-link`)]
  .filter((a) => { const r = a.getBoundingClientRect(); const cs = getComputedStyle(a); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && !a.closest('fieldset[disabled]') && (r.height < 44 || r.width < 44); })
  .map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);
async function pickRecord(page, id, query, value) {
  await page.click(`#${id}-open`); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row');
  await page.fill('#record-picker-search', query); await page.waitForTimeout(700);
  await page.click(`#record-picker .record-picker-row[data-value="${value}"]`);
  await page.waitForSelector('#record-picker', { state: 'hidden' });
}
sql(`INSERT INTO base_templates (key, name, description, version, guidelines, area_rule, sort_order) VALUES ('smoke_browser', 'SMOKE Contracts', 'A second template for the proof.', 1,
  jsonb_build_array(jsonb_build_object('kind', 'extraction', 'title', 'Parties', 'body', 'Extract the parties and the dates.')), 'none', 3) ON CONFLICT (key) DO NOTHING`);

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — Bea, a Member, makes her first base`);
  const { ctx, page, errors } = await session(28, vp);
  await page.goto(BASE + '/bases/new', { waitUntil: 'networkidle' });
  const cards = await page.locator('#base-form-field-template .kn-choice-card').evaluateAll((els) => els.map((e) => e.getBoundingClientRect()).map((r) => ({ x: Math.round(r.x), w: Math.round(r.width), y: Math.round(r.y) })));
  if (vp.width < 600) ok(cards.length >= 2 && cards.every((c) => c.x === cards[0].x) && cards[1].y > cards[0].y, 'the template cards stack one above the other ' + JSON.stringify(cards));
  else ok(cards.length >= 2 && cards[1].x > cards[0].x, 'the template cards sit side by side');
  ok(await page.evaluate(() => document.activeElement && document.activeElement.id === 'base-form-field-name'), 'the name has the focus');
  let o = await overflow(page); ok(o.sw === o.iw, `no sideways scroll on the form (${o.sw} = ${o.iw})`);
  let s = await small(page); ok(s.length === 0, 'every control of the form is at least 44 px ' + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${tag}-base-add.png`, fullPage: true });
  // three taps: the name, the template, Done
  await page.fill('#base-form-field-name', `SMOKE Bea ${tag}`);
  await page.click('#template-card-smoke_browser');
  await Promise.all([page.waitForURL(/\/bases\/\d+(\?notice=created)?$/), page.click('#base-form-save-btn')]);
  await page.waitForLoadState('networkidle');
  const id = Number(page.url().match(/\/bases\/(\d+)/)[1]);
  ok(await page.locator('#base-add-documents').isVisible(), `three taps land on the base (${id}) with Add documents first`);
  ok(sql(`SELECT template FROM knowledge_bases WHERE id = ${id}`) === 'smoke_browser', 'the template tapped is the base\'s');
  const tabs = await page.locator('#base-tabs .nav-link').evaluateAll((els) => els.map((e) => e.id));
  // a tab is lit only by its slice's controller (present and not the shell's placeholder): the expected set is read from disk, so a later
  // slice lighting its tab does not break this proof; Overview first, Share and Settings always (slice 1's), Ask hidden while a placeholder
  const built = (file) => fs.existsSync(ROOT + 'html' + file) && !fs.readFileSync(ROOT + 'html' + file, 'utf8').includes('render_nav_stub(');
  const expected = Object.entries({ overview: '/bases/view.php', ask: '/ask/index.php', sources: '/bases/sources.php', areas: '/bases/areas.php', guidelines: '/bases/guidelines.php',
    builds: '/bases/builds.php', graph: '/bases/graph.php', questions: '/bases/questions.php', keys: '/bases/keys.php', feeds: '/bases/feeds.php', proposals: '/bases/proposals.php', share: '/bases/share.php', settings: '/bases/settings.php' })
    .filter(([, f]) => built(f)).map(([k]) => 'base-tab-' + k);
  ok(JSON.stringify(tabs) === JSON.stringify(expected), 'the tabs are exactly the built slices\' (read from disk): ' + tabs.join(', '));
  o = await overflow(page); ok(o.sw === o.iw, `no sideways scroll on the base (${o.sw} = ${o.iw})`);
  s = await small(page); ok(s.length === 0, 'every control of the base is at least 44 px ' + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${tag}-base-view.png`, fullPage: true });
  // share with a person through the record picker
  await page.click('#base-tab-share'); await page.waitForSelector('#base-members-table');
  await pickRecord(page, 'share-form-member-field', 'Priya', 26);
  await page.selectOption('#share-form-member-level', 'curate');
  await page.click('#share-form-member-btn'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(300);
  ok(sql(`SELECT level FROM base_members WHERE base_id = ${id} AND principal_id = 26`) === 'curate', 'Priya shared at curate through the record picker');
  await page.waitForSelector('#base-members-table');
  ok((await page.locator('#base-members-table tbody tr').count()) >= 2, 'the table lists her beside the owner');
  const scroll = await page.evaluate(() => { const w = document.getElementById('base-members-scroll'); const cs = getComputedStyle(w); return { ox: cs.overflowX, sw: w.scrollWidth, cw: w.clientWidth }; });
  ok(['auto', 'scroll'].includes(scroll.ox), `the share table scrolls inside its card (overflow-x ${scroll.ox}, ${scroll.sw} in ${scroll.cw})`);
  o = await overflow(page); ok(o.sw === o.iw, `no sideways scroll on the share screen (${o.sw} = ${o.iw})`);
  s = await small(page); ok(s.length === 0, 'every control of the share screen is at least 44 px ' + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${tag}-base-share.png`, fullPage: true });
  // a refusal lands in #flash: an application asked to curate is refused by the database, in words
  await page.fill('#share-form-application-field', 'Not a key!');
  await page.evaluate(() => document.getElementById('share-form-application-field').removeAttribute('pattern'));
  await page.click('#share-form-application-btn'); await page.waitForTimeout(500);
  ok(/catalog key/i.test(await page.locator('#flash').innerText()), 'a refused share shows its sentence in #flash: ' + (await page.locator('#flash').innerText()).trim());
  // settings
  await page.goto(BASE + `/bases/${id}/settings`, { waitUntil: 'networkidle' });
  await page.click('#base-form-field-sensitivity-confidential');
  await page.click('#base-settings-save-btn'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(300);
  ok(sql(`SELECT sensitivity FROM knowledge_bases WHERE id = ${id}`) === 'confidential', 'the settings save');
  o = await overflow(page); ok(o.sw === o.iw, `no sideways scroll on the settings (${o.sw} = ${o.iw})`);
  s = await small(page); ok(s.length === 0, 'every control of the settings is at least 44 px ' + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${tag}-base-settings.png`, fullPage: true });
  // the list
  await page.goto(BASE + '/bases', { waitUntil: 'networkidle' });
  ok(await page.locator(`#base-card-${id}`).isVisible(), 'the list shows her base as a card');
  o = await overflow(page); ok(o.sw === o.iw, `no sideways scroll on the list (${o.sw} = ${o.iw})`);
  s = await small(page); ok(s.length === 0, 'every control of the list is at least 44 px ' + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${tag}-base-list.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

console.log('1280 x 800 — the admin\'s settings and templates');
{
  const { ctx, page, errors } = await session(2, DESK);
  for (const [path, shot] of [['/admin/settings', 'admin-settings'], ['/admin/templates', 'templates']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const o = await overflow(page); ok(o.sw === o.iw, `${path}: no sideways scroll`);
    const s = await small(page); ok(s.length === 0, `${path}: every control at least 44 px ` + s.join(', '));
    await page.screenshot({ path: `${SHOTS}/desk-${shot}.png`, fullPage: true });
  }
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  await page.fill('#settings-field-section_max_chars', '1500');   // within the field's own bounds, below the target: the database's pair CHECK refuses it
  await page.click('#admin-settings-settings-save'); await page.waitForTimeout(500);
  ok(/at least the target|from 1000/i.test(await page.locator('#flash').innerText()), 'a setting outside its CHECK is refused in #flash: ' + (await page.locator('#flash').innerText()).trim());
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

console.log('375 x 740 — JavaScript off');
{
  const { ctx, page, errors } = await session(28, PHONE, { javaScriptEnabled: false });
  await page.goto(BASE + '/bases/new');
  await page.fill('#base-form-field-name', 'SMOKE No Script');
  await page.click('#base-form-save-btn'); await page.waitForLoadState('load');
  const m = page.url().match(/\/bases\/(\d+)\?notice=created$/);
  ok(m !== null && (await page.locator('#base-add-documents').count()) === 1, 'creates a base (a plain POST, a redirect to the base): ' + page.url());
  const id = m ? Number(m[1]) : 0;
  await page.goto(BASE + `/bases/${id}/share`);
  await page.selectOption('#share-form-department-field', '7');
  await page.click('#share-form-department-btn'); await page.waitForLoadState('load');
  ok(sql(`SELECT level FROM base_members WHERE base_id = ${id} AND principal_kind = 'department' AND principal_id = 7`) === 'read' && /notice=shared/.test(page.url()), 'shares with a department');
  await page.goto(BASE + `/bases/${id}/settings`);
  await page.fill('#base-form-field-language', 'fr');
  await page.click('#base-settings-save-btn'); await page.waitForLoadState('load');
  ok(sql(`SELECT language FROM knowledge_bases WHERE id = ${id}`) === 'fr' && /notice=settings/.test(page.url()), 'saves the settings');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
