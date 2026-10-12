<?php

namespace leaplogic\redirects\models;

use craft\base\Model;
use leaplogic\redirects\matching\Matcher;

class Settings extends Model
{
    public bool $loggingEnabled = true;
    public string $ignorePatterns = '^wp-' . "\n" . '\.php$' . "\n" . '^\.env' . "\n" . '^\.git' . "\n" . 'xmlrpc' . "\n" . '^cgi-bin' . "\n" . '^vendor/' . "\n" . '^(favicon\.ico|apple-touch-icon.*\.png)$';
    public int $max404Rows = 5000;
    public int $retentionDays = 90;

    public function patterns(): array
    {
        return preg_split('/\R/', $this->ignorePatterns);
    }

    protected function defineRules(): array
    {
        return [
            ['loggingEnabled', 'boolean'],
            [['max404Rows', 'retentionDays'], 'integer', 'min' => 1],
            ['ignorePatterns', function(): void {
                foreach ($this->patterns() as $pattern) {
                    if (trim($pattern) !== '' && !Matcher::validRegex(Matcher::ignoreRegex(trim($pattern)))) {
                        $this->addError('ignorePatterns', 'Invalid ignore pattern: ' . $pattern);
                    }
                }
            }],
        ];
    }
}
