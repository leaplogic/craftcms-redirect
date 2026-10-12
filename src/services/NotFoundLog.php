<?php

namespace leaplogic\redirects\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use leaplogic\redirects\matching\Matcher;
use leaplogic\redirects\Plugin;
use yii\base\Component;
use yii\db\Expression;

class NotFoundLog extends Component
{
    public const TABLE = '{{%redirects_404s}}';

    public function record(int $siteId, string $path): void
    {
        try {
            $settings = Plugin::getInstance()->getSettings();
            if (!$settings->loggingEnabled || Plugin::getInstance()->rules->matcher()->isIgnored($path, $settings->patterns())) {
                return;
            }
            $path = Matcher::normalizePath($path);
            $request = Craft::$app->getRequest();
            $now = gmdate('Y-m-d H:i:s');
            $latest = [
                'lastSeen' => $now,
                'lastReferrer' => mb_substr((string)$request->getReferrer(), 0, 500),
                'lastUserAgent' => mb_substr((string)$request->getUserAgent(), 0, 255),
            ];
            Db::upsert(self::TABLE, [
                'siteId' => $siteId,
                'path' => mb_substr($path, 0, 500),
                'hitCount' => 1,
                'firstSeen' => $now,
                ...$latest,
            ], ['hitCount' => new Expression('{{%redirects_404s}}.[[hitCount]] + 1'), ...$latest]);
            $this->prune();
        } catch (\Throwable $e) {
            Craft::warning('Could not log 404: ' . $e->getMessage(), 'redirects');
        }
    }

    public function prune(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        Db::delete(self::TABLE, ['<', 'lastSeen', gmdate('Y-m-d H:i:s', time() - max(1, $settings->retentionDays) * 86400)]);
        $excess = (int)(new Query())->from(self::TABLE)->count() - max(1, $settings->max404Rows);
        if ($excess > 0) {
            $ids = (new Query())->select('id')->from(self::TABLE)->orderBy(['hitCount' => SORT_ASC, 'lastSeen' => SORT_ASC, 'id' => SORT_ASC])->limit($excess)->column();
            Db::delete(self::TABLE, ['id' => $ids]);
        }
    }

    public function visible(int $days): array
    {
        $query = (new Query())->from(self::TABLE)->orderBy(['hitCount' => SORT_DESC, 'lastSeen' => SORT_DESC, 'id' => SORT_ASC]);
        if ($days > 0) {
            $query->where(['>=', 'lastSeen', gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        }
        $rules = Plugin::getInstance()->rules;
        return array_values(array_filter($query->all(), static function(array $row) use ($rules): bool {
            $siteId = (int)$row['siteId'];
            // Encode the stored decoded path so URL-significant characters stay in the path.
            $path = implode('/', array_map('rawurlencode', explode('/', $row['path'])));
            $base = parse_url(UrlHelper::siteUrl('', null, null, $siteId));
            $current = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '')
                . (isset($base['port']) ? ':' . $base['port'] : '') . '/' . $path;
            return $rules->match($path, $siteId, null, $current) === null;
        }));
    }
}
