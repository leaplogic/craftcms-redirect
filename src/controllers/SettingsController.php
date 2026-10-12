<?php

namespace leaplogic\redirects\controllers;

use Craft;
use craft\helpers\UrlHelper;
use leaplogic\redirects\Plugin;
use yii\web\Response;

class SettingsController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);
        return $this->renderSettings();
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $settings = [
            'loggingEnabled' => (bool)$this->request->getBodyParam('loggingEnabled', false),
            'ignorePatterns' => (string)$this->request->getBodyParam('ignorePatterns', ''),
            'max404Rows' => filter_var($this->request->getBodyParam('max404Rows'), FILTER_VALIDATE_INT) ?: 0,
            'retentionDays' => filter_var($this->request->getBodyParam('retentionDays'), FILTER_VALIDATE_INT) ?: 0,
        ];
        if (!Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $settings)) {
            Craft::$app->getSession()->setError('Could not save settings.');
            return $this->renderSettings();
        }
        Plugin::getInstance()->notFoundLog->prune();
        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirect(UrlHelper::cpUrl('redirects/settings'));
    }

    private function renderSettings(): Response
    {
        return $this->renderTemplate('redirects/settings', [
            'settings' => Plugin::getInstance()->getSettings(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }
}
