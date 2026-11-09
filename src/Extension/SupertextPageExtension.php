<?php

namespace Supertext\Silverstripe\Extension;

use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;
use Supertext\Silverstripe\Service\Translator;
use Supertext\Silverstripe\Supertext;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Model\Locale;

/**
 * The "Supertext" tab on pages (and other Fluent-localised records that use it): translate
 * the current locale's saved content into other locales.
 *
 * @extends Extension<DataObject>
 */
class SupertextPageExtension extends Extension
{
    protected function updateCMSFields(FieldList $fields): void
    {
        $record = $this->owner;
        if (!$record->hasExtension(FluentExtension::class) || !$record->isInDB() || !Permission::check(Supertext::PERMISSION)) {
            return;
        }
        $current = Locale::getCurrentLocale();
        if (!$current || Locale::getCached()->count() < 2) {
            return;
        }

        Requirements::javascript('supertext/silverstripe-supertext-translation:client/supertext.js');
        Requirements::css('supertext/silverstripe-supertext-translation:client/supertext.css');

        $tab = 'Root.Supertext';
        $fields->findOrMakeTab($tab, _t(self::class . '.TAB', 'Supertext'));

        $from = Convert::raw2xml($current->getTitle());
        if (!Translator::existsIn($record, $current->Locale)) {
            $fields->addFieldToTab($tab, LiteralField::create('SupertextNoSource', sprintf(
                '<p class="alert alert-info">%s</p>',
                _t(
                    self::class . '.NO_SOURCE',
                    'This page has no content of its own in {locale} yet. Switch to a locale it was written in to translate it from there.',
                    ['locale' => $from]
                )
            )));

            return;
        }

        $options = [];
        $exportOptions = [];
        $default = [];
        $existing = [];
        $unmapped = [];
        foreach (Translator::singleton()->describe($record) as $item) {
            /** @var Locale $locale */
            $locale = $item['locale'];
            if ($locale->Locale === $current->Locale) {
                continue;
            }
            $label = Convert::raw2xml($locale->getTitle()) . ' <code>' . Convert::raw2xml($locale->Locale) . '</code>';
            // A file may go to any translator: unmapped locales can be exported too.
            $exportOptions[$locale->Locale] = DBField::create_field('HTMLFragment', $label);
            if (Supertext::languageCodeOrNull($locale) === null) {
                // No guessing: without a Supertext language nothing can be sent for this locale.
                $label .= ' <span class="supertext-chip">' . _t(self::class . '.NOT_MAPPED', 'No Supertext language: an administrator needs to choose one under Locales') . '</span>';
                $options[$locale->Locale] = DBField::create_field('HTMLFragment', $label);
                $unmapped[] = $locale->Locale;
                continue;
            }
            if ($item['last']) {
                $date = DBDatetime::create()->setValue($item['last'])->Date();
                $label .= ' <span class="supertext-chip">' . _t(self::class . '.LAST', 'Translated with Supertext on {date}', ['date' => $date]) . '</span>';
            } elseif ($item['exists']) {
                $label .= ' <span class="supertext-chip">' . _t(self::class . '.EXISTS', 'Already translated') . '</span>';
            }
            $options[$locale->Locale] = DBField::create_field('HTMLFragment', $label);
            if ($item['exists']) {
                $existing[] = $locale->Locale;
            } else {
                $default[] = $locale->Locale;
            }
        }

        $configured = Supertext::singleton()->apiKey() !== '';

        $fields->addFieldsToTab($tab, [
            LiteralField::create('SupertextIntro', sprintf(
                '<div class="supertext-intro"><h3>%s</h3><p>%s</p>%s</div>',
                _t(self::class . '.TITLE', 'Translate with Supertext'),
                _t(self::class . '.FROM', 'From <strong>{locale}</strong>, the locale you are editing.', ['locale' => $from]),
                $configured ? '' : '<p class="alert alert-warning">' . _t(
                    self::class . '.NOT_CONFIGURED',
                    'Supertext is not set up yet: an administrator needs to set SUPERTEXT_API_KEY.'
                ) . '</p>'
            )),
            CheckboxSetField::create('SupertextTargets', _t(self::class . '.INTO', 'Into'), $options, $default)
                ->setDisabledItems($unmapped)
                ->addExtraClass('supertext-targets no-change-track')
                ->setAttribute('data-existing', json_encode($existing)),
            CheckboxField::create('SupertextOverwrite', _t(self::class . '.OVERWRITE', 'Overwrite existing translations'))
                ->addExtraClass('supertext-overwrite no-change-track')
                ->setDescription(_t(
                    self::class . '.OVERWRITE_HELP',
                    'The selected locales that already have their own content get a new translation of {locale}: page fields and content blocks. Changes made in those locales are lost. Leave this off to translate only the missing locales.',
                    ['locale' => $from]
                )),
            LiteralField::create('SupertextHint', '<p class="supertext-hint">' . _t(
                self::class . '.HINT',
                'Supertext translates the saved draft of this locale: save your changes first. The translations are saved as drafts; review and publish them in each locale.'
            ) . '</p>'),
            FormAction::create('doSupertextTranslate', _t(self::class . '.BUTTON', 'Translate'))
                ->addExtraClass('btn-primary supertext-translate font-icon-translatable')
                ->setUseButtonTag(true)
                ->setAttribute('data-busy', _t(self::class . '.BUSY', 'Translating…')),
        ]);

        $this->addFileFields($fields, $tab, $current, $exportOptions);
    }

    /**
     * "Export for translation" and "Import translation": sent by client/supertext.js to
     * SupertextCMSMainExtension (not saved with the page).
     *
     * @param array<string, mixed> $exportOptions locale => label
     */
    private function addFileFields(FieldList $fields, string $tab, Locale $current, array $exportOptions): void
    {
        $controller = CMSPageEditController::singleton();
        $hint = static fn (string $text): string => '<p class="supertext-hint">' . $text . '</p>';

        $fields->addFieldsToTab($tab, [
            LiteralField::create('SupertextExportIntro', sprintf(
                '<div class="supertext-files"><h3>%s</h3>%s</div>',
                _t(self::class . '.EXPORT', 'Export for translation'),
                $hint(_t(
                    self::class . '.EXPORT_HINT',
                    'Download the texts of this page and its content blocks in {locale} as a file for a translation tool, a translator or Supertext\'s web app. Import the translated file below.',
                    ['locale' => Convert::raw2xml($current->getTitle())]
                ))
            )),
            OptionsetField::create('SupertextExportFormat', _t(self::class . '.FORMAT', 'File format'), [
                'xliff' => _t(self::class . '.FORMAT_XLIFF', 'XLIFF 1.2 (for translation tools)'),
                'html'  => _t(self::class . '.FORMAT_HTML', 'HTML (one language per file)'),
            ], 'xliff')->addExtraClass('supertext-export-format no-change-track'),
            CheckboxSetField::create('SupertextExportTargets', _t(self::class . '.INTO', 'Into'), $exportOptions, array_slice(array_keys($exportOptions), 0, 1))
                ->addExtraClass('supertext-targets supertext-export-targets no-change-track'),
            LiteralField::create('SupertextExportButton', sprintf(
                '<p><button type="button" class="btn btn-outline-primary font-icon-down-circled supertext-export" data-url="%s" data-source="%s">%s</button></p><p class="supertext-export-message supertext-warning" aria-live="polite"></p>',
                Convert::raw2att($controller->Link('supertextExport/' . (int) $this->owner->ID)),
                Convert::raw2att($current->Locale),
                _t(self::class . '.DOWNLOAD', 'Download file')
            )),
            LiteralField::create('SupertextImport', sprintf(
                '<div class="supertext-files supertext-import" data-url="%s"><h3>%s</h3>%s
                    <div class="field"><label class="form__field-label" for="SupertextImportFile">%s</label>
                        <div class="form__field-holder"><input type="file" id="SupertextImportFile" class="no-change-track supertext-import-file" accept=".xlf,.xliff,.xml,.html,.htm"></div></div>
                    <div class="field"><label><input type="checkbox" class="no-change-track supertext-import-changed"> %s</label></div>
                    <div class="field"><label><input type="checkbox" class="no-change-track supertext-import-overwrite"> %s</label></div>
                    <p><button type="button" class="btn btn-outline-primary font-icon-upload supertext-import-button" data-busy="%s">%s</button></p>
                    <div class="supertext-import-result" aria-live="polite"></div>%s</div>',
                Convert::raw2att($controller->Link('supertextImport')),
                _t(self::class . '.IMPORT', 'Import translation'),
                $hint(_t(
                    self::class . '.IMPORT_HINT',
                    'Upload a file exported with Export for translation (from any page, at most 10 MB). The translations are saved as drafts in their locale, like translations made with Supertext.'
                )),
                _t(self::class . '.FILE', 'Translated file (XLIFF or HTML)'),
                _t(self::class . '.INCLUDE_CHANGED', 'Also import texts whose source changed since the export'),
                _t(self::class . '.OVERWRITE', 'Overwrite existing translations'),
                Convert::raw2att(_t(self::class . '.IMPORTING', 'Importing…')),
                _t(self::class . '.IMPORT', 'Import translation'),
                $hint(_t(self::class . '.IMPORT_RELOAD', 'Reload the page in a locale to see what was imported there.'))
            )),
        ]);
    }

    /** The tab's fields aren't saved into the record. */
    public function saveSupertextTargets(mixed $value): void
    {
    }

    public function saveSupertextOverwrite(mixed $value): void
    {
    }

    public function saveSupertextExportFormat(mixed $value): void
    {
    }

    public function saveSupertextExportTargets(mixed $value): void
    {
    }
}
