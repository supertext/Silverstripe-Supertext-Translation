#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, no GIF) from a FRESH demo whose module talks
 * to stand-in.mjs, like movie.mjs. An administrator installs the module (terminal: Composer, the API key
 * in .env, db:build), checks the connection in the Supertext section, sets the German locale's Supertext
 * language and shows where editors find "Translate with Supertext". See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8096 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run movie:install
 *
 * The demo's API key should be a made-up one (the stand-in accepts any key); the terminal shows
 * st-demo-0000-0000-0000. MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/docs/movie/out/Silverstripe-Supertext-Translation-install.mp4, git-ignored). Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'
import { publicAddresses, signIn } from './movie/silverstripe.mjs'

const B = (process.env.BASE_URL || 'http://127.0.0.1:8096').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT || here('movie/out/Silverstripe-Supertext-Translation-install.mp4')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()
const FAKE_KEY = 'st-demo-0000-0000-0000'

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
await publicAddresses(page, B)

// Sign in as the demo administrator (not recorded): configuration is an administrator's task.
await signIn(page, B, need('DEMO_ADMIN_EMAIL'), need('DEMO_ADMIN_PASSWORD'))
const settle = async () => { await page.waitForLoadState('networkidle', { timeout: 1500 }).catch(() => {}); await page.waitForTimeout(500); await kit.sync() }
const menu = (label) => page.locator('.cms-menu a', { hasText: new RegExp(`^\\s*${label}\\s*$`) }).first()
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])

// The sample page, for the last step.
await page.goto(`${B}/admin/pages/?l=en_US`)
const pageId = (await page.locator('.jstree a', { hasText: 'Swiss chocolate' }).first().getAttribute('href')).match(/show\/(\d+)/)[1]

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'silverstripe-movie-install-'))
await page.goto('about:blank')
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })

/** More commands in the terminal kit.terminal() opened (same look; kit.terminal() would start a new one). */
async function typeMore(lines) {
  await page.evaluate(() => document.querySelector('#x > .p.c:last-child')?.remove())
  for (const l of lines) {
    if (l.cmd !== undefined) {
      await page.evaluate(() => { const x = document.getElementById('x')
        const p = document.createElement('span'); p.className = 'p'; p.textContent = '$ '; x.appendChild(p)
        const c = document.createElement('span'); c.className = 'c'; x.appendChild(c) })
      for (const ch of l.cmd) { await page.evaluate((ch) => { document.querySelector('#x .c').textContent += ch }, ch); await page.waitForTimeout(38) }
      await page.waitForTimeout(450)
      await page.evaluate(() => { document.querySelector('#x .c').classList.remove('c'); document.getElementById('x').append('\n') })
    } else {
      await page.evaluate((o) => { const s = document.createElement('span'); s.className = 'o'; s.textContent = o + '\n'; document.getElementById('x').appendChild(s) }, l.out ?? '')
      await page.waitForTimeout(l.wait ?? 180)
    }
  }
  await page.evaluate(() => { const c = document.createElement('span'); c.className = 'p c'; c.textContent = '$ '; document.getElementById('x').appendChild(c) })
}

// 1. Terminal: the commands from docs/INSTALLATION.md (output shortened).
await kit.say('First, in your Silverstripe project, tell Composer where to find the Supertext module, on GitHub.', {
  caption: 'Add the repository to <b>Composer</b>',
  during: () => kit.terminal({ title: 'my-site', lines: [
    { cmd: 'composer config repositories.supertext vcs https://github.com/Supertext/Silverstripe-Supertext-Translation' },
  ] }),
})
await kit.say('Then require the module, and Composer installs it.', {
  caption: 'Add the module: <b>composer require</b>',
  during: () => typeMore([
    { cmd: 'composer require supertext/silverstripe-supertext-translation:dev-main' },
    { out: './composer.json has been updated' },
    { out: 'Package operations: 1 install, 0 updates, 0 removals' },
    { out: '  - Installing supertext/silverstripe-supertext-translation (dev-main)' },
  ]),
})
await kit.say('Generate your API key at supertext.com, under Integrations, A P I. You need the Admin role. No account yet? Create one at supertext.com. Then put the key in your dot env file.', {
  caption: 'API key: <b>supertext.com → Integrations → API</b><br>No account yet? Create one at <b>supertext.com</b>',
  during: async () => { await kit.hold(1200); await typeMore([{ cmd: `echo 'SUPERTEXT_API_KEY="${FAKE_KEY}"' >> .env` }]) },
})
await kit.say('Then build the database. This adds two tables, and the Supertext fields of the Fluent locales.', {
  caption: 'Build the database: <b>sake db:build --flush</b>',
  during: () => typeMore([
    { cmd: 'vendor/bin/sake db:build --flush' },
    { out: 'Creating database tables' },
    { out: ' * Fluent_Locale' },
    { out: ' * SupertextState' },
    { out: ' * SupertextTranslation' },
    { out: 'Database build completed!' },
  ]),
})
await kit.hold(300)

// 2. The CMS as administrator: the Supertext section (loading time cut).
kit.pause()
await page.goto(`${B}/admin/pages/`)
await page.waitForLoadState('networkidle')
await page.waitForTimeout(600)
await kit.sync()
kit.resume()
await kit.say('Now sign in to the CMS as an administrator, and open Supertext in the menu.', {
  caption: 'In the CMS menu: <b>Supertext</b>',
  during: async () => {
    await kit.hold(600)
    await kit.click(menu('Supertext'))
    await page.locator('button.supertext-test').waitFor()
    await settle()
  },
})
await kit.say('The API key comes from the environment variable. ', {
  caption: 'API key: <b>Set (environment variable SUPERTEXT_API_KEY)</b>',
  during: async () => { await kit.hold(300); await kit.moveTo(page.getByText('Set (environment variable', { exact: false }).first()) },
})
await kit.say('Click Test connection. The API key check shows that the connection to Supertext works.', {
  caption: 'Click <b>Test connection</b>: <b>the connection to Supertext works</b>',
  during: async () => {
    await kit.click(page.locator('button.supertext-test'))
    await page.locator('.toast, .notice-item').filter({ hasText: 'the connection to Supertext works' }).first().waitFor({ timeout: 30_000 })
    await kit.sync()
    await page.evaluate(() => document.querySelectorAll('.cms-content *').forEach((e) => { if (e.scrollTop) e.scrollTop = 0 }))
  },
})

// 3. Language setup: the German locale.
await kit.say("Each Fluent locale has its own Supertext language. Open Locales in the menu, and then the locale Deutsch, which is German for Switzerland.", {
  caption: '<b>Locales</b> → open <b>Deutsch</b>',
  during: async () => {
    await kit.click(menu('Locales'))
    const row = page.locator('.ss-gridfield-item', { hasText: 'de_CH' }).first()
    await row.waitFor()
    await settle()
    await kit.hold(500)
    await kit.click(row.locator('td', { hasText: 'Deutsch' }).first())
    await page.locator('[id$="SupertextCode_Holder"]').waitFor()
    await settle()
  },
})
const code = page.locator('[id$="SupertextCode_Holder"]').first()
const chosen = code.locator('.chosen-container').first()
await kit.say('Further down, the Supertext language is preselected: German, Switzerland.', {
  caption: '<b>Supertext language</b>: preselected',
  during: async () => {
    await code.evaluate((el) => el.closest('.cms-content-fields, .panel--scrollable, .cms-panel-padded')?.scrollBy({ top: el.getBoundingClientRect().top - 180, behavior: 'smooth' }))
    await kit.hold(900)
    await kit.moveTo(chosen)
  },
})
await kit.say('Open it to pick another variant if you need one. We choose German, Switzerland.', {
  caption: 'Choose <b>German (Switzerland) (de-CH)</b>',
  during: async () => {
    await kit.click(chosen)
    const search = chosen.locator('.chosen-search input').first()
    if (await search.isVisible().catch(() => false)) await search.pressSequentially('German', { delay: 90 })
    await kit.hold(600)
    const option = chosen.locator('li.active-result', { hasText: /^German \(Switzerland\) \(de-CH\)$/ }).first()
    await kit.moveTo(option)
    await option.hover()
    await kit.hold(400)
    await ripple()
    await option.click()
    await kit.hold(300)
  },
})
const review = page.locator('[id$="SupertextReview_Holder"]').first()
await kit.say('Translation review can be switched on per locale. We leave it off.', {
  caption: '<b>Translation review</b>: optional, per locale (left off)',
  during: async () => {
    await review.evaluate((el) => el.closest('.cms-content-fields, .panel--scrollable, .cms-panel-padded')?.scrollBy({ top: el.getBoundingClientRect().top - 360, behavior: 'smooth' }))
    await kit.hold(700)
    await kit.moveTo(review.locator('label').first())
  },
})
const save = page.locator('button[name=action_doSave]').first()
await kit.say('Save, and do the same for French and Italian.', {
  caption: 'Click <b>Save</b>, then the same for the other locales',
  during: async () => {
    await kit.moveTo(save)
    await ripple()
    await Promise.all([page.waitForResponse((r) => r.request().method() === 'POST'), save.click()])
    await settle()
    await kit.hold(500)
  },
})

// 4. Where editors find it.
kit.pause()
await page.goto(`${B}/admin/pages/edit/show/${pageId}?l=en_US`)
const tab = page.locator('.cms-edit-form .nav-link', { hasText: 'Supertext' }).first()
await tab.waitFor()
await settle()
await kit.hold(1000)
kit.resume()
await kit.say("That's it. Editors now find Translate with Supertext on the Supertext tab of every page, here on Swiss chocolate.", {
  caption: 'Done: editors find <b>Translate with Supertext</b> on a page’s <b>Supertext</b> tab',
  during: async () => {
    await kit.click(tab)
    await page.locator('button.supertext-translate').waitFor()
    await kit.sync()
    await kit.moveTo(page.locator('button.supertext-translate'))
  },
  after: 300,
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Silverstripe', repo: 'Silverstripe-Supertext-Translation', kind: 'install' })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out}`)
