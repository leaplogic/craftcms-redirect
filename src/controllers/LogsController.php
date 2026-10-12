<?php

namespace leaplogic\redirects\controllers;

use Craft;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use leaplogic\redirects\Plugin;
use leaplogic\redirects\services\NotFoundLog;
use yii\web\Response;

class LogsController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requirePermission('redirects-view404s');
        $days = (int)$this->request->getQueryParam('days', 30);
        if (!in_array($days, [0, 7, 30], true)) {
            $days = 30;
        }
        Plugin::getInstance()->notFoundLog->prune();
        $rows = Plugin::getInstance()->notFoundLog->visible($days);
        $pages = max(1, (int)ceil(count($rows) / 50));
        $page = min($pages, max(1, (int)$this->request->getQueryParam('page', 1)));
        return $this->renderTemplate('redirects/logs/index', [
            'rows' => array_slice($rows, ($page - 1) * 50, 50), 'count' => count($rows),
            'days' => $days, 'page' => $page, 'pages' => $pages,
            'canManage' => Craft::$app->getUser()->checkPermission('redirects-manage'),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('redirects-view404s');
        Db::delete(NotFoundLog::TABLE, ['id' => (int)$this->request->getRequiredBodyParam('id')]);
        Craft::$app->getSession()->setNotice('404 deleted.');
        return $this->redirect(UrlHelper::cpUrl('redirects/404s'));
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('redirects-view404s');
        Db::delete(NotFoundLog::TABLE);
        Craft::$app->getSession()->setNotice('404 log cleared.');
        return $this->redirect(UrlHelper::cpUrl('redirects/404s'));
    }
}
