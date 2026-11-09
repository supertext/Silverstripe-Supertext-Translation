#!/usr/bin/env node
/**
 * Regenerates docs/images from a freshly set-up demo (no translations yet) whose module talks to
 * stand-in.mjs (SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/). See docs/DEVELOPER.md.
 *
 *   BASE_URL (default http://127.0.0.1:8096)
 *   DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD     Supertext admin, locales
 *   DEMO_EDITOR_EMAIL / DEMO_EDITOR_PASSWORD   translating (Editors group)
 */
import { chromium } from 'playwright'

const B = process.env.BASE_URL || 'http://127.0.0.1:8096'
const OUT = new URL('../../docs/images/', import.meta.url).pathname
const LIVE_API = 'https://api.supertext.com/v1/'
/** The public demo's address, shown instead of the local one. */
const SITE = process.env.SITE_URL || 'https://silverstripe-production.up.railway.app'
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()

const browser = await chromium.launch()

async function session(email, password) {
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 860 }, deviceScaleFactor: 1, locale: 'en-US' })).newPage()
  await page.goto(`${B}/Security/login`)
  await page.fill('input[name=Email]', email)
  await page.fill('input[name=Password]', password)
  await page.click('[type=submit]')
  await page.waitForLoadState('networkidle')
  return page
}

async function go(page, path) {
  await page.goto(B + path)
  await page.waitForLoadState('networkidle')
  await page.addStyleTag({ content: '*{animation:none!important;transition:none!important}' })
  await page.waitForTimeout(800)
}

/** Shows the public demo's address and the live API instead of local ones. */
async function publicUrls(page) {
  await page.evaluate(({ local, site, live }) => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (/27\.0\.0\.1:\d+/.test(n.nodeValue)) {
        n.nodeValue = n.nodeValue.replaceAll(local, site).replace(/(\.\.\.|…)1?27\.0\.0\.1:\d+/, '...' + site.replace('https://', '').slice(-24))
      }
    }
    document.querySelectorAll('[data-supertext-endpoint]').forEach((c) => { c.textContent = live })
  }, { local: B, site: SITE, live: LIVE_API })
}

/**
 * Dates and times of the run ("Translated with Supertext on Oct 17, 2026", "checked …") would
 * change the images on every run: they always show the same moment.
 */
async function freezeDates(page) {
  await page.evaluate(() => {
    const rules = [
      [/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]* \d{1,2}, \d{4}\b/g, 'Oct 6, 2026'],
      [/\b\d{1,2}\/\d{1,2}\/\d{4}\b/g, '06/10/2026'],
      [/\b\d{4}-\d{2}-\d{2}\b/g, '2026-10-06'],
      [/\b\d{1,2}:\d{2}(:\d{2})?\b/g, '9:30'],
    ]
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      let v = n.nodeValue
      for (const [re, fixed] of rules) v = v.replace(re, fixed)
      if (v !== n.nodeValue) n.nodeValue = v
    }
  })
}

const panel = (page) => page.locator('.cms-content').first()
async function shot(page, locator, name) {
  await freezeDates(page)
  const r = await locator.boundingBox()
  await page.screenshot({ path: OUT + name, clip: { x: r.x, y: r.y, width: r.width, height: Math.min(r.height, 640) } })
}

async function pageId(page) {
  await go(page, '/admin/pages/?l=en_US')
  const href = await page.locator('.jstree a', { hasText: 'Swiss chocolate' }).first().getAttribute('href')
  return href.match(/show\/(\d+)/)[1]
}

// --- Editor ------------------------------------------------------------------------------
{
  const page = await session(need('DEMO_EDITOR_EMAIL'), need('DEMO_EDITOR_PASSWORD'))
  const id = await pageId(page)
  await go(page, `/admin/pages/edit/show/${id}?l=en_US`)
  await page.locator('.cms-edit-form .nav-link', { hasText: 'Supertext' }).first().click()
  await page.waitForTimeout(600)
  await shot(page, panel(page), '01-supertext-tab.png')

  await page.locator('button.supertext-translate').click()
  await page.locator('.supertext-chip').first().waitFor({ timeout: 60_000 })
  await page.waitForTimeout(800)
  await shot(page, panel(page), '02-translated.png')

  // Ticking a locale that exists shows the overwrite option.
  await page.locator('.supertext-targets input[value="de_CH"]').check()
  await page.locator('.field.supertext-overwrite:not(.supertext-hidden)').waitFor()
  await page.mouse.click(1100, 700)
  await shot(page, panel(page), '03-overwrite-warning.png')

  // The German draft in the CMS
  await go(page, `/admin/pages/edit/show/${id}?l=de_CH`)
  await page.locator('.cms-edit-form .nav-link', { hasText: 'Main content' }).first().click()
  await page.waitForTimeout(600)
  await publicUrls(page)
  await shot(page, panel(page), '04-german-page.png')

  // Publish it and show the German front end
  await page.locator('button[name=action_publish]').click()
  await page.waitForTimeout(3000)
  await go(page, '/de/schweizer-schokolade-weltweit-versandt')
  await page.screenshot({ path: OUT + '05-german-frontend.png', clip: { x: 240, y: 0, width: 800, height: 620 } })
}

// --- Admin -----------------------------------------------------------------------------
{
  const page = await session(need('DEMO_ADMIN_EMAIL'), need('DEMO_ADMIN_PASSWORD'))
  await go(page, '/admin/supertext/')
  await page.locator('button.supertext-test').click()
  await page.locator('.toast, .notice-item').first().waitFor({ timeout: 30_000 })
  await page.waitForTimeout(500)
  // Clicking the button scrolls it into view; show the section from the top (Connection).
  await page.evaluate(() => document.querySelectorAll('.cms-content *').forEach((e) => { if (e.scrollTop) e.scrollTop = 0 }))
  await page.mouse.move(1200, 800)
  await page.waitForTimeout(300)
  await publicUrls(page)
  await shot(page, panel(page), '06-supertext-admin.png')

  await go(page, '/admin/locales/')
  await shot(page, panel(page), '07-locales.png')
  await page.locator('.ss-gridfield-item', { hasText: 'de_CH' }).first().click()
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(800)
  const code = page.locator('[id$="SupertextCode_Holder"]').first()
  // Down to the Translation review checkbox (below Form of address).
  const last = page.locator('[id$="SupertextReview_Holder"]').first()
  await last.scrollIntoViewIfNeeded()
  const a = await code.boundingBox()
  const z = await last.boundingBox()
  await page.screenshot({ path: OUT + '08-locale-settings.png', clip: { x: a.x, y: a.y - 8, width: a.width, height: z.y + z.height - a.y + 16 } })
}

await browser.close()
console.log(`Screenshots written to ${OUT}`)
