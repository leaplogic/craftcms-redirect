<?php

namespace leaplogic\redirects\services;

use Craft;
use craft\web\RedirectRule;

final class CodeRules
{
    public function configured(): array
    {
        return Craft::$app->getConfig()->getConfigFromFile('redirects') ?: [];
    }

    /** Evaluate the native rules without sending a response or redirect event. */
    public function matches(): bool
    {
        if (!class_exists(RedirectRule::class)) {
            return false;
        }
        foreach ($this->configured() as $from => $rule) {
            if (!$rule instanceof RedirectRule) {
                $config = is_string($rule) ? ['to' => $rule] : $rule;
                $rule = Craft::createObject(['class' => RedirectRule::class, 'from' => $from, ...$config]);
            }
            if ($rule->getMatch() !== null) {
                return true;
            }
        }
        return false;
    }

    /** Display configuration without invoking callbacks in a CP request. */
    public function forDisplay(): array
    {
        $rows = [];
        foreach ($this->configured() as $from => $rule) {
            if (is_string($rule)) {
                $rows[] = ['from' => (string)$from, 'to' => $rule, 'status' => 302];
            } elseif (is_array($rule)) {
                $computed = isset($rule['match']) || is_callable($rule['to'] ?? null);
                $rows[] = [
                    'from' => (string)($rule['from'] ?? $from),
                    'to' => $computed ? '(computed)' : (string)($rule['to'] ?? ''),
                    'status' => $rule['statusCode'] ?? 302,
                ];
            } elseif (is_object($rule)) {
                $reflection = new \ReflectionObject($rule);
                $computed = $reflection->hasProperty('_match') && $reflection->getProperty('_match')->isInitialized($rule);
                $rows[] = ['from' => $rule->from ?? (string)$from, 'to' => $computed ? '(computed)' : ($rule->to ?? '(computed)'), 'status' => $rule->statusCode ?? 302];
            }
        }
        return $rows;
    }
}
