const { chromium } = require('playwright');

const BASE  = 'http://localhost:8890';
const OUT   = '/out';
const LUCIA = process.env.LUCIA || '2';

// maxHeight trims a screen that keeps going past the part worth showing —
// the settings form and the DNS report are both long by design.
const PHASE = process.env.PHASE || 'a';

// The shots split in two because the settings and status screens show the
// transport, and a local Mailpit host says nothing to somebody reading the
// plugin's listing. Phase A captures what depends on the log data; phase B
// reconfigures to a real provider profile and captures the config screens.
const ALL = {
  a: [
    { file: 'screenshot-3.png', path: '/wp-admin/admin.php?page=diluxone-mail-log' },
    {
      file: 'screenshot-4.png',
      path: '/wp-admin/admin.php?page=diluxone-mail-log&view=1',
      // The body preview is tall so a real message can be read in it; for the
      // screenshot it only has to prove it is there, and the SMTP transcript
      // underneath is the part worth showing.
      css: '.diluxone-mail-body { height: 150px !important; min-height: 0 !important; } .diluxone-mail-transcript { max-height: 260px !important; }',
      maxHeight: 1180,
    },
    { file: 'screenshot-1.png', path: `/wp-admin/user-edit.php?user_id=${LUCIA}`, from: 'diluxone-mail-profile-log', maxHeight: 760 },
  ],
  b: [
    { file: 'screenshot-6.png', path: '/wp-admin/admin.php?page=diluxone-mail-status' },
    { file: 'screenshot-5.png', path: '/wp-admin/admin.php?page=diluxone-mail', maxHeight: 1080 },
    { file: 'screenshot-2.png', path: '/wp-admin/admin.php?page=diluxone-mail-dns', maxHeight: 1040 },
  ],
};

const SHOTS = ALL[PHASE];


const CHROME_OFF = `
  document.querySelectorAll('#adminmenumain, #wpadminbar, #wpfooter, #screen-meta, #screen-meta-links, .notice, .update-nag, .welcome-panel').forEach(e => e.remove());
  const c = document.getElementById('wpcontent');
  if (c) { c.style.marginLeft = '0'; c.style.padding = '0 28px'; }
  const b = document.getElementById('wpbody');
  if (b) b.style.paddingTop = '0';
  document.documentElement.style.marginTop = '0';
  document.body.classList.remove('admin-bar');
  document.body.style.minHeight = '0';
`;

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1200 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([ page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('#wp-submit') ]);
  console.log('login ok');

  for (const s of SHOTS) {
    await page.goto(`${BASE}${s.path}`, { waitUntil: 'networkidle' });
    await page.evaluate(CHROME_OFF);
    if (s.css) await page.addStyleTag({ content: s.css });
    await page.waitForTimeout(600);

    let clip;

    if (s.from) {
      // The plugin's section on a WordPress profile: from its own <h2> down to
      // the bottom of its table, with the rest of the profile form left out.
      const head  = page.locator('h2', { hasText: 'Mail sent to this person' }).first();
      const table = page.locator('.diluxone-mail-profile-log').first();

      await head.scrollIntoViewIfNeeded();
      await page.evaluate(() => window.scrollBy(0, -40));
      await page.waitForTimeout(250);

      const h = await head.boundingBox();
      const t = await table.boundingBox();
      const PAD = 26;
      clip = { x: 0, y: Math.max(0, h.y - 12), width: 1440, height: Math.ceil(t.y + t.height - h.y) + 12 + PAD };
    } else {
      const box = await page.evaluate(() => {
        const el = document.querySelector('#wpbody-content .wrap') || document.querySelector('#wpbody-content');
        const r = el.getBoundingClientRect();
        return { top: r.top + window.scrollY, height: r.height };
      });
      const PAD = 26;
      let height = Math.ceil(box.height) + PAD * 2;
      if (s.maxHeight) height = Math.min(height, s.maxHeight);
      await page.evaluate((y) => window.scrollTo(0, y), Math.max(0, box.top - PAD));
      await page.waitForTimeout(250);
      clip = { x: 0, y: 0, width: 1440, height };
    }

    if (s.maxHeight) clip.height = Math.min(clip.height, s.maxHeight);

    await page.screenshot({ path: `${OUT}/${s.file}`, clip });
    const height = clip.height;
    console.log('→', s.file, height + 'px');
  }

  await browser.close();
})();
