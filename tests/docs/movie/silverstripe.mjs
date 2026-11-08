// Helpers for the movie scripts (movie.mjs, movie-install.mjs): sign in, and keep local addresses out of every frame.

const LIVE_API = 'https://api.supertext.com/v1/'
/** The public demo's address, shown instead of the local one. */
const SITE = process.env.SITE_URL || 'https://silverstripe-production.up.railway.app'

/** Shows the public demo's address and the live API instead of the local ones, on every page and after every CMS (PJAX) update. */
export async function publicAddresses(page, base) {
  const host = new URL(base).host
  await page.addInitScript(({ host, site, live }) => {
    const fix = () => {
      if (!document.body) return
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
      for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        if (n.nodeValue.includes(host) || /27\.0\.0\.1:\d|localhost:\d/.test(n.nodeValue)) {
          n.nodeValue = n.nodeValue.replaceAll('http://' + host, site).replaceAll(host, site.replace('https://', ''))
            .replace(/(\.\.\.|…)[a-z:\/]*1?27\.0\.0\.1:\d+/, '...' + site.replace('https://', '').slice(-24))
            .replace(/https?:\/\/(127\.0\.0\.1|localhost):\d+\/v1\//, live)
            .replace(/(https?:\/\/)?1?27\.0\.0\.1:\d+|(https?:\/\/)?localhost:\d+/g, site.replace('https://', ''))
        }
      }
      document.querySelectorAll('[data-supertext-endpoint]').forEach((c) => { if (c.textContent !== live) c.textContent = live })
    }
    const start = () => { fix(); new MutationObserver(fix).observe(document.body, { childList: true, subtree: true, characterData: true }) }
    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start)
  }, { host, site: SITE, live: LIVE_API })
}

export async function signIn(page, base, email, password) {
  await page.goto(`${base}/Security/login`)
  await page.fill('input[name=Email]', email)
  await page.fill('input[name=Password]', password)
  await page.click('[type=submit]')
  await page.waitForLoadState('networkidle')
}
