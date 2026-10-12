<?php

namespace leaplogic\redirects\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use leaplogic\redirects\matching\Matcher;
use leaplogic\redirects\models\Rule;
use yii\base\Component;
use yii\caching\TagDependency;
use yii\db\Expression;

class Rules extends Component
{
    public const TABLE = '{{%redirects_rules}}';
    public const CACHE_TAG = 'leaplogic.redirects.rules';
    private ?array $enabledRules = null;

    public function matcher(): Matcher
    {
        return new Matcher(static fn(string $message) => Craft::warning($message, 'redirects'));
    }

    public function enabled(): array
    {
        return $this->enabledRules ??= Craft::$app->getCache()->getOrSet(
            self::CACHE_TAG,
            static fn(): array => (new Query())->from(self::TABLE)->where(['enabled' => true])->all(),
            0,
            new TagDependency(['tags' => [self::CACHE_TAG]]),
        );
    }

    public function invalidate(): void
    {
        $this->enabledRules = null;
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    public function match(string $path, int $siteId, ?string $mode, string $currentUrl, string $query = ''): ?array
    {
        $rules = array_filter($this->enabled(), static fn(array $rule): bool =>
            ($rule['siteId'] === null || (int)$rule['siteId'] === $siteId)
            && ($mode === null || $rule['mode'] === $mode));
        return $this->matcher()->match($rules, $path, $currentUrl, $query,
            static fn(string $destination): string => UrlHelper::siteUrl($destination, null, null, $siteId));
    }

    public function get(int $id): ?Rule
    {
        $row = (new Query())->from(self::TABLE)->where(['id' => $id])->one();
        if (!$row) {
            return null;
        }
        $model = new Rule();
        $model->setAttributes(array_intersect_key($row, $model->getAttributes()), false);
        $model->setNormalizedSource($model->sourcePath);
        return $model;
    }

    public function save(Rule $rule, ?int $logId = null): bool
    {
        if (!$rule->validate()) {
            return false;
        }
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $attributes = $rule->getAttributes();
            unset($attributes['id']);
            if ($rule->id !== null) {
                Db::update(self::TABLE, $attributes, ['id' => $rule->id]);
            } else {
                Db::insert(self::TABLE, $attributes);
                $rule->id = (int)Craft::$app->getDb()->getLastInsertID();
            }
            if ($logId !== null) {
                Db::delete(NotFoundLog::TABLE, ['id' => $logId]);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        $this->invalidate();
        return true;
    }

    public function delete(int $id): void
    {
        Db::delete(self::TABLE, ['id' => $id]);
        $this->invalidate();
    }

    public function recordHit(int $id): void
    {
        try {
            Db::update(self::TABLE, ['hitCount' => new Expression('[[hitCount]] + 1'), 'lastHitAt' => gmdate('Y-m-d H:i:s')], ['id' => $id], [], false);
        } catch (\Throwable $e) {
            Craft::warning('Could not record redirect hit: ' . $e->getMessage(), 'redirects');
        }
    }
}
