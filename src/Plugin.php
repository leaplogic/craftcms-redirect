<?php

namespace leaplogic\redirects;

use Craft;
use craft\base\Model;
use craft\events\ExceptionEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\web\twig\variables\Cp;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
use craft\web\Application;
use craft\web\ErrorHandler;
use craft\web\UrlManager;
use leaplogic\redirects\models\Settings;
use leaplogic\redirects\services\CodeRules;
use leaplogic\redirects\services\NotFoundLog;
use leaplogic\redirects\services\Rules;
use Twig\Error\RuntimeError;
use yii\base\Event;
use yii\web\HttpException;

/**
 * @property-read Rules $rules
 * @property-read NotFoundLog $notFoundLog
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();
        $this->setComponents(['rules' => Rules::class, 'notFoundLog' => NotFoundLog::class]);
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = ['heading' => 'Redirects', 'permissions' => [
                'redirects-manage' => ['label' => 'Manage redirects and import CSV'],
                'redirects-view404s' => ['label' => 'View and manage the 404 log'],
            ]];
        });
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'leaplogic\\redirects\\console\\controllers';
            return;
        }
        Event::on(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS, function(RegisterCpNavItemsEvent $event): void {
            $nav = $this->getCpNavItem();
            if ($nav !== null && !Craft::$app->getUser()->checkPermission('accessPlugin-' . $this->id)) {
                $event->navItems[] = $nav;
            }
        });
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event): void {
            $event->rules = array_merge($event->rules, [
                'redirects' => 'redirects/rules/index',
                'redirects/new' => 'redirects/rules/edit',
                'redirects/<id:\\d+>' => 'redirects/rules/edit',
                'redirects/404s' => 'redirects/logs/index',
                'redirects/import' => 'redirects/import/index',
                'redirects/settings' => 'redirects/settings/index',
            ]);
        });
        Event::on(Application::class, Application::EVENT_BEFORE_REQUEST, function(): void {
            if ($this->eligible()) {
                $this->apply('always');
            }
        });
        Event::on(ErrorHandler::class, ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION, function(ExceptionEvent $event): void {
            $exception = $event->exception;
            if ($exception instanceof RuntimeError && $exception->getPrevious() !== null) {
                $exception = $exception->getPrevious();
            }
            if (!$exception instanceof HttpException || $exception->statusCode !== 404 || !$this->eligible()) {
                return;
            }
            $this->apply('notFound');
            try {
                if (!(new CodeRules())->matches()) {
                    $this->notFoundLog->record(Craft::$app->getSites()->getCurrentSite()->id, $this->requestPath());
                }
            } catch (\Throwable $e) {
                Craft::warning('Could not evaluate native redirects before logging: ' . $e->getMessage(), 'redirects');
            }
        });
    }

    private function eligible(): bool
    {
        $request = Craft::$app->getRequest();
        return $request->getIsSiteRequest() && !$request->getIsActionRequest()
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && !$request->getIsLivePreview() && !$request->getIsPreview()
            && !$request->getHadToken() && $request->getToken() === null
            && $request->getQueryParam('x-craft-preview') === null
            && $request->getQueryParam('x-craft-live-preview') === null
            && !$request->getHeaders()->has('X-Craft-Preview-Token');
    }

    private function requestPath(): string
    {
        // Use the raw URL path: Craft's route path is already decoded and strips the site's base path.
        return (string)(parse_url(Craft::$app->getRequest()->getAbsoluteUrl(), PHP_URL_PATH) ?? '');
    }

    private function apply(string $mode): void
    {
        $request = Craft::$app->getRequest();
        $match = $this->rules->match($this->requestPath(), Craft::$app->getSites()->getCurrentSite()->id, $mode, $request->getAbsoluteUrl(), $request->getQueryStringWithoutPath());
        if ($match === null) {
            return;
        }
        $this->rules->recordHit((int)$match['rule']['id']);
        $response = Craft::$app->getResponse();
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->data = null;
        $response->content = $match['statusCode'] === 410 && !$request->getIsHead() ? 'Gone' : '';
        $response->setStatusCode($match['statusCode']);
        if ($match['statusCode'] === 410) {
            $response->getHeaders()->remove('Location');
        } else {
            // Preserve the resolved URL and use Location even for AJAX site requests.
            $response->getHeaders()->set('Location', $match['url']);
        }
        if (Craft::$app->state === Application::STATE_SENDING_RESPONSE) {
            $response->send();
        }
        Craft::$app->end();
    }

    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();
        $manage = $user->checkPermission('redirects-manage');
        $logs = $user->checkPermission('redirects-view404s');
        if (!$manage && !$logs) {
            return null;
        }
        $nav = parent::getCpNavItem();
        $nav['url'] = 'redirects';
        $nav['subnav'] = [];
        if ($manage) {
            $nav['subnav']['rules'] = ['label' => 'Redirects', 'url' => 'redirects'];
        }
        if ($logs) {
            $nav['subnav']['logs'] = ['label' => '404s', 'url' => 'redirects/404s'];
        }
        if ($manage) {
            $nav['subnav']['import'] = ['label' => 'Import', 'url' => 'redirects/import'];
        }
        if ($user->getIsAdmin()) {
            $nav['subnav']['settings'] = ['label' => 'Settings', 'url' => 'redirects/settings'];
        }
        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('redirects/settings'));
    }
}
