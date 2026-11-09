<?php

namespace Supertext\Silverstripe\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use Supertext\Silverstripe\Api\LanguageCodes;
use TractorCow\Fluent\Model\Locale;

/**
 * Supertext language and form of address per Fluent locale (Locales admin → a locale). Together the
 * locales' Supertext languages are the language mapping (locale → Supertext code), one combo box per
 * locale. Empty means the suggestion: the Supertext code equal to the locale (de_CH → de-CH), else the
 * SDK's default (de → de-DE, es_ES → es); a locale with neither (en_IE, gsw) has no Supertext language
 * until one is chosen.
 *
 * @extends Extension<Locale>
 */
class LocaleExtension extends Extension
{
    private static array $db = [
        'SupertextCode'       => 'Varchar(20)',
        'SupertextPoliteness' => "Enum(',more,less','')",
        // Translations into this locale go through Supertext's review service (Supertext::review()).
        'SupertextReview'     => 'Boolean(0)',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName(['SupertextCode', 'SupertextPoliteness', 'SupertextReview']);
        $locale = (string) $this->owner->Locale;
        $current = trim((string) $this->owner->getField('SupertextCode'));
        $suggested = $locale !== '' ? LanguageCodes::suggest($locale) : null;
        $targets = LanguageCodes::options(i18n::get_locale());

        // Empty = the suggested code (shown in the empty entry), or "— choose —" when there is none.
        $options = ['' => $suggested !== null
            ? _t(self::class . '.SUGGESTED', 'Default: {language}', ['language' => $targets[$suggested] ?? $suggested])
            : _t(self::class . '.CHOOSE', '— choose —')];
        $options += $targets;
        if ($current !== '' && !isset($options[$current])) {
            // A code set before (or one Supertext added later): kept and sent as it is.
            $options[$current] = _t(self::class . '.UNKNOWN', '{code} (not in Supertext’s list)', ['code' => $current]);
        }

        $code = $current !== '' ? $current : $suggested;
        $fields->addFieldToTab(
            'Root.Main',
            DropdownField::create('SupertextCode', _t(self::class . '.CODE', 'Supertext language'), $options)
                ->setDescription(_t(
                    self::class . '.CODE_HELP',
                    'The Supertext language this locale is translated into, and from. Leave it on Default to use the suggested one; choose another if you need a different variant (e.g. de → German (Switzerland)). Locales without a default (e.g. en_IE, gsw) need a Supertext language.'
                ))
        );

        // Formal/informal only where the chosen Supertext language offers it (or when one is set and needs resetting).
        $politeness = (string) $this->owner->getField('SupertextPoliteness');
        $supports = $code !== null ? LanguageCodes::supportsPoliteness($code) : null;
        if ($supports !== false || $politeness !== '') {
            $help = $supports === false
                ? _t(
                    self::class . '.NO_POLITENESS',
                    'Supertext offers no formal or informal tone for {code}: leave this on Default, otherwise translations into this locale fail.',
                    ['code' => (string) $code]
                )
                : _t(self::class . '.POLITENESS_HELP', 'How Supertext addresses the reader in this language.');
            $fields->addFieldToTab('Root.Main', DropdownField::create('SupertextPoliteness', _t(self::class . '.POLITENESS', 'Form of address'), [
                ''     => _t(self::class . '.DEFAULT', 'Default'),
                'more' => _t(self::class . '.FORMAL', 'Formal (Sie, vous)'),
                'less' => _t(self::class . '.INFORMAL', 'Informal (du, tu)'),
            ])->setDescription($help));
        }

        $fields->addFieldToTab('Root.Main', CheckboxField::create('SupertextReview', _t(self::class . '.REVIEW', 'Translation review'))
            ->setDescription(_t(
                self::class . '.REVIEW_HELP',
                'Translations into this locale go through Supertext’s review service, so reviewers can check and correct them on the website with the Supertext Localization Review extension. Their corrections are used the next time the page is translated.'
            )));
    }

    /** Saving stores Supertext's spelling of the code ("de_ch" → "de-CH"). */
    protected function onBeforeWrite(): void
    {
        $this->owner->setField('SupertextCode', LanguageCodes::canonical((string) $this->owner->getField('SupertextCode')));
    }

    protected function updateValidate(ValidationResult $result): void
    {
        $code = LanguageCodes::canonical((string) $this->owner->getField('SupertextCode'));
        // A code that isn't in Supertext's list is only kept when it was saved before (it is sent as it is).
        if ($code !== '' && !isset(LanguageCodes::options()[$code]) && $this->owner->isChanged('SupertextCode', DataObject::CHANGE_VALUE)) {
            $result->addFieldError('SupertextCode', _t(self::class . '.INVALID', '{code} is not a Supertext language.', ['code' => $code]));
        }
    }

    /**
     * Upgrade (dev/build): codes saved in another spelling (de_ch, DE-CH) are rewritten in Supertext's
     * (de-CH), so the combo box shows them selected. Empty stays empty (= the default), and codes
     * Supertext doesn't list are kept as they are.
     */
    protected function onRequireDefaultRecords(): void
    {
        $changed = 0;
        foreach (Locale::get() as $locale) {
            $saved = (string) $locale->getField('SupertextCode');
            $canonical = LanguageCodes::canonical($saved);
            if ($canonical !== $saved) {
                $locale->setField('SupertextCode', $canonical);
                $locale->write();
                $changed++;
            }
        }
        if ($changed > 0) {
            DB::alteration_message("Supertext: $changed locale code(s) rewritten in Supertext's spelling", 'changed');
        }
    }
}
