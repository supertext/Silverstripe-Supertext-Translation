# Developer guide — Supertext Translation for Silverstripe

How the module is built, how to work on it, and how it is released and deployed.

## Architecture

A Silverstripe 6 vendor module (`supertext/silverstripe-supertext-translation`, namespace `Supertext\Silverstripe`) on top of Fluent 8.

```
Page editor: "Supertext" tab (SupertextPageExtension, on SiteTree)          client/supertext.js
   │ POST admin/pages/edit/EditForm/<id>  action_doSupertextTranslate  (SupertextTargets[], SupertextOverwrite)
   ▼
SupertextCMSMainExtension::doSupertextTranslate  ── SUPERTEXT_TRANSLATE + canEdit, source = current CMS locale
   ▼
Service\Translator::translate(record, source, targets, overwrite, member)
   │ collect(): in the source locale (draft), the record's localised text fields + those of the
   │            localised objects it owns (findOwned, e.g. Elemental blocks), recursively
   │ per target: skip if it exists (unless overwrite); check SUPERTEXT_TRANSLATE and canEdit inside the target locale
   ▼
Api\SupertextClient::translateSegments()   the bundled Supertext SDK: one <div data-st-id="N"> per value,
   │   documents below 900k characters, POST file → poll status → GET translation → DELETE
   │   (Guzzle through Api\CallableTransport)
   ▼
apply(): in the target locale (draft): set the fields on each owned object and the record, writeToStage(DRAFT);
         new locale → URLSegment from the translated title; Model\TranslationLog rows

Same tab, client/supertext.js (fetch with the form's SecurityID):
   POST admin/pages/edit/supertextExport/<id>  (source, format, targets[])  → file download
   POST admin/pages/edit/supertextImport       (file, include_changed, overwrite)  → JSON {html} summary
   SupertextCMSMainExtension → Translator::exportFile() / importFile() → Api\FileTransfer (SDK Files\TranslationFiles)
```

| Path | Responsibility |
| --- | --- |
| `src/Api/` | Wrapper around the Supertext SDK (`SupertextClient`, `CallableTransport`), language codes (`LanguageCodes`), the Commando Center glue (`Reporting`, `PluginHealthTransport`, `StateStore`), the translation files glue (`FileTransfer`, `FileTransferException`), exceptions. No Silverstripe classes (HTTP through a transport callable), unit-tested on their own |
| `lib/supertext-sdk/` | Bundled copy of the Supertext PHP SDK, namespace `Supertext\Silverstripe\Sdk` (API classes in `Sdk\V1`; see *Supertext SDK*) |
| `lib/supertext-plugin-health/` | Bundled copy of Supertext Plugin Health (PHP), namespace `Supertext\Silverstripe\PluginHealth` (see *Supertext Plugin Health*) |
| `src/Supertext.php` | Settings (YAML + `SUPERTEXT_API_KEY` / `SUPERTEXT_API_URL` / `SUPERTEXT_REVIEW_URL` / `SUPERTEXT_VERSION_REPORTS`), the language mapping, code, tone and *Translation review* per locale, the Guzzle transport and client, the permission `SUPERTEXT_TRANSLATE` |
| `src/Service/Reports.php` | Version reports and API key check: builds `Api\Reporting` from Silverstripe (base URL, versions, the setting, Guzzle, `DatabaseStateStore`) and runs it at the right moments (see *Commando Center*) |
| `src/Service/DatabaseStateStore.php`, `src/Model/SupertextState.php` | Table `SupertextState` (`Key`, `Value`): the reporter's JSON state under `reporter` |
| `src/Control/PingController.php` | Public `GET /supertext/ping` (route in `_config/routes.yml`) |
| `src/Service/Translator.php` | Field rules, collecting, translating, saving |
| `src/Extension/SupertextPageExtension.php` | The Supertext tab (fields are not saved into the record: `saveSupertext*()` no-ops) |
| `src/Extension/SupertextCMSMainExtension.php` | The form action on `CMSMain`, and the actions `supertextExport` / `supertextImport` (see *Translation files*) |
| `src/Extension/LocaleExtension.php` | `SupertextCode`, `SupertextPoliteness`, `SupertextReview` on Fluent's `Locale` |
| `src/Control/SupertextAdmin.php` | The *Supertext* CMS section: status, latest API key check, plugin version, *Test connection*, version reports on/off and ping address, locales (*Not mapped*, tone warnings), log |
| `src/PluginVersion.php` | The installed version from `Composer\InstalledVersions` (no second copy in the code) and its GitHub release link for `X.Y.Z` / `vX.Y.Z`; no Silverstripe classes, unit-tested |
| `src/Model/TranslationLog.php` | Table `SupertextTranslation`: one row per record and target locale and run |
| `src/Task/` | `sake tasks:supertext-translate --page=<id> --from=en_US [--to=de_CH,fr_CH] [--overwrite] [--member=<email>]`, `sake tasks:supertext-check` (key check, language mapping, `check` report) |
| `client/` | Tab script (overwrite option, busy state, submitting buttons outside the toolbar) and styles, exposed to `_resources` |
| `lang/` | English, German, French and Italian strings (`en.yml` from `sake tasks:i18nTextCollectorTask --module=supertext/silverstripe-supertext-translation`; `de.yml`, `fr.yml`, `it.yml` by hand) |
| `src/Service/Messages.php` | Shows a `SupertextException` from the API client in the user's language (by its `reason`), falling back to the client's English message |

The CMS only submits forms through buttons in the bottom toolbar (`LeftAndMain.EditForm.js`); `client/supertext.js` triggers the submit for the tab's *Translate* and the admin's *Test connection* button the same way.

### Field rules

- A record is translated if it has `FluentExtension` (or `FluentVersionedExtension`). Its candidate fields are Fluent's localised fields (`getLocalisedTables()`), so Fluent's `translate`, `field_include`, `field_exclude`, `data_include` and `data_exclude` apply.
- Excluded: `Translator.exclude_fields` (`URLSegment`, `ExtraMeta`, `ReportClass`, `ExtraClass`, `Style`) and the class's `supertext_exclude_fields`.
- `HTMLText`, `HTMLVarchar` (and subclasses) are sent as HTML; everything else as escaped text with `<br>` for line breaks.
- **Owned objects** (`findOwned(false)`, recursively up to `max_depth`): included if they are localised **and** have content of their own in the source locale. This covers Elemental areas and blocks with direct localisation (Fluent on `BaseElement`).
- **URL segment**: for a locale that didn't exist, `SiteTree::generateURLSegment()` of the translated title; Silverstripe keeps it unique. Existing locales keep theirs.
- Empty values are skipped; an empty translation never overwrites.
- **Existing locale** = `isDraftedInLocale()` (versioned) or `existsInLocale()`. Fluent stores a row per locale only when content was saved there, so "exists" means "has its own content".
- Everything is written to the **draft** stage; nothing is published.

Each value is one `data-st-id` element, so a whole HTML field (all its paragraphs, bold words and links) is translated in context.

## Translation files (export for translation, import)

SDK 0.5.0 adds `Files\TranslationFiles` (XLIFF 1.2 and HTML, outside `V1`, no API call; `scripts/vendor-sdk.sh` rewrites its namespace like the rest). The Supertext tab has *Export for translation* and *Import translation* below *Translate*; `client/supertext.js` POSTs them (with the edit form's `SecurityID`, so they aren't saved with the page) to two actions of `SupertextCMSMainExtension` on the page editor's controller:

- **Export** (`admin/pages/edit/supertextExport/<id>`, `source`, `format=xliff|html`, `targets[]`): `Translator::exportFile()` checks *Translate content with Supertext* and edit rights in the source locale, collects the values with `collect()` in the source locale (the same as for API translations) and hands them to `Api\FileTransfer::export()`. The response is the file (`Content-Disposition: attachment`, `application/x-xliff+xml` or `text/html; charset=utf-8`, named `silverstripe-<id>-<locale>.xlf`, `silverstripe-<id>.xlf` for several locales, `.html`); errors are JSON `{message}` with status 400.
- **Keys** are `<class>#<id>.<field>` of each collected value (`Translator::unitKey()`), e.g. `SilverStripe\CMS\Model\SiteTree#12.Title`, `DNADesign\Elemental\Models\ElementContent#7.HTML`.
- **Job metadata:** `plugin` = `Silverstripe-Supertext-Translation`; `reference` = `page:<page id>:<source locale>:<target locale>`, e.g. `page:12:en_US:de_CH` (`FileTransfer::reference()`/`parseReference()`); `sourceLanguage`/`targetLanguage` = `Supertext::sourceCode()`/`languageCodeOrNull()` (the locale's *Supertext language*, else exact match, else the default), else the locale with `-` (`FileTransfer::fallbackCode()`), so unmapped locales never block an export.
- **Import** (`admin/pages/edit/supertextImport`, `file`, `include_changed`, `overwrite`): uploads over 10 MB (`FileTransfer::MAX_UPLOAD_BYTES`, also PHP's `UPLOAD_ERR_INI_SIZE`) are refused before reading; the temporary upload is read in memory, never stored. `Translator::importFile()` → `FileTransfer::import()`: `TranslationFiles::read()`, then per job: `plugin` must be ours (*other system*), the reference must parse and the page exist (*not found*), both locales must exist (*no language*), the user needs *Translate content with Supertext* and edit rights in the target locale. The values are collected again in the source locale and passed to `importTranslations()`; `ok` (and `source_changed` with *Also import texts whose source changed since the export*) are written with `apply()`, the same write-back as API results (draft stage of the target locale, owned objects first, URL segment for a new locale), `untranslated`, `invalid_markup` and `missing` never. A locale with its own content is only replaced with *Overwrite existing translations* (else *skipped*), and keeps the fields the file doesn't translate. Each written job adds a `TranslationLog` row. The answer is JSON `{html}`: the summary per job, rendered with `_t()` (counts *Imported*, *Skipped, source changed*, *Not translated*, *Formatting changed*, *Not in the file*).
- Errors are `Api\FileTransferException` reasons, shown by `Service\Messages::file()` in the user's language.
- Tests: `tests/unit/FileTransferTest.php` (reference round trip, export keys and codes, import rules, glue errors, size limit); `tests/demo-check.sh` exports the sample page as HTML through the CMS endpoint, changes its title and imports it again (overwrite, skip, other system).

## Supertext API protocol

Shared with the WordPress plugin and every other Supertext CMS plugin:

1. `POST {base}translate/ai/file`: multipart with `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (a Supertext target code, e.g. `de-CH`, `es`), optional `source_lang` (bare language, e.g. `en`); see *Language codes*, optional `politeness` (`more`/`less`, only for targets that offer it). Returns `{file_id}`.
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal).
3. `GET …/{file_id}/translation` returns the translated HTML.
4. `DELETE …/{file_id}` (files also expire after 24 h).

Auth header: `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403). Supertext shows the key with the prefix, so the client strips a pasted `Supertext-Auth-Key ` and always sends exactly one. Base URLs: `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`. `GET features` is the cost-free key check (*Test connection*, `supertext-check`).

**Rate limit:** the API limits requests per second per key (HTTP 429). The client retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds plus jitter. Target locales are translated one after the other.

## Supertext SDK

The protocol above, the retries, the language list, the API key check (`Client::checkApiKey()`) and the `data-st-id` document come from the official Supertext PHP SDK ([Supertext/Supertext-API-SDK-and-Guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide)). The module ships a copy in `lib/supertext-sdk/` with its namespace changed from `Supertext\Sdk` to `Supertext\Silverstripe\Sdk` (Composer autoloads it from the module's `composer.json`; `tests/bootstrap.php` too), so sites install nothing extra and another package that requires the SDK itself can't clash with it. `lib/supertext-sdk/COMMIT` names the SDK revision (currently 0.5.0). Everything that talks to the API is in `Sdk\V1` (`Client`, `Languages`, `ApiKeyCheck`, …); only the HTTP transport (`Sdk\Http`), the translation files (`Sdk\Files`) and `Sdk\Supertext::SDK_VERSION` are version-neutral. The vendor script copies `src/` with its subfolders and rewrites every `Supertext\Sdk\…` namespace (`V1` included).

- Never edit `lib/supertext-sdk/`. Fix the SDK in its repository, then run `scripts/vendor-sdk.sh [ref]` (default `main`; `SDK_DIR=/path/to/clone` uses a local clone) and the tests.
- `src/Api/SupertextClient.php` keeps the module's interface (`translateDocument`, `translateSegments`, `validateApiKey`, `checkApiKey`, `forReview`, transport callable), sends the User-Agent `supertext-sdk-php Silverstripe-Supertext-Translation/<version>` (`PluginVersion::current()`, as in the reports) and turns every SDK error code into a `SupertextException` reason (`REASONS`); `Service\Messages::of()` translates it. A new SDK error code needs a reason there, a `match` arm in `Messages` and its strings in all four `lang/` files.
- PHPStan analyses `src/` only; the SDK has its own checks in its repository.

**Translation review** (`SupertextReview` on the Fluent locale, per target locale): `Translator::send()` takes `$client->forReview(Supertext::review($target))`, a second SDK client with the same key, transport, timeouts and User-Agent whose base URL is `review_url` (default `SupertextClient::REVIEW_URL`, the translation proxy of [Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation); `SUPERTEXT_REVIEW_URL` wins when not empty). It is created once per client, on first use. Each document is uploaded, polled, downloaded and deleted by the same client (the SDK's `translateHtml` round trip). The proxy speaks the same API v1, forwards to the live Supertext API with the module's key, stores every segment per key and serves reviewers' corrections on the next upload, so nothing else changes in the module. `review_url: ''` (or the same URL as the API) means `forReview()` always returns the direct client. The key check (*Test connection*, `supertext-check`, automatic), the reports and the translation files never use the review service. Tests: `SupertextClientTest` (all four calls to the review URL, other languages direct, off without a review URL or with the API URL, the default).

## Supertext Plugin Health

The version reports, the key check result they carry and the ping answer come from [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health), the plugin side of the Commando Center (MIT, no dependencies, in the Commando Center's repository under `plugin-health/`). It doesn't need the SDK: `src/Api/Reporting.php` passes it the SDK's key check (`CallableKeyChecker` over `Client::checkApiKey()` and `Protocol::apiKeyFingerprint()`) and `Sdk\Supertext::SDK_VERSION`, and `PluginHealthTransport` sends its reports through the module's transport (Guzzle). Its `ReporterState` is the JSON the SDK 0.1–0.2 reporter stored, so sites keep their state (no new `installed` report).

The module ships a copy in `lib/supertext-plugin-health/` (`src/`, `LICENSE`, `COMMIT`, `VERSION`, a short README) with its namespace changed from `Supertext\PluginHealth` to `Supertext\Silverstripe\PluginHealth`, autoloaded by the module's `composer.json` and `tests/bootstrap.php`, copied into the demo with the rest of `lib/`, and not analysed by PHPStan, like the SDK.

- Never edit `lib/supertext-plugin-health/`. Fix it in Commando-Center-Supertext-Translation → `plugin-health/`, then run `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.1.0`; default `main`; `PLUGIN_HEALTH_DIR=/path/to/clone` uses a local clone) and the tests.
- Segments missing from Supertext's answer are left untouched in the target locale.
- The live API answers an unknown language (`xx-YY`) with HTTP 404 `INVALID_LANGUAGE_PAIR`; the SDK reports it as `invalid_language_pair` with both codes, shown as "Supertext does not support this language pair (en → xx-YY)".

## Language codes

Supertext accepts exactly the codes its `GET features` lists (the SDK's `Languages::TARGETS` / `SOURCES`, from the live API): bare sources (`en`, `de`); targets in one exact form each, regional for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), with a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), bare for the others (`es`, `nl`, `sl`). Anything else is HTTP 404 `INVALID_LANGUAGE_PAIR`; formal/informal for a target without it (`en-US`, `rm`, `sq`, `sr-*`, `gsw-*`) is HTTP 400 `FEATURE_ERROR` (`unsupported_feature`).

**Defaults only where obvious.** `src/Api/LanguageCodes.php` (the SDK's `Languages`; no CMS dependency, `tests/unit/LanguageCodesTest.php`) resolves a Fluent locale with **one mapping for both directions**: `Supertext::languageMapping()` = every locale whose *Supertext language* (`Fluent_Locale.SupertextCode`) is set → that code.

- **Target:** the mapping wins (its value is sent as it is, even if the SDK's list doesn't know it); else the Supertext code equal to the locale apart from case and `_`/`-` (`de_CH` → `de-CH`, `nl` → `nl`); else the SDK's default (`Languages::defaultTarget()`, SDK 0.4.0: `de` → `de-DE`, `en` → `en-US`, `fr` → `fr-FR`, `it` → `it-IT`, `pt` → `pt-PT`, `zh`/`zh_CN`/`zh_SG` → `zh-Hans`, `zh_TW`/`zh_HK`/`zh_MO` → `zh-Hant`, `sr` → `sr-Latn`, a region of a bare target → the bare target: `es_ES` → `es`); else `unsupported_language` (reason `unsupported_language`, `{language}` = the locale) and nothing is sent for that locale. Nothing else is guessed: `en_IE`, `de_LI`, `gsw`, `sr_RS` need a mapping.
- **Source:** the mapped code in its source form (`de-CH` → `de`, SDK 0.2.1), else the locale's language if Supertext offers it as a source (`en_US` → `en`), else `unsupported_language`.
- **Tone:** `Translator::send()` refuses `more`/`less` for a target where `Languages::supportsPoliteness()` is false with `unsupported_feature`, before calling Supertext, so the administrator's setting isn't silently dropped.
- **UI:** the locale's *Supertext language* (`LocaleExtension`) is a `DropdownField` of `LanguageCodes::options()` (the SDK's `targetOptions()`, "Name (code)", names localized with PHP intl's `Locale::getDisplayName()` in the CMS interface language and sorted with `Collator`; Supertext's English name for `gsw-u-…` and without intl). Empty means the suggestion `Languages::suggestTarget()` (*Default: German (Germany) (de-DE)*) or, without one, *— choose —*. Saving stores Supertext's spelling (`onBeforeWrite`, `LanguageCodes::canonical()`); `updateValidate` refuses a code outside the list unless it is the one already saved. An older value not in the list is kept as an extra option. *Form of address* is shown only for targets with a tone (or when one is set, with a warning). **Upgrade:** `onRequireDefaultRecords` (`sake db:build`) rewrites saved codes in Supertext's spelling (`de_ch` → `de-CH`); empty values stay empty and now mean the default. The Supertext section shows the code per locale, *Not mapped*, and tone warnings; the Supertext tab greys out unmapped locales (*No Supertext language*); `supertext-check` lists the mapping and fails while a locale is unmapped.

`SupertextClient` sends the codes it is given; the stand-in (`tests/docs/stand-in.mjs`) accepts exactly the targets of the bundled SDK's list and bare sources, like the real API. The demo's locales (`en_US`, `de_CH`, `fr_CH`, `it_CH`) are Supertext codes and need no mapping.

## Commando Center: version reports, key check, ping

Protocol: [Commando-Center-Supertext-Translation](https://github.com/Supertext/Commando-Center-Supertext-Translation) → `docs/DEVELOPER.md` → *Report protocol*. Supertext Plugin Health's `InstallationReporter`, `reportsEnabled()` and `pingResponse()` implement it, with the SDK's `checkApiKey()` as key check (see *Supertext Plugin Health*); `src/Api/Reporting.php` loads and stores the reporter's state and never throws, `src/Service/Reports.php` supplies what Silverstripe knows.

- **Setting:** `Supertext.version_reports` (YAML, default `true`), overridden by `SUPERTEXT_VERSION_REPORTS` (read with `Environment::getEnv`, passed to `InstallationReporter::reportsEnabled()`). Off: no reports, ping 404. `SUPERTEXT_REPORT_URL` sends reports elsewhere (tests, the stand-in's `POST /report`).
- **Report fields:** plugin `Silverstripe-Supertext-Translation` with `PluginVersion::current()` (Composer); system `Silverstripe CMS` with the installed `silverstripe/cms` version (else `silverstripe/framework`); runtime PHP; site URL `Director::absoluteBaseURL()` (`SS_BASE_URL`, else the request); `callback.url` = `<site>/supertext/ping`. HTTP through Guzzle (`Supertext::transport()`, which honours the request's own timeout: 3 s for reports, 10 s for the key check).
- **When:** Silverstripe has no settings form for the module (key in the environment, settings in YAML), so there is no `afterSettingsSaved`. `onRequest` runs once per request on back-end requests that run the module: `SupertextAdmin::init()` and `Translator::translate()` (the Translate action and the translate task); it reports `installed`, `upgraded` or `api_key_check` and checks the key only when its fingerprint changed. It is queued with `register_shutdown_function` and runs after `fastcgi_finish_request()` / `litespeed_finish_request()` where available (PHP-FPM, LiteSpeed), so the response isn't delayed; under mod_php it runs at the end of the request (usually nothing to send). A translation that gets 401/403 runs `authenticationFailed` once per request. *Test connection* runs `testConnection` and shows the check (`Messages::keyCheck()`, the center's texts in four languages). `supertext-check` runs `testConnection`, then `reportCheck`.
- **State:** JSON in the table `SupertextState` (`Key` = `reporter`), via `DatabaseStateStore`. It holds the versions and key status last reported, the time of the last failed report and the latest key check with the key's SHA-256 fingerprint, never the key.
- **Ping:** `PingController` on `supertext/ping` (`_config/routes.yml`): a plain `Controller`, so no login, no session, no CSRF token; `HTTPCacheControlMiddleware::disableCache()`; status, headers and body exactly as `pingResponse()` returns them.

## Local development

The demo project in `demo/project` installs the module from `demo/module` (a Composer path repository), which `demo/stage-module.sh` fills with the module's files. Silverstripe 6 needs MySQL or MariaDB (there is no PostgreSQL adapter for Silverstripe 6).

```bash
demo/stage-module.sh
cd demo/project
composer install
cat > .env <<'ENV'
SS_DATABASE_CLASS="MySQLDatabase"
SS_DATABASE_SERVER="127.0.0.1"
SS_DATABASE_USERNAME="…"
SS_DATABASE_PASSWORD="…"
SS_DATABASE_NAME="ss_demo"
SS_ENVIRONMENT_TYPE="dev"
SS_BASE_URL="http://127.0.0.1:8096"
SUPERTEXT_API_KEY="…"
ENV
vendor/bin/sake db:build --flush
DEMO_ADMIN_EMAIL=you@example.com DEMO_ADMIN_PASSWORD='…' vendor/bin/sake tasks:supertext-demo-setup
php -S 127.0.0.1:8096 -t public ../router.php   # serves files, everything else through public/index.php
# CMS: http://127.0.0.1:8096/admin
```

After changing the module, run `demo/stage-module.sh` and `composer update supertext/silverstripe-supertext-translation` (or copy the changed files into `vendor/supertext/silverstripe-supertext-translation`) and flush.

To work without a real key, run the stand-in API (`node tests/docs/stand-in.mjs`) and set `SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/`. It returns real German, French and Italian for the demo's pages and `[de-CH] …`-prefixed text for anything else.

## Tests

```bash
composer install
vendor/bin/phpunit
```

- `tests/unit/SupertextClientTest.php`: the SDK wrapper: protocol, auth header and prefix, the review service client (`forReview`: all four calls to the review URL, other locales direct, off with an empty URL or the API URL, default `REVIEW_URL`), codes sent as given, 429 retries, error reasons (language pair, unsupported language and feature included), clean-up, segments (plain text, HTML, blank, missing), splitting into several documents, the key check, and that every SDK error code has a reason and a `Messages::of()` arm.
- `tests/unit/LanguageCodesTest.php`: exact matches, the SDK defaults, no guessed regions, the combo box's options and suggestion, saved-code spelling, the mapping for targets and sources, tone support.
- `tests/unit/ReportingTest.php`: the Commando Center glue with a fake API and a fake center: the `installed` report (fields, callback URL, 3 s timeout, never the key), nothing sent again, `upgraded`, a rejected key reported once, *Test connection*, the `check` report, reports off (key still checked), a missing key, a broken or garbled state store, a state stored by the SDK 0.2 reporter (kept as it is, no new report), the SDK version and the key check's User-Agent, the ping answers.
- `tests/unit/FileTransferTest.php`: the translation files glue: reference round trip, file names, export with the collector's keys and the locales' codes (unmapped locales as they are), HTML with one locale, nothing to translate, import (`ok` written, `source_changed` only with the option, `untranslated`/`invalid_markup`/`missing` not written, skipped write), another plugin's file, unknown reference, missing page or locale, broken files and the 10 MB limit.
- `tests/unit/LangFilesTest.php`: `de.yml`, `fr.yml` and `it.yml` have every string of `en.yml` with the same placeholders, tags and URLs, and `en.yml` has every `_t(self::class . '.KEY')` used in `src/`.
- `tests/demo-check.sh` (CI): the demo image on MySQL with the stand-in, started twice: demo accounts created once and never duplicated, no passwords in the log, the Editors group and its permissions, the locales, `supertext-check`, translation of the sample page as the editor into three locales (page fields, ASCII URL segments, block titles and HTML with markup), the skip on a second run, the log, an HTML export → import round trip through the Supertext tab's endpoints as the editor (download headers, overwrite, skip, another system's file), and the Commando Center: the public ping (answer, 400, `no-store`), exactly one `installed` report and a `check` report sent to the stand-in (`SUPERTEXT_REPORT_URL`, read back from its `GET /reports`) without the key, and the state in `SupertextState`.

CI (`.github/workflows/ci.yml`) on every push and pull request: **test** (PHP lint, PHPUnit), **phpstan** (see *Code quality and security checks*) and **demo** (builds `demo/Dockerfile`, runs `tests/demo-check.sh`).

## Demo (Railway)

The public demo is a container built from `demo/Dockerfile`: PHP 8.3 with Apache, Silverstripe 6.3 with Fluent, Elemental and this module, locales English (`en_US`, default), Deutsch (`de_CH`), Français (`fr_CH`) and Italiano (`it_CH`) with formal tone, and a sample page with three content blocks. It runs on Railway in the `supertext-cms-demos` project, service `Silverstripe`, region EU West (Amsterdam): <https://silverstripe-production.up.railway.app/> (CMS: `/admin`). Data lives in a `silverstripe` database on the project's MySQL service.

**Deploys:** Railway builds `main` of this repository (`railway.json` points it at `demo/Dockerfile`).

**What's in `demo/`:**

| Path | Purpose |
| --- | --- |
| `Dockerfile` | PHP 8.3 + Apache (document root `demo/project/public`), PHP extensions, the module copied to `demo/module`, Composer install of `demo/project` |
| `docker/entrypoint.sh` | Every start: `DATABASE_URL` → `SS_DATABASE_*` (database `SILVERSTRIPE_DB_NAME`), `SS_BASE_URL` from `RAILWAY_PUBLIC_DOMAIN`, `.env` for later `sake` calls, `sake db:build --flush` (creates the database if missing), `sake tasks:supertext-demo-setup`, Apache on `$PORT` |
| `router.php` | Router for PHP's built-in server (local development) |
| `stage-module.sh` | Copies the module's files to `demo/module` for local installs |
| `project/` | The Silverstripe project: `composer.json`/`.lock`, `app/_config/demo.yml` (Elemental on pages, Fluent on blocks), `app/templates/Page.ss` (layout with locale switcher) and `app/src/DemoSetupTask.php` |
| `_manifest_exclude` | Keeps Silverstripe's class manifest out of `demo/` (the folder stays in Git archives because Railway builds from one) |
| `.env.example` | The variables below |

**Demo setup** (`sake tasks:supertext-demo-setup`, every start, only adds what is missing): the four locales (formal tone for German, French, Italian), the group **Editors**, the demo accounts, and the English pages *Welcome* and *Swiss chocolate, shipped worldwide* (the installer's *About Us* and *Contact Us* are archived).

**No volume:** Railway's volume limit for the project is reached, and the demo needs none: everything is in MySQL. Uploaded files would disappear with the next deploy.

**Service variables:**

| Variable | |
| --- | --- |
| `DATABASE_URL` | `${{MySQL.MYSQL_URL}}`; the demo uses the database `SILVERSTRIPE_DB_NAME` (default `silverstripe`) on that server |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Administrator (member of *Administrators*) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor for automated tests and screenshots: member of **Editors** (*Access to Pages*, *Translate content with Supertext*, view draft content), no locale restrictions, so every locale. Silverstripe's default *Content Authors* group lacks the Supertext permission, so the demo creates this group. |
| `SUPERTEXT_API_KEY` | Supertext key |
| `SUPERTEXT_API_URL` | Optional, e.g. a stand-in API (to try the review service, tick *Translation review* in the locales instead) |
| `SUPERTEXT_REVIEW_URL` | Optional: another review service than Supertext's (`review_url`; empty keeps the default) |
| `SUPERTEXT_VERSION_REPORTS` | Optional; `0` turns *Send version information to Supertext* off (on by default; the demo reports like any site) |
| `SUPERTEXT_REPORT_URL` | Tests only: where version reports go instead of Supertext's center (CI: the stand-in) |
| `PORT` | Port Apache listens on (Railway sets it) |

Railway also sets `RAILWAY_PUBLIC_DOMAIN`; the entrypoint makes `SS_BASE_URL` from it and trusts the proxy (`SS_TRUSTED_PROXY_IPS=*`) for HTTPS.

**Demo accounts:** on every start the setup creates the `DEMO_ADMIN` and `DEMO_EDITOR` accounts if no member with that e-mail address exists. Existing accounts are never changed; change passwords in the CMS. Passwords must pass Silverstripe 6's password validator (entropy based: long, varied passwords); if one doesn't, that account is skipped with a warning naming the variable and Silverstripe's reason, and the demo still starts. Passwords are never logged. Silverstripe 6 has no web installer, so there is no first-run screen; without `DEMO_ADMIN_*` there is no administrator.

**Run it locally:**

```bash
docker build -f demo/Dockerfile -t supertext-silverstripe-demo .
docker run --rm -p 8080:8080 \
  -e DATABASE_URL=mysql://root:pass@host.docker.internal:3306/mysql \
  -e SS_BASE_URL=http://localhost:8080 \
  -e DEMO_ADMIN_EMAIL=you@example.com -e DEMO_ADMIN_PASSWORD='choose-a-long-one' \
  -e SUPERTEXT_API_KEY=… supertext-silverstripe-demo
# http://localhost:8080/admin
```

## Docs screenshots

The images in `docs/images/` are generated by `tests/docs/screenshots.mjs` (Playwright) from a freshly set-up demo (no translations yet) whose module talks to `tests/docs/stand-in.mjs`. The stand-in returns German, French and Italian for the demo's pages (`samples.json`, real Supertext output). Regenerate them whenever a screen they show changes:

```bash
cd tests/docs && npm install && npx playwright install chromium
npm run stand-in &
# a fresh demo (db:build + supertext-demo-setup with DEMO_* set), served on :8096 with
# SUPERTEXT_API_KEY=anything SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report
BASE_URL=http://127.0.0.1:8096 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
  DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run screenshots
```

The script shows the live API address instead of the stand-in's and the public demo's address (`SITE_URL`) instead of the local one (the ping address included). Point `SUPERTEXT_REPORT_URL` at the stand-in, so taking screenshots never reports to Supertext's center.

## Demo movie

`docs/images/demo.gif` (shown at the top of the README) is recorded by `tests/docs/movie.mjs` with Supertext's movie kit (`tests/docs/movie/`: Playwright screencast → ffmpeg; intro and outro cards, captions, cursor; Inter font under the SIL Open Font License in `movie/assets/fonts/`; `movie/silverstripe.mjs` signs in and shows the public demo's address and the live API instead of local ones). Like the screenshots, it needs a **fresh** demo whose module talks to the stand-in API, `ffmpeg` and, for the voice, Piper:

```bash
pip install piper-tts                       # the voice; without it (or with MOVIE_VOICE=0) the movie is silent
cd tests/docs && npm install
npm run stand-in &                          # http://127.0.0.1:8765/v1/
# a fresh demo (db:build + supertext-demo-setup with DEMO_* set), served on :8096 with
# SUPERTEXT_API_KEY=st-demo-0000-0000-0000 SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report
BASE_URL=http://127.0.0.1:8096 DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
```

About 43 seconds, 1280×720, an English voice and English captions: the demo editor opens the English page *Swiss chocolate*, opens its *Supertext* tab, keeps *Deutsch* and *Français* (unticks *Italiano*), clicks *Translate* (the waiting time is cut), switches the CMS to *Deutsch* and opens the German draft with its translated content blocks. The script writes the GIF (800 px, 12 fps, keep it under 3 MB) to `docs/images/demo.gif` and the MP4 to `tests/docs/movie/out/Silverstripe-Supertext-Translation.mp4` (git-ignored; `MOVIE_OUT` changes the path). The MP4 is not committed; the GIF is silent. The voice model (63 MB, pinned revision, checked by SHA-256) is downloaded once into `tests/docs/movie/voice-cache/` (git-ignored, never committed); the time spent generating speech is cut from the movie. `CHROMIUM_PATH` uses another Chromium than Playwright's. Re-record it in the same commit when the Supertext tab changes.

**Install and configuration movie** (`tests/docs/movie-install.mjs`, `npm run movie:install`): the same kit, voice and look, for administrators. Same setup as above (the demo's key should be the made-up `st-demo-0000-0000-0000`; the stand-in accepts any key), but signed in as the demo **administrator**:

```bash
BASE_URL=http://127.0.0.1:8096 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run movie:install
```

About 96 seconds: a terminal (`kit.terminal()`) with the commands from `docs/INSTALLATION.md` (`composer config repositories.supertext …`, `composer require …:dev-main`, the key in `.env` with the fake key `st-demo-0000-0000-0000`, `sake db:build --flush`; output shortened), where the key comes from (supertext.com → Integrations → API, Admin role; create an account at supertext.com), then the CMS: the *Supertext* section (*API key: Set (environment variable SUPERTEXT_API_KEY)*, *Test connection* → *the connection to Supertext works*), **Locales** → *Deutsch* (*Supertext language* German (Switzerland) (de-CH), *Translation review* left off), *Save*, and where editors find *Translate with Supertext* (a page's *Supertext* tab). No GIF; the MP4 goes to `tests/docs/movie/out/Silverstripe-Supertext-Translation-install.mp4` (git-ignored, not committed; `MOVIE_OUT` changes the path). Re-record it when the installation steps, the Supertext section or the locale's Supertext fields change.

Voice: Piper voice “alba” (en_GB), trained on a dataset from the University of Edinburgh's Centre for Speech Technology Research, CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/)

## Code quality and security checks

- **Checks** (`.github/workflows/checks.yml`): on every push and pull request, [actionlint](https://github.com/rhysd/actionlint) and [zizmor](https://docs.zizmor.sh) lint the workflows. On pull requests, dependency review fails a PR that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current); checkouts don't keep credentials, and workflows get `contents: read` unless a job needs more (the release job: `contents: write`).
- **Links** (`.github/workflows/links.yml`): [lychee](https://lychee.cli.rs) checks the links in all Markdown files weekly and whenever docs change on `main`. Broken links open or update the issue "Broken links in the docs" (a docs push that breaks links also fails). Links that can't work from CI go in `.lycheeignore` (one regex per line).
- **PHPStan** (job `phpstan` in `ci.yml`, config `phpstan.neon`): level 5 on the module's own code (`src/`), not the tests or `demo/`. [cambis/silverstan](https://github.com/cambis/silverstan) (a dev dependency) tells PHPStan about Silverstripe's configuration properties, extensions and injector. Locally: `composer install`, then `vendor/bin/phpstan analyse`. Existing findings that aren't simple to fix are listed in `phpstan-baseline.neon` (mostly Fluent's extension methods such as `existsInLocale()`, which Silverstripe adds at runtime; regenerate with `vendor/bin/phpstan analyse --generate-baseline` after fixing one). New code must not add findings.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as PR comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot PRs and the "Broken links in the docs" issue.

## Dependency updates

Dependabot (`.github/dependabot.yml`) opens weekly pull requests: minor and patch updates grouped into one, GitHub Actions in another, each major update on its own.

`.github/workflows/dependabot-merge.yml` merges a minor or patch package update by itself once every other check on its pull request has passed (it waits up to 85 minutes and leaves the pull request alone if a check fails). GitHub Actions updates and majors stay for a person: the workflow's built-in token can't merge changes to workflow files, and a major needs a decision. Merges by the workflow don't start CI on `main`; the weekly health check looks at `main`.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Check that if strings changed, `lang/en.yml` is regenerated with the text collector and `lang/de.yml`, `fr.yml` and `it.yml` match.
2. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
3. There is no version field to change: Composer takes the version from the Git tag the workflow creates.
4. Push to `main`. The workflow tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

Submitting the package to Packagist is planned.
## Conventions

- PSR-12, PHP 8.3, typed properties; keep `src/Api/` free of Silverstripe classes.
- User-visible strings through `_t()` with English, German, French and Italian in `lang/`; new or changed strings need all four in the same commit. Formal address (Sie, vous, Lei) and the CMS's own terms (*Seite/Entwurf*, *page/brouillon*, *pagina/bozza*).
- The API client (`src/Api/`, no Silverstripe classes, built on the bundled SDK) throws `SupertextException` with an English message and a `reason` (e.g. `limit_exceeded`); `Service\Messages::of()` maps it to a `_t()` string. A new reason needs a `match` arm there and its strings in all four files.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- No settings form: the API key and settings live in the environment and YAML, so the key is checked on back-end requests when it changed, not on save.
- Translation runs inside the editor's request (one locale after the other, up to `timeout` each). Planned: a queued job and a batch action for several pages.
- Pages (`SiteTree`) only; Fluent-localised DataObjects in ModelAdmin are not offered yet.
- Elemental with *indirect* localisation (a separate block area per locale) is not supported.
- Existing locales keep their URL segment when overwritten.
- Re-translating overwrites manual changes in the translated fields; no change detection. A translation memory and reviewers' corrections come from the review service when a locale has *Translation review* on; corrections reach Silverstripe only with the next translation (no push).
- Not on Packagist yet.
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
