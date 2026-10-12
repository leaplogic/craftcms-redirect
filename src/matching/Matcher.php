<?php

declare(strict_types=1);

namespace leaplogic\redirects\matching;

/** Pure matching and URL handling; no Craft bootstrap is needed. */
final class Matcher
{
    public function __construct(private readonly ?\Closure $warning = null)
    {
    }

    public static function normalizePath(string $input): string
    {
        $input = trim($input);
        if (preg_match('~^https?://~i', $input)) {
            $input = (string)(parse_url($input, PHP_URL_PATH) ?? '');
        } else {
            $input = preg_split('/[?#]/', $input, 2)[0];
        }
        $input = trim(rawurldecode($input), '/');
        return function_exists('mb_strtolower') ? mb_strtolower($input, 'UTF-8') : strtolower($input);
    }

    /** Patterns use PHP delimiters, e.g. ~^old/(.+)$~i. */
    public static function validRegex(string $pattern): bool
    {
        return self::safePregMatch($pattern, '') !== false;
    }

    private static function safePregMatch(string $pattern, string $subject, ?array &$matches = null): int|false
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', (string)min((int)$backtrack, 100000));
        ini_set('pcre.recursion_limit', (string)min((int)$recursion, 10000));
        try {
            return @preg_match($pattern, $subject, $matches);
        } finally {
            ini_set('pcre.backtrack_limit', (string)$backtrack);
            ini_set('pcre.recursion_limit', (string)$recursion);
        }
    }

    public static function ignoreRegex(string $pattern): string
    {
        return '~' . str_replace('~', '\\~', $pattern) . '~i';
    }

    public function isIgnored(string $path, array $patterns): bool
    {
        $path = self::normalizePath($path);
        foreach ($patterns as $pattern) {
            if (trim($pattern) !== '' && $this->regex(self::ignoreRegex(trim($pattern)), $path) !== null) {
                return true;
            }
        }
        return false;
    }

    private function regex(string $pattern, string $path): ?array
    {
        $result = self::safePregMatch($pattern, $path, $matches);
        if ($result === false) {
            ($this->warning)?->__invoke('Redirect regex failed: ' . preg_last_error_msg());
        }
        return $result === 1 ? $matches : null;
    }

    public static function sortRules(array $rules): array
    {
        $priority = ['exact' => 0, 'prefix' => 1, 'regex' => 2];
        usort($rules, static function(array $a, array $b) use ($priority): int {
            $type = ($priority[$a['matchType']] ?? 3) <=> ($priority[$b['matchType']] ?? 3);
            if ($type !== 0) {
                return $type;
            }
            if ($a['matchType'] === 'prefix') {
                $length = strlen($b['sourcePath']) <=> strlen($a['sourcePath']);
                if ($length !== 0) {
                    return $length;
                }
            }
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });
        return $rules;
    }

    /** The resolver turns a site-relative destination into an absolute site URL. */
    public function match(array $rules, string $path, string $currentUrl, string $query, callable $resolver): ?array
    {
        $path = self::normalizePath($path);
        foreach (self::sortRules($rules) as $rule) {
            if (isset($rule['enabled']) && !$rule['enabled']) {
                continue;
            }
            $source = $rule['sourcePath'];
            $captures = [];
            $matches = match ($rule['matchType']) {
                'exact' => $path === $source,
                'prefix' => $source === '' || $path === $source || str_starts_with($path, $source . '/'),
                'regex' => ($captures = $this->regex($source, $path)) !== null,
                default => false,
            };
            if (!$matches) {
                continue;
            }
            if ((int)$rule['statusCode'] === 410) {
                return ['rule' => $rule, 'statusCode' => 410, 'url' => null];
            }
            $destination = $rule['destination'];
            if ($rule['matchType'] === 'regex') {
                $destination = preg_replace_callback('/\$(\d+)/', static fn(array $m): string => $captures[(int)$m[1]] ?? '', $destination);
            }
            if (!self::validDestination($destination)) {
                ($this->warning)?->__invoke('Redirect destination is invalid after substitution.');
                continue;
            }
            $url = preg_match('~^https?://~i', $destination) ? $destination : $resolver($destination);
            if (self::sameLocation($url, $currentUrl)) {
                continue;
            }
            if (($rule['preserveQuery'] ?? true) && $query !== '') {
                $url = self::appendQuery($url, $query);
            }
            return ['rule' => $rule, 'statusCode' => (int)$rule['statusCode'], 'url' => $url];
        }
        return null;
    }

    public static function validDestination(string $value): bool
    {
        if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value) || str_starts_with($value, '//')) {
            return false;
        }
        if (preg_match('~^https?://~i', $value)) {
            $parts = parse_url($value);
            return $parts !== false && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
        }
        return !preg_match('/^[^\/?#]*:/', $value);
    }

    /** Preserve raw encoding and repeated keys; destination keys take precedence. */
    public static function appendQuery(string $url, string $query): string
    {
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$path, $existing] = array_pad(explode('?', $base, 2), 2, '');
        $pairs = $existing === '' ? [] : explode('&', $existing);
        $keys = array_map(static fn(string $pair): string => urldecode(explode('=', $pair, 2)[0]), $pairs);
        foreach (explode('&', ltrim($query, '?')) as $pair) {
            if ($pair !== '' && !in_array(urldecode(explode('=', $pair, 2)[0]), $keys, true)) {
                $pairs[] = $pair;
            }
        }
        return $path . ($pairs ? '?' . implode('&', $pairs) : '') . ($fragment !== null ? '#' . $fragment : '');
    }

    public static function sameLocation(string $a, string $b): bool
    {
        $left = parse_url($a);
        $right = parse_url($b);
        return $left !== false && $right !== false
            && strtolower($left['host'] ?? '') === strtolower($right['host'] ?? '')
            && self::normalizePath($left['path'] ?? '') === self::normalizePath($right['path'] ?? '');
    }
}
