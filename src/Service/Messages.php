<?php

/**
 * @package     Supertext Translation for Silverstripe
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\Silverstripe\Service;

use SilverStripe\Core\Convert;
use Supertext\Silverstripe\Api\FileTransferException;
use Supertext\Silverstripe\Api\SupertextException;
use Supertext\Silverstripe\PluginHealth\KeyCheck;

/** Shows errors from the API client (which only speaks English) in the CMS user's language. */
final class Messages
{
    public const SIGNUP_URL = 'https://www.supertext.com/person/en/account/signin';
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    public static function of(\Throwable $e): string
    {
        if ($e instanceof FileTransferException) {
            return self::file($e);
        }
        if (!$e instanceof SupertextException || $e->reason === '') {
            return $e->getMessage();
        }

        $links = ['signup' => self::SIGNUP_URL, 'apikey' => self::API_KEY_URL];
        $text = match ($e->reason) {
            'no_file_id' => _t(self::class . '.NO_FILE_ID', 'Supertext did not return a file id.'),
            'translation_failed' => _t(self::class . '.TRANSLATION_FAILED', 'Supertext could not translate the document.'),
            'limit_exceeded' => _t(self::class . '.LIMIT_EXCEEDED', 'Your Supertext translation limit is exceeded. Please upgrade your subscription.'),
            'deleted' => _t(self::class . '.DELETED', 'The document was deleted at Supertext before it could be downloaded.'),
            'timeout' => _t(self::class . '.TIMEOUT', 'Timed out waiting for the Supertext translation.'),
            'empty' => _t(self::class . '.EMPTY', 'The translated document was empty.'),
            'no_api_key' => self::noApiKey(),
            'unreachable' => _t(self::class . '.UNREACHABLE', 'Could not reach Supertext: {error}', ['error' => (string) ($e->args[0] ?? '')]),
            'auth_failed' => _t(self::class . '.AUTH_FAILED', 'Authentication failed. Please check the Supertext API key. No Supertext account yet? Create one at {signup}. Generate your API key at {apikey} (requires the Admin role).', $links),
            'invalid_language_pair' => _t(self::class . '.INVALID_LANGUAGE_PAIR', 'Supertext does not support this language pair ({source} → {target}). Check the Supertext language of the locale.', ['source' => (string) ($e->args[0] ?? ''), 'target' => (string) ($e->args[1] ?? '')]),
            'unsupported_language' => _t(self::class . '.UNSUPPORTED_LANGUAGE', 'The language {language} has no Supertext language yet. Ask your administrator to map it (Locales → the locale → Supertext language).', ['language' => (string) ($e->args[0] ?? '')]),
            'unsupported_feature' => _t(self::class . '.UNSUPPORTED_FEATURE', 'Supertext offers no formal or informal tone for this language. Ask your administrator to set the form of address of the locale to Default (Locales → the locale).'),
            'not_found' => _t(self::class . '.NOT_FOUND', 'The requested Supertext resource was not found.'),
            'too_large' => _t(self::class . '.TOO_LARGE', 'The content is too large for Supertext to translate in one go.'),
            'rate_limited' => _t(self::class . '.RATE_LIMITED', 'Too many requests to Supertext. Please try again shortly.'),
            'unavailable' => _t(self::class . '.UNAVAILABLE', 'The Supertext service is currently unavailable.'),
            'http_error' => _t(self::class . '.HTTP_ERROR', 'Supertext answered with HTTP {status}.', ['status' => (string) ($e->args[0] ?? '')]),
            default => null,
        };

        if ($text === null) {
            return $e->getMessage();
        }

        return $e->detail !== '' ? $text . ' (' . $e->detail . ')' : $text;
    }

    /** Errors of "Export for translation" / "Import translation" in the CMS user's language. */
    public static function file(FileTransferException $e): string
    {
        $arg = (string) ($e->args[0] ?? '');

        return match ($e->reason) {
            'nothing_to_translate' => _t(self::class . '.FILE_NOTHING_TO_TRANSLATE', 'Nothing to translate.'),
            'invalid_request' => _t(self::class . '.FILE_INVALID_REQUEST', 'Choose a file format and at least one locale (exactly one for HTML).'),
            'invalid_file' => _t(self::class . '.FILE_INVALID_FILE', 'This file isn\'t a valid XLIFF or HTML translation file.'),
            'missing_metadata' => _t(self::class . '.FILE_MISSING_METADATA', 'This file wasn\'t exported by the Supertext module, or its header was removed.'),
            'too_large' => _t(self::class . '.FILE_TOO_LARGE', 'The file is too large (at most 10 MB).'),
            'other_system' => _t(self::class . '.FILE_OTHER_SYSTEM', 'This file belongs to another system ({plugin}).', ['plugin' => $arg]),
            'not_found' => _t(self::class . '.FILE_NOT_FOUND', 'The page this file was exported from no longer exists.'),
            'no_language' => _t(self::class . '.FILE_NO_LANGUAGE', 'The site has no locale {language}.', ['language' => $arg]),
            'no_file' => _t(self::class . '.FILE_NO_FILE', 'Choose a file to import.'),
            default => $e->getMessage(),
        };
    }

    /**
     * The result of the automatic API key check in the CMS user's language (texts from the Commando
     * Center's guide). $html: escaped, "supertext.com" and "supertext.com → Integrations → API" as links
     * opening in a new tab; otherwise plain text with the bare URLs.
     */
    public static function keyCheck(KeyCheck $check, bool $html = true): string
    {
        $text = match ($check->status) {
            KeyCheck::OK => _t(self::class . '.KEYCHECK_OK', 'API key checked: the connection to Supertext works.'),
            KeyCheck::INVALID => _t(self::class . '.KEYCHECK_INVALID', 'Supertext rejected the API key. Check that you copied the whole key, or generate a new one at supertext.com → Integrations → API (requires the Admin role). No Supertext account yet? Create one at supertext.com.'),
            KeyCheck::MISSING => _t(self::class . '.KEYCHECK_MISSING', 'No API key is set yet. No Supertext account yet? Create one at supertext.com. Generate your API key at supertext.com → Integrations → API (requires the Admin role).'),
            KeyCheck::UNREACHABLE => _t(self::class . '.KEYCHECK_UNREACHABLE', 'The API key couldn\'t be checked because Supertext can\'t be reached. The settings are saved; check again later.'),
            default => _t(self::class . '.KEYCHECK_ERROR', 'The API key couldn\'t be checked: Supertext answered with an error ({status}). The settings are saved; check again later.', ['status' => (string) ($check->httpStatus ?? $check->errorCode ?? '')]),
        };

        return self::withLinks($text, $html);
    }

    /** Turns "supertext.com → Integrations → API" and "supertext.com" into the API key and account links. */
    public static function withLinks(string $text, bool $html = true): string
    {
        $path = 'supertext.com → Integrations → API';
        if (!$html) {
            $text = str_replace($path, "\x00", $text);
            $text = (string) preg_replace('/\bsupertext\.com\b/u', self::SIGNUP_URL, $text);

            return str_replace("\x00", $path . ' (' . self::API_KEY_URL . ')', $text);
        }
        $text = str_replace(Convert::raw2xml($path), "\x00", Convert::raw2xml($text));
        $text = (string) preg_replace('/\bsupertext\.com\b/u', sprintf('<a href="%s" target="_blank" rel="noopener">supertext.com</a>', self::SIGNUP_URL), $text);

        return str_replace("\x00", sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', self::API_KEY_URL, Convert::raw2xml($path)), $text);
    }

    public static function noApiKey(): string
    {
        return _t(
            self::class . '.NO_API_KEY',
            'No Supertext API key is configured. Set the SUPERTEXT_API_KEY environment variable. No Supertext account yet? Create one at {signup}. Generate your API key at {apikey} (requires the Admin role).',
            ['signup' => self::SIGNUP_URL, 'apikey' => self::API_KEY_URL]
        );
    }
}
