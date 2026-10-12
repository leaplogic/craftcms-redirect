<?php

namespace leaplogic\redirects\console\controllers;

use craft\console\Controller;
use leaplogic\redirects\Plugin;
use leaplogic\redirects\services\Importer;
use yii\console\ExitCode;

class ImportController extends Controller
{
    public $defaultAction = 'index';
    public bool $preview = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['preview']);
    }

    /** Import exact, all-sites redirects from a CSV file. */
    public function actionIndex(string $file): int
    {
        if (!is_file($file) || !is_readable($file) || ($csv = file_get_contents($file)) === false) {
            $this->stderr("Cannot read CSV file: $file\n");
            return ExitCode::NOINPUT;
        }
        $report = (new Importer(Plugin::getInstance()->rules))->run($csv, !$this->preview);
        $verb = $this->preview ? 'Would create' : 'Created';
        $this->stdout("$verb {$report['created']}; skipped {$report['skipped']}; errors " . count($report['errors']) . ".\n");
        foreach ($report['errors'] as $error) {
            $this->stderr($error . "\n");
        }
        return $report['errors'] ? ExitCode::DATAERR : ExitCode::OK;
    }
}
