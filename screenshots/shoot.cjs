#!/usr/bin/env node
// Takes the screenshots listed in screenshots/spec.json from the wp-env site.
//
// Usage: npm run screenshots [-- <id> ...]
// Env:   SHOT_BASE (default http://localhost:8888), SHOT_USER (admin), SHOT_PASSWORD (password)
//
// Needs a running wp-env (npm run env:start) and ImageMagick (`convert`) for .webp output.
'use strict';

const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { pathToFileURL } = require('url');

const root = path.resolve(__dirname, '..');
const spec = JSON.parse(fs.readFileSync(path.join(__dirname, 'spec.json'), 'utf8'));
const only = process.argv.slice(2);
const base = (process.env.SHOT_BASE || 'http://localhost:8888').replace(/\/$/, '');
const user = process.env.SHOT_USER || 'admin';
const pass = process.env.SHOT_PASSWORD || 'password';

// Run a WP-CLI command in the wp-env cli container. The command is split on spaces, so
// arguments must not contain spaces.
// Returns the command's output.
function wp(command) {
  const args = command.split(' ').filter(Boolean);
  if (args[0] !== 'wp') throw new Error(`Commands must start with "wp": ${command}`);
  return execFileSync('npx', ['wp-env', 'run', 'cli', ...args], { cwd: root, stdio: ['ignore', 'pipe', 'inherit'], encoding: 'utf8' });
}

async function login(browser) {
  const context = await browser.newContext();
  const page = await context.newPage();
  // Log in straight to the plugin screen: the Dashboard can be slow to load.
  await page.goto(`${base}/wp-login.php?redirect_to=${encodeURIComponent(`${base}/wp-admin/tools.php?page=nanopost-email-check`)}`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/\/wp-admin\//, { timeout: 90000 }), page.click('#wp-submit')]);
  if (!page.url().includes('/wp-admin')) throw new Error('Login failed. Set SHOT_USER and SHOT_PASSWORD.');
  const file = path.join(os.tmpdir(), `nanopost-email-check-shots-${process.pid}.json`);
  await context.storageState({ path: file });
  await context.close();
  return file;
}

async function shoot(browser, state, shot) {
  const s = { ...spec.defaults, ...shot, hide: [...(spec.defaults.hide || []), ...(shot.hide || [])] };
  const context = await browser.newContext({
    viewport: { width: s.width || 1280, height: 900 },
    deviceScaleFactor: s.scale || 2,
    reducedMotion: 'reduce',
    storageState: state,
  });
  const page = await context.newPage();
  // URL patterns to refuse, for example a script that would call a real service.
  for (const pattern of s.block || []) await page.route(pattern, (route) => route.abort());
  // Nothing from other hosts (avatars, news feeds): it only slows the page load.
  if (!s.html) await page.route((url) => /^https?:$/.test(url.protocol) && url.origin !== new URL(base).origin, (route) => route.abort());
  if (s.html) {
    // A local page, not the site. "data" is a WP-CLI command that prints JSON for it.
    if (s.data) await page.addInitScript((d) => { window.shotData = d; }, JSON.parse(wp(s.data)));
    await page.goto(pathToFileURL(path.join(root, s.html)).href, { waitUntil: 'networkidle' });
  } else {
    await page.goto(base + s.path, { waitUntil: 'networkidle' });
  }

  await page.addStyleTag({
    content:
      (s.hide.length ? `${s.hide.join(',')}{display:none!important}` : '') +
      '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}',
  });
  for (const [selector, value] of Object.entries(s.replace || {})) {
    await page.$eval(selector, (el, v) => { if ('value' in el) el.value = v; else el.textContent = v; }, value);
  }
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => window.scrollTo(0, 0));

  const options = { fullPage: true, mask: (s.mask || []).map((m) => page.locator(m)), maskColor: '#c9ced6' };

  if (!s.full) {
    // Boxes are relative to the viewport; at scroll 0 that is the page.
    const box = async (sel) => {
      const b = await page.locator(sel).first().boundingBox();
      if (!b) throw new Error(`${shot.id}: selector not visible: ${sel}`);
      return b;
    };
    const pad = s.pad ?? 16;
    const z = await box(s.to);
    if (s.top) {
      // From the top-left of the page to below "to", full window width.
      options.clip = { x: 0, y: 0, width: s.width || 1280, height: Math.ceil(z.y + z.height + pad) };
    } else {
      const a = await box(s.from);
      const x = Math.max(0, Math.min(a.x, z.x) - pad);
      const y = Math.max(0, a.y - pad);
      options.clip = {
        x,
        y,
        width: Math.ceil(Math.max(a.x + a.width, z.x + z.width) + pad - x),
        height: Math.ceil(z.y + z.height + pad - y),
      };
    }
  }

  const out = path.join(root, s.out);
  fs.mkdirSync(path.dirname(out), { recursive: true });
  if (out.endsWith('.webp')) {
    const tmp = `${out}.tmp.png`;
    await page.screenshot({ ...options, path: tmp });
    execFileSync('convert', [tmp, '-quality', '82', out]);
    fs.unlinkSync(tmp);
  } else {
    await page.screenshot({ ...options, path: out });
  }
  await context.close();
  console.log(`${shot.id}: ${s.out}`);
}

(async () => {
  const shots = spec.shots.filter((s) => only.length === 0 || only.includes(s.id));
  if (shots.length === 0) throw new Error(`No shot matches: ${only.join(', ')}`);

  (spec.before || []).forEach(wp);
  const browser = await chromium.launch();
  try {
    const state = await login(browser);
    for (const shot of shots) {
      (shot.setup || []).forEach(wp);
      try {
        await shoot(browser, state, shot);
      } finally {
        (shot.teardown || []).forEach(wp);
      }
    }
    fs.unlinkSync(state);
  } finally {
    await browser.close();
    (spec.after || []).forEach(wp);
  }
})().catch((e) => {
  console.error(`FAIL ${e.message}`);
  process.exit(1);
});
