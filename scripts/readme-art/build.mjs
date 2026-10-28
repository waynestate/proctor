#!/usr/bin/env node
/*
 * PROCTOR's README art kit.
 *
 * Renders everything under docs/readme/ from the repo's own static front page
 * (site/) and brand files (site/brand/). Every image is a crop, resize or
 * local render of something the page already shows; nothing is redrawn or
 * traced. The proctor watches the build. It does not help.
 *
 * Deterministic: same inputs, same images. No server, no network — the page
 * is opened as a file:// URL and every badge is rendered locally.
 */

import { chromium } from "playwright";
import { makeBadge } from "badge-maker";
import sharp from "sharp";
import { fileURLToPath, pathToFileURL } from "node:url";
import path from "node:path";
import fs from "node:fs";

/* ------------------------------------------------------------------ paths */
const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, "..", "..");
const siteDir = path.join(repoRoot, "site");
const indexHtml = path.join(siteDir, "index.html");
const outDir = path.join(repoRoot, "docs", "readme");
const badgesDir = path.join(outDir, "badges");
const castDir = path.join(outDir, "cast");

const MAX_BYTES = 300 * 1024;
const FORBIDDEN = /noug[a]t|snu[.]gs|citadel/i;

function fail(msg) {
  console.error(`readme-art: ${msg}`);
  process.exit(1);
}

function assertClean(region, text) {
  const hit = text.match(FORBIDDEN);
  if (hit) fail(`forbidden text ${JSON.stringify(hit[0])} in ${region}; refusing to ship it`);
}

function rel(p) {
  return path.relative(repoRoot, p).replace(/\\/g, "/");
}

/* ------------------------------------------------------------- composer */
const composer = JSON.parse(fs.readFileSync(path.join(repoRoot, "composer.json"), "utf8"));
const phpConstraint = composer.require?.php;
const license = composer.license;
if (!phpConstraint || !license) fail("composer.json is missing require.php or license");

fs.mkdirSync(badgesDir, { recursive: true });
fs.mkdirSync(castDir, { recursive: true });

/* --------------------------------------------------------------- browser */
const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 1280, height: 900 },
  deviceScaleFactor: 2,
  reducedMotion: "reduce",
});
const page = await context.newPage();
await page.goto(pathToFileURL(indexHtml).href, { waitUntil: "networkidle" });
await page.evaluate(() => document.fonts.ready);

/* Hide the masthead only if it overlaps the page (fixed/sticky). It sits in
   normal flow today, so this is a no-op — but the rule is the rule. */
const mastheadPos = await page.evaluate(() => {
  const el = document.querySelector(".masthead");
  return el ? getComputedStyle(el).position : "static";
});
if (mastheadPos === "fixed" || mastheadPos === "sticky") {
  await page.addStyleTag({ content: ".masthead { display: none !important; }" });
}

/* ------------------------------------------------------------------ hero */
const cover = page.locator(".cover");
assertClean(".cover", await cover.innerText());
await sharp(await cover.screenshot())
  .resize({ width: 1280 })
  .jpeg({ quality: 82, mozjpeg: true })
  .toFile(path.join(outDir, "hero.jpg"));
console.log(`wrote ${rel(path.join(outDir, "hero.jpg"))}`);

/* --------------------------------------------------------------- marking */
const marking = page.locator(".marking");
assertClean(".marking", await marking.innerText());
await sharp(await marking.screenshot())
  .resize({ width: 1280 })
  .jpeg({ quality: 82, mozjpeg: true })
  .toFile(path.join(outDir, "marking.jpg"));
console.log(`wrote ${rel(path.join(outDir, "marking.jpg"))}`);

/* ------------------------------------------------------------------ rule */
/* A section divider cut from the real page: a 1280x28 strip of ruled paper
   showing the red margin line and one ruled line, and no text. The ruled
   lines are located in the rendered pixels, not assumed from the CSS. */
const padBox = await page.evaluate(() => {
  const sec = document.querySelector("#questions");
  const head = sec.querySelector(".sec-head");
  const sy = window.scrollY;
  return {
    secTop: Math.round(sec.getBoundingClientRect().top + sy),
    headTop: Math.round(head.getBoundingClientRect().top + sy),
  };
});
const padTop = padBox.secTop + 2; // clear the dashed border of the section above
const padHeight = padBox.headTop - padTop;
if (padHeight < 40) fail("no empty ruled padding above the questions heading; cannot cut a clean strip");

const padPng = await page.screenshot({ fullPage: true, clip: { x: 0, y: padTop, width: 1280, height: padHeight } });
const pad = await sharp(padPng).raw().toBuffer({ resolveWithObject: true });
const { width: pw, height: ph, channels: pc } = pad.info;
const isRuleRow = (row) => {
  let hits = 0;
  let samples = 0;
  for (let x = Math.floor(pw * 0.2); x < pw * 0.9; x += 16) {
    const i = (row * pw + x) * pc;
    const r = pad.data[i];
    const g = pad.data[i + 1];
    const b = pad.data[i + 2];
    samples++;
    if (g > r + 1 && b < r) hits++;
  }
  return hits > samples / 2;
};
const ruleRows = [];
for (let y = 0; y < ph; y++) if (isRuleRow(y)) ruleRows.push(y);
if (!ruleRows.length) fail("no ruled line found in the empty padding; cannot cut rule.png");

/* Centre a 28 CSS px (56 device px) strip on the middle ruled line, aligned
   to the 2x2 device grid so the 2 px line lands inside one resampling block. */
const stripH = 56;
const mid = ruleRows[Math.floor(ruleRows.length / 2)];
let stripY = Math.max(0, Math.min(mid - stripH / 2, ph - stripH));
stripY += ((mid - stripY) % 2 + 2) % 2;
stripY = Math.max(0, Math.min(stripY, ph - stripH));
const clip = { x: 0, y: padTop + stripY / 2, width: 1280, height: 28 };

/* The strip must be text-free: grep the rendered rectangles of every text
   node that intersects it (containers are ignored — only painted text counts). */
const stripTexts = await page.evaluate((c) => {
  const sy = window.scrollY;
  const out = new Set();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let node;
  while ((node = walker.nextNode())) {
    const t = node.textContent;
    if (!t.trim()) continue;
    const range = document.createRange();
    range.selectNodeContents(node);
    for (const r of range.getClientRects()) {
      if (r.right <= c.x || r.left >= c.x + c.width) continue;
      if (r.bottom <= c.y - sy || r.top >= c.y - sy + c.height) continue;
      out.add(t);
      break;
    }
  }
  return [...out];
}, clip);
for (const t of stripTexts) assertClean("rule strip", t);

/* Cut the strip out of the pad capture itself — no second render, no drift. */
await sharp(padPng)
  .extract({ left: 0, top: stripY, width: pw, height: stripH })
  .resize({ width: 1280 })
  .png({ palette: true, quality: 85, compressionLevel: 9 })
  .toFile(path.join(outDir, "rule.png"));

/* Verify the cut: the red margin line and a ruled line must both be in it. */
const rule = await sharp(path.join(outDir, "rule.png")).raw().toBuffer({ resolveWithObject: true });
const { width: rw, height: rh, channels: rc } = rule.info;
const px = (x, y) => {
  const i = (y * rw + x) * rc;
  return [rule.data[i], rule.data[i + 1], rule.data[i + 2]];
};
let hasMargin = false;
for (let y = 0; y < rh && !hasMargin; y++)
  for (let x = 48; x <= 62; x++) {
    const [r, g, b] = px(x, y);
    if (r - g > 25 && r - b > 20) {
      hasMargin = true;
      break;
    }
  }
/* A ruled line is a row whose green-minus-red mean is no longer the paper's
   -3 (a full line reads +3, a half-resampled line about 0). */
let hasRule = false;
for (let y = 0; y < rh && !hasRule; y++) {
  let sum = 0;
  let n = 0;
  for (let x = 200; x < 1200; x += 8) {
    const [r, g] = px(x, y);
    sum += g - r;
    n++;
  }
  if (sum / n > -1.5) hasRule = true;
}
if (!hasMargin || !hasRule) {
  fs.rmSync(path.join(outDir, "rule.png"));
  console.log(`rule.png: no clean text-free strip (margin:${hasMargin} rule:${hasRule}); skipped`);
} else {
  console.log(`wrote ${rel(path.join(outDir, "rule.png"))}`);
}

/* ------------------------------------------------------------- report.svg */
/* Nine real lines from the page's own report, marked the way the page marks
   them. Motion is CSS only; the unanimated state is the finished state. */
const rawLines = await page.locator("#questions .q__answer").allInnerTexts();
if (rawLines.length !== 9) fail(`expected 9 report lines, found ${rawLines.length}`);
const lines = rawLines.map((raw) => {
  const m = raw.match(/^(PASS|INFO|WARN|FAIL)\s+(.+)$/s);
  if (!m) fail(`unparseable report line: ${raw}`);
  return { status: m[1], rest: m[2].trim() };
});

const esc = (s) =>
  s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");

const PITCH = 40;
const TOP = 56;
const W = 1280;
const H = TOP + lines.length * PITCH + 56;
const STATUS_COLOR = { FAIL: "#9B1C2E", WARN: "#8a5a00", PASS: "#0C5449", INFO: "#3d4a57" };
const CHAR_W = 10.5; // 17px monospace, conservative
const MAX_CHARS = Math.floor((1240 - 120) / CHAR_W);

const svg = [];
svg.push(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" role="img" aria-labelledby="report-title report-desc">`);
svg.push(`  <title id="report-title">PROCTOR text report, marked</title>`);
svg.push(`  <desc id="report-desc">Nine real lines from PROCTOR's text report, each marked the way the page marks it.</desc>`);
svg.push(`  <style>`);
svg.push(`    text { font-family: ui-monospace, "SFMono-Regular", Consolas, Menlo, monospace; font-size: 17px; }`);
svg.push(`    .st { font-weight: 700; }`);
for (const [k, v] of Object.entries(STATUS_COLOR)) svg.push(`    .st--${k.toLowerCase()} { fill: ${v}; }`);
svg.push(`    .rest { fill: #1f2a33; }`);
svg.push(`    .tk path { fill: none; stroke: #0C5449; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 1; stroke-dashoffset: 0; }`);
svg.push(`    @keyframes ln-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }`);
svg.push(`    @keyframes tk-draw { from { stroke-dashoffset: 1; } to { stroke-dashoffset: 0; } }`);
svg.push(`    @media (prefers-reduced-motion: no-preference) {`);
svg.push(`      .ln { animation: ln-in .35s both; }`);
svg.push(`      .tk path { animation: tk-draw .35s both; }`);
svg.push(`    }`);
svg.push(`    @media (prefers-reduced-motion: reduce) { * { animation: none !important; } }`);
svg.push(`  </style>`);
svg.push(`  <rect x="0" y="0" width="${W}" height="${H}" rx="14" fill="#F4F1EA"/>`);
svg.push(`  <line x1="96" y1="0" x2="96" y2="${H}" stroke="#9B1C2E" stroke-width="2" opacity="0.6"/>`);

lines.forEach((line, i) => {
  const y = TOP + i * PITCH;
  let text = `${line.status} ${line.rest}`;
  if (text.length > MAX_CHARS) text = `${text.slice(0, MAX_CHARS - 1)}…`;
  const status = text.slice(0, 4);
  const rest = text.slice(4).trimStart();
  const delay = (i * 0.45).toFixed(2);
  svg.push(`  <line x1="0" y1="${y + PITCH}" x2="${W}" y2="${y + PITCH}" stroke="rgba(60,90,130,.18)" stroke-width="1"/>`);
  svg.push(`  <g class="ln" style="animation-delay:${delay}s">`);
  svg.push(`    <text x="120" y="${y + 26}"><tspan class="st st--${status.toLowerCase()}">${esc(status)}</tspan><tspan class="rest" xml:space="preserve"> ${esc(rest)}</tspan></text>`);
  svg.push(`  </g>`);
  if (status === "PASS") {
    const tickDelay = (i * 0.45 + 0.35).toFixed(2);
    svg.push(`  <svg class="tk" x="48" y="${y + 9}" width="30" height="22" viewBox="0 0 40 30" aria-hidden="true">`);
    svg.push(`    <path d="M3 16 L14 26 L37 3" pathLength="1" style="animation-delay:${tickDelay}s"/>`);
    svg.push(`  </svg>`);
  }
});
svg.push(`</svg>`);
fs.writeFileSync(path.join(outDir, "report.svg"), svg.join("\n") + "\n");
console.log(`wrote ${rel(path.join(outDir, "report.svg"))}`);

/* ------------------------------------------------------------------ board */
const boardSrc = path.join(siteDir, "brand", "proctor-board.png");
if (!fs.existsSync(boardSrc)) fail("site/brand/proctor-board.png is missing");
let board = await sharp(boardSrc).resize({ width: 1400 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
if (board.length > MAX_BYTES) {
  board = await sharp(boardSrc).resize({ width: 1400 }).jpeg({ quality: 75, mozjpeg: true }).toBuffer();
}
fs.writeFileSync(path.join(outDir, "board.jpg"), board);
console.log(`wrote ${rel(path.join(outDir, "board.jpg"))} (${board.length} bytes)`);

/* ----------------------------------------------------------------- badges */
const badgeStyle = { labelColor: "#0C5449", color: "#9B1C2E", style: "flat" };
const badges = [
  { key: "php", label: "php", message: phpConstraint },
  { key: "license", label: "licence", message: license },
  { key: "exit", label: "exit codes", message: "0 pass · 1 fail · 2 tripped" },
];
for (const b of badges) {
  const badge = makeBadge({ ...badgeStyle, label: b.label, message: b.message });
  fs.writeFileSync(path.join(badgesDir, `${b.key}.svg`), badge);
  console.log(`wrote ${rel(path.join(badgesDir, `${b.key}.svg`))}`);
}

/* ------------------------------------------------------------------- cast */
/* The WayneUI tile is composed here, not copied: the reverse mark (white
   frame, gold tab) is set on a Wayne State green rounded square so it sits
   beside the other thirteen tiles as a sibling, not a foreign object. The
   traced paths are placed unchanged — no edits to any d= value, fill, or
   the mark's own viewBox. */
const markSrc = path.join(scriptDir, "src", "wayneui-mark-reverse.svg");
if (!fs.existsSync(markSrc)) fail("scripts/readme-art/src/wayneui-mark-reverse.svg is missing");
const markText = fs.readFileSync(markSrc, "utf8");
const markOpen = markText.match(/<svg[^>]*>/);
const markClose = markText.lastIndexOf("</svg>");
if (!markOpen || markClose < markOpen.index + markOpen[0].length) fail("cannot find the <svg> element in wayneui-mark-reverse.svg");
const markInner = markText.slice(markOpen.index + markOpen[0].length, markClose);
const wayneuiTile = [
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="WayneUI">',
  '  <rect width="512" height="512" rx="80" ry="80" fill="#0C5449"/>',
  '  <svg x="31.3" y="30.3" width="449.4" height="451.4" viewBox="0 0 1980 1989">',
  markInner,
  "  </svg>",
  "</svg>",
].join("\n");
const wayneuiTileSvg = path.join(castDir, "wayneui.svg");
fs.writeFileSync(wayneuiTileSvg, wayneuiTile);
console.log(`wrote ${rel(wayneuiTileSvg)}`);
/* The dark variant is retired: the green tile reads in both themes. */
const wayneuiDark = path.join(castDir, "wayneui-dark.png");
if (fs.existsSync(wayneuiDark)) {
  fs.rmSync(wayneuiDark);
  console.log(`removed ${rel(wayneuiDark)}`);
}

const CAST = [
  "wayneui", "creed", "proctor", "sesame", "helmet", "sourdough", "squire",
  "preflight", "pinstripe", "booster", "rollcall", "drawbridge", "advisor", "weld",
];
for (const name of CAST) {
  let src;
  if (name === "proctor") src = path.join(siteDir, "brand", "proctor-avatar-256.png");
  else if (name === "wayneui") src = wayneuiTileSvg;
  else src = path.join(siteDir, "assets", "marks", `${name}.png`);
  if (!fs.existsSync(src)) {
    console.log(`cast: no source for ${name} (${rel(src)}); skipped`);
    continue;
  }
  const opts = name === "wayneui" ? { density: 300 } : undefined;
  await sharp(src, opts)
    .resize(96, 96)
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toFile(path.join(castDir, `${name}.png`));
  console.log(`wrote ${rel(path.join(castDir, `${name}.png`))}`);
}

await browser.close();

/* ------------------------------------------------------------- size gate */
const files = [];
(function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(p);
    else files.push(p);
  }
})(outDir);
files.sort();
let tooBig = false;
for (const f of files) {
  const size = fs.statSync(f).size;
  console.log(`${rel(f)}: ${size} bytes`);
  if (size > MAX_BYTES) tooBig = true;
}
if (tooBig) fail("one or more files in docs/readme/ exceed 300 KB");
console.log("readme-art: all outputs are 300 KB or under. Pencils down.");
