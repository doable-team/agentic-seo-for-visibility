#!/usr/bin/env node
// editor-check.js <base-url> <post-id> [...post-ids]: open each post in the real block
// editor (headless Chrome) and print JSON per post: every block (nested too) with
// name and isValid, plus the editor's validation messages for the invalid ones.
// Needs playwright-core (NODE_PATH) and Chrome (CHROME, default /usr/bin/google-chrome).
// WP admin admin/admin.
const { chromium } = require('playwright-core');
(async () => {
  const [base, ...ids] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome' });
  const page = await browser.newPage();
  const logs = [];
  page.on('console', (m) => { if (/block validation|Block validation|Expected|unexpected/i.test(m.text())) logs.push(m.text().slice(0, 400)); });
  await page.goto(base + '/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  for (const id of ids) {
    logs.length = 0;
    await page.goto(`${base}/wp-admin/post.php?post=${id}&action=edit`);
    await page.waitForFunction(() => window.wp && wp.data && wp.data.select('core/block-editor') && wp.data.select('core/editor').getCurrentPostId(), null, { timeout: 60000 });
    await page.waitForTimeout(2500);
    const blocks = await page.evaluate(() => {
      const out = [];
      const walk = (list, depth) => list.forEach((b) => { out.push({ name: b.name, isValid: b.isValid, depth }); walk(b.innerBlocks || [], depth + 1); });
      walk(wp.data.select('core/block-editor').getBlocks(), 0);
      return out;
    });
    const invalid = blocks.filter((b) => !b.isValid);
    console.log(JSON.stringify({ id: Number(id), blocks, invalid: invalid.map((b) => b.name), messages: invalid.length ? logs.slice(0, 6) : [] }));
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
