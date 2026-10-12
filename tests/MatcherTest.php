<?php

declare(strict_types=1);

namespace leaplogic\redirects\tests;

use leaplogic\redirects\matching\Matcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase
{
    private function rule(array $attributes = []): array
    {
        return array_replace(['id' => 1, 'sourcePath' => 'old', 'matchType' => 'exact', 'destination' => '/new', 'statusCode' => 301, 'preserveQuery' => true, 'enabled' => true], $attributes);
    }

    private function match(array $rules, string $path = '/old', string $query = '', ?Matcher $matcher = null): ?array
    {
        return ($matcher ?? new Matcher())->match($rules, $path, 'https://example.com/' . ltrim($path, '/'), $query,
            static fn(string $path): string => 'https://example.com/' . ltrim($path, '/'));
    }

    public static function paths(): array
    {
        return [
            ['/HELLO/World/', 'hello/world'],
            ['///HELLO///', 'hello'],
            ['https://example.com/en/Old?x=1', 'en/old'],
            ['hello/world?q=1#top', 'hello/world'],
            ['https://EXAMPLE.com/Hello%20World/?x=1', 'hello world'],
            ['/caf%C3%A9', 'café'],
            ['/a%2Fb/', 'a/b'],
            ['/a%3Fb?ignored=yes', 'a?b'],
            ['/a+b', 'a+b'],
            ['/', ''],
        ];
    }

    #[DataProvider('paths')]
    public function testNormalization(string $input, string $expected): void
    {
        self::assertSame($expected, Matcher::normalizePath($input));
    }

    public function testExactMatchAndNonMatch(): void
    {
        self::assertSame('https://example.com/new', $this->match([$this->rule()], '/OLD/?q=1')['url']);
        self::assertNull($this->match([$this->rule()], 'older'));
        self::assertNull($this->match([$this->rule(['enabled' => false])], 'old'));
    }

    public function testFullUrlInputAndEncodedReservedPathCharacters(): void
    {
        self::assertSame('https://example.com/new', $this->match([$this->rule(['sourcePath' => 'en/old'])], 'https://example.com/en/OLD?x=1')['url']);
        self::assertSame('https://example.com/new', $this->match([$this->rule(['sourcePath' => 'a?b'])], '/a%3Fb')['url']);
        self::assertSame('https://example.com/new', $this->match([$this->rule(['sourcePath' => 'a%b'])], '/a%25b')['url']);
    }

    public function testPrefixRequiresSegmentBoundaryAndUsesFixedDestination(): void
    {
        $rule = $this->rule(['matchType' => 'prefix']);
        self::assertNotNull($this->match([$rule], 'old'));
        self::assertSame('https://example.com/new', $this->match([$rule], 'old/child')['url']);
        self::assertNull($this->match([$rule], 'older/child'));
        self::assertNotNull($this->match([$this->rule(['matchType' => 'prefix', 'sourcePath' => ''])], 'anything'));
    }

    public function testPrecedenceExactThenLongestPrefixThenRegexById(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'matchType' => 'regex', 'sourcePath' => '~^old/(.+)$~']),
            $this->rule(['id' => 2, 'matchType' => 'prefix']),
            $this->rule(['id' => 4, 'matchType' => 'prefix', 'sourcePath' => 'old/child']),
            $this->rule(['id' => 5, 'sourcePath' => 'old/child/page']),
            $this->rule(['id' => 3, 'matchType' => 'regex', 'sourcePath' => '~^old/(.+)$~']),
        ];
        self::assertSame(5, $this->match($rules, 'old/child/page')['rule']['id']);
        self::assertSame(4, $this->match($rules, 'old/child/another')['rule']['id']);
        self::assertSame(2, $this->match($rules, 'old/other')['rule']['id']);
        self::assertSame(1, $this->match([$rules[4], $rules[0]], 'old/other')['rule']['id']);
    }

    public function testRegexSubstitutesCapturesWithoutChangingDestinationCase(): void
    {
        $rule = $this->rule(['matchType' => 'regex', 'sourcePath' => '~^old/([^/]+)/(\d+)$~', 'destination' => 'https://other.test/New/$2/$1']);
        self::assertSame('https://other.test/New/42/thing', $this->match([$rule], '/Old/Thing/42')['url']);
        self::assertNull($this->match([$rule], '/old/thing/nope'));
    }

    public function testDoubleDigitCaptureSubstitution(): void
    {
        $rule = $this->rule(['matchType' => 'regex', 'sourcePath' => '~^(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)$~', 'destination' => '/$10/$1']);
        self::assertSame('https://example.com/j/a', $this->match([$rule], 'abcdefghij')['url']);
    }

    public function testQueryPreservationMergesBeforeFragmentAndDestinationWins(): void
    {
        $rule = $this->rule(['destination' => '/new?fixed=1#section']);
        self::assertSame('https://example.com/new?fixed=1&x=a%20b&tag=1&tag=2#section', $this->match([$rule], 'old', 'fixed=2&x=a%20b&tag=1&tag=2')['url']);
        self::assertSame('https://example.com/new?a=1', $this->match([$this->rule()], 'old', 'a=1')['url']);
        self::assertSame('https://example.com/new', $this->match([$this->rule(['preserveQuery' => false])], 'old', 'a=1')['url']);
        self::assertSame('/new?my.key=1&new=3#top', Matcher::appendQuery('/new?my.key=1#top', 'my%2Ekey=2&new=3'));
    }

    public function testGoneDoesNotResolveDestination(): void
    {
        $result = (new Matcher())->match([$this->rule(['statusCode' => 410, 'destination' => ''])], 'old', 'https://example.com/old', 'a=1', static function(): never { throw new \LogicException('Must not resolve a Gone response.'); });
        self::assertSame(410, $result['statusCode']);
        self::assertNull($result['url']);
    }

    public function testLoopGuardComparesHostAndNormalizedPathIgnoringQueryAndScheme(): void
    {
        self::assertNull($this->match([$this->rule(['destination' => 'http://EXAMPLE.com/OLD/?different=1#top'])]));
        self::assertNull($this->match([$this->rule(['destination' => '/old'])]));
        self::assertNotNull($this->match([$this->rule(['destination' => 'https://other.test/old'])]));
        self::assertTrue(Matcher::sameLocation('https://example.com/a%20b/', 'https://example.com/A%20B'));
    }

    public function testInvalidAndExpensiveRegexFailSafelyWithWarning(): void
    {
        $warnings = [];
        $matcher = new Matcher(static function(string $message) use (&$warnings): void { $warnings[] = $message; });
        self::assertFalse(Matcher::validRegex('~[~'));
        self::assertTrue(Matcher::validRegex('~^old/(.+)$~i'));
        self::assertNull($this->match([$this->rule(['matchType' => 'regex', 'sourcePath' => '~[~'])], 'old', '', $matcher));
        $limit = ini_get('pcre.backtrack_limit');
        self::assertNull($this->match([$this->rule(['matchType' => 'regex', 'sourcePath' => '~(*NO_JIT)^(a+)+$~'])], str_repeat('a', 1000) . '!', '', $matcher));
        self::assertSame($limit, ini_get('pcre.backtrack_limit'));
        self::assertCount(2, $warnings);
    }

    public function testIgnoreListUsesNormalizedPathsAndDoesNotIgnoreRealPages(): void
    {
        $matcher = new Matcher();
        $patterns = ['^wp-', '\.php$', '^\.env', '^\.git', 'xmlrpc', '^cgi-bin', '^vendor/', '^(favicon\.ico|apple-touch-icon.*\.png)$'];
        foreach (['/WP-login', '/old.php?x=1', '/.ENV', '/.git/config', '/xmlrpc', '/cgi-bin/test', '/vendor/file', '/favicon.ico', '/apple-touch-icon-180.png'] as $path) {
            self::assertTrue($matcher->isIgnored($path, $patterns), $path);
        }
        self::assertFalse($matcher->isIgnored('/ministries/kids', $patterns));
        self::assertFalse($matcher->isIgnored('/a.php/child', $patterns));
    }

    public function testUnsafeDestinationsAreRejectedIncludingAfterCaptureSubstitution(): void
    {
        foreach (['javascript:alert(1)', '//other.test', '/bad\\path', "/bad\r\nLocation:x", 'https://user:pass@example.com'] as $destination) {
            self::assertFalse(Matcher::validDestination($destination));
        }
        self::assertTrue(Matcher::validDestination('/new?x=1#top'));
        self::assertTrue(Matcher::validDestination('https://example.com/New'));
        self::assertNull($this->match([$this->rule(['matchType' => 'regex', 'sourcePath' => '~^old/(.+)$~s', 'destination' => '/$1'])], 'old/%0d%0aLocation:evil'));
    }
}
