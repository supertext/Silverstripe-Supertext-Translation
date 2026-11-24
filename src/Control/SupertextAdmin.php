<?php

namespace Supertext\Silverstripe\Control;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;
use SilverStripe\ORM\FieldType\DBDatetime;
use Supertext\Silverstripe\Api\LanguageCodes;
use Supertext\Silverstripe\Model\TranslationLog;
use Supertext\Silverstripe\PluginVersion;
use Supertext\Silverstripe\PluginHealth\KeyCheck;
use Supertext\Silverstripe\Service\Messages;
use Supertext\Silverstripe\Service\Reports;
use Supertext\Silverstripe\Supertext;
use TractorCow\Fluent\Control\LocaleAdmin;
use TractorCow\Fluent\Model\Locale;

/**
 * Admin section "Supertext": connection status with the latest API key check, Test connection
 * (administrators), whether version information is sent to Supertext, the locales with their
 * Supertext language and form of address, and the translation log.
 */
class SupertextAdmin extends ModelAdmin
{
    private static string $url_segment = 'supertext';

    private static string $menu_title = 'Supertext';

    private static string $menu_icon_class = 'font-icon-translatable';

    private static array $managed_models = [
        'translations' => ['dataClass' => TranslationLog::class, 'title' => 'Translations'],
    ];

    private static array $allowed_actions = ['doTestConnection', 'doSendConfiguration'];

    private static array $required_permission_codes = [Supertext::PERMISSION];

    public $showImportForm = false;

    protected function init()
    {
        parent::init();
        Requirements::javascript('supertext/silverstripe-supertext-translation:client/supertext.js');
        Requirements::css('supertext/silverstripe-supertext-translation:client/supertext.css');
        // Version report and key check (only if the key changed), after the response.
        Reports::onRequest();
    }

    public function getEditForm($id = null, $fields = null): Form
    {
        $form = parent::getEditForm($id, $fields);

        $fields = $form->Fields();
        $fields->unshift(LiteralField::create('SupertextLocales', $this->localesHtml()));
        if (Permission::check('ADMIN')) {
            $fields->unshift(
                FormAction::create('doTestConnection', _t(self::class . '.TEST', 'Test connection'))
                    ->addExtraClass('btn-outline-primary font-icon-sync supertext-test')
                    ->setUseButtonTag(true)
                    ->setAttribute('data-busy', _t(self::class . '.TESTING', 'Testing…'))
            );
        }
        if (Permission::check('ADMIN')) {
            // Below the connection table, next to Test connection: installed modules and language mapping, on demand.
            $fields->unshift(LiteralField::create('SupertextConfigHelp', '<p class="supertext-help">' . Convert::raw2xml(_t(self::class . '.CONFIG_HELP', 'Sends Supertext the modules and themes installed on this site (name, version) and its Supertext language mappings, so Supertext support can help better. Nothing else is sent.')) . '</p>'));
            $fields->unshift(
                FormAction::create('doSendConfiguration', _t(self::class . '.CONFIG', 'Send configuration to Supertext'))
                    ->addExtraClass('btn-outline-primary font-icon-upload supertext-test supertext-config')
                    ->setUseButtonTag(true)
                    ->setAttribute('data-busy', _t(self::class . '.CONFIG_SENDING', 'Sending…'))
            );
        }
        $fields->unshift(LiteralField::create('SupertextConnection', $this->connectionHtml()));

        return $form;
    }

    protected function getGridFieldConfig(): GridFieldConfig
    {
        return GridFieldConfig_RecordViewer::create(50);
    }

    public function doTestConnection(array $data, Form $form): HTTPResponse
    {
        if (!Permission::check('ADMIN')) {
            throw new HTTPResponse_Exception('Action not allowed', 403);
        }
        // The automatic key check (cost-free): stored, shown in the Connection section, reported if it changed.
        $check = Reports::testConnection();
        $message = $check !== null ? Messages::keyCheck($check, false) : Messages::noApiKey();
        $status = $check?->status === KeyCheck::OK ? 200 : 400;

        $response = $status === 200
            ? $this->getResponseNegotiator()->respond($this->getRequest())
            : HTTPResponse::create($message, $status);
        $response->addHeader('X-Status', rawurlencode($message));

        return $response;
    }

    public function doSendConfiguration(array $data, Form $form): HTTPResponse
    {
        if (!Permission::check('ADMIN')) {
            throw new HTTPResponse_Exception('Action not allowed', 403);
        }
        $result = Reports::sendConfiguration();
        if (!$result['enabled']) {
            $message = _t(self::class . '.CONFIG_OFF', 'Turn on version information (version_reports) to send the configuration.');
        } elseif (!$result['sent']) {
            $message = _t(self::class . '.CONFIG_FAILED', "The configuration couldn't be sent. Try again later.");
        } else {
            $message = _t(self::class . '.CONFIG_SENT', 'Sent: {extensions} modules and themes, {languages} languages.', ['extensions' => $result['extensions'], 'languages' => $result['languages']]);
        }

        $response = $result['sent']
            ? $this->getResponseNegotiator()->respond($this->getRequest())
            : HTTPResponse::create($message, 400);
        $response->addHeader('X-Status', rawurlencode($message));

        return $response;
    }

    private function connectionHtml(): string
    {
        $settings = Supertext::singleton();
        $key = $settings->apiKey() !== ''
            ? _t(self::class . '.KEY_SET', 'Set (environment variable SUPERTEXT_API_KEY)')
            : '<strong>' . _t(self::class . '.KEY_MISSING', 'Missing: set the environment variable SUPERTEXT_API_KEY') . '</strong>';
        $reports = $settings->versionReportsEnabled();

        return sprintf(
            '<div class="supertext-status"><h2>%s</h2><table class="table supertext-table"><tbody>
                <tr><th>%s</th><td>%s</td></tr>
                <tr><th>%s</th><td data-supertext-keycheck>%s</td></tr>
                <tr><th>%s</th><td><code data-supertext-endpoint>%s</code></td></tr>
                <tr><th>%s</th><td>%d s</td></tr>
                <tr><th>%s</th><td>%s</td></tr>
                <tr><th>%s</th><td data-supertext-reports>%s<p class="supertext-help">%s</p></td></tr>
            </tbody></table><p class="supertext-help">%s</p></div>',
            _t(self::class . '.CONNECTION', 'Connection'),
            _t(self::class . '.API_KEY', 'API key'),
            $key,
            _t(self::class . '.KEY_CHECK', 'API key check'),
            $this->keyCheckHtml(Reports::lastCheck()),
            _t(self::class . '.API', 'API'),
            Convert::raw2xml($settings->baseUrl()),
            _t(self::class . '.TIMEOUT', 'Timeout per locale'),
            $settings->timeout(),
            _t(self::class . '.VERSION', 'Plugin version'),
            $this->versionHtml(),
            _t(self::class . '.REPORTS', 'Send version information to Supertext'),
            $reports
                ? _t(self::class . '.REPORTS_ON', 'On') . ' · ' . _t(self::class . '.PING', 'Ping address: {url}', ['url' => '<code>' . Convert::raw2xml(Reports::callbackUrl()) . '</code>'])
                : '<strong>' . _t(self::class . '.REPORTS_OFF', 'Off') . '</strong>',
            _t(
                self::class . '.REPORTS_HELP',
                'Sends the site URL and the versions of this plugin, the CMS and the runtime to Supertext after an update and when the API key changes, so Supertext support can see which version you run. No content, no API key and no personal data are sent. Turn it off with version_reports: false in the YAML configuration or SUPERTEXT_VERSION_REPORTS=0.'
            ),
            _t(
                self::class . '.KEY_HELP',
                'No Supertext account yet? <a href="{signup}" target="_blank" rel="noopener">Create one at supertext.com</a>. Generate your API key at <a href="{apikey}" target="_blank" rel="noopener">supertext.com → Integrations → API</a> (requires the Admin role).',
                ['signup' => Supertext::SIGNUP_URL, 'apikey' => Supertext::API_KEY_URL]
            )
        );
    }

    /** The latest key check with its time, or "not checked yet". */
    private function keyCheckHtml(?KeyCheck $check): string
    {
        if ($check === null) {
            return _t(self::class . '.NOT_CHECKED', 'Not checked yet. Test connection checks the key now.');
        }
        $time = strtotime($check->checkedAt);
        $when = $time === false ? $check->checkedAt : DBDatetime::create()->setValue(date('Y-m-d H:i:s', $time))->FormatFromSettings();

        return sprintf(
            '<span class="%s">%s</span><br><small>%s</small>',
            $check->status === KeyCheck::OK ? 'supertext-ok' : 'supertext-warning',
            Messages::keyCheck($check),
            _t(self::class . '.CHECKED_AT', 'checked {time}', ['time' => Convert::raw2xml($when)])
        );
    }

    /** The installed version, linked to its GitHub release when it is one (X.Y.Z). */
    private function versionHtml(): string
    {
        $version = PluginVersion::current();
        $url = PluginVersion::releaseUrl($version);
        if ($url === null) {
            return Convert::raw2xml($version);
        }

        return sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', Convert::raw2att($url), Convert::raw2xml($version));
    }

    private function localesHtml(): string
    {
        $rows = '';
        foreach (Locale::getCached() as $locale) {
            $politeness = match (Supertext::politeness($locale)) {
                'more'  => _t(self::class . '.FORMAL', 'Formal (Sie, vous)'),
                'less'  => _t(self::class . '.INFORMAL', 'Informal (du, tu)'),
                default => _t(self::class . '.DEFAULT', 'Default'),
            };
            $code = Supertext::languageCodeOrNull($locale);
            if ($code !== null && Supertext::politeness($locale) !== '' && LanguageCodes::supportsPoliteness($code) === false) {
                $politeness .= ' <span class="supertext-warning">· ' . _t(self::class . '.NO_POLITENESS', 'Supertext offers no formal/informal choice for this language') . '</span>';
            }
            $rows .= sprintf(
                '<tr><td>%s <code>%s</code></td><td>%s</td><td>%s</td></tr>',
                Convert::raw2xml($locale->getTitle()),
                Convert::raw2xml($locale->Locale),
                $code !== null
                    ? '<code>' . Convert::raw2xml($code) . '</code>'
                    : '<strong class="supertext-warning">' . _t(self::class . '.NOT_MAPPED', 'Not mapped: choose a Supertext language for this locale') . '</strong>',
                $politeness
            );
        }

        return sprintf(
            '<div class="supertext-status supertext-locales"><h2>%s</h2><p>%s</p>
                <table class="table supertext-table"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>
                <h2>%s</h2></div>',
            _t(self::class . '.LOCALES', 'Locales'),
            _t(
                self::class . '.LOCALES_HELP',
                'The Fluent locales with the Supertext language and form of address they are translated with. Change them under <a href="{link}">Locales</a>.',
                ['link' => Convert::raw2att(LocaleAdmin::singleton()->Link())]
            ),
            _t(self::class . '.LOCALE', 'Locale'),
            _t(self::class . '.CODE', 'Supertext language'),
            _t(self::class . '.POLITENESS', 'Form of address'),
            $rows,
            _t(self::class . '.LOG', 'Translations')
        );
    }
}
