// Headless Chromium proof of slice 9 (docs/build-specs/home-admin.md "Proof", the last bullet): at 375 x 740 and 1280 x 800 — the home's regions in the phone order with the first-run card alone,
// the builds region polling until the build ends, the admin cards; the admin index and its lists (tables at 1280, cards at 375), the model page (Test output wraps, Save through its confirm), purge,
// the agents page, Revoke from the key list, the person's settings, notifications and tokens; every control >= 44 px, no sideways scroll, no console errors. Run by tests/phase3/slice9/run.sh last.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8501';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/kn-shots-s9';
const KEY = process.env.ACTION_TOKEN_KEY;
fs.mkdirSync(SHOTS, { recursive: true });
const fixture = JSON.parse(fs.readFileSync(ROOT + 'bin/dev_directory.json', 'utf8'));
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
async function session(member, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const small = (page, scope = '#page-content') => page.evaluate((s) => [...document.querySelectorAll(`${s} a.btn, ${s} button, ${s} input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ${s} select, ${s} summary, ${s} .nav-link`)]
  .filter((a) => { const r = a.getBoundingClientRect(); const cs = getComputedStyle(a); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && (r.height < 44 || r.width < 44); })
  .map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);
const php = (code) => execFileSync('php', ['-r', `require 'tests/phase3/slice9/lib.php'; ${code}`], { cwd: ROOT, env: process.env }).toString().trim();
const seed = () => JSON.parse(php('echo json_encode(browser_world9());'));
const check = async (page, what, shot) => {
  const o = await overflow(page); ok(o.sw === o.iw, `${what}: no sideways scroll (${o.sw} = ${o.iw})`);
  const s = await small(page); ok(s.length === 0, `${what}: every control at least 44 px ` + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${shot}.png`, fullPage: true });
};
const visible = (page, sel) => page.locator(sel).first().isVisible();
const top = async (page, sel) => (await page.locator(sel).first().boundingBox()).y;

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  const phone = vp.width < 600;
  const w = seed();
  console.log(`${label} — the home`);

  // first run: nothing readable
  php(`foreach ([${w.handbook}, ${w.policies}] as $b) { act(as_member(CARA), '/bases/visibility.php', ['base' => $b, 'visibility' => 'private']); }`);
  let { ctx, page, errors } = await session(26, vp);
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  ok(await visible(page, '#home-first-run') && await visible(page, '#home-create-base-btn') && (await page.locator('#home-bases, #home-recent, #home-pins, #home-note').count()) === 0, 'first run: the card alone, with its one button');
  ok((await page.locator('#home-steps').innerText()).includes('Create a base · Add documents · Ask'), '…and the three steps in muted text');
  await check(page, 'first run', `${tag}-home-first-run`);
  await ctx.close();
  php(`foreach ([${w.handbook}, ${w.policies}] as $b) { act(as_member(CARA), '/bases/visibility.php', ['base' => $b, 'visibility' => 'internal']); }`);

  // Priya: the phone order
  ({ ctx, page, errors } = await session(26, vp));
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  const ys = [await top(page, '#home-bases'), await top(page, '#home-recent'), await top(page, '#home-pins'), await top(page, '#home-note')];
  if (phone) { ok(ys[0] < ys[1] && ys[1] < ys[2] && ys[2] < ys[3], `Priya, on a phone the regions stack in order: My bases · recent questions · pins · the note (${ys.map(Math.round).join(' < ')})`); }
  else {
    const bx = await page.locator('#home-bases').boundingBox(), rq = await page.locator('#home-recent').boundingBox(), nt = await page.locator('#home-note').boundingBox();
    ok(bx.width > 800 && rq.x < nt.x && Math.abs(rq.y - nt.y) < 30, `at 1280 My bases is full width and the rest sit in two columns (bases ${Math.round(bx.width)} px; recent x ${Math.round(rq.x)}, note x ${Math.round(nt.x)})`);
  }
  ok((await page.locator('#home-recent [id^="home-question-"]:not([id$="-link"])').count()) === 5 && (await page.locator('#home-builds, #home-gaps, #home-admin').count()) === 0, 'five recent questions; no curator or admin region');
  await check(page, 'Priya\'s home', `${tag}-home-priya`);
  await ctx.close();

  // Cara: builds polling
  ({ ctx, page, errors } = await session(27, vp));
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  ok(await visible(page, `#home-build-${w.build}`) && (await page.locator(`#home-build-${w.build}-time`).innerText()).includes('33 %'), 'Cara: Builds running shows the build at 33 %');
  ok(await visible(page, '#home-pins [id^="home-pin-"]') && await visible(page, '#home-note-link') && await visible(page, '#home-gaps [id^="home-gap-"]'), '…a pin due, the Archivist\'s note and the week\'s gaps');
  const empt = await page.locator(`#home-base-${w.empty}-add`).count();
  ok(empt === 1, '…and "Add documents" on her empty base');
  await check(page, 'Cara\'s home', `${tag}-home-cara`);
  sql(`UPDATE build_items SET status = 'done' WHERE build_id = ${w.build} AND status = 'queued'`);
  await page.waitForFunction((id) => document.querySelector(`#home-build-${id}-time`)?.innerText.includes('100 %'), w.build, { timeout: 12000 });
  ok(true, 'the region polled and moved on to 100 % by itself');
  sql(`UPDATE builds SET status = 'done' WHERE id = ${w.build}`);
  await page.waitForSelector('#home-builds-none', { timeout: 12000 });
  ok((await page.locator('#home-builds[hx-trigger]').count()) === 0, 'when the build finished the region said so and stopped polling');
  await page.screenshot({ path: `${SHOTS}/${tag}-home-cara-done.png`, fullPage: true });
  await ctx.close();

  // the admin
  console.log(`${label} — the admin`);
  ({ ctx, page, errors } = await session(1, vp));
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  ok(await visible(page, '#home-usage') && await visible(page, '#home-failed-builds') && await visible(page, '#home-dispatches') && await visible(page, '#home-proposals'), 'the admin\'s home: usage, failed builds, dispatches, proposals');
  await check(page, 'the admin\'s home', `${tag}-home-admin`);
  await page.goto(`${BASE}/admin/`, { waitUntil: 'networkidle' });
  ok((await page.locator('#admin-pages [id^="admin-page-"][id$="-state"]').count()) === 11, 'the index: eleven cards, each with its state line');
  const wcard = (await page.locator('#admin-page-admin-model').boundingBox()).width;
  ok(phone ? wcard > 300 : wcard < 600, `the cards are ${phone ? 'one column on a phone' : 'a grid at 1280'} (${Math.round(wcard)} px)`);
  await check(page, 'the admin index', `${tag}-admin-index`);
  await page.click('#admin-page-admin-bases-link');
  await page.waitForURL(/\/admin\/bases/);
  ok(phone ? !(await visible(page, '#admin-bases-table')) && await visible(page, '#admin-bases-cards') : await visible(page, '#admin-bases-table'), `every base: ${phone ? 'cards' : 'a table'}`);
  ok((await page.locator('.feather-lock').count()) >= 2, 'the private ones carry the lock');
  await check(page, 'every base', `${tag}-admin-bases`);
  await page.goto(`${BASE}/admin/usage`, { waitUntil: 'networkidle' });
  ok(phone ? !(await visible(page, '#usage-table')) && await visible(page, '#usage-cards') : await visible(page, '#usage-table'), `usage: ${phone ? 'cards' : 'a table'}`);
  ok(await visible(page, '#usage-statement-link'), '…with the link to the OS statement');
  await check(page, 'usage', `${tag}-admin-usage`);
  await page.goto(`${BASE}/admin/keys`, { waitUntil: 'networkidle' });
  ok(phone ? !(await visible(page, '#admin-keys-table')) && await visible(page, '#admin-keys-cards') : await visible(page, '#admin-keys-table'), `keys: ${phone ? 'cards' : 'a table'}`);
  await check(page, 'every key', `${tag}-admin-keys`);
  const kid = w.keys[0];
  page.once('dialog', (d) => d.accept());
  await page.click(`#admin-key-${phone ? 'card' : 'row'}-${kid}-revoke`);
  await page.waitForSelector('#notice-banner');
  ok(page.url().includes('/admin/keys') && (await page.locator('#notice-banner').innerText()).includes('Revoked') && sql(`SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = ${kid}`) === 't', 'Revoke from the list (through its confirm) lands back on the list');
  await page.goto(`${BASE}/admin/dispatches`, { waitUntil: 'networkidle' });
  ok(phone ? await visible(page, '#dispatches-cards') : await visible(page, '#dispatches-table'), 'slice 8\'s dispatches are linked and as responsive');

  // the model
  await page.goto(`${BASE}/admin/model?base=${w.handbook}&section=${w.section}`, { waitUntil: 'networkidle' });
  ok(await visible(page, '#model-test-output') && (await page.locator('#model-test-note').innerText()).includes('M5'), 'the model page: the Test output and the M5 sentence');
  const pre = await page.evaluate(() => { const p = document.querySelector('#model-test-output'); const cs = getComputedStyle(p); return { ws: cs.whiteSpace, sw: p.scrollWidth, cw: p.clientWidth }; });
  ok(pre.ws === 'pre-wrap' && pre.sw <= pre.cw + 1, `the Test output wraps (${pre.ws}, ${pre.sw} <= ${pre.cw})`);
  await check(page, 'the model page', `${tag}-admin-model`);
  await page.fill('#model-field-model_alias', `browser-model-${tag}`);
  page.once('dialog', (d) => d.accept());
  await page.click('#model-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved the model') && sql('SELECT model_alias FROM kn_settings WHERE id = 1') === `browser-model-${tag}`, 'Save the model (through its confirm) lands with the notice, recorded');
  await page.fill('#model-field-embedding_model', `browser-embed-${tag}`);
  page.once('dialog', (d) => d.accept());
  await page.click('#model-save-btn');
  await page.waitForSelector('#flash .alert, #flash [role="alert"], #flash');
  ok((await page.locator('#flash').innerText()).includes('must be rebuilt') && sql('SELECT embedding_model FROM kn_settings WHERE id = 1') !== `browser-embed-${tag}`, 'a new embedding model without the box is refused in the flash, nothing recorded');

  // purge
  await page.goto(`${BASE}/admin/purge`, { waitUntil: 'networkidle' });
  ok(await visible(page, '#purge-confirm') && (await page.locator('#admin-purge-would').innerText()).includes('older than 365 days'), 'purge: the count first');
  await check(page, 'purge', `${tag}-admin-purge`);
  page.once('dialog', (d) => d.accept());
  await page.click('#admin-purge-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Purged') && sql('SELECT count(*) FROM questions WHERE created_at < now() - interval \'365 days\'') === '0', 'Purge (through its confirm) removes them and says how many');
  await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle' });
  ok(await visible(page, '#admin-settings-purge-link'), 'slice 1\'s Retention form carries the Purge now link');

  // agents
  await page.goto(`${BASE}/admin/agents`, { waitUntil: 'networkidle' });
  ok(await visible(page, '#agent-card-archivist') && (await page.locator('#agent-card-archivist-duty').innerText()).includes('Mondays at 06:45'), 'the agents page: the Archivist\'s duty, Mondays at 06:45');
  await check(page, 'the agents page', `${tag}-admin-agents`);
  await page.selectOption('#agents-field-expert', '90');
  await page.click('#agents-save-btn');
  await page.waitForSelector('#notice-banner');
  ok(sql('SELECT expert_member_id FROM kn_settings WHERE id = 1') === '90' && (await page.locator('#notice-banner').innerText()).includes('expert'), 'naming the expert lands with the notice, recorded');
  sql('UPDATE kn_settings SET expert_member_id = 40 WHERE id = 1');
  ok(errors.length === 0, `no console errors on the admin pages ${errors.join(' | ')}`);
  await ctx.close();

  // the person's screens
  console.log(`${label} — settings, notifications, tokens`);
  ({ ctx, page, errors } = await session(26, vp));
  await page.goto(`${BASE}/settings/`, { waitUntil: 'networkidle' });
  ok((await page.locator('#prefs-kinds input[type=checkbox]').count()) === 9 && (await page.locator('#prefs-text-kinds input[type=checkbox]').count()) === 9, 'settings: the nine kinds, emailed and texted');
  await check(page, 'settings', `${tag}-settings`);
  await page.uncheck('#prefs-field-kind-build_done');
  await page.click('#prefs-save-btn');
  await page.waitForSelector('#notice-banner, #flash .alert');
  ok(!sql(`SELECT kinds FROM notification_prefs WHERE member_id = 26`).includes('build_done'), 'a preference saves');
  await page.goto(`${BASE}/notifications`, { waitUntil: 'networkidle' });
  ok((await page.locator('[id^="notification-"][id$="-read-btn"]').count()) >= 2, 'notifications: unread ones with their read button');
  await check(page, 'notifications', `${tag}-notifications`);
  await page.click('#notifications-read-all-btn');
  await page.waitForFunction(() => document.querySelectorAll('[id^="notification-"][id$="-read-btn"]').length === 0);
  ok(sql('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === '0', 'Mark all read');
  await page.goto(`${BASE}/settings/tokens/`, { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', `browser ${tag}`);
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_') && (await page.locator('#tokens-url-records').innerText()).endsWith('/mcp/records') && (await page.locator('#tokens-url-activity').innerText()).endsWith('/mcp/activity'), 'tokens: minted once, with the two URLs');
  await check(page, 'tokens', `${tag}-tokens`);
  page.once('dialog', (d) => d.accept());
  await page.click('[id^="token-row-"][id$="-revoke-btn"]');
  await page.waitForSelector('#tokens-table .badge:has-text("revoked")');
  ok(true, 'revoke');
  ok(errors.length === 0, `no console errors ${errors.join(' | ')}`);
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
