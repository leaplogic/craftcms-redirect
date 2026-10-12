<?php

namespace leaplogic\redirects\controllers;

use craft\web\Controller;

abstract class BaseController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        return true;
    }
}
