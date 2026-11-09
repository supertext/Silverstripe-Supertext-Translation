<?php

namespace Supertext\Silverstripe\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use Supertext\Silverstripe\Api\FileTransfer;
use Supertext\Silverstripe\Api\FileTransferException;
use Supertext\Silverstripe\Api\LanguageCodes;
use Supertext\Silverstripe\Api\SupertextClient;
use Supertext\Silverstripe\Api\SupertextException;
use Supertext\Silverstripe\Sdk\V1\SupertextException as SdkException;
use Supertext\Silverstripe\Model\TranslationLog;
use Supertext\Silverstripe\Supertext;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Translates a Fluent-localised record (usually a page) from one locale into others.
 *
 * The record's localised text fields, and those of the localised objects it owns (e.g. an
 * Elemental area's blocks), are read in the source locale (draft stage), sent to Supertext
 * as one HTML document per target locale (one data-st-id element per value), and written
 * to the draft stage of the target locale. Nothing is published.
 */
class Translator
{
    use Configurable;
    use Injectable;

    /** Localised fields that are never translated. */
    private static array $exclude_fields = ['URLSegment', 'ExtraMeta', 'ReportClass', 'ExtraClass', 'Style'];

    /** How deep owned objects (area → blocks → nested blocks) are followed. */
    private static int $max_depth = 5;

    private ?SupertextClient $client = null;

    /** HTTP status of a rejected API key during the current translate() call, for the key check. */
    private ?int $rejectedKey = null;

    public function setClient(SupertextClient $client): static
    {
        $this->client = $client;

        return $this;
    }

    /** True if the record has content of its own in the locale (not just Fluent's fallback). */
    public static function existsIn(DataObject $record, string $locale): bool
    {
        if ($record->hasExtension(Versioned::class) && $record->hasMethod('isDraftedInLocale')) {
            return (bool) $record->isDraftedInLocale($locale);
        }

        return (bool) $record->existsInLocale($locale);
    }

    /**
     * Locales with the record's state: [['locale' => Locale, 'exists' => bool, 'last' => ?string]].
     */
    public function describe(DataObject $record): array
    {
        $last = TranslationLog::lastTranslations($record);
        $out = [];
        foreach (Locale::getCached() as $locale) {
            $out[] = [
                'locale' => $locale,
                'exists' => self::existsIn($record, $locale->Locale),
                'last'   => $last[$locale->Locale] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $targets locale codes (e.g. de_CH)
     *
     * @return list<array{locale: string, status: string, message: string, created: bool}>
     *
     * @throws SupertextException when nothing can be translated at all (no source, no key)
     */
    public function translate(DataObject $record, string $source, array $targets, bool $overwrite = false, ?Member $member = null): array
    {
        $sourceLocale = Locale::getByLocale($source);
        if (!$sourceLocale) {
            throw new SupertextException(_t(self::class . '.NO_LOCALE', 'Unknown locale {locale}.', ['locale' => $source]));
        }
        if (!self::existsIn($record, $source)) {
            throw new SupertextException(_t(
                self::class . '.NO_SOURCE',
                'This page has no content of its own in {locale} to translate from.',
                ['locale' => $sourceLocale->getTitle()]
            ));
        }

        $client = $this->client ?? Supertext::singleton()->client();
        // Version report and key check (only if the key changed), after the response.
        Reports::onRequest();
        if (!$client->hasApiKey()) {
            throw new SupertextException(Messages::noApiKey());
        }
        $this->rejectedKey = null;

        $units = $this->inLocale($source, fn () => $this->collect($this->reload($record)));

        $results = [];
        foreach (array_unique($targets) as $target) {
            if ($target === $source) {
                continue;
            }
            $results[] = $this->translateInto($client, $record, $units, $sourceLocale, $target, $overwrite, $member);
        }
        $rejected = $this->takeRejectedKey();
        if ($rejected !== null) {
            Reports::authenticationFailed($rejected);
        }

        foreach ($results as $result) {
            TranslationLog::create([
                'RecordClass'  => $record->baseClass(),
                'RecordID'     => $record->ID,
                'RecordTitle'  => mb_substr((string) $record->getTitle(), 0, 255),
                'SourceLocale' => $source,
                'TargetLocale' => $result['locale'],
                'Status'       => $result['status'],
                'Message'      => $result['message'],
                'MemberID'     => $member?->ID ?: 0,
            ])->write();
        }

        return $results;
    }

    private function translateInto(SupertextClient $client, DataObject $record, array $units, Locale $source, string $target, bool $overwrite, ?Member $member): array
    {
        $result = ['locale' => $target, 'status' => TranslationLog::TRANSLATED, 'message' => '', 'created' => false];
        $targetLocale = Locale::getByLocale($target);
        if (!$targetLocale) {
            return ['status' => TranslationLog::ERROR, 'message' => _t(self::class . '.NO_LOCALE', 'Unknown locale {locale}.', ['locale' => $target])] + $result;
        }

        $exists = self::existsIn($record, $target);
        if ($exists && !$overwrite) {
            return ['status' => TranslationLog::SKIPPED, 'message' => _t(self::class . '.SKIPPED', 'Already translated.')] + $result;
        }

        if (!$this->canWriteIn($record, $target, $member)) {
            return ['status' => TranslationLog::ERROR, 'message' => _t(
                self::class . '.FORBIDDEN',
                'You are not allowed to edit this page in {locale}.',
                ['locale' => $targetLocale->getTitle()]
            )] + $result;
        }

        try {
            $translations = $this->send($client, $units, $source, $targetLocale);
            $this->inLocale($target, fn () => $this->apply($record, $units, $translations, $exists));
        } catch (SupertextException $e) {
            if ($e->reason === 'auth_failed') {
                $this->rejectedKey = in_array($e->getCode(), [401, 403], true) ? $e->getCode() : 401;
            }

            return ['status' => TranslationLog::ERROR, 'message' => Messages::of($e)] + $result;
        } catch (\Throwable $e) {
            \SilverStripe\Core\Injector\Injector::inst()->get(\Psr\Log\LoggerInterface::class)
                ->error('Supertext translation failed: ' . $e->getMessage(), ['exception' => $e]);

            return ['status' => TranslationLog::ERROR, 'message' => $e->getMessage() ?: get_class($e)] + $result;
        }

        return ['created' => !$exists] + $result;
    }

    /** Whether the member may translate with Supertext and edit the record in the locale. */
    private function canWriteIn(DataObject $record, string $locale, ?Member $member): bool
    {
        $allowed = $this->inLocale($locale, function () use ($record, $member) {
            Permission::reset();
            $member = $member ?: \SilverStripe\Security\Security::getCurrentUser();

            return Permission::checkMember($member, Supertext::PERMISSION) && $this->reload($record)->canEdit($member);
        });
        Permission::reset();

        return (bool) $allowed;
    }

    /**
     * "Export for translation": the page's segments in the source locale (the same as for an API
     * translation) as an XLIFF 1.2 file (one or more locales) or an HTML file (one locale).
     *
     * @param list<string> $targets locale codes
     *
     * @return array{name: string, type: string, body: string}
     *
     * @throws SupertextException|FileTransferException
     */
    public function exportFile(DataObject $record, string $source, array $targets, string $format, ?Member $member = null): array
    {
        $sourceLocale = $this->sourceLocale($record, $source);
        if (!$this->canWriteIn($record, $source, $member)) {
            throw new SupertextException(_t(self::class . '.FORBIDDEN', 'You are not allowed to edit this page in {locale}.', ['locale' => $sourceLocale->getTitle()]));
        }
        $codes = [];
        foreach (array_unique($targets) as $target) {
            $locale = Locale::getByLocale($target);
            if (!$locale || $target === $source) {
                throw new FileTransferException('no_language', [$target]);
            }
            // A file may go to any translator: an unmapped locale doesn't block the export.
            $codes[$target] = Supertext::languageCodeOrNull($locale) ?? FileTransfer::fallbackCode($target);
        }
        try {
            $sourceCode = Supertext::sourceCode($sourceLocale);
        } catch (\Throwable) {
            $sourceCode = FileTransfer::fallbackCode($source);
        }
        $units = $this->inLocale($source, fn () => $this->collect($this->reload($record)));

        return (new FileTransfer())->export((int) $record->ID, $source, $sourceCode, self::fields($units), $codes, $format);
    }

    /**
     * "Import translation": writes every job of an uploaded XLIFF or HTML file to the draft stage
     * of its locale, like an API translation (locales with their own content only with $overwrite).
     *
     * @return list<array<string, mixed>> FileTransfer::import() results, `saved` with status, page, locale
     *
     * @throws FileTransferException for the whole file (invalid_file, missing_metadata, too_large)
     */
    public function importFile(string $content, bool $includeChanged, bool $overwrite, ?Member $member = null): array
    {
        $load = function (array $ref): array {
            $record = Versioned::withVersionedMode(static function () use ($ref) {
                Versioned::set_stage(Versioned::DRAFT);

                return SiteTree::get()->setUseCache(false)->byID($ref['id']);
            });
            if (!$record instanceof DataObject) {
                throw new FileTransferException('not_found');
            }
            foreach ([$ref['source'], $ref['target']] as $code) {
                if (!Locale::getByLocale($code)) {
                    throw new FileTransferException('no_language', [$code]);
                }
            }
            if ($ref['source'] === $ref['target']) {
                throw new FileTransferException('no_language', [$ref['target']]);
            }

            return [$record, $this->sourceLocale($record, $ref['source'])];
        };

        $current = function (array $ref) use ($load, $member): array {
            [$record] = $load($ref);
            if (!$this->canWriteIn($record, $ref['target'], $member)) {
                throw new SupertextException($this->forbidden($ref['target']));
            }

            return self::fields($this->inLocale($ref['source'], fn () => $this->collect($this->reload($record))));
        };

        $write = function (array $ref, array $translations) use ($load, $member, $overwrite): array {
            [$record] = $load($ref);
            $target = $ref['target'];
            $exists = self::existsIn($record, $target);
            if ($exists && !$overwrite) {
                return ['status' => 'skipped', 'title' => (string) $record->getTitle()];
            }
            if (!$this->canWriteIn($record, $target, $member)) {
                throw new SupertextException($this->forbidden($target));
            }
            $units = $this->inLocale($ref['source'], fn () => $this->collect($this->reload($record)));
            $byIndex = [];
            foreach ($units as $i => $unit) {
                if (isset($translations[self::unitKey($unit)])) {
                    $byIndex[$i] = $translations[self::unitKey($unit)];
                }
            }
            $this->inLocale($target, fn () => $this->apply($record, $units, $byIndex, $exists));
            TranslationLog::create([
                'RecordClass'  => $record->baseClass(),
                'RecordID'     => $record->ID,
                'RecordTitle'  => mb_substr((string) $record->getTitle(), 0, 255),
                'SourceLocale' => $ref['source'],
                'TargetLocale' => $target,
                'Status'       => TranslationLog::TRANSLATED,
                'Message'      => _t(self::class . '.IMPORTED', 'Imported from a translation file.'),
                'MemberID'     => $member?->ID ?: 0,
            ])->write();

            return ['status' => $exists ? 'updated' : 'created', 'title' => (string) $record->getTitle()];
        };

        return (new FileTransfer())->import($content, $includeChanged, $current, $write);
    }

    /** The key of a collected value in translation files: `<class>#<id>.<field>`. */
    public static function unitKey(array $unit): string
    {
        return $unit['class'] . '#' . $unit['id'] . '.' . $unit['field'];
    }

    /**
     * @param list<array{class: string, id: int, field: string, html: bool, value: string, root: bool}> $units
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    private static function fields(array $units): array
    {
        $fields = [];
        foreach ($units as $unit) {
            $fields[self::unitKey($unit)] = [$unit['value'], $unit['html']];
        }

        return $fields;
    }

    /** The source locale, if the record has content of its own there. */
    private function sourceLocale(DataObject $record, string $source): Locale
    {
        $sourceLocale = Locale::getByLocale($source);
        if (!$sourceLocale) {
            throw new FileTransferException('no_language', [$source]);
        }
        if (!self::existsIn($record, $source)) {
            throw new SupertextException(_t(
                self::class . '.NO_SOURCE',
                'This page has no content of its own in {locale} to translate from.',
                ['locale' => $sourceLocale->getTitle()]
            ));
        }

        return $sourceLocale;
    }

    private function forbidden(string $locale): string
    {
        return _t(self::class . '.FORBIDDEN', 'You are not allowed to edit this page in {locale}.', ['locale' => Locale::getByLocale($locale)?->getTitle() ?? $locale]);
    }

    /** The HTTP status of a key Supertext rejected during this translate() call, once. */
    private function takeRejectedKey(): ?int
    {
        $status = $this->rejectedKey;
        $this->rejectedKey = null;

        return $status;
    }

    /**
     * The values to translate: the record's own localised text fields and those of the
     * localised objects it owns that have content of their own in the source locale.
     *
     * @return list<array{class: string, id: int, field: string, html: bool, value: string, root: bool}>
     */
    public function collect(DataObject $record): array
    {
        $units = [];
        $seen = [];
        $locale = FluentState::singleton()->getLocale();
        $walk = function (DataObject $object, int $depth, bool $root) use (&$walk, &$units, &$seen, $locale): void {
            $key = $object->baseClass() . '#' . $object->ID;
            if (isset($seen[$key]) || $depth > (int) static::config()->get('max_depth')) {
                return;
            }
            $seen[$key] = true;

            if ($object->hasExtension(FluentExtension::class) && ($root || self::existsIn($object, $locale))) {
                foreach ($this->translatableFields($object) as $field => $html) {
                    $value = trim((string) $object->getField($field));
                    if ($value === '' || ($html && trim(strip_tags($value)) === '' && !str_contains($value, '<img'))) {
                        continue;
                    }
                    $units[] = [
                        'class' => get_class($object), 'id' => (int) $object->ID, 'field' => $field,
                        'html'  => $html, 'value' => $value, 'root' => $root,
                    ];
                }
            }

            if ($object->hasMethod('findOwned')) {
                foreach ($object->findOwned(false) as $owned) {
                    if ($owned instanceof DataObject && $owned->isInDB()) {
                        $walk($owned, $depth + 1, false);
                    }
                }
            }
        };
        $walk($record, 0, true);

        return $units;
    }

    /** @return array<string, bool> field => is HTML */
    public function translatableFields(DataObject $object): array
    {
        $exclude = array_merge(
            (array) static::config()->get('exclude_fields'),
            (array) $object->config()->get('supertext_exclude_fields')
        );
        $schema = DataObject::getSchema();
        $out = [];
        foreach ($object->getLocalisedTables() as $fields) {
            foreach ($fields as $field) {
                if (in_array($field, $exclude, true)) {
                    continue;
                }
                $spec = (string) $schema->fieldSpec($object, $field, \SilverStripe\ORM\DataObjectSchema::DB_ONLY);
                $type = strtok($spec, '(');
                $class = \SilverStripe\Core\Injector\Injector::inst()->getServiceSpec($type)['class'] ?? $type;
                $out[$field] = is_a($class, DBHTMLText::class, true) || is_a($class, DBHTMLVarchar::class, true)
                    || in_array($type, ['HTMLText', 'HTMLVarchar', 'HTMLFragment'], true);
            }
        }

        return $out;
    }

    /** @return array<int, string> unit index => translation */
    private function send(SupertextClient $client, array $units, Locale $source, Locale $target): array
    {
        if ($units === []) {
            return [];
        }
        // No guessing: a locale without a Supertext language fails here (unsupported_language), nothing is sent.
        $targetCode = Supertext::languageCode($target);
        $sourceCode = Supertext::sourceCode($source);
        $politeness = Supertext::politeness($target);
        if ($politeness !== '' && LanguageCodes::supportsPoliteness($targetCode) === false) {
            // Supertext would refuse it (unsupported_feature); say so instead of dropping the administrator's setting.
            throw SupertextClient::fromSdk(new SdkException(SdkException::UNSUPPORTED_FEATURE, targetLang: $targetCode, feature: 'politeness'));
        }

        $segments = [];
        foreach ($units as $i => $unit) {
            $segments[$i] = ['text' => $unit['value'], 'html' => $unit['html']];
        }

        // Locales with "Translation review" go through Supertext's review service (same key and protocol).
        return $client->forReview(Supertext::review($target))->translateSegments($segments, $targetCode, $sourceCode, $politeness ?: 'default');
    }

    /** Writes the translations to the draft stage of the current (target) locale. */
    private function apply(DataObject $record, array $units, array $translations, bool $existed): void
    {
        $byObject = [];
        foreach ($units as $i => $unit) {
            $value = trim((string) ($translations[$i] ?? ''));
            if ($value !== '') {
                $byObject[$unit['class'] . '#' . $unit['id']][$unit['field']] = $value;
            }
        }

        $rootKey = get_class($record) . '#' . $record->ID;
        $byObject += [$rootKey => []]; // the record itself is always written, so the locale exists

        // Owned objects first, the record last.
        uksort($byObject, static fn ($a, $b) => ($a === $rootKey) <=> ($b === $rootKey));

        foreach ($byObject as $key => $fields) {
            [$class, $id] = explode('#', $key);
            $object = $key === $rootKey ? $this->reload($record) : DataObject::get($class)->byID((int) $id);
            if (!$object) {
                continue;
            }
            foreach ($fields as $field => $value) {
                $object->setField($field, $value);
            }
            if ($key === $rootKey && !$existed && $object instanceof SiteTree && isset($fields['Title'])) {
                $object->URLSegment = $object->generateURLSegment($fields['Title']);
            }
            if ($object->hasExtension(Versioned::class)) {
                $object->writeToStage(Versioned::DRAFT);
            } else {
                $object->forceChange();
                $object->write();
            }
        }
    }

    /** The record as stored in the current locale's draft stage. */
    private function reload(DataObject $record): DataObject
    {
        $class = get_class($record);
        $read = static fn () => DataObject::get($class)->setUseCache(false)->byID($record->ID);
        $fresh = $record->hasExtension(Versioned::class)
            ? Versioned::withVersionedMode(static function () use ($read) {
                Versioned::set_stage(Versioned::DRAFT);

                return $read();
            })
            : $read();

        return $fresh ?: $record;
    }

    private function inLocale(string $locale, callable $callback): mixed
    {
        return FluentState::singleton()->withState(static function (FluentState $state) use ($locale, $callback) {
            $state->setLocale($locale);

            return Versioned::withVersionedMode(static function () use ($callback) {
                Versioned::set_stage(Versioned::DRAFT);

                return $callback();
            });
        });
    }
}
