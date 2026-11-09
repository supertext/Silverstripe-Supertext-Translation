<?php

namespace Supertext\Silverstripe\Tests\unit;

use PHPUnit\Framework\TestCase;
use Supertext\Silverstripe\Api\LanguageCodes;
use Supertext\Silverstripe\Api\SupertextException;

/** The locale's Supertext language, else an exact Supertext code, else the SDK's default, else unsupported_language. */
final class LanguageCodesTest extends TestCase
{
    public function testLocaleThatIsASupertextCodeIsUsed(): void
    {
        self::assertSame('de-CH', LanguageCodes::target('de_CH'));
        self::assertSame('en-US', LanguageCodes::target('en_US'));
        self::assertSame('fr-CH', LanguageCodes::target('fr-ch'));
        self::assertSame('es', LanguageCodes::target('es'));
        self::assertSame('zh-Hant', LanguageCodes::target('zh_hant'));
    }

    public function testObviousDefaults(): void
    {
        self::assertSame('de-DE', LanguageCodes::target('de'));
        self::assertSame('en-US', LanguageCodes::target('en'));
        self::assertSame('es', LanguageCodes::target('es_ES'));
        self::assertSame('sl', LanguageCodes::target('sl_SI'));
        self::assertSame('zh-Hans', LanguageCodes::target('zh_CN'));
        self::assertSame('zh-Hant', LanguageCodes::target('zh_TW'));
    }

    public function testNoGuessedRegions(): void
    {
        foreach (['en_IE', 'de_LI', 'fr_BE', 'gsw', 'xx'] as $locale) {
            try {
                LanguageCodes::target($locale);
                self::fail("$locale should need a Supertext language");
            } catch (SupertextException $e) {
                self::assertSame('unsupported_language', $e->reason);
                self::assertSame([$locale], $e->args);
            }
            self::assertNull(LanguageCodes::targetOrNull($locale));
            self::assertNull(LanguageCodes::suggest($locale));
        }
    }

    public function testSuggestionForTheComboBox(): void
    {
        self::assertSame('de-CH', LanguageCodes::suggest('de_CH'));
        self::assertSame('de-DE', LanguageCodes::suggest('de'));
        self::assertSame('es', LanguageCodes::suggest('es_ES'));
    }

    public function testComboBoxOptions(): void
    {
        $english = LanguageCodes::options();
        self::assertSame('German (Switzerland) (de-CH)', $english['de-CH']);
        self::assertCount(count(LanguageCodes::targets()), $english);
        self::assertCount(count($english), LanguageCodes::options('de_DE'));
        if (class_exists(\Locale::class)) {
            self::assertSame('Deutsch (Schweiz) (de-CH)', LanguageCodes::options('de_DE')['de-CH']);
        }
        self::assertStringEndsWith('(gsw-u-sd-chzh)', LanguageCodes::options('fr_FR')['gsw-u-sd-chzh']);
    }

    public function testSavedCodesInSupertextsSpelling(): void
    {
        self::assertSame('de-CH', LanguageCodes::canonical(' de_ch '));
        self::assertSame('zh-Hant', LanguageCodes::canonical('ZH-HANT'));
        self::assertSame('', LanguageCodes::canonical(''));
        self::assertSame('xx-YY', LanguageCodes::canonical('xx-YY'), 'a code Supertext does not list is kept');
    }

    public function testTheMappingWins(): void
    {
        $mapping = ['de' => 'de-CH', 'es_ES' => 'es', 'en_US' => 'en-GB', 'it_CH' => '  '];
        self::assertSame('de-CH', LanguageCodes::target('de', $mapping));
        self::assertSame('es', LanguageCodes::target('es_ES', $mapping));
        self::assertSame('en-GB', LanguageCodes::target('en_US', $mapping));
        self::assertSame('it-CH', LanguageCodes::target('it_CH', $mapping), 'a blank entry means no mapping');
        self::assertSame('xx-YY', LanguageCodes::target('xx', ['xx' => 'xx-YY']), 'a mapped code is sent as it is');
    }

    public function testSourcesAreBareAndUseTheSameMapping(): void
    {
        self::assertSame('en', LanguageCodes::source('en_US'));
        self::assertSame('de', LanguageCodes::source('de_CH'));
        self::assertSame('de', LanguageCodes::source('de', ['de' => 'de-CH']));
        self::assertSame('en', LanguageCodes::source('en_XX', ['en_XX' => 'en-US']));
        self::assertSame('', LanguageCodes::source(''));
        $this->expectException(SupertextException::class);
        LanguageCodes::source('xx_YY');
    }

    public function testPolitenessOnlyWhereSupertextOffersIt(): void
    {
        self::assertTrue(LanguageCodes::supportsPoliteness('de-CH'));
        self::assertFalse(LanguageCodes::supportsPoliteness('en-US'));
        self::assertFalse(LanguageCodes::supportsPoliteness('rm'));
        self::assertNull(LanguageCodes::supportsPoliteness('xx'));
    }

    public function testListsTheSupertextTargets(): void
    {
        $codes = array_column(LanguageCodes::targets(), 'code');
        self::assertContains('de-CH', $codes);
        self::assertContains('es', $codes);
        self::assertNotContains('es-ES', $codes);
        self::assertSame('de-CH', LanguageCodes::match('DE_ch'));
        self::assertNull(LanguageCodes::match('de'));
    }
}
