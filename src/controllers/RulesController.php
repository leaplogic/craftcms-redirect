<?php

namespace leaplogic\redirects\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\UrlHelper;
use leaplogic\redirects\models\Rule;
use leaplogic\redirects\Plugin;
use leaplogic\redirects\services\CodeRules;
use leaplogic\redirects\services\NotFoundLog;
use leaplogic\redirects\services\Rules;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class RulesController extends BaseController
{
    public function actionIndex(): Response
    {
        if (!Craft::$app->getUser()->checkPermission('redirects-manage') && Craft::$app->getUser()->checkPermission('redirects-view404s')) {
            return $this->redirect(UrlHelper::cpUrl('redirects/404s'));
        }
        $this->requirePermission('redirects-manage');
        $search = trim((string)$this->request->getQueryParam('q', ''));
        $sort = (string)$this->request->getQueryParam('sort', 'sourcePath');
        $direction = $this->request->getQueryParam('direction') === 'desc' ? 'desc' : 'asc';
        if (!in_array($sort, ['sourcePath', 'destination', 'matchType', 'mode', 'statusCode', 'hitCount', 'lastHitAt', 'enabled'], true)) {
            $sort = 'sourcePath';
        }
        $query = (new Query())->from(Rules::TABLE);
        if ($search !== '') {
            $query->where(['or', ['like', 'sourcePath', $search], ['like', 'destination', $search], ['like', 'note', $search]]);
        }
        $count = (int)$query->count();
        $pages = max(1, (int)ceil($count / 50));
        $page = min($pages, max(1, (int)$this->request->getQueryParam('page', 1)));
        return $this->renderTemplate('redirects/rules/index', [
            'rules' => $query->orderBy([$sort => $direction === 'desc' ? SORT_DESC : SORT_ASC, 'id' => SORT_ASC])->offset(($page - 1) * 50)->limit(50)->all(),
            'codeRules' => (new CodeRules())->forDisplay(),
            'search' => $search, 'sort' => $sort, 'direction' => $direction, 'page' => $page, 'pages' => $pages, 'count' => $count,
        ]);
    }

    public function actionEdit(?int $id = null): Response
    {
        $this->requirePermission('redirects-manage');
        $rule = $id === null ? new Rule() : $this->find($id);
        $logId = (int)$this->request->getQueryParam('logId', 0) ?: null;
        if ($logId !== null && $id === null) {
            $this->requirePermission('redirects-view404s');
            $log = (new Query())->from(NotFoundLog::TABLE)->where(['id' => $logId])->one();
            if (!$log) {
                throw new NotFoundHttpException('404 log entry not found.');
            }
            $rule->setNormalizedSource($log['path']);
            $rule->siteId = (int)$log['siteId'];
        } elseif ($id === null) {
            $rule->sourcePath = (string)$this->request->getQueryParam('source', '');
        }
        return $this->editResponse($rule, $logId);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('redirects-manage');
        $id = (int)$this->request->getBodyParam('id', 0);
        $rule = $id ? $this->find($id) : new Rule();
        $logId = (int)$this->request->getBodyParam('logId', 0) ?: null;
        if ($logId !== null) {
            $this->requirePermission('redirects-view404s');
        }
        foreach (['sourcePath', 'matchType', 'destination', 'mode', 'note'] as $field) {
            $rule->$field = (string)$this->request->getBodyParam($field, '');
        }
        $rule->statusCode = filter_var($this->request->getBodyParam('statusCode'), FILTER_VALIDATE_INT) ?: 0;
        $rule->siteId = (int)$this->request->getBodyParam('siteId', 0) ?: null;
        $rule->preserveQuery = (bool)$this->request->getBodyParam('preserveQuery', false);
        $rule->enabled = (bool)$this->request->getBodyParam('enabled', false);
        if (!Plugin::getInstance()->rules->save($rule, $logId)) {
            Craft::$app->getSession()->setError('Could not save redirect.');
            return $this->editResponse($rule, $logId);
        }
        Craft::$app->getSession()->setNotice('Redirect saved.');
        return $this->redirect(UrlHelper::cpUrl('redirects'));
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('redirects-manage');
        $rule = $this->find((int)$this->request->getRequiredBodyParam('id'));
        $rule->enabled = (bool)$this->request->getRequiredBodyParam('enabled');
        if (!Plugin::getInstance()->rules->save($rule)) {
            Craft::$app->getSession()->setError(implode(' ', $rule->getFirstErrors()));
        } else {
            Craft::$app->getSession()->setNotice('Redirect updated.');
        }
        return $this->redirect(UrlHelper::cpUrl('redirects'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('redirects-manage');
        Plugin::getInstance()->rules->delete((int)$this->request->getRequiredBodyParam('id'));
        Craft::$app->getSession()->setNotice('Redirect deleted.');
        return $this->redirect(UrlHelper::cpUrl('redirects'));
    }

    private function find(int $id): Rule
    {
        return Plugin::getInstance()->rules->get($id) ?? throw new NotFoundHttpException('Redirect not found.');
    }

    private function editResponse(Rule $rule, ?int $logId): Response
    {
        $sites = [['label' => 'All sites', 'value' => '']];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sites[] = ['label' => $site->name, 'value' => $site->id];
        }
        return $this->renderTemplate('redirects/rules/edit', ['rule' => $rule, 'sites' => $sites, 'logId' => $logId]);
    }
}
