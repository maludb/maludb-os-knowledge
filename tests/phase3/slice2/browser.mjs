// Headless Chromium proof of slice 2 (docs/build-specs/sources-sections.md "Proof", the last bullet): at 375 x 740 and 1280 x 800 — the drop zone
// takes three files and shows three result lines (one POST each); the source cards stack on a phone; the polling stops when everything is ready;
// section-view at 375 reads with no sideways scroll, the quote marked and visible, previous / next at least 44 px; the outline indents within the
// viewport at depth 5; every control >= 44 px; no console errors; without JavaScript the multi-file form posts and shows its lines.
// Run through tests/phase3/slice2/run.sh (servers up, the world of the earlier proofs in place).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8501';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/kn-shots-s2';
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
const worker = () => execFileSync('php', ['bin/worker.php'], { cwd: ROOT, env: process.env }).toString().trim();
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
const small = (page, scope = '#page-content') => page.evaluate((s) => [...document.querySelectorAll(`${s} a.btn, ${s} button, ${s} input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ${s} select, ${s} summary, ${s} .nav-link, ${s} .kn-outline-link`)]
  .filter((a) => { const r = a.getBoundingClientRect(); const cs = getComputedStyle(a); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && (r.height < 44 || r.width < 44); })
  .map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'kn-s2-browser-'));
const files = ['Alpha notes.md', 'Bravo list.txt', 'Charlie rows.csv'].map((n, i) => {
  const p = path.join(dir, n);
  fs.writeFileSync(p, i === 2 ? 'name,qty\nladder,2\nglove,9\n' : `# ${n}\n\nThe browser proof wrote these words, number ${i}.\n`);
  return p;
});
const baseId = Number(sql("SELECT id FROM knowledge_bases WHERE key = 'smoke-field-manuals'"));
// a source five headings deep, for the outline
const deep = '# One\n\nWords one.\n\n## Two\n\nWords two.\n\n### Three\n\nWords three.\n\n#### Four\n\nWords four.\n\n##### Five is the deepest heading in this pasted text\n\nWords five.\n';

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — Priya adds documents`);
  const { ctx, page, errors } = await session(26, vp);
  await page.goto(`${BASE}/sources/new?base=${baseId}`, { waitUntil: 'networkidle' });
  let o = await overflow(page); ok(o.sw === o.iw, `no sideways scroll on Add documents (${o.sw} = ${o.iw})`);
  let s = await small(page); ok(s.length === 0, 'every control of Add documents is at least 44 px ' + s.join(', '));
  const renamed = files.map((f) => { const p = f.replace(/(\.\w+)$/, ` ${tag}$1`); fs.writeFileSync(p, fs.readFileSync(f, 'utf8') + `\n${tag}\n`); return p; });   // different bytes per viewport
  const posts = [];
  page.on('request', (r) => { if (r.url().endsWith('/sources/add.php') && r.method() === 'POST') posts.push(r.url()); });
  await page.setInputFiles('#source-form-field-files', renamed);
  await page.waitForFunction(() => document.querySelectorAll('#upload-results > div').length === 3 && ![...document.querySelectorAll('#upload-results .kn-upload-state')].length, null, { timeout: 15000 });
  const lines = await page.locator('#upload-results > div').allInnerTexts();
  ok(posts.length === 3 && lines.length === 3 && lines.every((l) => / — added/.test(l)), `three files, three POSTs, three result lines: ${lines.map((l) => l.trim()).join(' | ')}`);
  await page.screenshot({ path: `${SHOTS}/${tag}-source-add.png`, fullPage: true });
  // the list polls while they are read, and stops when they are ready
  const polls = [];
  page.on('response', (r) => { if (r.url().includes('/sources-status')) polls.push(r.status()); });
  await page.goto(`${BASE}/bases/${baseId}/sources`, { waitUntil: 'networkidle' });
  ok(await page.locator('#sources-pending').isVisible(), 'the list says the new sources are being read');
  await page.waitForResponse((r) => r.url().includes('/sources-status'), { timeout: 8000 });
  worker();
  await page.waitForResponse((r) => r.url().includes('/sources-status') && r.status() === 286, { timeout: 8000 });
  const n = polls.length;
  await page.waitForTimeout(6500);
  ok(polls.length === n && (await page.locator('#sources-pending').count()) === 0, `the polling stops when everything is ready (${polls.join(', ')})`);
  const cards = await page.locator('#sources-list > div').evaluateAll((els) => els.slice(0, 3).map((e) => { const r = e.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y) }; }));
  if (vp.width < 600) ok(cards.length >= 2 && cards[0].x === cards[1].x && cards[1].y > cards[0].y, 'the source cards stack ' + JSON.stringify(cards));
  else ok(cards.length >= 2 && cards[1].x > cards[0].x, 'the source cards sit side by side');
  o = await overflow(page); ok(o.sw === o.iw, `no sideways scroll on the sources (${o.sw} = ${o.iw})`);
  s = await small(page); ok(s.length === 0, 'every control of the sources is at least 44 px ' + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${tag}-sources.png`, fullPage: true });
  // the outline at depth 5
  const res = await page.evaluate(async ([b, text, t]) => {
    const fd = new URLSearchParams({ base: b, kind: 'text', title: 'SMOKE Deep ' + t, text, csrf_token: document.querySelector('meta[name="csrf-token"]').content });
    const r = await fetch('/sources/add.php', { method: 'POST', headers: { Accept: 'application/json' }, body: fd });
    return r.json();
  }, [String(baseId), deep, tag]);
  worker();
  await page.goto(`${BASE}/sources/${res.record_id}`, { waitUntil: 'networkidle' });
  const depth = await page.evaluate(() => { let d = 0; let el = document.querySelector('#source-outline'); while (el && el.querySelector(':scope > li > ul')) { el = el.querySelector(':scope > li > ul'); d++; } return d + 1; });
  const deepest = await page.locator('#source-outline li li li li li .kn-outline-link').first().boundingBox();
  ok(depth === 5 && deepest && deepest.x + deepest.width <= vp.width, `the outline indents five deep within the viewport (depth ${depth}, right edge ${deepest ? Math.round(deepest.x + deepest.width) : '?'})`);
  o = await overflow(page); ok(o.sw === o.iw, `no sideways scroll on the source (${o.sw} = ${o.iw})`);
  s = await small(page); ok(s.length === 0, 'every control of the source is at least 44 px ' + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${tag}-source-view.png`, fullPage: true });
  // a section, a quote marked
  const sec = sql(`SELECT x.id || '|' || x.text FROM sections x WHERE x.base_id = ${baseId} AND x.status <> 'forgotten' AND length(x.text) > 200
                    AND (SELECT count(*) FROM sections y WHERE y.source_id = x.source_id AND y.status <> 'forgotten') >= 2 ORDER BY x.id LIMIT 1`);
  const [secId, secText] = [sec.split('|')[0], sec.slice(sec.indexOf('|') + 1)];
  const quote = secText.split(/\s+/).slice(20, 27).join(' ');
  await page.goto(`${BASE}/sections/${secId}?quote=${encodeURIComponent(quote)}`, { waitUntil: 'networkidle' });
  const mark = await page.locator('mark#quote').boundingBox();
  ok(mark && mark.y >= 0 && mark.y + mark.height <= vp.height, `the quote is marked and scrolled into view (y ${mark ? Math.round(mark.y) : '?'})`);
  o = await overflow(page); ok(o.sw === o.iw, `section-view reads with no sideways scroll (${o.sw} = ${o.iw})`);
  s = await small(page); ok(s.length === 0, 'every control of the section is at least 44 px (previous / next included) ' + s.join(', '));
  ok((await page.locator('#section-next').count()) + (await page.locator('#section-prev').count()) >= 1, 'previous or next is there');
  await page.screenshot({ path: `${SHOTS}/${tag}-section-view.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

console.log('375 x 740 — JavaScript off');
{
  const { ctx, page, errors } = await session(26, PHONE, { javaScriptEnabled: false });
  await page.goto(`${BASE}/sources/new?base=${baseId}`);
  const two = files.slice(0, 2).map((f) => { const p = f.replace(/(\.\w+)$/, ' nojs$1'); fs.writeFileSync(p, fs.readFileSync(f, 'utf8') + '\nno script\n'); return p; });
  await page.setInputFiles('#source-form-field-files', two);
  await page.click('#source-files-btn'); await page.waitForLoadState('load');
  const lines = await page.locator('#upload-results > div').allInnerTexts();
  ok(/done=1/.test(page.url()) && lines.length === 2 && lines.every((l) => / — added/.test(l)), `the multi-file form posts once and shows its lines: ${lines.map((l) => l.trim()).join(' | ')}`);
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}
await browser.close();
fs.rmSync(dir, { recursive: true, force: true });
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
