<?php

/**
 * @package     Supertext Translation for Silverstripe
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\Silverstripe\Api;

use Supertext\Silverstripe\Sdk\V1\Languages;
use Supertext\Silverstripe\Sdk\V1\SupertextException as SdkException;

/**
 * Fluent locales → Supertext language codes, with the Supertext SDK's rules (the same in every
 * Supertext plugin), without guessing.
 *
 * Supertext accepts exactly the codes of its `GET features` list (Languages::TARGETS/SOURCES):
 * bare sources ("en"); targets regional only for German, English, French, Italian and Portuguese
 * ("de-CH", "en-US"), with a script for Chinese and Serbian ("zh-Hans", "sr-Latn"), bare for the
 * others ("es", "nl"). A locale is resolved by the mapping (each locale's "Supertext language"
 * combo box), else a Supertext code equal to the locale apart from case and "_"/"-" ("de_CH" →
 * "de-CH"), else the SDK's default where one is obvious ("de" → "de-DE", "es_ES" → "es",
 * "zh_TW" → "zh-Hant"), else the error unsupported_language and nothing is sent ("en_IE", "de_LI",
 * "gsw" need a Supertext language).
 *
 * Has no Silverstripe dependencies.
 */
final class LanguageCodes
{
    /** @return list<array{code: string, name: string, politeness: bool}> the targets Supertext offers */
    public static function targets(): array
    {
        return Languages::TARGETS;
    }

    /**
     * The targets for the combo box, sorted by name, as code => "Name (code)". The name is in
     * $displayLocale where PHP's intl extension knows it ("Deutsch (Schweiz) (de-CH)"), else Supertext's
     * English name.
     *
     * @return array<string, string>
     */
    public static function options(string $displayLocale = 'en'): array
    {
        $options = [];
        foreach (Languages::targetOptions() as $target) {
            $options[$target['code']] = self::displayName($target['code'], $target['name'], $displayLocale) . ' (' . $target['code'] . ')';
        }
        // Localized names: sorted in that language's order where intl can.
        if (class_exists(\Collator::class) && !str_starts_with(strtolower($displayLocale), 'en')) {
            $collator = new \Collator(str_replace('-', '_', $displayLocale));
            uasort($options, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));
        }

        return $options;
    }

    /** What the combo box preselects for a locale: the Supertext code equal to it, else the default ("de" → "de-DE"), else null. */
    public static function suggest(string $locale): ?string
    {
        return Languages::suggestTarget($locale);
    }

    /** The saved value in Supertext's spelling ("de_ch" → "de-CH"); a code that isn't in the list stays as it is. */
    public static function canonical(string $code): string
    {
        $code = trim($code);

        return $code === '' ? '' : (Languages::matchTarget($code) ?? $code);
    }

    /**
     * Target code to send.
     *
     * @param array<string, string> $mapping locale → Supertext code (the locales' "Supertext language")
     *
     * @throws SupertextException unsupported_language when the locale is not mapped, not a Supertext code and has no default
     */
    public static function target(string $locale, array $mapping = []): string
    {
        try {
            return Languages::target($locale, self::clean($mapping));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /**
     * Source code to send: the mapped code or the locale in its source form ("de-CH" → "de").
     *
     * @param array<string, string> $mapping locale → Supertext code
     *
     * @throws SupertextException unsupported_language
     */
    public static function source(string $locale, array $mapping = []): string
    {
        try {
            return Languages::source($locale, self::clean($mapping));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /**
     * The target code, or null when the locale has none (for the status page and the editor).
     *
     * @param array<string, string> $mapping
     */
    public static function targetOrNull(string $locale, array $mapping = []): ?string
    {
        try {
            return Languages::target($locale, self::clean($mapping));
        } catch (SdkException) {
            return null;
        }
    }

    /** The Supertext code equal to the locale (apart from case and "_"/"-"), else null: "de_CH" → "de-CH", "de" → null. */
    public static function match(string $locale): ?string
    {
        return Languages::matchTarget($locale);
    }

    /** Whether Supertext offers formal/informal for a target code; null for a code that isn't in its list. */
    public static function supportsPoliteness(string $target): ?bool
    {
        return Languages::supportsPoliteness($target);
    }

    private static function displayName(string $code, string $english, string $displayLocale): string
    {
        // Unicode extensions (gsw-u-sd-chzh) aren't display names intl renders well: keep Supertext's name.
        if (!class_exists(\Locale::class) || str_contains($code, '-u-') || str_starts_with(strtolower($displayLocale), 'en')) {
            return $english;
        }
        $name = \Locale::getDisplayName(str_replace('-', '_', $code), str_replace('-', '_', $displayLocale));
        if (!is_string($name) || strcasecmp($name, $code) === 0 || strcasecmp($name, str_replace('-', '_', $code)) === 0) {
            return $english;
        }

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /**
     * @param array<string, string> $mapping
     *
     * @return array<string, string> without blank entries
     */
    private static function clean(array $mapping): array
    {
        return array_filter(array_map(static fn ($code): string => trim((string) $code), $mapping), static fn (string $code): bool => $code !== '');
    }
}
