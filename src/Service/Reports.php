<?php

namespace Supertext\Silverstripe\Service;

use Composer\InstalledVersions;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use TractorCow\Fluent\Model\Locale;
use Supertext\Silverstripe\Api\CallableTransport;
use Supertext\Silverstripe\Api\Reporting;
use Supertext\Silverstripe\PluginVersion;
use Supertext\Silverstripe\PluginHealth\InstallationReporter;
use Supertext\Silverstripe\PluginHealth\KeyCheck;
use Supertext\Silverstripe\PluginHealth\ReporterResult;
use Supertext\Silverstripe\Supertext;

/**
 * The module's side of the Supertext Commando Center: gives Api\Reporting what Silverstripe knows
 * (site URL, versions, the setting, the table SupertextState, Guzzle) and decides when it runs.
 *
 * - onRequest(): back-end requests that run the module (the Supertext section, the Translate
 *   action, the translate task), after the response has been sent.
 * - authenticationFailed(): a translation was rejected with 401/403.
 * - testConnection(): the "Test connection" button, waits for the check.
 * - reportCheck(): the check task.
 *
 * Silverstripe keeps the API key in the environment and the settings in YAML, so there is no
 * "settings saved": the key is checked on back-end requests when its fingerprint changed.
 * Nothing here ever throws.
 */
final class Reports
{
    /** For tests: replaces the Reporting built from the site's settings. */
    private static ?Reporting $override = null;

    private static bool $queued = false;

    public static function setReporting(?Reporting $reporting): void
    {
        self::$override = $reporting;
    }

    public static function reporting(): Reporting
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $settings = Supertext::singleton();
        $report = Environment::getEnv('SUPERTEXT_REPORT_URL');

        return new Reporting(
            new DatabaseStateStore(),
            $settings->versionReportsEnabled(),
            self::siteUrl(),
            PluginVersion::current(),
            self::systemVersion(),
            $settings->baseUrl(),
            new CallableTransport($settings->transport()),
            \is_string($report) && trim($report) !== '' ? trim($report) : null,
        );
    }

    /** The site's base URL as Silverstripe knows it (SS_BASE_URL, else the request), without trailing slash. */
    public static function siteUrl(): string
    {
        return rtrim(Director::absoluteBaseURL(), '/');
    }

    public static function callbackUrl(): string
    {
        return Reporting::callbackUrl(self::siteUrl());
    }

    /** The installed Silverstripe CMS version (silverstripe/cms, else silverstripe/framework), or null. */
    public static function systemVersion(): ?string
    {
        foreach (['silverstripe/cms', 'silverstripe/framework'] as $package) {
            try {
                if (InstalledVersions::isInstalled($package)) {
                    $version = InstalledVersions::getPrettyVersion($package);
                    if (\is_string($version) && $version !== '') {
                        return $version;
                    }
                }
            } catch (\Throwable) {
                // Composer's runtime data is missing: try the next package.
            }
        }

        return null;
    }

    /**
     * Back-end request that runs the module: version report and key check (only when the key
     * changed), once per request, after the response has been sent.
     */
    public static function onRequest(): void
    {
        if (self::$queued) {
            return;
        }
        self::$queued = true;
        self::afterResponse(static fn () => self::run(Reporting::ON_REQUEST));
    }

    /** A translation got 401/403: the key is marked invalid and reported if that changed. */
    public static function authenticationFailed(int $httpStatus): void
    {
        self::afterResponse(static fn () => self::run(Reporting::AUTHENTICATION_FAILED, $httpStatus));
    }

    /** "Test connection": checks the key now. */
    public static function testConnection(): ?KeyCheck
    {
        $result = self::run(Reporting::TEST_CONNECTION);

        return $result->check ?? self::fallbackCheck();
    }

    /** The check task: the key check and a `check` report now (if reports are on). */
    public static function reportCheck(): ?KeyCheck
    {
        $check = self::testConnection();
        self::run(Reporting::REPORT_CHECK);

        return $check;
    }

    public static function lastCheck(): ?KeyCheck
    {
        try {
            return self::reporting()->lastCheck();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * *Send configuration to Supertext*: the installed modules and themes and every Fluent locale's
     * Supertext code, now (only while reports are on).
     *
     * @return array{enabled: bool, sent: bool, extensions: int, languages: int}
     */
    public static function sendConfiguration(): array
    {
        $extensions = InstallationReporter::normalizeExtensions(self::installedExtensions());
        $languages = InstallationReporter::normalizeLanguages(self::languageMapping());
        $enabled = Supertext::singleton()->versionReportsEnabled();
        $result = $enabled ? self::reporting()->sendConfiguration($extensions, $languages) : null;

        return ['enabled' => $enabled, 'sent' => (bool) $result?->sent, 'extensions' => \count($extensions), 'languages' => \count($languages)];
    }

    /**
     * The installed Silverstripe modules and themes (Composer packages of the types silverstripe-vendormodule,
     * silverstripe-module and silverstripe-theme): name and version only. Installed modules are active.
     *
     * @return list<array{name: string, version: string, active: bool, type: string}>
     */
    public static function installedExtensions(): array
    {
        $list = [];
        foreach (['silverstripe-vendormodule' => 'module', 'silverstripe-module' => 'module', 'silverstripe-theme' => 'theme'] as $composerType => $type) {
            foreach (array_unique(InstalledVersions::getInstalledPackagesByType($composerType)) as $package) {
                $list[] = ['name' => $package, 'version' => (string) InstalledVersions::getPrettyVersion($package), 'active' => true, 'type' => $type];
            }
        }

        return $list;
    }

    /**
     * Every Fluent locale with the Supertext code it is translated into (null when it has none).
     *
     * @return list<array{code: string, supertext: ?string}>
     */
    public static function languageMapping(): array
    {
        $list = [];
        foreach (Locale::get() as $locale) {
            $list[] = ['code' => (string) $locale->Locale, 'supertext' => Supertext::languageCodeOrNull($locale)];
        }

        return $list;
    }

    public static function run(string $action, int $httpStatus = 401): ?ReporterResult
    {
        try {
            $result = self::reporting()->run($action, Supertext::singleton()->apiKey(), $httpStatus);
            if ($result?->event !== null) {
                self::logger()?->debug(sprintf('Supertext version report %s: %s', $result->event, $result->sent ? 'sent' : 'not sent'));
            }

            return $result;
        } catch (\Throwable $e) {
            self::logger()?->debug('Supertext version report skipped: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Runs $task after the response: at shutdown, after handing the response to the client where the
     * server allows it (PHP-FPM, LiteSpeed). On the command line it runs at the end of the task.
     */
    private static function afterResponse(callable $task): void
    {
        register_shutdown_function(static function () use ($task): void {
            try {
                if (!Director::is_cli()) {
                    if (\function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    } elseif (\function_exists('litespeed_finish_request')) {
                        litespeed_finish_request();
                    }
                }
                $task();
            } catch (\Throwable) {
                // Never let a version report break anything.
            }
        });
    }

    /** The key check without the store (only if the store failed). */
    private static function fallbackCheck(): ?KeyCheck
    {
        try {
            return KeyCheck::from(Supertext::singleton()->client()->checkApiKey());
        } catch (\Throwable) {
            return null;
        }
    }

    private static function logger(): ?LoggerInterface
    {
        try {
            return Injector::inst()->get(LoggerInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
