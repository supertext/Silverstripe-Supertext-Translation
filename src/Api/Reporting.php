<?php

/**
 * @package     Supertext Translation for Silverstripe
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\Silverstripe\Api;

use Supertext\Silverstripe\PluginHealth\CallableKeyChecker;
use Supertext\Silverstripe\PluginHealth\InstallationReporter;
use Supertext\Silverstripe\PluginHealth\KeyCheck;
use Supertext\Silverstripe\PluginHealth\PingResponse;
use Supertext\Silverstripe\PluginHealth\ReporterResult;
use Supertext\Silverstripe\PluginHealth\ReporterState;
use Supertext\Silverstripe\Sdk\Http\HttpTransport;
use Supertext\Silverstripe\Sdk\Supertext;
use Supertext\Silverstripe\Sdk\V1\Client;
use Supertext\Silverstripe\Sdk\V1\Protocol;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check and the callback
 * ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md → Report protocol). The protocol
 * is Supertext Plugin Health's InstallationReporter (bundled in lib/supertext-plugin-health), the
 * key check is the SDK's Client::checkApiKey(); this class only wires them together, loads and
 * stores the state and makes sure nothing here ever throws into a request, a save or a translation.
 *
 * Has no Silverstripe dependencies: Service\Reports supplies the site URL, the versions, the
 * state store and the HTTP transport.
 */
final class Reporting
{
    /** The GitHub repository name, as the center expects it. */
    public const PLUGIN_NAME = 'Silverstripe-Supertext-Translation';

    public const SYSTEM_NAME = 'Silverstripe CMS';

    /** The ping route, relative to the site's base URL (_config/routes.yml). */
    public const PING_PATH = 'supertext/ping';

    public const ON_REQUEST = 'onRequest';
    public const TEST_CONNECTION = 'testConnection';
    public const AUTHENTICATION_FAILED = 'authenticationFailed';
    public const REPORT_CHECK = 'reportCheck';

    /**
     * @param bool                         $enabled   InstallationReporter::reportsEnabled() of the setting and the environment
     * @param string                       $siteUrl   the site's base URL
     * @param HttpTransport|null           $transport the SDK's HTTP transport, for the key check and the reports
     * @param string|null                  $reportUrl where reports go (SUPERTEXT_REPORT_URL); null: Plugin Health's default
     * @param (callable(float): void)|null $sleep     tests: the key check's 429 waits
     */
    public function __construct(
        private readonly StateStore $store,
        public readonly bool $enabled,
        private readonly string $siteUrl,
        private readonly string $pluginVersion,
        private readonly ?string $systemVersion,
        private readonly string $apiBaseUrl,
        private readonly ?HttpTransport $transport = null,
        private readonly ?string $reportUrl = null,
        private $sleep = null,
    ) {
    }

    /** The absolute URL of the ping route for a base URL ("https://example.com/" → "https://example.com/supertext/ping"). */
    public static function callbackUrl(string $siteUrl): string
    {
        return rtrim($siteUrl, '/') . '/' . self::PING_PATH;
    }

    /** The ping route's answer: 200 with the challenge, 400 for a bad one, 404 while reports are off. */
    public static function ping(mixed $challenge, bool $enabled): PingResponse
    {
        return InstallationReporter::pingResponse(\is_string($challenge) ? $challenge : null, self::PLUGIN_NAME, $enabled);
    }

    /** @param (callable(): iterable<mixed>)|null $languages the language mapping every report of this reporter carries */
    public function reporter(?callable $languages = null): InstallationReporter
    {
        return new InstallationReporter(
            enabled: $this->enabled,
            siteUrl: $this->siteUrl,
            pluginName: self::PLUGIN_NAME,
            pluginVersion: $this->pluginVersion,
            systemName: self::SYSTEM_NAME,
            keyChecker: new CallableKeyChecker(
                fn (#[\SensitiveParameter] string $key) => $this->client($key)->checkApiKey(),
                [Protocol::class, 'apiKeyFingerprint'],
            ),
            systemVersion: $this->systemVersion,
            sdkVersion: Supertext::SDK_VERSION,
            callbackUrl: self::callbackUrl($this->siteUrl),
            reportUrl: $this->reportUrl,
            transport: $this->transport !== null ? new PluginHealthTransport($this->transport) : null,
            languages: $languages,
        );
    }

    /** The SDK client for the key check: the configured endpoint and transport, User-Agent "<plugin>/<version>". */
    private function client(#[\SensitiveParameter] string $apiKey): Client
    {
        return new Client(
            $apiKey,
            baseUrl: $this->apiBaseUrl,
            userAgent: self::PLUGIN_NAME . '/' . $this->pluginVersion,
            transport: $this->transport,
            sleep: $this->sleep,
        );
    }

    /**
     * Runs one reporter action with the stored state and stores the new state. Never throws: null
     * if something failed (the state store, an unexpected error).
     *
     * @param string $action one of the constants ON_REQUEST, TEST_CONNECTION, AUTHENTICATION_FAILED, REPORT_CHECK
     */
    public function run(string $action, #[\SensitiveParameter] string $apiKey, int $httpStatus = 401): ?ReporterResult
    {
        try {
            $reporter = $this->reporter();
            $stored = $this->store->load();
            $state = ReporterState::from($stored);
            $result = match ($action) {
                self::TEST_CONNECTION => $reporter->testConnection($state, $apiKey),
                self::AUTHENTICATION_FAILED => $reporter->authenticationFailed($state, $apiKey, $httpStatus),
                self::REPORT_CHECK => $reporter->reportCheck($state),
                default => $reporter->onRequest($state, $apiKey),
            };
            $json = json_encode($result->state, JSON_UNESCAPED_SLASHES);
            if ($json !== false && $json !== $stored) {
                $this->store->save($json);
            }

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * *Send configuration to Supertext*: the installed modules and themes and the language mapping in
     * one `extensions` report, now (also after a recent failure), only while reports are on. Never
     * throws: null if something failed.
     *
     * @param list<array{name: string, version?: string, active?: bool, type?: string}> $extensions
     * @param list<array{code: string, supertext: ?string}>                            $languages
     */
    public function sendConfiguration(array $extensions, array $languages): ?ReporterResult
    {
        try {
            $stored = $this->store->load();
            $result = $this->reporter(static fn (): array => $languages)->sendExtensions(ReporterState::from($stored), $extensions);
            $json = json_encode($result->state, JSON_UNESCAPED_SLASHES);
            if ($json !== false && $json !== $stored) {
                $this->store->save($json);
            }

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The latest stored key check (for the Supertext section), null if none or unreadable. */
    public function lastCheck(): ?KeyCheck
    {
        try {
            return ReporterState::from($this->store->load())->keyCheck;
        } catch (\Throwable) {
            return null;
        }
    }
}
