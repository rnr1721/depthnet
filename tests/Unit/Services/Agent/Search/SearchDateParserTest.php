<?php

namespace Tests\Unit\Services\Agent\Search;

use App\Services\Agent\Search\SearchDateParser;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for SearchDateParser.
 *
 * The parser is loaded with a fixture JSON vocabulary so tests don't depend
 * on the project's actual keywords file. Carbon is frozen to a known moment
 * for deterministic relative-date checks.
 */
class SearchDateParserTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir() . '/search_keywords_test_' . uniqid('', true) . '.json';
        file_put_contents($this->fixturePath, json_encode([
            'today'      => ['en' => ['today'], 'ru' => ['сегодня']],
            'yesterday'  => ['en' => ['yesterday'], 'ru' => ['вчера']],
            'last_week'  => ['en' => ['last week', 'week'], 'ru' => ['прошлая неделя', 'неделя']],
            'this_week'  => ['en' => ['this week'], 'ru' => ['эта неделя']],
            'last_month' => ['en' => ['last month', 'month'], 'ru' => ['прошлый месяц']],
            'this_month' => ['en' => ['this month'], 'ru' => ['этот месяц']],
            'last_year'  => ['en' => ['last year', 'year'], 'ru' => ['прошлый год']],
            'this_year'  => ['en' => ['this year'], 'ru' => ['этот год']],
        ]));

        // Freeze "now" to a Monday afternoon for deterministic relative dates
        Carbon::setTestNow(Carbon::create(2026, 5, 18, 14, 30, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Unfreeze
        if (file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }
        parent::tearDown();
    }

    private function makeParser(): SearchDateParser
    {
        return new SearchDateParser(new NullLogger(), $this->fixturePath);
    }

    // -------------------------------------------------------------------------
    // Plain semantic queries (no date involved)
    // -------------------------------------------------------------------------

    public function test_plain_query_returns_text_only(): void
    {
        $result = $this->makeParser()->parse('optimize database queries');

        $this->assertNull($result->from);
        $this->assertNull($result->to);
        $this->assertSame('optimize database queries', $result->query);
        $this->assertFalse($result->hasTimeFilter());
        $this->assertTrue($result->hasSemanticQuery());
    }

    public function test_empty_query_returns_empty_result(): void
    {
        $result = $this->makeParser()->parse('');

        $this->assertNull($result->from);
        $this->assertNull($result->to);
        $this->assertSame('', $result->query);
        $this->assertFalse($result->hasTimeFilter());
        $this->assertFalse($result->hasSemanticQuery());
    }

    public function test_whitespace_only_query_returns_empty(): void
    {
        $result = $this->makeParser()->parse("  \n\t  ");

        $this->assertSame('', $result->query);
        $this->assertFalse($result->hasTimeFilter());
    }

    // -------------------------------------------------------------------------
    // Pure date expressions (no semantic part)
    // -------------------------------------------------------------------------

    public function test_today_keyword_resolves_to_today_range(): void
    {
        $result = $this->makeParser()->parse('today');

        $this->assertNotNull($result->from);
        $this->assertNotNull($result->to);
        $this->assertSame('', $result->query);
        $this->assertTrue($result->from->isSameDay(Carbon::today()));
        $this->assertTrue($result->to->isSameDay(Carbon::today()));
        $this->assertSame(0, $result->from->hour);
        $this->assertSame(23, $result->to->hour);
    }

    public function test_yesterday_keyword(): void
    {
        $result = $this->makeParser()->parse('yesterday');

        $this->assertNotNull($result->from);
        $this->assertTrue($result->from->isSameDay(Carbon::yesterday()));
    }

    public function test_iso_single_date(): void
    {
        $result = $this->makeParser()->parse('2026-03-15');

        $this->assertNotNull($result->from);
        $this->assertSame('2026-03-15', $result->from->toDateString());
        $this->assertSame('2026-03-15', $result->to->toDateString());
        $this->assertSame(0, $result->from->hour);
        $this->assertSame(23, $result->to->hour);
    }

    public function test_iso_date_range(): void
    {
        $result = $this->makeParser()->parse('2026-03-10:2026-03-15');

        $this->assertSame('2026-03-10', $result->from->toDateString());
        $this->assertSame('2026-03-15', $result->to->toDateString());
        $this->assertSame(0, $result->from->hour);
        $this->assertSame(23, $result->to->hour);
    }

    public function test_iso_date_range_with_spaces_around_colon(): void
    {
        $result = $this->makeParser()->parse('2026-03-10 : 2026-03-15');

        $this->assertSame('2026-03-10', $result->from->toDateString());
        $this->assertSame('2026-03-15', $result->to->toDateString());
    }

    public function test_last_week_returns_full_previous_calendar_week(): void
    {
        // Frozen "now" is Mon 2026-05-18 → "last week" = Mon 2026-05-11 to Sun 2026-05-17
        $result = $this->makeParser()->parse('last week');

        $this->assertSame('2026-05-11', $result->from->toDateString());
        $this->assertSame('2026-05-17', $result->to->toDateString());
        $this->assertSame(0, $result->from->hour);
        $this->assertSame(23, $result->to->hour);
    }

    public function test_last_month_returns_full_previous_calendar_month(): void
    {
        // Frozen "now" is 2026-05-18 → "last month" = Apr 1 to Apr 30
        $result = $this->makeParser()->parse('last month');

        $this->assertSame('2026-04-01', $result->from->toDateString());
        $this->assertSame('2026-04-30', $result->to->toDateString());
    }

    public function test_this_week_runs_from_monday_through_today(): void
    {
        // Frozen "now" is Mon 2026-05-18 14:30 → "this week" = Mon 2026-05-18 00:00 – Mon 2026-05-18 23:59
        $result = $this->makeParser()->parse('this week');

        $this->assertSame('2026-05-18', $result->from->toDateString());
        $this->assertSame('2026-05-18', $result->to->toDateString());
    }

    public function test_this_month_runs_from_first_through_today(): void
    {
        $result = $this->makeParser()->parse('this month');

        $this->assertSame('2026-05-01', $result->from->toDateString());
        $this->assertSame('2026-05-18', $result->to->toDateString());
    }

    // -------------------------------------------------------------------------
    // ISO year-month (YYYY-MM) and year (YYYY)
    // -------------------------------------------------------------------------

    public function test_iso_year_month_returns_full_calendar_month(): void
    {
        $result = $this->makeParser()->parse('2026-03');

        $this->assertSame('2026-03-01', $result->from->toDateString());
        $this->assertSame('2026-03-31', $result->to->toDateString());
        $this->assertSame(0, $result->from->hour);
        $this->assertSame(23, $result->to->hour);
    }

    public function test_iso_year_month_handles_february(): void
    {
        // 2026 is not a leap year — Feb has 28 days
        $result = $this->makeParser()->parse('2026-02');

        $this->assertSame('2026-02-01', $result->from->toDateString());
        $this->assertSame('2026-02-28', $result->to->toDateString());
    }

    public function test_iso_year_month_handles_leap_february(): void
    {
        // 2024 IS a leap year — Feb has 29 days
        $result = $this->makeParser()->parse('2024-02');

        $this->assertSame('2024-02-01', $result->from->toDateString());
        $this->assertSame('2024-02-29', $result->to->toDateString());
    }

    public function test_iso_year_month_invalid_month_falls_back_to_semantic(): void
    {
        // Month 13 isn't valid — should be treated as raw semantic text
        $result = $this->makeParser()->parse('2026-13');

        $this->assertFalse($result->hasTimeFilter());
        $this->assertSame('2026-13', $result->query);
    }

    public function test_iso_year_month_with_semantic_part(): void
    {
        $result = $this->makeParser()->parse('2026-03 | release notes');

        $this->assertSame('2026-03-01', $result->from->toDateString());
        $this->assertSame('2026-03-31', $result->to->toDateString());
        $this->assertSame('release notes', $result->query);
    }

    public function test_iso_year_returns_full_calendar_year(): void
    {
        $result = $this->makeParser()->parse('2025');

        $this->assertSame('2025-01-01', $result->from->toDateString());
        $this->assertSame('2025-12-31', $result->to->toDateString());
        $this->assertSame(0, $result->from->hour);
        $this->assertSame(23, $result->to->hour);
    }

    public function test_iso_year_with_semantic_part(): void
    {
        $result = $this->makeParser()->parse('2025 | reflections');

        $this->assertSame('2025-01-01', $result->from->toDateString());
        $this->assertSame('2025-12-31', $result->to->toDateString());
        $this->assertSame('reflections', $result->query);
    }

    public function test_iso_year_out_of_sane_range_falls_back_to_semantic(): void
    {
        // Three-digit year and very-far-future year should not match as date
        $result = $this->makeParser()->parse('999');
        $this->assertFalse($result->hasTimeFilter());

        $result = $this->makeParser()->parse('9999');
        $this->assertFalse($result->hasTimeFilter());
    }

    // -------------------------------------------------------------------------
    // Year keywords (last_year / this_year)
    // -------------------------------------------------------------------------

    public function test_last_year_returns_full_previous_calendar_year(): void
    {
        // Frozen "now" is 2026-05-18 → "last year" = Jan 1, 2025 to Dec 31, 2025
        $result = $this->makeParser()->parse('last year');

        $this->assertSame('2025-01-01', $result->from->toDateString());
        $this->assertSame('2025-12-31', $result->to->toDateString());
    }

    public function test_this_year_runs_from_jan_first_through_today(): void
    {
        $result = $this->makeParser()->parse('this year');

        $this->assertSame('2026-01-01', $result->from->toDateString());
        $this->assertSame('2026-05-18', $result->to->toDateString());
    }

    public function test_russian_year_keywords(): void
    {
        $result = $this->makeParser()->parse('прошлый год');
        $this->assertSame('2025-01-01', $result->from->toDateString());
        $this->assertSame('2025-12-31', $result->to->toDateString());

        $result = $this->makeParser()->parse('этот год | размышления');
        $this->assertSame('2026-01-01', $result->from->toDateString());
        $this->assertSame('размышления', $result->query);
    }

    public function test_invalid_iso_date_is_treated_as_semantic(): void
    {
        $result = $this->makeParser()->parse('2026-13-99');

        // Invalid date — not recognised as time filter, kept as semantic query
        $this->assertFalse($result->hasTimeFilter());
        $this->assertSame('2026-13-99', $result->query);
    }

    // -------------------------------------------------------------------------
    // Pipe-separated: date | semantic
    // -------------------------------------------------------------------------

    public function test_pipe_with_date_and_semantic(): void
    {
        $result = $this->makeParser()->parse('yesterday | optimization notes');

        $this->assertNotNull($result->from);
        $this->assertTrue($result->from->isSameDay(Carbon::yesterday()));
        $this->assertSame('optimization notes', $result->query);
    }

    public function test_pipe_with_iso_date(): void
    {
        $result = $this->makeParser()->parse('2026-03-15 | release notes');

        $this->assertSame('2026-03-15', $result->from->toDateString());
        $this->assertSame('release notes', $result->query);
    }

    public function test_pipe_with_iso_range(): void
    {
        $result = $this->makeParser()->parse('2026-03-10:2026-03-15 | bugfixes');

        $this->assertSame('2026-03-10', $result->from->toDateString());
        $this->assertSame('2026-03-15', $result->to->toDateString());
        $this->assertSame('bugfixes', $result->query);
    }

    public function test_pipe_with_unrecognised_prefix_keeps_full_query(): void
    {
        // "domain:work" looks pipe-separated but isn't a date — must be left intact
        $result = $this->makeParser()->parse('domain:work | optimization');

        $this->assertFalse($result->hasTimeFilter());
        $this->assertSame('domain:work | optimization', $result->query);
    }

    public function test_pipe_with_semantic_on_both_sides_keeps_full_query(): void
    {
        $result = $this->makeParser()->parse('apple | pie');

        $this->assertFalse($result->hasTimeFilter());
        $this->assertSame('apple | pie', $result->query);
    }

    public function test_pipe_with_extra_pipes_in_semantic_part(): void
    {
        // Only the FIRST pipe is the separator; subsequent pipes belong to semantic
        $result = $this->makeParser()->parse('today | foo | bar');

        $this->assertTrue($result->hasTimeFilter());
        $this->assertSame('foo | bar', $result->query);
    }

    // -------------------------------------------------------------------------
    // Multilingual lookup
    // -------------------------------------------------------------------------

    public function test_russian_keyword(): void
    {
        $result = $this->makeParser()->parse('вчера');

        $this->assertTrue($result->from->isSameDay(Carbon::yesterday()));
    }

    public function test_russian_keyword_with_semantic(): void
    {
        $result = $this->makeParser()->parse('сегодня | оптимизация запросов');

        $this->assertTrue($result->from->isSameDay(Carbon::today()));
        $this->assertSame('оптимизация запросов', $result->query);
    }

    public function test_mixed_language_query(): void
    {
        // Russian date keyword + English semantic content — should work
        $result = $this->makeParser()->parse('вчера | meeting notes');

        $this->assertTrue($result->from->isSameDay(Carbon::yesterday()));
        $this->assertSame('meeting notes', $result->query);
    }

    public function test_uppercase_keyword_is_recognised(): void
    {
        $result = $this->makeParser()->parse('TODAY | something');

        $this->assertTrue($result->from->isSameDay(Carbon::today()));
        $this->assertSame('something', $result->query);
    }

    public function test_multi_word_russian_keyword(): void
    {
        $result = $this->makeParser()->parse('прошлая неделя | работа');

        $this->assertNotNull($result->from);
        $this->assertSame('работа', $result->query);
    }

    // -------------------------------------------------------------------------
    // Whitespace tolerance
    // -------------------------------------------------------------------------

    public function test_extra_spaces_around_pipe(): void
    {
        $result = $this->makeParser()->parse('  today   |   work  ');

        $this->assertTrue($result->hasTimeFilter());
        $this->assertSame('work', $result->query);
    }

    public function test_extra_spaces_in_keyword(): void
    {
        // "last  week" with two spaces — not in vocabulary, won't match
        $result = $this->makeParser()->parse('last  week');

        $this->assertFalse($result->hasTimeFilter());
        $this->assertSame('last  week', $result->query);
    }

    // -------------------------------------------------------------------------
    // parseDateExpression direct access
    // -------------------------------------------------------------------------

    public function test_parse_date_expression_returns_null_for_unknown(): void
    {
        $this->assertNull($this->makeParser()->parseDateExpression('not a date'));
    }

    public function test_parse_date_expression_returns_tuple_for_known(): void
    {
        $result = $this->makeParser()->parseDateExpression('today');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Carbon::class, $result[0]);
        $this->assertInstanceOf(Carbon::class, $result[1]);
    }

    public function test_parse_date_expression_empty_returns_null(): void
    {
        $this->assertNull($this->makeParser()->parseDateExpression(''));
        $this->assertNull($this->makeParser()->parseDateExpression('   '));
    }

    // -------------------------------------------------------------------------
    // Introspection
    // -------------------------------------------------------------------------

    public function test_list_keywords_returns_all_variants(): void
    {
        $keywords = $this->makeParser()->listKeywords();

        $this->assertArrayHasKey('today', $keywords);
        $this->assertArrayHasKey('yesterday', $keywords);
        $this->assertContains('today', $keywords['today']);
        $this->assertContains('сегодня', $keywords['today']);
    }

    // -------------------------------------------------------------------------
    // Failure modes
    // -------------------------------------------------------------------------

    public function test_missing_vocabulary_file_still_handles_iso_dates(): void
    {
        $parser = new SearchDateParser(new NullLogger(), '/nonexistent/path.json');

        $result = $parser->parse('2026-03-15');
        $this->assertSame('2026-03-15', $result->from->toDateString());

        $result = $parser->parse('today');
        $this->assertFalse($result->hasTimeFilter()); // No keywords loaded
        $this->assertSame('today', $result->query);
    }

    public function test_malformed_json_does_not_crash(): void
    {
        $badPath = sys_get_temp_dir() . '/bad_keywords_' . uniqid('', true) . '.json';
        file_put_contents($badPath, '{not valid json');

        try {
            $parser = new SearchDateParser(new NullLogger(), $badPath);

            // ISO dates still work
            $result = $parser->parse('2026-03-15');
            $this->assertSame('2026-03-15', $result->from->toDateString());

            // Keywords don't (vocab is empty)
            $result = $parser->parse('today');
            $this->assertFalse($result->hasTimeFilter());
        } finally {
            unlink($badPath);
        }
    }

    public function test_describe_time_filter_single_day(): void
    {
        $result = $this->makeParser()->parse('2026-03-15');
        $this->assertSame('2026-03-15', $result->describeTimeFilter());
    }

    public function test_describe_time_filter_range(): void
    {
        $result = $this->makeParser()->parse('2026-03-10:2026-03-15');
        $this->assertSame('2026-03-10 to 2026-03-15', $result->describeTimeFilter());
    }

    public function test_describe_time_filter_null_when_no_filter(): void
    {
        $result = $this->makeParser()->parse('just some text');
        $this->assertNull($result->describeTimeFilter());
    }
}
