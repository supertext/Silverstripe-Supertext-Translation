<?php

namespace Supertext\Silverstripe\Tests\unit;

use PHPUnit\Framework\TestCase;
use Supertext\Silverstripe\Api\CallableTransport;
use Supertext\Silverstripe\Api\Reporting;
use Supertext\Silverstripe\Api\StateStore;
use Supertext\Silverstripe\Sdk\Supertext;
use Supertext\Silverstripe\Sdk\V1\Protocol;

/**
 * The module's Commando Center glue: Supertext Plugin Health's InstallationReporter with the SDK's
 * key check and the module's state store,
 * against a fake Supertext API and a fake center (never the live ones).
 */
final class ReportingTest extends TestCase
{
    private const KEY = 'secret-test-key-123';
    private const CENTER = 'https://center.test/api/v1/report';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    private array $calls = [];

    private int $apiStatus = 200;

    private ArrayStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayStore();
    }

    private function reporting(bool $enabled = true, string $version = '0.3.0', ?StateStore $store = null): Reporting
    {
        $transport = new CallableTransport(function (string $method, string $url, array $headers, ?string $body, float $timeout): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body', 'timeout');
            if ($url === self::CENTER) {
                return ['status' => 202, 'body' => '{"ok":true}'];
            }

            return ['status' => $this->apiStatus, 'body' => '{}'];
        });

        return new Reporting(
            $store ?? $this->store,
            $enabled,
            'https://cms.example.ch/',
            $version,
            '6.1.0',
            'https://api.test/v1/',
            $transport,
            self::CENTER,
            static function (): void {
            },
        );
    }

    /** @return list<array<string, mixed>> */
    private function reports(): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            if ($call['url'] === self::CENTER) {
                $out[] = json_decode((string) $call['body'], true);
            }
        }

        return $out;
    }

    public function testFirstRequestChecksTheKeyAndReportsTheInstallation(): void
    {
        $result = $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);

        self::assertNotNull($result);
        self::assertSame('installed', $result->event);
        self::assertTrue($result->sent);
        self::assertSame('ok', $result->check?->status);
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertSame('https://api.test/v1/features', $this->calls[0]['url']);
        self::assertStringContainsString('Silverstripe-Supertext-Translation/0.3.0', $this->calls[0]['headers']['User-Agent'] ?? '');

        $report = $this->reports()[0];
        self::assertSame('installed', $report['event']);
        self::assertSame('https://cms.example.ch/', $report['site_url']);
        self::assertSame(['name' => 'Silverstripe-Supertext-Translation', 'version' => '0.3.0'], $report['plugin']);
        self::assertSame(['name' => 'Silverstripe CMS', 'version' => '6.1.0'], $report['system']);
        self::assertSame('PHP', $report['runtime']['name']);
        self::assertSame(['url' => 'https://cms.example.ch/supertext/ping'], $report['callback']);
        self::assertTrue($report['api_key_configured']);
        self::assertSame('ok', $report['api_key_check']['status']);
        self::assertSame(Supertext::SDK_VERSION, $report['sdk_version']);
        self::assertSame(3.0, end($this->calls)['timeout'], 'reports wait at most 3 s');

        // Never the key, not even in the stored state.
        foreach ($this->calls as $call) {
            self::assertStringNotContainsString(self::KEY, (string) $call['body']);
        }
        self::assertStringNotContainsString(self::KEY, (string) $this->store->json);
        self::assertSame('ok', $this->reporting()->lastCheck()?->status);
    }

    public function testNothingIsSentAgainUntilSomethingChanges(): void
    {
        $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);
        $this->calls = [];

        $result = $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);
        self::assertNull($result?->event);
        self::assertSame([], $this->calls);

        // A plugin update is reported as "upgraded".
        $result = $this->reporting(true, '0.4.0')->run(Reporting::ON_REQUEST, self::KEY);
        self::assertSame('upgraded', $result?->event);
        self::assertSame('0.4.0', $this->reports()[0]['plugin']['version']);
    }

    public function testARejectedKeyIsReportedOnce(): void
    {
        $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);
        $this->calls = [];

        $result = $this->reporting()->run(Reporting::AUTHENTICATION_FAILED, self::KEY, 403);
        self::assertSame('api_key_check', $result?->event);
        self::assertSame('invalid', $result?->check?->status);
        self::assertCount(1, $this->reports(), 'no request to Supertext, one report');

        $this->calls = [];
        $this->reporting()->run(Reporting::AUTHENTICATION_FAILED, self::KEY, 401);
        self::assertSame([], $this->calls);
    }

    public function testTestConnectionChecksTheKeyNow(): void
    {
        $this->apiStatus = 401;
        $result = $this->reporting()->run(Reporting::TEST_CONNECTION, self::KEY);

        self::assertSame('invalid', $result?->check?->status);
        self::assertSame(10.0, $this->calls[0]['timeout']);
    }

    public function testTheCheckTaskSendsACheckReport(): void
    {
        $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);
        $this->calls = [];

        $result = $this->reporting()->run(Reporting::REPORT_CHECK, self::KEY);
        self::assertSame('check', $result?->event);
        self::assertSame('check', $this->reports()[0]['event']);
    }

    public function testOffMeansNoReportsButTheKeyIsStillChecked(): void
    {
        $result = $this->reporting(false)->run(Reporting::ON_REQUEST, self::KEY);

        self::assertSame('ok', $result?->check?->status);
        self::assertSame([], $this->reports());
    }

    public function testAMissingKeyIsCheckedWithoutARequestToSupertext(): void
    {
        $result = $this->reporting()->run(Reporting::ON_REQUEST, '');

        self::assertSame('missing', $result?->check?->status);
        foreach ($this->calls as $call) {
            self::assertSame(self::CENTER, $call['url']);
        }
        self::assertFalse($this->reports()[0]['api_key_configured']);
    }

    public function testABrokenStoreNeverThrows(): void
    {
        $broken = new class () implements StateStore {
            public function load(): ?string
            {
                throw new \RuntimeException('database gone');
            }

            public function save(string $json): void
            {
                throw new \RuntimeException('database gone');
            }
        };

        self::assertNull($this->reporting(true, '0.3.0', $broken)->run(Reporting::ON_REQUEST, self::KEY));
        self::assertNull($this->reporting(true, '0.3.0', $broken)->lastCheck());
    }

    public function testAGarbledStateMeansNeverReported(): void
    {
        $this->store->json = '{not json';
        $result = $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);

        self::assertSame('installed', $result?->event);
    }

    public function testAStateStoredByTheSdk02ReporterIsKept(): void
    {
        $this->store->json = (string) json_encode([
            'reportedVersions' => '0.3.0|6.1.0|' . PHP_VERSION,
            'reportedKeyStatus' => 'ok',
            'lastFailureAt' => null,
            'keyCheck' => ['status' => 'ok', 'checkedAt' => '2026-10-13T08:00:00Z', 'httpStatus' => 200, 'errorCode' => null, 'fingerprint' => Protocol::apiKeyFingerprint(self::KEY)],
        ], JSON_UNESCAPED_SLASHES);
        $stored = $this->store->json;

        $result = $this->reporting()->run(Reporting::ON_REQUEST, self::KEY);

        self::assertNull($result?->event, 'no new installed report');
        self::assertSame([], $this->calls);
        // Plugin Health 0.2+ adds keyChecks and reportedKeyStatuses (null with one key); everything else is kept.
        self::assertSame(json_decode($stored, true) + ['keyChecks' => null, 'reportedKeyStatuses' => null], json_decode($this->store->json, true), 'same storage format');
        self::assertSame('2026-10-13T08:00:00Z', $this->reporting()->lastCheck()?->checkedAt);
    }

    public function testSendConfigurationSendsModulesAndTheLanguageMapping(): void
    {
        $result = $this->reporting()->sendConfiguration(
            [['name' => 'tractorcow/silverstripe-fluent', 'version' => '8.0.0', 'active' => true, 'type' => 'module']],
            [['code' => 'en_US', 'supertext' => 'en-US'], ['code' => 'de_CH', 'supertext' => 'de-CH'], ['code' => 'en_IE', 'supertext' => null]],
        );

        self::assertSame('extensions', $result?->event);
        self::assertTrue($result?->sent);
        [$report] = $this->reports();
        self::assertSame('extensions', $report['event']);
        self::assertSame([['name' => 'tractorcow/silverstripe-fluent', 'version' => '8.0.0', 'active' => true, 'type' => 'module']], $report['extensions']);
        self::assertSame([['code' => 'en_US', 'supertext' => 'en-US'], ['code' => 'de_CH', 'supertext' => 'de-CH'], ['code' => 'en_IE', 'supertext' => null]], $report['languages']);
    }

    public function testSendConfigurationSendsNothingWithReportsOff(): void
    {
        $result = $this->reporting(enabled: false)->sendConfiguration([['name' => 'a/b']], [['code' => 'de_CH', 'supertext' => 'de-CH']]);

        self::assertFalse((bool) $result?->sent);
        self::assertSame([], $this->reports());
    }

    public function testPingAnswers(): void
    {
        $ok = Reporting::ping('abcdefghijklmnop_-12', true);
        self::assertSame(200, $ok->status);
        self::assertSame(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $ok->headers);
        self::assertSame('{"ok":true,"plugin":"Silverstripe-Supertext-Translation","challenge":"abcdefghijklmnop_-12"}', $ok->body);

        self::assertSame(400, Reporting::ping('short', true)->status);
        self::assertSame(400, Reporting::ping('<script>alert(1)</script>xxxxxxxx', true)->status);
        self::assertSame(400, Reporting::ping(['array'], true)->status);
        self::assertSame(400, Reporting::ping(null, true)->status);
        self::assertSame(404, Reporting::ping('abcdefghijklmnop_-12', false)->status);
    }

    public function testCallbackUrl(): void
    {
        self::assertSame('https://cms.example.ch/supertext/ping', Reporting::callbackUrl('https://cms.example.ch/'));
        self::assertSame('https://example.com/sub/supertext/ping', Reporting::callbackUrl('https://example.com/sub'));
    }
}

final class ArrayStore implements StateStore
{
    public ?string $json = null;

    public function load(): ?string
    {
        return $this->json;
    }

    public function save(string $json): void
    {
        $this->json = $json;
    }
}
