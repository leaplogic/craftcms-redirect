<?php

namespace leaplogic\redirects\migrations;

use Craft;
use craft\db\Migration;
use leaplogic\redirects\services\Rules;
use yii\caching\TagDependency;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%redirects_rules}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),
            'sourcePath' => $this->string(500)->notNull(),
            'matchType' => $this->string(10)->notNull()->defaultValue('exact'),
            'destination' => $this->string(1000)->notNull(),
            'statusCode' => $this->smallInteger()->notNull()->defaultValue(301),
            'mode' => $this->string(10)->notNull()->defaultValue('notFound'),
            'preserveQuery' => $this->boolean()->notNull()->defaultValue(true),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'note' => $this->text(),
            'hitCount' => $this->integer()->notNull()->defaultValue(0),
            'lastHitAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%redirects_rules}}', ['enabled', 'mode']);
        $this->addForeignKey(null, '{{%redirects_rules}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->createTable('{{%redirects_404s}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'path' => $this->string(500)->notNull(),
            'hitCount' => $this->integer()->notNull()->defaultValue(1),
            'firstSeen' => $this->dateTime()->notNull(),
            'lastSeen' => $this->dateTime()->notNull(),
            'lastReferrer' => $this->string(500),
            'lastUserAgent' => $this->string(255),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%redirects_404s}}', ['siteId', 'path'], true);
        $this->createIndex(null, '{{%redirects_404s}}', ['lastSeen']);
        $this->createIndex(null, '{{%redirects_404s}}', ['hitCount', 'lastSeen']);
        $this->addForeignKey(null, '{{%redirects_404s}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        TagDependency::invalidate(Craft::$app->getCache(), Rules::CACHE_TAG);
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%redirects_404s}}');
        $this->dropTableIfExists('{{%redirects_rules}}');
        TagDependency::invalidate(Craft::$app->getCache(), Rules::CACHE_TAG);
        return true;
    }
}
