// Headless Chromium proof of slice 7 (docs/build-specs/api-keys-k7.md "Proof", the last bullet): at 375 x 740 and 1280 x 800 — the keys table at 1280 and as cards
// at 375; the mint form; the copy-once box with Copy (once: a reload shows nothing); rotate shows the old key's end time; revoke through its confirm; the key edit form;
// the connections page; every control >= 44 px, no sideways scroll, no console errors. Run by tests/phase3/slice7/run.sh last.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8501';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/kn-shots-s7';
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
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, permissions: ['clipboard-read', 'clipboard-write'], ...opts });
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
const php = (code) => execFileSync('php', ['-r', `require 'tests/phase3/slice7/lib.php'; ${code}`], { cwd: ROOT, env: process.env }).toString().trim();
const w = JSON.parse(php('$w = keys_world(); mint(CARA, $w["manuals"], "Browser seed"); echo json_encode($w);'));
const check = async (page, what, shot) => {
  const o = await overflow(page); ok(o.sw === o.iw, `${what}: no sideways scroll (${o.sw} = ${o.iw})`);
  const s = await small(page); ok(s.length === 0, `${what}: every control at least 44 px ` + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${shot}.png`, fullPage: true });
};

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — Marco curates the keys`);
  const { ctx, page, errors } = await session(27, vp);
  const dialogs = [];
  page.on('dialog', (d) => { dialogs.push(d.message()); d.accept(); });
  await page.goto(`${BASE}/bases/${w.manuals}/keys`, { waitUntil: 'networkidle' });
  ok(await page.locator('#base-tab-keys[aria-current="page"]').count() === 1, 'the Keys tab is lit');
  if (tag === 'desk') { ok(await page.locator('#keys-table').isVisible() && !(await page.locator('#keys-cards').isVisible()), 'the keys as a table at 1280'); }
  else { ok(await page.locator('#keys-cards').isVisible() && !(await page.locator('#keys-table').isVisible()), 'the keys as cards at 375'); }
  ok(await page.locator('#keys-api-curl').isVisible() && (await page.locator('#keys-api-curl').innerText()).includes('Bearer KEY'), 'the API note with the curl example');
  await check(page, 'the keys', `${tag}-keys`);
  // mint
  await page.click('#keys-mint-btn');
  await page.waitForSelector('#key-form');
  ok(await page.locator('#key-form-field-label').isVisible() && await page.locator('#key-form-field-focus_area').isVisible() && (await page.getAttribute('#key-form-field-rate_per_minute', 'placeholder')) === '30', 'the mint form: label, area, limits with the defaults as placeholders');
  await check(page, 'the mint form', `${tag}-mint`);
  await page.fill('#key-form-field-label', `Browser ${tag}`);
  await page.selectOption('#key-form-field-focus_area', { label: 'Warranty' });
  await page.click('#key-form-save-btn');
  await page.waitForSelector('#key-minted');
  const raw = await page.inputValue('#key-minted-value');
  ok(/^mcp_[0-9a-f]{48}$/.test(raw) && (await page.locator('#key-minted').innerText()).includes('Shown once'), 'the copy-once box shows the key and "Shown once — store it now"');
  ok(!page.url().includes('mcp_') && page.url().includes('/bases/' + w.manuals + '/keys'), 'the key is not in the URL');
  await page.click('#key-minted-copy');
  await page.waitForFunction(() => document.getElementById('key-minted-copy').textContent.includes('Copied'), null, { timeout: 4000 }).then(() => ok(true, 'Copy copies it'), async () => ok(false, 'Copy copies it — the button reads "' + (await page.locator('#key-minted-copy').innerText()) + '"'));
  const clip = await page.evaluate(() => navigator.clipboard.readText()).catch(() => '');
  ok(clip === raw, 'the clipboard holds the key');
  await check(page, 'the copy-once box', `${tag}-minted`);
  await page.reload({ waitUntil: 'networkidle' });
  ok(await page.locator('#key-minted').count() === 0 && !(await page.content()).includes(raw), 'a reload shows nothing');
  const row = sql(`SELECT id FROM mcp_access_tokens WHERE label = 'Browser ${tag}' AND scope = 'api' ORDER BY id DESC LIMIT 1`);
  const rowSel = tag === 'desk' ? `#key-${row}` : `#key-card-${row}`;
  ok(await page.locator(rowSel).isVisible() && (await page.locator(rowSel).innerText()).includes('Warranty'), 'the new key is in the list, bound to Warranty');
  // edit
  await page.click(`#${tag === 'desk' ? 'key-' : 'key-card-'}${row}-edit`);
  await page.waitForSelector('#key-form');
  ok((await page.inputValue('#key-form-field-label')) === `Browser ${tag}` && await page.locator('input[name=api_key]').count() === 1, 'the edit form carries the key (never its base)');
  await check(page, 'the edit form', `${tag}-edit`);
  await page.fill('#key-form-field-label', `Browser ${tag} v2`);
  await page.click('#key-form-save-btn');
  await page.waitForSelector(rowSel);
  ok((await page.locator(rowSel).innerText()).includes(`Browser ${tag} v2`), 'Save lands on the list with the new label');
  // rotate
  await page.click(`#${tag === 'desk' ? 'key-' : 'key-card-'}${row}-rotate`);
  await page.waitForSelector('#key-minted');
  ok(dialogs.some((d) => d.includes('Rotate the key')) && (await page.locator('#key-minted-old').innerText()).includes('The old key stops working at'), 'Rotate (confirmed) shows the new key and the old key\'s end time');
  ok((await page.inputValue('#key-minted-value')) !== raw && (await page.locator(rowSel).innerText()).toLowerCase().includes('expiring'), 'a different key; the old row is "expiring until <time>"');
  await check(page, 'after a rotate', `${tag}-rotated`);
  // revoke the old one
  await page.click(`#${tag === 'desk' ? 'key-' : 'key-card-'}${row}-revoke`);
  await page.waitForFunction((s) => document.querySelector(s)?.innerText.toLowerCase().includes('revoked'), rowSel);
  ok(dialogs.some((d) => d.includes('Revoke the key')) && await page.locator(`#${tag === 'desk' ? 'key-' : 'key-card-'}${row}-revoke`).count() === 0, 'Revoke (confirmed) → the row reads revoked and offers no more buttons');
  await check(page, 'after a revoke', `${tag}-revoked`);
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — the admin's connections`);
  const { ctx, page, errors } = await session(1, vp);
  await page.goto(`${BASE}/admin/connections`, { waitUntil: 'networkidle' });
  ok(await page.locator('#connections-shares').isVisible() && await page.locator('#principal-helpdesk').isVisible() && await page.locator('#connections-reads').isVisible() && await page.locator('#share-reads').isVisible(), 'the shares, the application with its base, the reads and the last calls');
  await check(page, 'the connections', `${tag}-connections`);
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

console.log('375 x 740 — Priya (a Member) has no Keys tab');
{
  const { ctx, page, errors } = await session(26, PHONE);
  await page.goto(`${BASE}/bases/${w.manuals}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#base-tab-keys').count() === 0, 'no Keys tab for a reader');
  const r = await page.goto(`${BASE}/bases/${w.manuals}/keys`);
  ok(r.status() === 403 && (await page.content()).includes('You may not change this knowledge base'), 'the keys page → 403 in words');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
