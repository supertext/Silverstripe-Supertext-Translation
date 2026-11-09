<?php

namespace Supertext\Silverstripe\Tests\unit;

use PHPUnit\Framework\TestCase;
use Supertext\Silverstripe\Api\FileTransfer;
use Supertext\Silverstripe\Api\FileTransferException;
use Supertext\Silverstripe\Sdk\Files\TranslationFiles;

/** "Export for translation" / "Import translation": the glue around the SDK's TranslationFiles. */
final class FileTransferTest extends TestCase
{
    private const PAGE = 'SilverStripe\\CMS\\Model\\SiteTree#12.';
    private const BLOCK = 'DNADesign\\Elemental\\Models\\ElementContent#7.';

    /** @return array<string, array{0: string, 1: bool}> what Translator collects for a page with a content block */
    private static function fields(): array
    {
        return [
            self::PAGE . 'Title'     => ['Welcome', false],
            self::PAGE . 'MenuTitle' => ['Home', false],
            self::BLOCK . 'HTML'     => ['<p>Hello <strong>world</strong></p>', true],
        ];
    }

    public function testReferenceRoundTrip(): void
    {
        self::assertSame('page:12:en_US:de_CH', FileTransfer::reference(12, 'en_US', 'de_CH'));
        self::assertSame(['id' => 12, 'source' => 'en_US', 'target' => 'de_CH'], FileTransfer::parseReference('page:12:en_US:de_CH'));
        self::assertSame(['id' => 3, 'source' => 'en', 'target' => 'sr-Latn'], FileTransfer::parseReference(FileTransfer::reference(3, 'en', 'sr-Latn')));

        foreach (['', 'page:0:en:de', 'page:12:de', 'entry:12:en:de', 'page:12:en:de CH', 'page:x:en:de', 'page:12:en:de:fr'] as $bad) {
            self::assertNull(FileTransfer::parseReference($bad), $bad);
        }
    }

    public function testFileNames(): void
    {
        self::assertSame('silverstripe-12-de_CH.xlf', FileTransfer::fileName(12, ['de_CH'], FileTransfer::XLIFF));
        self::assertSame('silverstripe-12.xlf', FileTransfer::fileName(12, ['de_CH', 'fr_FR'], FileTransfer::XLIFF));
        self::assertSame('silverstripe-12-de_CH.html', FileTransfer::fileName(12, ['de_CH'], FileTransfer::HTML));
    }

    public function testXliffExportUsesTheCollectorsKeysAndTheCodes(): void
    {
        $file = (new FileTransfer())->export(12, 'en_US', 'en', self::fields(), ['de_CH' => 'de-CH', 'en_IE' => FileTransfer::fallbackCode('en_IE')], FileTransfer::XLIFF);

        self::assertSame('silverstripe-12.xlf', $file['name']);
        self::assertSame('application/x-xliff+xml', $file['type']);

        $jobs = TranslationFiles::read($file['body']);
        self::assertCount(2, $jobs);
        self::assertSame(FileTransfer::PLUGIN, $jobs[0]->plugin);
        self::assertSame('page:12:en_US:de_CH', $jobs[0]->reference);
        self::assertSame('en', $jobs[0]->sourceLanguage);
        self::assertSame('de-CH', $jobs[0]->targetLanguage);
        self::assertSame('en-IE', $jobs[1]->targetLanguage, 'an unmapped locale is written as the locale with -');
        self::assertSame(array_keys(self::fields()), array_map(static fn ($u) => $u->key, $jobs[0]->units));
    }

    public function testHtmlExportHasExactlyOneLocale(): void
    {
        $file = (new FileTransfer())->export(12, 'en_US', 'en', self::fields(), ['fr_FR' => 'fr-FR'], FileTransfer::HTML);

        self::assertSame('silverstripe-12-fr_FR.html', $file['name']);
        self::assertSame('text/html; charset=utf-8', $file['type']);
        self::assertSame('fr-FR', TranslationFiles::read($file['body'])[0]->targetLanguage);

        $this->expectExceptionObject(new FileTransferException('invalid_request'));
        (new FileTransfer())->export(12, 'en_US', 'en', self::fields(), ['fr_FR' => 'fr-FR', 'de_CH' => 'de-CH'], FileTransfer::HTML);
    }

    public function testNothingToTranslate(): void
    {
        foreach ([[], ['x#1.Title' => ['   ', false]]] as $fields) {
            try {
                (new FileTransfer())->export(12, 'en_US', 'en', $fields, ['de_CH' => 'de-CH'], FileTransfer::XLIFF);
                self::fail('no exception');
            } catch (FileTransferException $e) {
                self::assertSame('nothing_to_translate', $e->reason);
            }
        }
    }

    public function testImportWritesOkSegmentsOnly(): void
    {
        $xliff = $this->translated($this->export(), ['Welcome' => 'Willkommen', 'Home' => 'Startseite']);
        $written = [];
        $results = $this->import($xliff, false, self::fields(), $written);

        self::assertSame([[['id' => 12, 'source' => 'en_US', 'target' => 'de_CH'], [self::PAGE . 'Title' => 'Willkommen', self::PAGE . 'MenuTitle' => 'Startseite']]], $written);
        self::assertSame(['imported' => 2, 'changed' => 0, 'untranslated' => 1, 'invalid' => 0, 'missing' => 0], $results[0]['counts']);
        self::assertSame('created', $results[0]['saved']['status']);
        self::assertArrayNotHasKey('error', $results[0]);
    }

    public function testSourceChangedOnlyWithTheOption(): void
    {
        $xliff = $this->translated($this->export(), ['Welcome' => 'Willkommen', 'Home' => 'Startseite']);
        $changed = [self::PAGE . 'Title' => ['Welcome home', false]] + self::fields();

        $written = [];
        $results = $this->import($xliff, false, $changed, $written);
        self::assertSame([self::PAGE . 'MenuTitle' => 'Startseite'], $written[0][1]);
        self::assertSame(1, $results[0]['counts']['changed']);

        $written = [];
        $results = $this->import($xliff, true, $changed, $written);
        self::assertSame([self::PAGE . 'Title' => 'Willkommen', self::PAGE . 'MenuTitle' => 'Startseite'], $written[0][1]);
        self::assertSame(0, $results[0]['counts']['changed']);
        self::assertSame(2, $results[0]['counts']['imported']);
    }

    public function testInvalidMarkupAndMissingAreNotWritten(): void
    {
        $html = (new FileTransfer())->export(12, 'en_US', 'en', self::fields(), ['de_CH' => 'de-CH'], FileTransfer::HTML)['body'];
        $html = str_replace(['Hello <strong>world</strong>', '>Welcome<'], ['Hallo <script>x</script>', '>Willkommen<'], $html);
        $more = self::fields() + [self::BLOCK . 'Title' => ['A new field', false]];

        $written = [];
        $results = $this->import($html, false, $more, $written);

        self::assertArrayHasKey(self::PAGE . 'Title', $written[0][1]);
        self::assertArrayNotHasKey(self::BLOCK . 'HTML', $written[0][1]);
        self::assertArrayNotHasKey(self::BLOCK . 'Title', $written[0][1]);
        self::assertSame(1, $results[0]['counts']['invalid']);
        self::assertSame(1, $results[0]['counts']['missing']);
    }

    public function testNothingWrittenWhenNothingIsTranslated(): void
    {
        $written = [];
        $results = $this->import($this->export(), false, self::fields(), $written);

        self::assertSame([], $written);
        self::assertSame(3, $results[0]['counts']['untranslated']);
        self::assertArrayNotHasKey('saved', $results[0]);
    }

    public function testFileOfAnotherPlugin(): void
    {
        $written = [];
        $results = $this->import(str_replace(FileTransfer::PLUGIN, 'Contao-Supertext-Translation', $this->export()), false, self::fields(), $written);

        self::assertSame([], $written);
        self::assertInstanceOf(FileTransferException::class, $results[0]['error']);
        self::assertSame('other_system', $results[0]['error']->reason);
        self::assertSame(['Contao-Supertext-Translation'], $results[0]['error']->args);
    }

    public function testUnknownReference(): void
    {
        $written = [];
        $results = $this->import(str_replace('page:12:en_US:de_CH', 'entry:12', $this->export()), false, self::fields(), $written);

        self::assertSame('not_found', $results[0]['error']->reason);
        self::assertSame([], $written);
    }

    public function testMissingPageOrLocaleComesFromTheLoader(): void
    {
        $results = (new FileTransfer())->import(
            $this->export(),
            false,
            static fn (array $ref) => throw new FileTransferException('no_language', [$ref['target']]),
            static fn () => self::fail('nothing may be written'),
        );

        self::assertSame('no_language', $results[0]['error']->reason);
        self::assertSame(['de_CH'], $results[0]['error']->args);
    }

    public function testSkippedWriteImportsNothing(): void
    {
        $xliff = $this->translated($this->export(), ['Welcome' => 'Willkommen']);
        $results = (new FileTransfer())->import($xliff, false, static fn () => self::fields(), static fn () => ['status' => 'skipped']);

        self::assertSame('skipped', $results[0]['saved']['status']);
        self::assertSame(0, $results[0]['counts']['imported']);
    }

    public function testFileErrors(): void
    {
        foreach (['<html><body>hi</body></html>' => 'missing_metadata', 'not a file' => 'invalid_file', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><xliff/>' => 'invalid_file'] as $content => $reason) {
            try {
                (new FileTransfer())->import($content, false, static fn () => [], static fn () => []);
                self::fail('no exception for ' . $reason);
            } catch (FileTransferException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testTooLargeIsRefused(): void
    {
        FileTransfer::assertUploadSize(FileTransfer::MAX_UPLOAD_BYTES);

        try {
            FileTransfer::assertUploadSize(FileTransfer::MAX_UPLOAD_BYTES + 1);
            self::fail('no exception');
        } catch (FileTransferException $e) {
            self::assertSame('too_large', $e->reason);
        }

        $this->expectExceptionObject(new FileTransferException('too_large'));
        (new FileTransfer())->import(str_repeat(' ', FileTransfer::MAX_UPLOAD_BYTES + 1), false, static fn () => [], static fn () => []);
    }

    private function export(): string
    {
        return (new FileTransfer())->export(12, 'en_US', 'en', self::fields(), ['de_CH' => 'de-CH'], FileTransfer::XLIFF)['body'];
    }

    /**
     * Fills <target> elements the way a CAT tool does, for the given plain sources.
     *
     * @param array<string, string> $translations source text => translation
     */
    private function translated(string $xliff, array $translations): string
    {
        foreach ($translations as $source => $target) {
            $xliff = preg_replace('#(<source>' . preg_quote($source, '#') . '</source>)#', '$1<target state="translated">' . $target . '</target>', $xliff, 1, $count);
            self::assertSame(1, $count, $source);
        }

        return (string) $xliff;
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $current
     * @param list<array{0: array{id: int, source: string, target: string}, 1: array<string, string>}> $written
     *
     * @return list<array<string, mixed>>
     */
    private function import(string $content, bool $includeChanged, array $current, array &$written): array
    {
        return (new FileTransfer())->import(
            $content,
            $includeChanged,
            static fn (array $ref): array => $current,
            static function (array $ref, array $translations) use (&$written): array {
                $written[] = [$ref, $translations];

                return ['status' => 'created', 'title' => 'Willkommen'];
            },
        );
    }
}
