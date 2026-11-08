#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) from a FRESH demo whose module talks to
 * stand-in.mjs (SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/), like screenshots.mjs. The editor opens
 * the English page "Swiss chocolate", translates it on its Supertext tab into German and French and
 * checks the German draft. See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://127.0.0.1:8096 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/
 * (git-ignored); MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/docs/movie/out/Silverstripe-Supertext-Translation.mp4, git-ignored; the MP4 is not committed).
 * CHROMIUM_PATH: a Chromium to use instead of Playwright's. Needs ffmpeg.
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
const out = process.env.MOVIE_OUT || here('movie/out/Silverstripe-Supertext-Translation.mp4')
const gif = here('../../docs/images/demo.gif')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
await publicAddresses(page, B)

// Sign in as the demo editor and open the English page (not recorded).
await signIn(page, B, need('DEMO_EDITOR_EMAIL'), need('DEMO_EDITOR_PASSWORD'))
await page.goto(`${B}/admin/pages/?l=en_US`)
const treeLink = (title) => page.locator('.jstree a', { hasText: title }).first()
const id = (await treeLink('Swiss chocolate').getAttribute('href')).match(/show\/(\d+)/)[1]
await page.goto(`${B}/admin/pages/edit/show/${id}?l=en_US`)
await page.locator('.cms-edit-form .nav-link', { hasText: 'Supertext' }).first().waitFor()
await page.waitForLoadState('networkidle')
await page.waitForTimeout(800)

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'silverstripe-movie-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const tab = (label) => page.locator('.cms-edit-form .nav-link', { hasText: label }).first()

// 1. The English page in the CMS.
await kit.say("Here's a page in English, “Swiss chocolate”, in the Silverstripe CMS.", { caption: 'A page in English: “Swiss chocolate”' })

// 2. The Supertext tab.
await kit.say('Open the Supertext tab of the page.', {
  caption: 'Open the <b>Supertext</b> tab',
  during: async () => {
    await kit.click(tab('Supertext'))
    await page.locator('button.supertext-translate').waitFor()
    await kit.sync()
  },
})

// 3. Pick the locales and start.
await kit.say('Keep German and French, and untick Italian.', {
  caption: 'Choose the locales: <b>Deutsch</b> and <b>Français</b>',
  during: async () => {
    await kit.hold(700)
    await kit.click(page.locator('.supertext-targets label', { hasText: 'Italiano' }).first())
  },
})
const translate = page.locator('button.supertext-translate')
await kit.say('Then click Translate.', { caption: 'Click <b>Translate</b>', during: () => kit.moveTo(translate), after: 0 })

// The stand-in answers at once: send the form only after the busy button has been seen.
let release
const released = new Promise((resolve) => (release = resolve))
await page.route(/\/admin\/pages\/edit\/EditForm/, async (route) => {
  if (route.request().method() === 'POST') await released
  await route.continue()
})
await page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
await kit.say('Supertext translates the content.', {
  caption: 'Supertext translates the content…',
  during: async () => {
    await translate.click()
    await kit.hold(1300)
    release()
  },
})
kit.pause()
await page.locator('.supertext-chip').first().waitFor({ timeout: 300_000 })
await page.waitForLoadState('networkidle')
await page.waitForTimeout(500)
kit.resume()
await kit.sync()

// 4. The result: switch to German and open the page.
await kit.say('A few seconds later, the German and French versions are there, saved as drafts.', { caption: 'Done: German and French, saved as drafts' })
const locales = page.locator('select.cms-fluent-selector-locales')
await kit.say("Let's switch to Deutsch and check the German page.", {
  caption: 'Switch the locale to <b>Deutsch</b>',
  during: async () => {
    await kit.click(page.locator('.cms-fluent-selector').first())
    kit.pause()
    await Promise.all([page.waitForURL(/l=de_CH|\/de_CH|de_CH/, { timeout: 60_000 }).catch(() => {}), locales.selectOption('de_CH')])
    await page.waitForLoadState('networkidle')
    if (!(await treeLink('Schweizer Schokolade').isVisible().catch(() => false))) await page.goto(`${B}/admin/pages/edit/show/${id}?l=de_CH`)
    await treeLink('Schweizer Schokolade').waitFor()
    await page.waitForTimeout(600)
    kit.resume()
    await kit.sync()
    await kit.hold(400)
    await kit.click(treeLink('Schweizer Schokolade'))
    await page.locator('.cms-edit-form input[name=Title]').waitFor()
    await page.waitForLoadState('networkidle')
    await kit.sync()
  },
})
await kit.say('The German version is ready. Review it, and publish it as usual.', {
  caption: 'The German version is ready – review it and publish',
  during: async () => {
    await kit.hold(600)
    await page.locator('.cms-content-fields').first().evaluate((el) => el.scrollBy({ top: 200, behavior: 'smooth' })).catch(() => {})
  },
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Silverstripe', repo: 'Silverstripe-Supertext-Translation', gif })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`)
