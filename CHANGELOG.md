# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/).

## Unreleased

### Added

- PHP 8.5: the unit tests run on PHP 8.3, 8.4 and 8.5 in CI (was 8.3 only; the module already allowed PHP 8.3 and later).
- **Translation review** on/off per locale (**Locales** → a locale → *Translation review*): translations into a locale with *Translation review* ticked go through Supertext's review service (Supertext Localization Review), where customers and Supertext linguists correct them on the website with a Chrome extension; corrections come back into Silverstripe with the next translation, and texts translated before keep their (reviewed) wording. Same API key, billing, languages and messages; other locales keep going directly to Supertext. New setting `review_url` (defaults to Supertext's review service; `''` turns review off everywhere; `SUPERTEXT_REVIEW_URL` wins when set). `sake db:build` adds the field `SupertextReview` to `Fluent_Locale` (off for existing locales). All four interface languages.
- Requests to Supertext name the module's version in the User-Agent (`Silverstripe-Supertext-Translation/<version>`).
- Export for translation (XLIFF 1.2 or HTML) and import of translated files; Supertext SDK 0.5.0. At the bottom of the Supertext tab: download a page's texts (page fields and content blocks) for one or more locales (XLIFF) or one locale (HTML), and import the translated file as drafts in its locale, with a summary (imported, skipped because the source changed, not translated, formatting changed, not in the file). No API key needed; uploads up to 10 MB, never stored; imports appear in the translation log. All new texts in English, German, French and Italian.
- French and Italian interface, and German where it was missing: messages from Supertext (limit reached, authentication failed, timeouts and others) now appear in the user's interface language too. The "no API key" and "authentication failed" messages now also link to Supertext account sign-up.
- The **Supertext** CMS section shows the installed plugin version under *Connection*, linked to its GitHub release notes for released versions.
- Automatic API key check: when the key in `SUPERTEXT_API_KEY` is new or changed, on *Test connection*, in `supertext-check` and when Supertext rejects the key during a translation. The Supertext section shows the latest result with its time (*the connection works*, *rejected*, *no key*, *Supertext can't be reached*, *error*, with the account and API key links where they help) in English, German, French and Italian. *Test connection* now shows that result.
- *Send version information to Supertext* (on by default; `version_reports: false` in YAML or `SUPERTEXT_VERSION_REPORTS=0` turns it off): after an update and when the key check result changes, the module sends the site URL, the module, Silverstripe CMS, PHP and SDK versions, whether a key is set, the key check result and the ping address to Supertext. No content, no API key and no personal data. Shown in the Supertext section; `supertext-check` sends a `check` report.
- Public ping endpoint `/supertext/ping` that only echoes Supertext's challenge, so Supertext can check the site is reachable (404 with version reports off).
- New table `SupertextState` for this state (run `sake db:build` after updating).
- Support: the Supertext support form is linked in the README, the user guide and GitHub's "New issue" page.

### Changed

- Tested with Silverstripe CMS 6.3 (released 2026-10-28): the demo and its CI check now run Silverstripe 6.3 (framework 6.3.3, cms 6.3.1, recipe-cms 6.3, admin 3.3, TinyMCE editor 1.2) with Fluent 8.2.3, Elemental 6.2.4 and Guzzle 7.16.1; the module already allowed any Silverstripe 6.
- **Default Supertext languages** (Supertext SDK 0.4.0): a locale without a *Supertext language* that isn't itself a Supertext code now uses the obvious default instead of failing: `de` → `de-DE`, `en` → `en-US`, `fr` → `fr-FR`, `it` → `it-IT`, `pt` → `pt-PT`, `zh_CN` → `zh-Hans`, `zh_TW` → `zh-Hant`, `es_ES` → `es`, `nb_NO` → `nb`. Locales without an obvious default (`en_IE`, `de_LI`, `gsw`) still need one. The locale's *Supertext language* combo box shows the suggestion as its first entry (*Default: German (Germany) (de-DE)*, else *— choose —*), lists the languages with names in your interface language, and only saves Supertext's codes. *Form of address* only appears for languages that offer it. On `sake db:build`, saved codes in another spelling (`de_ch`) are rewritten as Supertext's (`de-CH`); nothing configured is lost.
- Version reports, the API key check result and the reachability ping now use Supertext Plugin Health 0.1.0 (bundled in `lib/supertext-plugin-health`); Supertext SDK updated to 0.3.0 (now 0.4.0). No change for sites: settings and stored state stay as they are.
- **Languages are no longer guessed.** A locale that is a Supertext code (`de_CH`, `fr_CH`, `en_US`, `nl`, apart from case and `_`/`-`) is sent as that code; every other locale needs its *Supertext language* (Locales admin), which is now a list of Supertext's languages instead of a text field. The old default regions are gone: they were wrong for most languages (Supertext wants `es`, `sl`, `nl` without region, `zh-Hans` instead of `zh-CN`); the defaults above replace them. A locale without a Supertext language gets the message *has no Supertext language yet* (four languages) and nothing is sent for it; the Supertext section marks it *Not mapped*, the Supertext tab greys it out. The *Supertext language* serves as target and (without region) as source.
- Formal/informal for a language Supertext offers no tone for (English, Swiss German, Romansh, Albanian, Serbian) is flagged on the locale and in the Supertext section, and translating into it fails with *Supertext offers no formal or informal tone for this language* instead of sending it.
- All Supertext API calls now go through the official Supertext PHP SDK (0.2.1), bundled with the module (nothing extra to install).
- An unsupported language pair now has its own message, naming the codes ("Supertext does not support this language pair (en → xx-YY)"), in English, German, French and Italian.

## 0.1.0 — 2026-10-07

### Added

- First version for Silverstripe CMS 6 with Fluent 8.
- **Supertext** tab on pages: translate the current locale's saved draft into other locales, with "Already translated" / "Translated with Supertext on …" per locale and an explicit *Overwrite existing translations* option.
- Translates every text field Fluent localises (HTML as HTML) and those of the localised objects a page owns, such as Elemental blocks; new locales get a URL segment from the translated title. Translations are saved as drafts.
- Permission *Translate content with Supertext*; the user must be able to edit the page in each target locale (Fluent locale permissions apply).
- *Supertext language* and *Form of address* per locale in Fluent's Locales admin.
- **Supertext** CMS section: API key status, API address, *Test connection* (administrators), the locales and the translation log.
- Links to create a Supertext account and to generate the API key (supertext.com → Integrations → API, Admin role) in the **Supertext** section, in `supertext-check` when no key is set, and in the installation guide.
- Settings: `SUPERTEXT_API_KEY` (with or without the `Supertext-Auth-Key` prefix), `SUPERTEXT_API_URL`, environment, timeout and excluded fields in YAML.
- Tasks `supertext-translate` and `supertext-check`.
- Retries when the Supertext API answers HTTP 429 (rate limit).
- English and German CMS strings.
- Demo for Railway (`demo/`) with demo accounts, an Editors group, four locales and sample pages with Elemental blocks created on every start.
