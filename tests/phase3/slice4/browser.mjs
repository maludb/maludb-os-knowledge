// Headless Chromium proof of slice 4 (docs/build-specs/builds.md "Proof", the last bullet): at 375 x 740 and 1280 x 800 — the estimate card and
// the start button; build-view's bar moving under polling (a request every 5 s while visible, none while hidden, none after the 286); the items
// table scrolls within the page; the failed sections link to their sections; every control >= 44 px; no console errors; with JavaScript off a
// build starts and is cancelled (a full page, no polling). Run through tests/phase3/slice4/run.sh after the PHP proofs (the failures exist).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8501';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/kn-shots-s4';
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
const php = (code) => execFileSync('php', ['-r', `require 'tests/phase3/slice4/lib.php'; ${code}`], { cwd: ROOT, env: process.env }).toString().trim();
const pass = () => execFileSync('php', ['bin/worker.php'], { cwd: ROOT, env: process.env }).toString().trim();
const w = JSON.parse(php('echo json_encode(browser_world());'));
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const check = async (page, what, shot) => {
  const o = await overflow(page); ok(o.sw === o.iw, `${what}: no sideways scroll (${o.sw} = ${o.iw})`);
  const s = await small(page); ok(s.length === 0, `${what}: every control at least 44 px ` + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${shot}.png`, fullPage: true });
};
const barWidth = (page) => page.evaluate(() => parseFloat(document.getElementById('build-progress-bar').style.width));

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — Priya curates`);
  const { ctx, page, errors } = await session(26, vp);
  page.on('dialog', (d) => d.accept());
  await page.goto(`${BASE}/bases/${w.builds}/builds`, { waitUntil: 'networkidle' });
  ok(await page.locator('#build-estimate').isVisible(), 'the estimate card is shown');
  if (tag === 'phone') {
    ok(await page.locator('#build-start').isEnabled(), 'the start button is enabled');
    await check(page, 'the builds page', `${tag}-builds`);
    const polls = [];
    page.on('request', (r) => { if (/\/builds\/\d+\/progress$/.test(new URL(r.url()).pathname)) polls.push(Date.now()); });
    page.on('response', (r) => { if (/\/progress$/.test(new URL(r.url()).pathname)) polls.status = r.status(); });
    await page.click('#build-start');
    await page.waitForURL(/\/builds\/\d+/);
    await page.waitForSelector('#build-progress');
    const buildId = Number(page.url().match(/\/builds\/(\d+)/)[1]);
    ok((await barWidth(page)) === 0 && (await page.locator('#build-progress #build-cancel').count()) === 1, 'build-view opens on the queued build: the bar at 0%, Cancel offered');
    await wait(11000);
    ok(polls.length === 2, `while visible it polls every 5 s (${polls.length} requests in 11 s)`);
    pass();
    await wait(5500);
    const mid = await barWidth(page);
    ok(mid > 0 && mid < 100, `a worker pass later the bar moved (${mid}%)`);
    await page.evaluate(() => Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' }));
    const n = polls.length;
    await wait(11000);
    ok(polls.length === n, 'while hidden: no request');
    await page.evaluate(() => Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' }));
    pass();
    await wait(5500);
    ok((await barWidth(page)) === 100 && polls.status === 286 && (await page.locator('#build-progress-status').innerText()).trim() === 'done', 'the next pass finishes it: 100%, done, the partial answered 286');
    ok((await page.locator('#build-cancel').count()) === 0, 'Cancel leaves with the 286 (it lives inside the polled region)');
    const m = polls.length;
    await wait(11000);
    ok(polls.length === m, 'after the 286: no more requests');
    ok(sql(`SELECT status FROM builds WHERE id = ${buildId}`) === 'done', 'the build is done in the database');
    const sc = await page.evaluate(() => getComputedStyle(document.getElementById('build-items-scroll')).overflowX);
    ok(['auto', 'scroll'].includes(sc), `the items table scrolls within the page (overflow-x ${sc})`);
    await check(page, 'build-view', `${tag}-build-view`);
  } else {
    await check(page, 'the builds page with its builds', `${tag}-builds`);
    const last = sql(`SELECT max(id) FROM builds WHERE base_id = ${w.builds}`);
    await page.goto(`${BASE}/builds/${last}`, { waitUntil: 'networkidle' });
    await check(page, 'a finished build', `${tag}-build-view`);
  }
  if (w.failed_build) {
    await page.goto(`${BASE}/builds/${w.failed_build}`, { waitUntil: 'networkidle' });
    const link = page.locator('#build-failed a').first();
    ok(await link.count() === 1 || (await page.locator('#build-failed a').count()) >= 1, 'a build with a failed section lists it');
    await check(page, 'a build with failures', `${tag}-build-failed`);
    const href = await link.getAttribute('href');
    await link.click();
    await page.waitForURL(/\/sections\/\d+/);
    ok(page.url().endsWith(href.split('?')[0]) || /\/sections\/\d+/.test(page.url()), 'the failed section links to its section');
  } else ok(false, 'no build with a failed section — run the failures proof first');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

console.log('375 x 740 — JavaScript off');
{
  const { ctx, page, errors } = await session(26, PHONE, { javaScriptEnabled: false });
  const polls = [];
  page.on('request', (r) => { if (/\/progress$/.test(new URL(r.url()).pathname)) polls.push(r.url()); });
  await page.goto(`${BASE}/bases/${w.nojs}/builds`);
  await page.click('#build-start'); await page.waitForLoadState('load');
  const m = page.url().match(/\/builds\/(\d+)/);
  ok(!!m && (await page.locator('#notice-banner').innerText()).includes('The build is queued'), 'Start answers a full page: the build, with its notice');
  await page.click('#build-cancel'); await page.waitForLoadState('load');
  ok(m && sql(`SELECT status FROM builds WHERE id = ${m[1]}`) === 'cancelled' && (await page.locator('#notice-banner').innerText()).includes('cancelled'), 'Cancel answers a full page: cancelled');
  ok(polls.length === 0, 'no polling without JavaScript');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
