<?php

namespace leaplogic\redirects\models;

use Craft;
use craft\base\Model;
use leaplogic\redirects\matching\Matcher;

class Rule extends Model
{
    public ?int $id = null;
    public ?int $siteId = null;
    public string $sourcePath = '';
    public string $matchType = 'exact';
    public string $destination = '';
    public int $statusCode = 301;
    public string $mode = 'notFound';
    public bool $preserveQuery = true;
    public bool $enabled = true;
    public ?string $note = null;
    private ?string $normalizedSource = null;
    private ?string $normalizedType = null;

    protected function defineRules(): array
    {
        return [
            [['matchType', 'mode'], 'required'],
            ['sourcePath', 'string', 'max' => 500],
            ['destination', 'string', 'max' => 1000],
            ['matchType', 'in', 'range' => ['exact', 'prefix', 'regex']],
            ['mode', 'in', 'range' => ['notFound', 'always']],
            ['statusCode', 'in', 'range' => [301, 302, 307, 308, 410]],
            [['preserveQuery', 'enabled'], 'boolean'],
            ['note', 'string'],
            ['siteId', function(): void {
                if ($this->siteId !== null && !Craft::$app->getSites()->getSiteById($this->siteId)) {
                    $this->addError('siteId', 'Choose a valid site.');
                }
            }],
            ['sourcePath', function(): void {
                if ($this->matchType === 'regex' && !Matcher::validRegex($this->sourcePath)) {
                    $this->addError('sourcePath', 'Enter a valid PHP regex including delimiters, such as ~^old/(.+)$~i.');
                }
            }, 'skipOnEmpty' => false],
            ['destination', function(): void {
                if ($this->statusCode !== 410 && !Matcher::validDestination($this->destination)) {
                    $this->addError('destination', 'Enter an HTTP(S) URL or site-relative path without spaces. Use %20 for spaces.');
                }
            }, 'skipOnEmpty' => false],
        ];
    }

    public function setNormalizedSource(string $source): void
    {
        $this->sourcePath = $this->normalizedSource = $source;
        $this->normalizedType = $this->matchType;
    }

    public function getSourceInput(): string
    {
        if ($this->matchType === 'regex' || $this->sourcePath !== $this->normalizedSource || $this->matchType !== $this->normalizedType) {
            return $this->sourcePath;
        }
        return str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $this->sourcePath);
    }

    public function beforeValidate(): bool
    {
        if ($this->sourcePath !== $this->normalizedSource || $this->matchType !== $this->normalizedType) {
            $this->sourcePath = $this->matchType === 'regex' ? trim($this->sourcePath) : Matcher::normalizePath($this->sourcePath);
            $this->normalizedSource = $this->sourcePath;
            $this->normalizedType = $this->matchType;
        }
        $this->destination = trim($this->destination);
        return parent::beforeValidate();
    }
}
