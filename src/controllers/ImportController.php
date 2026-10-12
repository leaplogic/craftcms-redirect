<?php

namespace leaplogic\redirects\controllers;

use leaplogic\redirects\Plugin;
use leaplogic\redirects\services\Importer;
use yii\web\Response;

class ImportController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requirePermission('redirects-manage');
        return $this->renderTemplate('redirects/import/index', ['csv' => '', 'report' => null, 'imported' => false]);
    }

    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('redirects-manage');
        $csv = (string)$this->request->getRequiredBodyParam('csv');
        return $this->renderTemplate('redirects/import/index', [
            'csv' => $csv, 'report' => (new Importer(Plugin::getInstance()->rules))->run($csv), 'imported' => false,
        ]);
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('redirects-manage');
        $csv = (string)$this->request->getRequiredBodyParam('csv');
        return $this->renderTemplate('redirects/import/index', [
            'csv' => $csv, 'report' => (new Importer(Plugin::getInstance()->rules))->run($csv, true), 'imported' => true,
        ]);
    }
}
