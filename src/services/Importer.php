<?php

namespace leaplogic\redirects\services;

use craft\db\Query;
use leaplogic\redirects\models\Rule;

final class Importer
{
    public function __construct(private readonly Rules $rules)
    {
    }

    /** Preview and import share parsing, validation, and duplicate handling. */
    public function run(string $csv, bool $import = false): array
    {
        $report = ['created' => 0, 'skipped' => 0, 'errors' => [], 'total' => 0];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($stream);
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            $header = $header === false ? [] : array_map('trim', $header);
            if (!in_array('source', $header, true) || !in_array('destination', $header, true) || count(array_unique($header)) !== count($header)
                || array_diff($header, ['source', 'destination', 'statusCode', 'mode'])) {
                $report['errors'][] = 'Header must contain source,destination with optional statusCode,mode columns.';
                return $report;
            }
            $seen = array_fill_keys((new Query())->select('sourcePath')->from(Rules::TABLE)->column(), true);
            $line = 1;
            while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                $report['total']++;
                if (count($values) > count($header)) {
                    $report['errors'][] = "Row $line: too many columns.";
                    continue;
                }
                $row = array_combine($header, array_pad($values, count($header), ''));
                $status = trim($row['statusCode'] ?? '') ?: '301';
                if (!in_array($status, ['301', '302', '307', '308', '410'], true)) {
                    $report['errors'][] = "Row $line: invalid status code.";
                    continue;
                }
                $rule = new Rule([
                    'sourcePath' => $row['source'], 'destination' => $row['destination'],
                    'statusCode' => (int)$status, 'mode' => trim($row['mode'] ?? '') ?: 'notFound',
                ]);
                if (!$rule->validate()) {
                    $report['errors'][] = "Row $line: " . implode(' ', $rule->getFirstErrors());
                    continue;
                }
                if (isset($seen[$rule->sourcePath])) {
                    $report['skipped']++;
                    continue;
                }
                try {
                    if ($import && !$this->rules->save($rule)) {
                        $report['errors'][] = "Row $line: " . implode(' ', $rule->getFirstErrors());
                        continue;
                    }
                    $seen[$rule->sourcePath] = true;
                    $report['created']++;
                } catch (\Throwable $e) {
                    \Craft::error('Redirect import failed: ' . $e->getMessage(), 'redirects');
                    $report['errors'][] = "Row $line: could not save this redirect. See the application log.";
                }
            }
        } finally {
            fclose($stream);
        }
        return $report;
    }
}
