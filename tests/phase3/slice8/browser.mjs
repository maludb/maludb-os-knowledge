// Headless Chromium proof of slice 8 (docs/build-specs/agents-feeds.md "Proof", the last bullet): at 375 x 740 and 1280 x 800 — the proposals with their accept forms (a merge's tick boxes,
// an area's fields, a build's estimate), Accept landing on the work it did, Dismiss with a reason; the feeds as a table at 1280 and cards at 375, the follow form, Sync now, Delete through
// its confirm; the dispatches table and Retry; Propose areas and Draft guidelines; a Member sees none of it; every control >= 44 px, no sideways scroll, no console errors.
// Run by tests/phase3/slice8/run.sh last.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8501';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/kn-shots-s8';
const KEY = process.env.ACTION_TOKEN_KEY;
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
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
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
const php = (code) => execFileSync('php', ['-r', `require 'tests/phase3/slice8/lib.php'; ${code}`], { cwd: ROOT, env: process.env }).toString().trim();
const seed = () => JSON.parse(php("kb_state(['projects_fake' => true, 'config' => ['model_identifier' => 'fake-model', 'base_url' => 'http://127.0.0.1:9', 'embedding_model' => 'fake-embed', 'secret_ref' => 'fake-secret', 'model_alias' => 'knowledge-extract']]); echo json_encode(browser_world());"));
const check = async (page, what, shot) => {
  const o = await overflow(page); ok(o.sw === o.iw, `${what}: no sideways scroll (${o.sw} = ${o.iw})`);
  const s = await small(page); ok(s.length === 0, `${what}: every control at least 44 px ` + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${shot}.png`, fullPage: true });
};

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  const w = seed();
  console.log(`${label} — Marco reads the Archivist's proposals`);
  const { ctx, page, errors } = await session(27, vp);
  const dialogs = [];
  page.on('dialog', (d) => { dialogs.push(d.message()); d.accept(); });
  await page.goto(`${BASE}/bases/${w.manuals}/proposals`, { waitUntil: 'networkidle' });
  ok(await page.locator('#base-tab-proposals[aria-current="page"]').count() === 1 && await page.locator('#proposals-tab-proposed[aria-current="true"]').count() === 1, 'the Proposals tab is lit, and the Proposed pill');
  ok(await page.locator(`#proposal-${w.merge}`).isVisible() && await page.locator('#merge-group-1').isChecked() && await page.locator('#merge-group-2').isChecked(), 'the merge card: both groups ticked');
  ok(await page.locator(`#proposal-accept-${w.area}-name`).inputValue() === 'Browser claims' && (await page.locator(`#proposal-accept-${w.area}-seed_terms`).inputValue()).includes('claim form'), 'the area card: its fields, editable');
  ok((await page.locator(`#proposal-accept-${w.stale}-estimate`).innerText()).includes('Would build'), 'the build card: the estimate — "' + (await page.locator(`#proposal-accept-${w.stale}-estimate`).innerText()).replace(/\s+/g, ' ').slice(0, 90) + '…"');
  ok((await page.locator(`#proposal-${w.gap}-payload`).innerText()).includes('2 questions') && await page.locator(`#proposal-${w.gap}-subject`).getAttribute('href') === '/questions/' + w.q_gap2, 'the gap card: the sample, the count, the subject as a link');
  await check(page, 'the proposals', `${tag}-proposals`);
  // dismiss with a reason
  await page.click(`#proposal-dismiss-${w.gap}-open`);
  await page.fill(`#proposal-dismiss-${w.gap}-note`, 'Not worth a source.');
  await check(page, 'a dismiss form open', `${tag}-dismiss`);
  await page.click(`#proposal-dismiss-${w.gap}-btn`);
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Dismissed') && await page.locator(`#proposal-${w.gap}`).count() === 0, 'Dismiss → the notice, the card leaves the Proposed pill');
  await page.click('#proposals-tab-dismissed');
  await page.waitForSelector(`#proposal-${w.gap}-note`);
  ok((await page.locator(`#proposal-${w.gap}-note`).innerText()).includes('Not worth a source.') && (await page.locator(`#proposal-${w.gap}-decision`).innerText()).includes('Dismissed by'), 'the Dismissed pill: who and why');
  await check(page, 'the dismissed', `${tag}-dismissed`);
  // accept the area, edited
  await page.goto(`${BASE}/bases/${w.manuals}/proposals`, { waitUntil: 'networkidle' });
  await page.fill(`#proposal-accept-${w.area}-name`, `Browser claims ${tag}`);
  await page.click(`#proposal-accept-${w.area}-btn`);
  await page.waitForSelector('#area-view-header, h5:has-text("Browser claims")');
  ok(page.url().includes('/areas/') && (await page.content()).includes(`Browser claims ${tag}`), 'Accept an area → it lands on the new area, as edited');
  // accept the merge with the second group unticked
  await page.goto(`${BASE}/bases/${w.manuals}/proposals`, { waitUntil: 'networkidle' });
  await page.uncheck('#merge-group-2');
  await page.click(`#proposal-accept-${w.merge}-btn`);
  await page.waitForURL(/vocabulary/);
  const aliases = sql(`SELECT aliases FROM vocabulary_terms WHERE base_id = ${w.manuals} AND canonical_name = 'Browser Pump'`);
  ok(aliases.includes('Browser Pumps') && sql(`SELECT count(*) FROM vocabulary_terms WHERE base_id = ${w.manuals} AND canonical_name = 'Browser Valve'`) === '0', 'Accept a merge with one group unticked → lands on the vocabulary; only the ticked group merged (' + aliases + ')');
  // accept a retry
  await page.goto(`${BASE}/bases/${w.manuals}/proposals`, { waitUntil: 'networkidle' });
  await page.click(`#proposal-accept-${w.failed_source}-btn`);
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Accepted') && sql(`SELECT status FROM sources WHERE id = ${w.failed}`) === 'uploaded', 'Accept a failed source → back in the queue');
  // accept the build
  await page.click(`#proposal-accept-${w.stale}-btn`);
  await page.waitForURL(/\/builds\/\d+/);
  ok(page.url().includes('/builds/') && sql(`SELECT count(*) FROM builds WHERE base_id = ${w.manuals} AND status = 'queued'`) === '1', 'Accept a build → lands on the queued build');
  sql(`UPDATE builds SET status = 'cancelled' WHERE base_id = ${w.manuals} AND status = 'queued'`);
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  const w = seed();
  console.log(`${label} — Marco follows a feed`);
  const { ctx, page, errors } = await session(27, vp);
  const dialogs = [];
  page.on('dialog', (d) => { dialogs.push(d.message()); d.accept(); });
  await page.goto(`${BASE}/bases/${w.manuals}/feeds`, { waitUntil: 'networkidle' });
  ok(await page.locator('#base-tab-feeds[aria-current="page"]').count() === 1, 'the Feeds tab is lit');
  if (tag === 'desk') { ok(await page.locator('#feeds-table').isVisible() && !(await page.locator('#feeds-cards').isVisible()), 'the feeds as a table at 1280'); }
  else { ok(await page.locator('#feeds-cards').isVisible() && !(await page.locator('#feeds-table').isVisible()), 'the feeds as cards at 375'); }
  const sel = tag === 'desk' ? `#feed-${w.feed}` : `#feed-card-${w.feed}`;
  ok((await page.locator(sel).innerText()).includes('no connection') && (await page.locator(sel).innerText()).includes('a super-admin approves it in the operating system') && (await page.locator(sel).innerText()).includes('Space: product'), 'the feed: "no connection" with the kernel\'s sentence, the arguments in words');
  await check(page, 'the feeds', `${tag}-feeds`);
  await page.click('#feeds-add-btn');
  await page.waitForSelector('#feed-form');
  ok(await page.locator('#feed-form-field-space').isVisible() && !(await page.locator('#feed-form-field-category').isVisible()), 'the follow form: the Spaces tool asks for the space');
  await page.selectOption('#feed-form-field-source_tool', 'helpdesk.articles_published');
  ok(!(await page.locator('#feed-form-field-space').isVisible()) && await page.locator('#feed-form-field-category').isVisible(), 'choosing Help Desk swaps the argument for the category');
  await page.selectOption('#feed-form-field-source_tool', 'spaces.pages_index');
  await check(page, 'the follow form', `${tag}-feed-form`);
  await page.fill('#feed-form-field-space', `browser-${tag}`);
  await page.click('#feed-form-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved the feed') && (await page.content()).includes(`Space: browser-${tag}`), 'Follow → the list with the new feed');
  const nid = sql(`SELECT id FROM feeds WHERE base_id = ${w.manuals} AND arguments->>'space' = 'browser-${tag}'`);
  await page.click(`#${tag === 'desk' ? 'feed-' : 'feed-card-'}${nid}-sync`);
  await page.waitForSelector('#notice-banner:has-text("Sync requested")');
  ok(true, 'Sync now → "Sync requested"');
  await page.click(`#${tag === 'desk' ? 'feed-' : 'feed-card-'}${nid}-edit`);
  await page.waitForSelector('#feed-form');
  ok((await page.inputValue('#feed-form-field-space')) === `browser-${tag}` && await page.locator('input[name=feed]').count() === 1, 'Edit → the form with the feed\'s values');
  await check(page, 'the edit form', `${tag}-feed-edit`);
  await page.click('#feed-form-cancel');
  await page.waitForSelector('#feeds-content');
  await page.click(`#${tag === 'desk' ? 'feed-' : 'feed-card-'}${nid}-delete`);
  await page.waitForSelector('#notice-banner:has-text("Stopped following")');
  ok(dialogs.some((d) => d.includes('Stop following this feed')) && sql(`SELECT count(*) FROM feeds WHERE id = ${nid}`) === '0', 'Delete (confirmed) → the feed is gone');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  const w = seed();
  console.log(`${label} — the admin's dispatches and proposals, and asking the expert`);
  const { ctx, page, errors } = await session(1, vp);
  await page.goto(`${BASE}/admin/dispatches`, { waitUntil: 'networkidle' });
  if (tag === 'desk') { ok(await page.locator('#dispatches-table').isVisible() && !(await page.locator('#dispatches-cards').isVisible()), 'the dispatches as a table at 1280'); }
  else { ok(await page.locator('#dispatches-cards').isVisible() && !(await page.locator('#dispatches-table').isVisible()), 'the dispatches as cards at 375'); }
  const row = tag === 'desk' ? `#dispatch-row-${w.dispatch_failed}` : `#dispatch-card-${w.dispatch_failed}`;
  ok((await page.locator(row).innerText()).includes('failed') && (await page.locator(row).innerText()).includes('The kernel is not reachable right now.'), 'the failed dispatch: its status and its detail');
  await check(page, 'the dispatches', `${tag}-dispatches`);
  await page.click(`#${tag === 'desk' ? 'dispatch-row-' : 'dispatch-card-'}${w.dispatch_failed}-retry`);
  await page.waitForSelector('#notice-banner:has-text("Back in the queue")');
  ok(sql(`SELECT status FROM agent_dispatches WHERE id = ${w.dispatch_failed}`) === 'sent', 'Retry → back in the queue');
  sql(`DELETE FROM agent_dispatches WHERE id = ${w.dispatch_failed}`);       // (the retried one would answer "already on it" below)
  await page.goto(`${BASE}/admin/dispatches?status=refused`, { waitUntil: 'networkidle' });
  ok((await page.content()).includes('This person may not use the expert.') && !(await page.content()).includes('The kernel is not reachable'), 'the status filter');
  await page.goto(`${BASE}/admin/proposals`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#proposal-${w.merge}`).isVisible() && await page.locator(`#merge-group-${w.merge}-1`).count() === 1, 'the admin\'s proposals: every base, the merge\'s tick boxes carry the proposal');
  await check(page, 'the admin\'s proposals', `${tag}-admin-proposals`);
  await page.click('#proposals-filter-base-open');
  await page.waitForSelector('#record-picker-list');
  ok((await page.locator('#record-picker-list').innerText()).includes('SMOKE Manuals'), 'the base filter is the record picker');
  await page.click(`#record-picker-row-${w.manuals}`);
  await page.click('#proposals-filter-go');
  await page.waitForURL(/base=/);
  ok(await page.locator(`#proposal-${w.merge}`).isVisible(), 'filtered to one base');
  await page.goto(`${BASE}/bases/${w.manuals}/areas`, { waitUntil: 'networkidle' });
  await check(page, 'the areas with Propose areas', `${tag}-areas`);
  await page.click('#areas-propose-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Knowledge Expert has been asked') && sql(`SELECT count(*) FROM agent_dispatches WHERE base_id = ${w.manuals} AND kind = 'propose_areas' AND status = 'sent' AND run_id IS NULL`) === '1', 'Propose areas → "The Knowledge Expert has been asked", one dispatch queued');
  await page.goto(`${BASE}/bases/${w.manuals}/guidelines`, { waitUntil: 'networkidle' });
  await page.click('#guidelines-draft-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('asked') && sql(`SELECT count(*) FROM agent_dispatches WHERE base_id = ${w.manuals} AND kind = 'draft_guidelines' AND status = 'sent' AND run_id IS NULL`) === '1', 'Draft guidelines from a sample → asked, queued');
  await page.click('#guidelines-draft-btn');
  await page.waitForSelector('#notice-banner:has-text("already on it")');
  ok(true, 'a second press: "The expert is already on it."');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

console.log('375 x 740 — Priya (a Member) sees none of it');
{
  const w = seed();
  const { ctx, page, errors } = await session(26, PHONE);
  await page.goto(`${BASE}/bases/${w.manuals}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#base-tab-proposals').count() === 0 && await page.locator('#base-tab-feeds').count() === 0, 'no Proposals and no Feeds tab');
  let r = await page.goto(`${BASE}/bases/${w.manuals}/proposals`);
  ok(r.status() === 403 && (await page.content()).includes('You may not change this knowledge base'), 'the proposals page → 403 in words');
  r = await page.goto(`${BASE}/bases/${w.manuals}/feeds`);
  ok(r.status() === 403, 'the feeds page → 403');
  r = await page.goto(`${BASE}/admin/dispatches`);
  ok(r.status() === 403, 'the dispatches → 403');
  await page.goto(`${BASE}/bases/${w.manuals}/areas`, { waitUntil: 'networkidle' });
  ok(await page.locator('#areas-propose-btn').count() === 0, 'no Propose areas button');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

console.log('375 x 740 — JavaScript off: Dismiss and Accept are plain forms');
{
  const w = seed();
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  await page.goto(`${BASE}/bases/${w.manuals}/proposals`, { waitUntil: 'load' });
  await page.click(`#proposal-dismiss-${w.gap}-open`);
  await page.fill(`#proposal-dismiss-${w.gap}-note`, 'No JavaScript here.');
  await Promise.all([page.waitForNavigation(), page.click(`#proposal-dismiss-${w.gap}-btn`)]);
  ok(page.url().includes('/proposals') && (await page.content()).includes('Dismissed') && sql(`SELECT status FROM archivist_proposals WHERE id = ${w.gap}`) === 'dismissed', 'Dismiss posts a form and comes back with the notice');
  await Promise.all([page.waitForNavigation(), page.click(`#proposal-accept-${w.failed_source}-btn`)]);
  ok((await page.content()).includes('Accepted'), 'Accept posts a form and comes back with the notice');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
