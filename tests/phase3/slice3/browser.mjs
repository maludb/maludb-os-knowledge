// Headless Chromium proof of slice 3 (docs/build-specs/guidelines-areas.md "Proof", the last bullet): at 375 x 740 and 1280 x 800 — the kind groups
// stack; the examples sit beside the form at 1280 and below it at 375; Preview fills #preview without a reload and a long prompt never widens the
// viewport (the pre wraps); the vocabulary table scrolls within the page; area cards with chips; every control >= 44 px; no console errors; with
// JavaScript off a guideline saves and Preview answers a full page. Run through tests/phase3/slice3/run.sh after the PHP proofs (the world exists).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8501';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/kn-shots-s3';
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
const baseId = Number(sql("SELECT id FROM knowledge_bases WHERE key = 'smoke-field-manuals'"));
const check = async (page, what, shot) => {
  const o = await overflow(page); ok(o.sw === o.iw, `${what}: no sideways scroll (${o.sw} = ${o.iw})`);
  const s = await small(page); ok(s.length === 0, `${what}: every control at least 44 px ` + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${shot}.png`, fullPage: true });
};

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — Priya curates`);
  const { ctx, page, errors } = await session(26, vp);
  await page.goto(`${BASE}/bases/${baseId}/guidelines`, { waitUntil: 'networkidle' });
  const groups = await page.locator('section[id^="guidelines-kind-"]').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().x)));
  ok(groups.length === 5 && groups.every((x) => x === groups[0]), 'the five kind groups stack ' + JSON.stringify(groups));
  await check(page, 'the guidelines', `${tag}-guidelines`);
  await page.goto(`${BASE}/guidelines/new?base=${baseId}&kind=extraction`, { waitUntil: 'networkidle' });
  const form = await page.locator('#guideline-form').boundingBox();
  const ex = await page.locator('#guideline-examples').boundingBox();
  if (vp.width < 600) ok(ex.y > form.y + form.height - 1, 'the examples sit below the form at 375');
  else ok(ex.x > form.x + form.width - 1 && Math.abs(ex.y - form.y) < 120, 'the examples sit beside the form at 1280');
  await page.fill('#guideline-form-field-body', 'Extract every BROWSER-DRAFT widget. ' + 'Averyveryverylongwordthatmustwrapinsidethepreviewandneverwidentheviewport'.repeat(4));
  const url = page.url();
  await page.click('#preview-btn');
  await page.waitForSelector('#preview #preview-prompt');
  ok(page.url() === url && (await page.locator('#preview-prompt').innerText()).includes('EXTRACT: Extract every BROWSER-DRAFT widget.'), 'Preview fills #preview without a reload, the draft in the prompt');
  await check(page, 'the guideline form with a long preview', `${tag}-guideline-preview`);
  await page.selectOption('#guideline-form-field-kind', 'answering');
  await page.fill('#guideline-form-field-body', `Answer in the browser's words (${tag}).`);
  await page.click('#guideline-form-save-btn');
  await page.waitForURL(/\/bases\/\d+\/guidelines/);
  await page.waitForLoadState('networkidle');
  ok(Number(sql(`SELECT count(*) FROM guidelines WHERE base_id = ${baseId} AND body = 'Answer in the browser''s words (${tag}).'`)) === 1 && (await page.locator('#notice-banner').innerText()).includes('applies to the next question'), 'an answering guideline saves and says it applies to the next question');
  await page.goto(`${BASE}/bases/${baseId}/vocabulary`, { waitUntil: 'networkidle' });
  const sc = await page.evaluate(() => { const w = document.getElementById('vocabulary-scroll'); return getComputedStyle(w).overflowX; });
  ok(['auto', 'scroll'].includes(sc), `the vocabulary table scrolls within the page (overflow-x ${sc})`);
  await check(page, 'the vocabulary', `${tag}-vocabulary`);
  await page.goto(`${BASE}/bases/${baseId}/areas`, { waitUntil: 'networkidle' });
  ok((await page.locator('[id^="area-card-"][id$="-linked"]').count()) >= 1, 'area cards carry their chips (linked)');
  await check(page, 'the areas', `${tag}-areas`);
  const areaId = sql(`SELECT id FROM focus_areas WHERE base_id = ${baseId} AND status = 'active' ORDER BY id LIMIT 1`);
  await page.goto(`${BASE}/areas/${areaId}`, { waitUntil: 'networkidle' });
  await check(page, 'an area', `${tag}-area-view`);
  await page.goto(`${BASE}/bases/${baseId}/compiled`, { waitUntil: 'networkidle' });
  await check(page, 'what the engine is told', `${tag}-compiled`);
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

console.log('375 x 740 — JavaScript off');
{
  const { ctx, page, errors } = await session(26, PHONE, { javaScriptEnabled: false });
  await page.goto(`${BASE}/guidelines/new?base=${baseId}&kind=audience`);
  await page.fill('#guideline-form-field-body', 'Readers without JavaScript.');
  await page.click('#preview-btn'); await page.waitForLoadState('load');
  ok(/\/guidelines\/preview\.php$/.test(page.url()) && (await page.locator('#preview-system').count()) === 1 && (await page.inputValue('#guideline-form-field-body')) === 'Readers without JavaScript.', 'Preview answers a full page, the form kept');
  await page.click('#guideline-form-save-btn'); await page.waitForLoadState('load');
  ok(Number(sql(`SELECT count(*) FROM guidelines WHERE base_id = ${baseId} AND body = 'Readers without JavaScript.'`)) === 1 && /\/bases\/\d+\/guidelines/.test(page.url()), 'and the guideline saves');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
