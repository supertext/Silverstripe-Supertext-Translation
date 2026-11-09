<?php

/**
 * @package     Supertext Translation for Silverstripe
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\Silverstripe\Api;

use Supertext\Silverstripe\Sdk\Files\FileJob;
use Supertext\Silverstripe\Sdk\Files\FileSegment;
use Supertext\Silverstripe\Sdk\Files\ImportedSegment;
use Supertext\Silverstripe\Sdk\Files\TranslationFileError;
use Supertext\Silverstripe\Sdk\Files\TranslationFiles;

/**
 * "Export for translation" / "Import translation": a page's segments as an XLIFF 1.2 or HTML
 * file (the SDK's TranslationFiles), and the translated file written back like an API
 * translation. No Silverstripe dependency: Service\Translator passes in the segments (its
 * collector, the same as for API translations), the Supertext codes and the write-back.
 *
 * Reference of a job: `page:<page id>:<source locale>:<target locale>`, e.g. `page:12:en_US:de_CH`.
 */
final class FileTransfer
{
    /** The `plugin` of every job: this repository's name. */
    public const PLUGIN = 'Silverstripe-Supertext-Translation';

    /** Uploads larger than this are refused before they are read. */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public const XLIFF = 'xliff';
    public const HTML = 'html';

    public static function reference(int $pageId, string $source, string $target): string
    {
        return 'page:' . $pageId . ':' . $source . ':' . $target;
    }

    /** @return array{id: int, source: string, target: string}|null */
    public static function parseReference(string $reference): ?array
    {
        if (!preg_match('/^page:([1-9]\d{0,18}):([A-Za-z0-9_-]{1,32}):([A-Za-z0-9_-]{1,32})$/', $reference, $m)) {
            return null;
        }

        return ['id' => (int) $m[1], 'source' => $m[2], 'target' => $m[3]];
    }

    /**
     * `silverstripe-12-de_CH.xlf`, `silverstripe-12.xlf` for several locales, `silverstripe-12-de_CH.html`.
     *
     * @param list<string> $targets
     */
    public static function fileName(int $pageId, array $targets, string $format): string
    {
        $suffix = count($targets) === 1 ? '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $targets[0]) : '';

        return 'silverstripe-' . $pageId . $suffix . ($format === self::HTML ? '.html' : '.xlf');
    }

    /** The code written into a file for a locale Supertext has no language for: the locale with `-`. */
    public static function fallbackCode(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $fields key => [text, isHtml]
     *
     * @return list<FileSegment>
     */
    public static function segments(array $fields): array
    {
        $segments = [];
        foreach ($fields as $key => [$text, $isHtml]) {
            $segments[] = new FileSegment((string) $key, $text, $isHtml);
        }

        return $segments;
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $fields      the page's segments (Translator's collector)
     * @param array<string, string>                    $targetCodes target locale => Supertext code; exactly one for HTML
     *
     * @return array{name: string, type: string, body: string}
     *
     * @throws FileTransferException nothing_to_translate, invalid_request
     */
    public function export(int $pageId, string $source, string $sourceCode, array $fields, array $targetCodes, string $format): array
    {
        if (!in_array($format, [self::XLIFF, self::HTML], true) || $targetCodes === [] || ($format === self::HTML && count($targetCodes) !== 1)) {
            throw new FileTransferException('invalid_request');
        }
        if ($fields === []) {
            throw new FileTransferException('nothing_to_translate');
        }

        $segments = self::segments($fields);
        $jobs = [];
        foreach ($targetCodes as $target => $code) {
            $jobs[] = new FileJob(self::PLUGIN, self::reference($pageId, $source, (string) $target), $sourceCode, $code, $segments);
        }
        try {
            $body = $format === self::HTML ? TranslationFiles::exportHtml($jobs[0]) : TranslationFiles::exportXliff($jobs);
        } catch (TranslationFileError $e) {
            throw new FileTransferException($e->errorCode === TranslationFileError::INVALID_JOB ? 'nothing_to_translate' : $e->errorCode);
        }

        return [
            'name' => self::fileName($pageId, array_map('strval', array_keys($targetCodes)), $format),
            'type' => $format === self::HTML ? 'text/html; charset=utf-8' : 'application/x-xliff+xml',
            'body' => $body,
        ];
    }

    /** @throws FileTransferException too_large */
    public static function assertUploadSize(int $bytes): void
    {
        if ($bytes > self::MAX_UPLOAD_BYTES) {
            throw new FileTransferException('too_large');
        }
    }

    /**
     * Reads an uploaded file and writes every job back.
     *
     * @param \Closure(array{id: int, source: string, target: string}): array<string, array{0: string, 1: bool}> $current
     *        the page's current segments; throws FileTransferException not_found / no_language (or any error, e.g. no permission)
     * @param \Closure(array{id: int, source: string, target: string}, array<string, string>): array<string, mixed> $write
     *        writes the translations (key => text) like an API translation; returns e.g. {status: created|updated|skipped}
     *
     * @return list<array{reference: string, id: int, source: string, target: string, counts: array{imported: int, changed: int, untranslated: int, invalid: int, missing: int}, saved?: array<string, mixed>, error?: \Throwable}>
     *
     * @throws FileTransferException invalid_file, missing_metadata, too_large (the whole file)
     */
    public function import(string $content, bool $includeChanged, \Closure $current, \Closure $write): array
    {
        self::assertUploadSize(strlen($content));
        try {
            $files = TranslationFiles::read($content);
        } catch (TranslationFileError $e) {
            throw new FileTransferException($e->errorCode);
        }

        $results = [];
        foreach ($files as $file) {
            $ref = self::parseReference($file->reference);
            $result = [
                'reference' => $file->reference,
                'id'        => $ref['id'] ?? 0,
                'source'    => $ref['source'] ?? '',
                'target'    => $ref['target'] ?? '',
                'counts'    => ['imported' => 0, 'changed' => 0, 'untranslated' => 0, 'invalid' => 0, 'missing' => 0],
            ];
            try {
                if ($file->plugin !== self::PLUGIN) {
                    throw new FileTransferException('other_system', [$file->plugin]);
                }
                if ($ref === null) {
                    throw new FileTransferException('not_found');
                }
                $translations = [];
                foreach (TranslationFiles::importTranslations($file, self::segments($current($ref))) as $segment) {
                    $use = $segment->status === ImportedSegment::OK
                        || ($segment->status === ImportedSegment::SOURCE_CHANGED && $includeChanged);
                    if ($use && $segment->text !== null && trim($segment->text) !== '') {
                        $translations[$segment->key] = $segment->text;
                        continue;
                    }
                    $bucket = match ($segment->status) {
                        ImportedSegment::SOURCE_CHANGED => 'changed',
                        ImportedSegment::INVALID_MARKUP => 'invalid',
                        ImportedSegment::MISSING        => 'missing',
                        default                         => 'untranslated',
                    };
                    $result['counts'][$bucket]++;
                }
                if ($translations !== []) {
                    $result['saved'] = $write($ref, $translations);
                    if (($result['saved']['status'] ?? '') !== 'skipped') {
                        $result['counts']['imported'] = count($translations);
                    }
                }
            } catch (\Throwable $e) {
                $result['error'] = $e;
            }
            $results[] = $result;
        }

        return $results;
    }
}
