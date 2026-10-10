// Headless Chromium proof of slice 6 (docs/build-specs/graph-explorer.md "Proof", the last bullet): at 375 x 740 and 1280 x 800 — the graph page's
// regions and the search; a subject (statements, neighbours, sections, Ask about this, Path to…); at 1280 the drawing with 24 nodes at most, every node a
// link, the labels cut with the full name in <title>; at 375 the drawing hidden and the lists carrying the same nodes; the path chain; a community;
// a curator's duplicates and Merge opening the vocabulary form prefilled; every control >= 44 px, no sideways scroll, no console errors; and the
// registry reading the four screens built. Run by tests/phase3/slice6/run.sh last.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8501';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/kn-shots-s6';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(ROOT + 'bin/dev_directory.json', 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.knowledge.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
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
const visible = (a) => { const r = a.getBoundingClientRect(); const cs = getComputedStyle(a); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
// buttons, fields, tabs: 44 x 44; the rows of a list (links that fill a line): 44 high
const small = (page, scope = '#page-content') => page.evaluate(([s, vis]) => {
  const v = new Function('a', 'return (' + vis + ')(a)');
  const sel = [`${s} a.btn`, `${s} button`, `${s} input:not([type=hidden]):not([type=checkbox]):not([type=radio])`, `${s} select`, `${s} summary`, `${s} .nav-link`].join(',');
  const rows = [`${s} a.kn-row-link`, `${s} a.list-group-item-action`].join(',');
  const bad = [...document.querySelectorAll(sel)].filter((a) => v(a) && (a.getBoundingClientRect().height < 44 || a.getBoundingClientRect().width < 44))
    .concat([...document.querySelectorAll(rows)].filter((a) => v(a) && a.getBoundingClientRect().height < 44));
  return bad.map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height));
}, [scope, visible.toString()]);
const php = (code) => execFileSync('php', ['-r', `require 'tests/phase3/slice6/lib.php'; ${code}`], { cwd: ROOT, env: process.env }).toString().trim();
const w = JSON.parse(php('echo json_encode(graph_world());'));
// the world: communities, surprises; the hub for the 24-node drawing
const world = (extra) => php(`$w = graph_world(); g_set($w, ${extra}); g_cache_clear($w['pumps']);`);
world('g_communities($w) + g_surprises($w)');
const check = async (page, what, shot) => {
  const o = await overflow(page); ok(o.sw === o.iw, `${what}: no sideways scroll (${o.sw} = ${o.iw})`);
  const s = await small(page); ok(s.length === 0, `${what}: every control at least 44 px ` + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${shot}.png`, fullPage: true });
};
const enc = encodeURIComponent;

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — Priya explores`);
  const { ctx, page, errors } = await session(26, vp);
  page.on('dialog', (d) => d.accept());
  await page.goto(`${BASE}/bases/${w.pumps}/graph`, { waitUntil: 'networkidle' });
  ok(await page.locator('#graph-size').isVisible() && await page.locator('#graph-communities').isVisible() && await page.locator('#graph-god-nodes').isVisible() && await page.locator('#graph-surprises').isVisible(), 'the graph page: size, communities, most connected, surprises');
  ok(await page.locator('#graph-duplicates').count() === 0, 'a Reader has no duplicates region');
  ok(await page.locator('#base-tab-graph[aria-current="page"]').count() === 1, 'the Graph tab is lit');
  ok(await page.locator('#graph-community-11').isVisible() && (await page.locator('#graph-surprises .list-group-item').count()) === 2, 'two community cards and the two surprises of the base');
  await check(page, 'the graph page', `${tag}-graph`);
  await page.fill('#graph-search-q', 'Zorblat');
  await page.click('#graph-search-btn');
  await page.waitForSelector('#graph-search-item-1');
  ok((await page.locator('#graph-search-results a').count()) === 3, 'search "Zorblat" → three subjects');
  await check(page, 'the graph page with a search', `${tag}-search`);
  await page.click('#graph-search-item-1');
  await page.waitForSelector('#subject-head');
  ok((await page.locator('#subject-name').innerText()) === 'Zorblat Pump', 'a result opens its subject');
  ok(await page.locator('#subject-statements').isVisible() && await page.locator('#subject-neighbours').isVisible() && await page.locator('#subject-sections').isVisible(), 'statements, neighbours, the sections behind it');
  ok((await page.locator('[id$="-back"]').first().getAttribute('href')).includes('/graph'), 'a way back to the graph');
  const svgShown = await page.locator('svg#subject-svg').isVisible();
  if (tag === 'desk') {
    ok(svgShown && (await page.locator('svg#subject-svg a.graph-svg-node').count()) === 5, 'at 1280 the drawing is there, five nodes');
  } else {
    ok(!svgShown, 'at 375 the drawing is hidden');
  }
  await check(page, 'a subject', `${tag}-subject`);
  await page.click('#subject-ask');
  await page.waitForSelector('#ask-composer');
  ok((await page.inputValue('#ask-question')) === 'What does this knowledge base say about Zorblat Pump?', 'Ask about this opens the window with the question typed in (not sent)');
  await page.goto(`${BASE}/bases/${w.pumps}/subject?name=${enc('Zorblat Pump')}`, { waitUntil: 'networkidle' });
  await page.fill('#subject-path-to', 'Merrow Filter');
  await page.click('#subject-path-btn');
  await page.waitForSelector('#path-chain');
  ok((await page.locator('#path-chain > li').count()) === 3 && (await page.locator('#path-node-2').innerText()).includes('Merrow Filter'), 'Path to… → the chain of three, through the Merrow Filter section');
  await check(page, 'the path', `${tag}-path`);
  await page.fill('#path-to', 'Quillon Valve');
  await page.click('#path-btn');
  await page.waitForSelector('#path-withheld');
  ok((await page.locator('#path-result').innerText()).includes('There is no path between these inside this base.') && (await page.locator('#path-chain').count()) === 0, 'a name that is not the base\'s → the sentence, no chain');
  const gone = await page.goto(`${BASE}/bases/${w.pumps}/subject?name=${enc('Quillon Valve')}`);
  ok(gone.status() === 404 && !(await page.content()).includes('closes at twelve'), 'Quillon Valve from Pumps is a 404');
  await page.goto(`${BASE}/bases/${w.pumps}/community?community=11`, { waitUntil: 'networkidle' });
  ok((await page.locator('#community-members a').count()) === 3 && await page.locator('#community-ask').isVisible(), 'a community: three members and Ask about this');
  await check(page, 'a community', `${tag}-community`);
  await page.goto(`${BASE}/sections/${w.zorblat}`, { waitUntil: 'networkidle' });
  const taught = await page.locator('#taught').innerText().catch(() => '(none)');
  ok(await page.locator('#taught').isVisible() && taught.includes('Zorblat Pump'), 'section-view: what it taught the graph' + (taught.includes('Zorblat Pump') ? '' : ' — ' + taught.replace(/\n/g, ' | ')));
  await check(page, 'a section', `${tag}-section`);
  await page.goto(`${BASE}/sections/${w.top}`, { waitUntil: 'networkidle' });
  ok((await page.locator('#taught-not-built').innerText()) === 'Not in the graph yet.', 'an unbuilt section: "Not in the graph yet."');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

// the 24-node drawing: a hub with 45 neighbours (38 of them the base's)
world("(function () use ($w) { $h = g_hub($w, 45, 2); return ['subjects' => $h['subjects'], 'neighbors' => $h['neighbors']]; })()");
for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — the hub`);
  const { ctx, page, errors } = await session(26, vp);
  await page.goto(`${BASE}/bases/${w.pumps}/subject?name=${enc('Hub Pump')}`, { waitUntil: 'networkidle' });
  const listed = await page.locator('#subject-neighbours a').allInnerTexts();
  ok(listed.length === 38 && await page.locator('#subject-neighbours-more').isVisible(), 'the list carries the 38 neighbours that are the base\'s, and says "and more"');
  if (tag === 'desk') {
    const nodes = page.locator('svg#subject-svg a.graph-svg-node');
    const n = await nodes.count();
    ok(n === 24 && (await page.locator('svg#subject-svg').getAttribute('data-nodes')) === '24', 'at 1280 the drawing holds 24 nodes at most');
    const hrefs = await nodes.evaluateAll((els) => els.map((e) => e.getAttribute('href')));
    ok(hrefs.every((h) => h && h.startsWith('/')) , 'every node is a link');
    const titles = await nodes.evaluateAll((els) => els.map((e) => e.querySelector('title').textContent));
    const labels = await nodes.evaluateAll((els) => els.map((e) => e.querySelector('text').textContent));
    ok(labels.every((l) => l.length <= 24) && titles.every((t) => t.length > 0), 'labels cut at 24 characters, the full name in <title>');
    const box = await page.locator('svg#subject-svg').boundingBox();
    ok(box && box.width > 500 && box.height > 300, 'and it is drawn at size (' + Math.round(box?.width) + ' x ' + Math.round(box?.height) + ')');
    const sectionsFirst = await nodes.evaluateAll((els) => els.filter((e) => e.getAttribute('data-kind') === 'section').length);
    ok(sectionsFirst === 1, 'the section behind the hub is in the drawing');
    const inLists = titles.filter((t) => t.startsWith('Cap ')).every((t) => listed.some((l) => l.includes(t)));
    ok(inLists, 'every subject the drawing shows is also in the list');
    await page.locator('svg#subject-svg a.graph-svg-node[data-kind="subject"]').first().click();
    await page.waitForSelector('#subject-head');
    ok((await page.locator('#subject-name').innerText()).startsWith('Cap '), 'a node is a link: it opens that subject');
  } else {
    ok(!(await page.locator('svg#subject-svg').isVisible()) && (await page.locator('#subject-svg-card').isVisible()) === false, 'at 375 the drawing is hidden and the lists carry the nodes');
  }
  await page.goto(`${BASE}/bases/${w.pumps}/subject?name=${enc('Hub Pump')}`, { waitUntil: 'networkidle' });
  await check(page, 'the hub', `${tag}-hub`);
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}
world('[]');

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — Marco curates`);
  const { ctx, page, errors } = await session(27, vp);
  await page.goto(`${BASE}/bases/${w.pumps}/graph`, { waitUntil: 'networkidle' });
  ok(await page.locator('#graph-duplicates').isVisible() && await page.locator('#dup-group-1').isVisible(), 'a curator sees the duplicates region and its groups');
  ok((await page.locator('#dup-suggested-1').innerText()) === 'Zorblat Pump', 'with the suggested name');
  await check(page, 'the graph page (curator)', `${tag}-dups`);
  await page.click('#dup-merge-1');
  await page.waitForSelector('#term-form');
  const al = await page.inputValue('#term-form-field-aliases');
  ok((await page.inputValue('#term-form-field-canonical_name')) === 'Zorblat Pump' && al.includes('Zorblat Pumps'), 'Merge opens the vocabulary form with the suggestion as the term and the others as aliases');
  await check(page, 'the vocabulary form, prefilled', `${tag}-merge`);
  await page.goto(`${BASE}/bases/${w.valves}/graph`, { waitUntil: 'networkidle' });
  ok((await page.locator('#graph-size-documents').innerText()) === '1', 'Valves\' graph (Marco owns it): its own one document');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

const reg = JSON.parse(fs.readFileSync(ROOT + 'mcp/action_registry.json', 'utf8'));
const screens = reg.screens || reg;
const built = ['graph', 'subject-view', 'community-view', 'path'].map((id) => (Array.isArray(screens) ? screens.find((s) => s.screen === id) : screens[id]));
ok(built.every((s) => s && s.built === true), 'the registry reads the four screens built');
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
