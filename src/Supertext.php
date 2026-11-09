<?php

namespace Supertext\Silverstripe;

use GuzzleHttp\Client;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\PermissionProvider;
use Supertext\Silverstripe\Api\LanguageCodes;
use Supertext\Silverstripe\Api\SupertextClient;
use Supertext\Silverstripe\PluginHealth\InstallationReporter;
use TractorCow\Fluent\Model\Locale;

/**
 * Settings (YAML config plus environment variables) and the permission.
 *
 *   Supertext\Silverstripe\Supertext:
 *     environment: live        # live | staging | testing
 *     api_url: ''              # custom base URL; SUPERTEXT_API_URL wins
 *     review_url: 'https://…/v1/'  # Supertext's review service for locales with Translation review
 *                              # ('' = review off everywhere); SUPERTEXT_REVIEW_URL (non-empty) wins
 *     timeout: 180             # seconds per locale
 *     version_reports: true    # "Send version information to Supertext"; SUPERTEXT_VERSION_REPORTS wins
 *
 * The Supertext language of each Fluent locale is set on the locale (LocaleExtension): together
 * they are the language mapping (locale → Supertext code). A locale without one that is itself a
 * Supertext code (de_CH → de-CH) is sent as that code; any other locale has no Supertext language.
 * Translations into a locale with *Translation review* ticked (SupertextReview) go through review_url.
 *
 * The API key comes from the SUPERTEXT_API_KEY environment variable. Administrators create a
 * Supertext account at SIGNUP_URL and generate the key at API_KEY_URL (Admin role required).
 */
class Supertext implements PermissionProvider
{
    use Configurable;
    use Injectable;

    public const PERMISSION = 'SUPERTEXT_TRANSLATE';

    /** Log in to or create a Supertext account (by e-mail). */
    public const SIGNUP_URL = 'https://www.supertext.com/person/en/account/signin';

    /** Generate the API key: supertext.com → Integrations → API (Admin role in the Supertext account). */
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    private static string $environment = 'live';

    private static string $api_url = '';

    /** Supertext's review service, for locales with "Translation review" on ('' = off everywhere). */
    private static string $review_url = SupertextClient::REVIEW_URL;

    private static int $timeout = 180;

    private static float $poll_interval = 2.0;

    /** "Send version information to Supertext" (on by default); SUPERTEXT_VERSION_REPORTS=0 turns it off. */
    private static bool $version_reports = true;

    public function apiKey(): string
    {
        return SupertextClient::normalizeKey((string) Environment::getEnv('SUPERTEXT_API_KEY'));
    }

    public function baseUrl(): string
    {
        $url = (string) (Environment::getEnv('SUPERTEXT_API_URL') ?: static::config()->get('api_url'));

        return SupertextClient::baseUrlFor((string) static::config()->get('environment'), $url);
    }

    /** The review service's base URL: SUPERTEXT_REVIEW_URL if set (non-empty), else review_url ('' = review off). */
    public function reviewUrl(): string
    {
        $env = Environment::getEnv('SUPERTEXT_REVIEW_URL');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return trim((string) static::config()->get('review_url'));
    }

    public function timeout(): int
    {
        return max(10, (int) static::config()->get('timeout'));
    }

    /**
     * The language mapping: every locale whose "Supertext language" is set → that code.
     *
     * @return array<string, string>
     */
    public static function languageMapping(): array
    {
        $mapping = [];
        foreach (Locale::getCached() as $locale) {
            $code = trim((string) $locale->getField('SupertextCode'));
            if ($code !== '') {
                $mapping[(string) $locale->Locale] = $code;
            }
        }

        return $mapping;
    }

    /**
     * Supertext target code for a Fluent locale: its Supertext language, else the Supertext code equal to
     * the locale (de_CH → de-CH). No guessing: "de" or "es_ES" need a Supertext language.
     *
     * @throws Api\SupertextException unsupported_language
     */
    public static function languageCode(Locale $locale): string
    {
        return LanguageCodes::target((string) $locale->Locale, self::languageMapping());
    }

    /** The target code, or null when the locale has no Supertext language (status page, editor). */
    public static function languageCodeOrNull(Locale $locale): ?string
    {
        return LanguageCodes::targetOrNull((string) $locale->Locale, self::languageMapping());
    }

    /**
     * Supertext source code for a Fluent locale: the source form of its Supertext language or of the locale (en_US -> en).
     *
     * @throws Api\SupertextException unsupported_language
     */
    public static function sourceCode(Locale $locale): string
    {
        return LanguageCodes::source((string) $locale->Locale, self::languageMapping());
    }

    /** "more" (formal), "less" (informal) or "" (default). */
    public static function politeness(Locale $locale): string
    {
        $value = (string) $locale->getField('SupertextPoliteness');

        return in_array($value, ['more', 'less'], true) ? $value : '';
    }

    /** Whether translations into this locale go through Supertext's review service ("Translation review"). */
    public static function review(Locale $locale): bool
    {
        return (bool) $locale->getField('SupertextReview');
    }

    /** Whether version reports are on: the version_reports setting, overridden by SUPERTEXT_VERSION_REPORTS. */
    public function versionReportsEnabled(): bool
    {
        $env = Environment::getEnv('SUPERTEXT_VERSION_REPORTS');

        return InstallationReporter::reportsEnabled(
            (bool) static::config()->get('version_reports'),
            is_scalar($env) && $env !== false ? (string) $env : null
        );
    }

    /**
     * HTTP for the Supertext API and the version reports (Guzzle). The timeout is the request's own
     * (translation requests 60 s, the key check 10 s, a version report 3 s).
     *
     * @return callable(string, string, array<string, string>, ?string, float=): array{status: int, body: string, headers: array<string, string>}
     */
    public function transport(): callable
    {
        $http = new Client(['http_errors' => false, 'timeout' => 60, 'connect_timeout' => 15]);

        return static function (string $method, string $url, array $headers, ?string $body, float $timeout = 60.0) use ($http): array {
            // A GuzzleException (no connection) becomes the client's "Could not reach Supertext" error.
            $response = $http->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeout,
                'connect_timeout' => min(15.0, $timeout),
            ]);
            $out = [];
            foreach ($response->getHeaders() as $name => $values) {
                $out[strtolower($name)] = implode(', ', $values);
            }

            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody(), 'headers' => $out];
        };
    }

    /**
     * The Supertext client. Translations into a locale with *Translation review* use
     * client()->forReview(true); the key check, reports and translation files always use this one.
     */
    public function client(): SupertextClient
    {
        return new SupertextClient(
            $this->apiKey(),
            $this->baseUrl(),
            $this->transport(),
            $this->timeout(),
            (float) static::config()->get('poll_interval'),
            reviewUrl: $this->reviewUrl(),
        );
    }

    public function providePermissions(): array
    {
        return [
            self::PERMISSION => [
                'name'     => _t(self::class . '.PERMISSION', 'Translate content with Supertext'),
                'help'     => _t(
                    self::class . '.PERMISSION_HELP',
                    'Send pages to Supertext and save the translations in other locales. Editing the page and the locales is still required.'
                ),
                'category' => _t(self::class . '.PERMISSION_CATEGORY', 'Supertext'),
                'sort'     => 100,
            ],
        ];
    }
}
