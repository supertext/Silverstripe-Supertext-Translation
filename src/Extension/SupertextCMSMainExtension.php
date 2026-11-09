<?php

namespace Supertext\Silverstripe\Extension;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use Supertext\Silverstripe\Api\FileTransfer;
use Supertext\Silverstripe\Api\FileTransferException;
use Supertext\Silverstripe\Api\SupertextException;
use Supertext\Silverstripe\Model\TranslationLog;
use Supertext\Silverstripe\Service\Messages;
use Supertext\Silverstripe\Service\Translator;
use Supertext\Silverstripe\Supertext;
use TractorCow\Fluent\Model\Locale;

/**
 * Handles the Supertext tab in the page editor: the Translate button, and "Export for
 * translation" / "Import translation" (admin/pages/edit/supertextExport/<id> and
 * admin/pages/edit/supertextImport, POSTed by client/supertext.js with the form's SecurityID).
 *
 * @extends Extension<LeftAndMain>
 */
class SupertextCMSMainExtension extends Extension
{
    private static array $allowed_actions = ['doSupertextTranslate', 'supertextExport', 'supertextImport'];

    /** "Export for translation": the file as a download, or a JSON {message} with status 400. */
    public function supertextExport(HTTPRequest $request): HTTPResponse
    {
        $this->guard($request);
        $record = SiteTree::get()->byID((int) $request->param('ID'));
        $source = (string) $request->postVar('source');
        if (!$record instanceof SiteTree || $source === '') {
            return $this->json(['message' => Messages::file(new FileTransferException('not_found'))], 400);
        }
        $targets = array_values(array_filter((array) $request->postVar('targets'), 'is_string'));
        $format = $request->postVar('format') === FileTransfer::HTML ? FileTransfer::HTML : FileTransfer::XLIFF;

        try {
            $file = Translator::singleton()->exportFile($record, $source, $targets, $format, Security::getCurrentUser());
        } catch (FileTransferException|SupertextException $e) {
            return $this->json(['message' => Messages::of($e)], 400);
        }

        $response = HTTPResponse::create($file['body'], 200);
        $response->addHeader('Content-Type', $file['type']);
        $response->addHeader('Content-Disposition', 'attachment; filename="' . $file['name'] . '"');
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** "Import translation": the summary as JSON {html}, or {message} with status 400. Nothing is stored. */
    public function supertextImport(HTTPRequest $request): HTTPResponse
    {
        $this->guard($request);
        $upload = $request->postVar('file');

        try {
            $error = is_array($upload) ? (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new FileTransferException('too_large');
            }
            $path = is_array($upload) ? (string) ($upload['tmp_name'] ?? '') : '';
            if ($error !== UPLOAD_ERR_OK || $path === '' || !is_uploaded_file($path)) {
                throw new FileTransferException('no_file');
            }
            // Refuse large files before reading them.
            FileTransfer::assertUploadSize(max((int) ($upload['size'] ?? 0), (int) filesize($path)));
            $results = Translator::singleton()->importFile(
                (string) file_get_contents($path),
                (bool) $request->postVar('include_changed'),
                (bool) $request->postVar('overwrite'),
                Security::getCurrentUser()
            );
        } catch (FileTransferException $e) {
            return $this->json(['message' => Messages::of($e)], 400);
        }

        return $this->json(['html' => $this->summaryHtml($results)]);
    }

    /** @param list<array<string, mixed>> $results Translator::importFile() results */
    private function summaryHtml(array $results): string
    {
        $names = [];
        foreach (Locale::getCached() as $locale) {
            $names[$locale->Locale] = $locale->getTitle();
        }
        $html = '';
        foreach ($results as $result) {
            $locale = Convert::raw2xml($names[$result['target']] ?? ($result['target'] ?: $result['reference']));
            $saved = $result['saved'] ?? [];
            $title = Convert::raw2xml((string) ($saved['title'] ?? ''));
            if (isset($result['error'])) {
                $html .= '<li class="supertext-warning">' . $locale . ': ' . Convert::raw2xml(Messages::of($result['error'])) . '</li>';
                continue;
            }
            $head = match ($saved['status'] ?? '') {
                'created' => _t(self::class . '.IMPORT_CREATED', '{locale}: “{title}” translated (saved as a draft).', ['locale' => $locale, 'title' => $title]),
                'updated' => _t(self::class . '.IMPORT_UPDATED', '{locale}: “{title}” updated (saved as a draft).', ['locale' => $locale, 'title' => $title]),
                'skipped' => _t(self::class . '.IMPORT_SKIPPED', '{locale}: already translated, skipped. Tick Overwrite existing translations to replace it.', ['locale' => $locale]),
                default => $locale,
            };
            $counts = $result['counts'];
            $lines = ['<li>' . _t(self::class . '.SUMMARY_OK', 'Imported: {count}', ['count' => $counts['imported']]) . '</li>'];
            foreach ([
                'changed'      => _t(self::class . '.SUMMARY_CHANGED', 'Skipped, source changed since the export: {count}', ['count' => $counts['changed']]),
                'untranslated' => _t(self::class . '.SUMMARY_UNTRANSLATED', 'Not translated in the file: {count}', ['count' => $counts['untranslated']]),
                'invalid'      => _t(self::class . '.SUMMARY_INVALID', 'Formatting changed, not imported: {count}', ['count' => $counts['invalid']]),
                'missing'      => _t(self::class . '.SUMMARY_MISSING', 'Not in the file: {count}', ['count' => $counts['missing']]),
            ] as $key => $text) {
                if ($counts[$key] > 0) {
                    $lines[] = '<li>' . $text . '</li>';
                }
            }
            $html .= '<li><strong>' . $head . '</strong><ul>' . implode('', $lines) . '</ul></li>';
        }

        return '<ul class="supertext-import-summary">' . $html . '</ul>';
    }

    /** POST, the CMS form's SecurityID and the Supertext permission. */
    private function guard(HTTPRequest $request): void
    {
        if (!$request->isPOST() || !SecurityToken::inst()->checkRequest($request) || !Permission::check(Supertext::PERMISSION)) {
            throw new HTTPResponse_Exception('Action not allowed', 403);
        }
    }

    private function json(array $data, int $status = 200): HTTPResponse
    {
        $response = HTTPResponse::create((string) json_encode($data), $status);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');

        return $response;
    }

    public function doSupertextTranslate(array $data, Form $form): HTTPResponse
    {
        if (!Permission::check(Supertext::PERMISSION)) {
            throw new HTTPResponse_Exception('Action not allowed', 403);
        }
        $record = $form->getRecord();
        $source = Locale::getCurrentLocale();
        if (!$record instanceof DataObject || !$record->isInDB() || !$source || !$record->canEdit()) {
            throw new HTTPResponse_Exception('Action not allowed', 403);
        }

        $targets = array_values(array_filter((array) ($data['SupertextTargets'] ?? []), 'is_string'));
        if ($targets === []) {
            return $this->complete(_t(self::class . '.NONE', 'Choose at least one locale to translate into.'), 400);
        }

        try {
            $results = Translator::singleton()->translate(
                $record,
                $source->Locale,
                $targets,
                !empty($data['SupertextOverwrite']),
                Security::getCurrentUser()
            );
        } catch (SupertextException $e) {
            return $this->complete(Messages::of($e), 400);
        }

        $names = [];
        foreach (Locale::getCached() as $locale) {
            $names[$locale->Locale] = $locale->getTitle();
        }
        $done = $skipped = $errors = [];
        foreach ($results as $result) {
            $name = $names[$result['locale']] ?? $result['locale'];
            match ($result['status']) {
                TranslationLog::TRANSLATED => $done[] = $name,
                TranslationLog::SKIPPED    => $skipped[] = $name,
                default                    => $errors[] = $name . ': ' . $result['message'],
            };
        }

        $parts = [];
        if ($done) {
            $parts[] = _t(self::class . '.DONE', 'Translated into {locales} (saved as drafts).', ['locales' => implode(', ', $done)]);
        }
        if ($skipped) {
            $parts[] = _t(self::class . '.SKIPPED', 'Already translated, skipped: {locales}.', ['locales' => implode(', ', $skipped)]);
        }
        if ($errors) {
            $parts[] = _t(self::class . '.FAILED', 'Failed: {errors}', ['errors' => implode('; ', $errors)]);
        }

        return $this->complete(implode(' ', $parts), $done || !$errors ? 200 : 400);
    }

    private function complete(string $message, int $status = 200): HTTPResponse
    {
        $owner = $this->owner;
        $response = $status === 200
            ? $owner->getResponseNegotiator()->respond($owner->getRequest())
            : HTTPResponse::create($message, $status);
        $response->addHeader('X-Status', rawurlencode($message));

        return $response;
    }
}
