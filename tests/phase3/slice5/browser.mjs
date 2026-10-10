// Headless Chromium proof of slice 5 (docs/build-specs/ask.md "Proof", the last bullet): at 375 x 740 and 1280 x 800 — the base picker and the
// area chips; a question sent → the bubble at once, the answer with two chips; a chip opens the section with the quote marked and a way back; the
// evidence disclosure; thumbs down expands the comment box (no modal); Pin expands the title field; an evidence_only turn's Ask the expert → the
// thinking row, then the reply once the fake kernel flips; a no_evidence card with "Ask the whole base"; a failed card with Try again; the empty
// base's "Add documents"; suggested chips fill the composer; JavaScript off: the form posts and the page shows the turn; every control >= 44 px,
// no sideways scroll, no console errors; the questions table and gap cards at 1280, cards at 375. Run by tests/phase3/slice5/run.sh last.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8501';
const ROOT = new URL('../../../', import.meta.url).pathname;
const SHOTS = process.env.SHOTS || '/tmp/kn-shots-s5';
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
const php = (code) => execFileSync('php', ['-r', `require 'tests/phase3/slice5/lib.php'; ${code}`], { cwd: ROOT, env: process.env }).toString().trim();
const w = JSON.parse(php('echo json_encode(ask_world());'));
const kb = (o) => php(`kb_state(json_decode('${JSON.stringify(o).replace(/'/g, "\\'")}', true));`);
const chat = (o) => php(`chat_state(json_decode('${JSON.stringify(o).replace(/'/g, "\\'")}', true));`);
php('kb_state(["answer_route" => true, "answer_mode" => "ok", "scripts" => [carry_script(ask_world()), ["match" => "koala", "answer" => m1("no_evidence", [], [], null, 0, 0)]]]);');
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const check = async (page, what, shot) => {
  const o = await overflow(page); ok(o.sw === o.iw, `${what}: no sideways scroll (${o.sw} = ${o.iw})`);
  const s = await small(page); ok(s.length === 0, `${what}: every control at least 44 px ` + s.join(', '));
  await page.screenshot({ path: `${SHOTS}/${shot}.png`, fullPage: true });
};
const lastTurn = (page) => page.locator('#ask-turns > .kn-turn').last();

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — Priya asks`);
  const { ctx, page, errors } = await session(26, vp);
  page.on('dialog', (d) => d.accept());
  await page.goto(`${BASE}/ask?pick=1`, { waitUntil: 'networkidle' });
  ok(await page.locator(`#ask-pick-${w.handbook}`).isVisible(), 'the base picker lists the Handbook');
  await check(page, 'the base picker', `${tag}-picker`);
  await page.click(`#ask-pick-${w.handbook}`);
  await page.waitForSelector('#ask-composer');
  ok(await page.locator('#ask-area-leave').isVisible() && await page.locator('#ask-area-all').isVisible(), 'the area chips: Whole base and Leave');
  ok(await page.locator('#ask-suggestions a').count() >= 2, 'an empty conversation shows the suggested questions');
  await page.click('#ask-suggestion-0');
  ok((await page.inputValue('#ask-question')) === 'How does carry-over work?', 'a suggested chip fills the composer (it does not send)');
  await check(page, 'the window', `${tag}-window`);
  await page.fill('#ask-question', `How does carry-over work? (${tag})`);
  const sent = page.waitForResponse((r) => r.url().endsWith('/ask/send.php'));
  await page.press('#ask-question', 'Enter');
  await page.waitForSelector('#ask-pending', { timeout: 3000 }).then(() => ok(true, 'Enter sends: the bubble is echoed at once with "Searching the base…"'), () => ok(false, 'the bubble echoed at once'));
  await sent;
  await page.waitForSelector('#ask-turns > .kn-turn[data-status="answered"]');
  const turn = lastTurn(page);
  const qid = (await turn.getAttribute('id')).replace('q-', '');
  ok(await turn.locator('.kn-cite').count() === 2 && await turn.locator('.kn-cite-verified').count() === 2, 'the answer with two verified chips');
  ok((await page.inputValue('#ask-question')) === '' && (await page.inputValue('#ask-c')).startsWith('c_'), 'the composer is cleared and carries the conversation');
  await check(page, 'an answered turn', `${tag}-answered`);
  await turn.locator('summary', { hasText: 'Show the evidence' }).click();
  ok(await page.locator(`#evidence-${qid}[open]`).count() === 1, 'the evidence disclosure opens');
  await page.click(`#thumbs-down-${qid}`);
  ok(await page.locator(`#thumbs-comment-${qid}`).isVisible(), 'thumbs down expands the comment box beneath (no modal)');
  await page.fill(`#thumbs-comment-${qid}`, 'Too short');
  await page.click(`#thumbs-send-${qid}`);
  await page.waitForFunction((id) => document.querySelector(`#thumbs-down-${id}`)?.getAttribute('aria-pressed') === 'true', qid);
  ok(true, 'Send keeps thumbs down pressed');
  const chip = page.locator(`#cite-${qid}-1`);
  await chip.click();
  await page.waitForURL(/\/sections\/\d+/);
  await page.waitForSelector('mark#quote');
  const inView = await page.evaluate(() => { const r = document.getElementById('quote').getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; });
  ok(inView, 'a chip opens the section with the quote marked and scrolled into view');
  const back = page.locator('[id$="-back"]').first();
  ok(await back.count() === 1 && (await back.getAttribute('href')).includes('/ask?base='), 'and a way back to the conversation');
  await back.click();
  await page.waitForSelector(`#q-${qid}`);
  ok(true, 'back to the conversation');
  if (tag === 'phone') {
    await page.click(`#ask-area-leave`);
    await page.waitForSelector('#ask-composer');
    await page.fill('#ask-question', 'Is there koala leave?');
    await page.click('#ask-send');
    await page.waitForSelector('#ask-turns > .kn-turn[data-status="no_evidence"]');
    const nt = lastTurn(page);
    ok(await nt.locator('[id^="ask-whole-"]').count() === 1, 'a no_evidence card in an area offers "Ask the whole base"');
    await nt.locator('[id^="ask-whole-"]').click();
    await page.waitForFunction(() => document.querySelectorAll('#ask-turns > .kn-turn').length >= 2 && !document.querySelector('#ask-turns > .kn-turn:last-child [id^="ask-whole-"]'));
    ok(true, '…which asks again without the area');
    php('kb_stop();');
    await page.fill('#ask-question', 'How does carry-over work? (down)');
    await page.click('#ask-send');
    await page.waitForSelector('#ask-turns > .kn-turn[data-status="failed"]');
    const ft = lastTurn(page);
    ok(await ft.locator('[id^="try-again-"]').count() === 1, 'a failed card with Try again');
    php('kb_start();');
    await ft.locator('[id^="try-again-"]').click();
    await page.waitForSelector('#ask-turns > .kn-turn[data-status="answered"]:last-child');
    ok(true, 'Try again posts the same question as a new turn — answered');
    // evidence only, then the expert
    chat({ chat_http: 202, chat: { run_id: 6101, status: 'running', finished: false } });
    php('kb_state(["answer_route" => false, "recall" => [["chunk_id" => 81, "document_id" => 501, "statement_id" => 1, "source_text" => "Up to five days of unused leave carry over.", "similarity" => 0.77, "subject_name" => "Leave", "verb_name" => "covers"]], "titles" => ["501" => "Carry-over"]]);');
    await page.fill('#ask-question', 'How does carry-over work? (passages)');
    await page.click('#ask-send');
    await page.waitForSelector('#ask-turns > .kn-turn[data-status="evidence_only"]');
    const et = lastTurn(page);
    const eid = (await et.getAttribute('id')).replace('q-', '');
    ok(await et.locator(`#pin-btn-${eid}`).count() === 0 && await et.locator(`#ask-expert-${eid}`).count() === 1, 'an evidence_only turn: no Pin, Ask the expert');
    await page.click(`#ask-expert-${eid}`);
    await page.waitForSelector(`#expert-thinking-${eid}`);
    ok(true, 'Ask the expert → the thinking row');
    chat({ chat_run: { run_id: 6101, status: 'succeeded', finished: true, reply: 'The expert: **five days** carry over.' } });
    await page.waitForSelector(`#expert-reply-${eid}`, { timeout: 10000 }).then(() => ok(true, 'the reply appears once the run finishes (the poll)'), () => ok(false, 'the reply appears once the run finishes'));
    php('kb_state(["answer_route" => true]);');
    await check(page, 'the window after six turns', `${tag}-turns`);
  } else {
    await page.goto(`${BASE}/questions/${qid}`, { waitUntil: 'networkidle' });
    ok(await page.locator(`#pin-btn-${qid}`).count() === 0, 'Priya (a Member) sees no Pin');
    await check(page, 'question-view', `${tag}-question`);
  }
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

for (const [label, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const tag = vp.width < 600 ? 'phone' : 'desk';
  console.log(`${label} — Marco curates`);
  const { ctx, page, errors } = await session(27, vp);
  page.on('dialog', (d) => d.accept());
  const qid = sql(`SELECT id FROM questions WHERE base_id = ${w.handbook} AND status = 'answered' ORDER BY id DESC LIMIT 1`);
  await page.goto(`${BASE}/questions/${qid}`, { waitUntil: 'networkidle' });
  await page.click(`#pin-btn-${qid}`);
  ok(await page.locator(`#pin-title-${qid}`).isVisible(), 'Pin expands the title field (no modal)');
  await check(page, 'question-view with Pin open', `${tag}-pin`);
  await page.goto(`${BASE}/bases/${w.handbook}/questions`, { waitUntil: 'networkidle' });
  ok(tag === 'desk' ? await page.locator('#questions-table').isVisible() : await page.locator('#questions-cards').isVisible(), tag === 'desk' ? 'the questions as a table at 1280' : 'the questions as cards at 375');
  await check(page, 'the questions', `${tag}-questions`);
  await page.goto(`${BASE}/bases/${w.handbook}/gaps`, { waitUntil: 'networkidle' });
  ok(await page.locator('#gaps-cards .card').count() >= 1, 'the gaps as cards');
  await check(page, 'the gaps', `${tag}-gaps`);
  await page.goto(`${BASE}/ask?base=${w.empty}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#ask-add-documents').isVisible() && await page.locator('#ask-composer').count() === 0, 'the empty base: "Nothing in this base can be asked yet" and Add documents, no composer');
  await page.goto(`${BASE}/pinned`, { waitUntil: 'networkidle' });
  await check(page, 'the pinned answers', `${tag}-pinned`);
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}

console.log('375 x 740 — JavaScript off');
{
  const { ctx, page, errors } = await session(26, PHONE, { javaScriptEnabled: false });
  await page.goto(`${BASE}/ask?base=${w.handbook}`);
  await page.fill('#ask-question', 'How does carry-over work? (no script)');
  await page.click('#ask-send'); await page.waitForLoadState('load');
  ok(/\/ask\?base=\d+.*&c=c_[0-9a-f]{24}/.test(page.url()) && await page.locator('.kn-turn[data-status="answered"]').count() === 1, 'the form posts and the page shows the turn');
  ok(errors.length === 0, 'no console errors ' + errors.join(' | '));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
