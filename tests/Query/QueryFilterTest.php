<?php

declare(strict_types=1);

namespace Rocket\Tests\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rocket\Query\QueryBuilder;
use Rocket\Query\QueryFilter;
use Rocket\Tests\Fixtures\TestUser;

/**
 * Tests for request-driven filtering, sorting and pagination.
 *
 * @package Rocket\Tests\Query
 */
final class QueryFilterTest extends TestCase
{
    /**
     * Start a builder for the fixture entity.
     */
    private function query(): QueryBuilder
    {
        return new QueryBuilder(TestUser::class);
    }

    #[Test]
    public function it_applies_a_simple_filter(): void
    {
        $query = QueryFilter::apply(
            $this->query(),
            ['status' => ['column' => 'status']],
            ['status' => 'active']
        );

        $this->assertStringContainsString('status = :', $query->toSql());
        $this->assertContains('active', array_values($query->getBindings()));
    }

    #[Test]
    public function it_skips_a_filter_with_no_value(): void
    {
        $query = QueryFilter::apply($this->query(), ['status' => ['column' => 'status']], []);

        $this->assertSame('SELECT * FROM users', $query->toSql());
    }

    #[Test]
    public function it_drops_a_value_outside_the_declared_allowlist(): void
    {
        $query = QueryFilter::apply(
            $this->query(),
            ['status' => ['column' => 'status', 'values' => ['open', 'closed']]],
            ['status' => 'deleted']
        );

        $this->assertSame('SELECT * FROM users', $query->toSql());
    }

    #[Test]
    public function it_escapes_like_metacharacters(): void
    {
        // A search for "100%" must not turn into a wildcard matching every row.
        $query = QueryFilter::apply(
            $this->query(),
            ['q' => ['column' => 'title', 'operator' => 'LIKE']],
            ['q' => '100%']
        );

        $this->assertSame(['%100\\%%'], array_values($query->getBindings()));
    }

    #[Test]
    public function it_searches_several_columns_with_or(): void
    {
        $query = QueryFilter::apply(
            $this->query(),
            ['q' => ['column' => ['title', 'body'], 'operator' => 'LIKE']],
            ['q' => 'luxid']
        );

        $this->assertMatchesRegularExpression('/\(title LIKE :\w+ OR body LIKE :\w+\)/', $query->toSql());
        $this->assertCount(2, $query->getBindings());
    }

    #[Test]
    public function it_sorts_by_an_allowlisted_column(): void
    {
        $query = QueryFilter::sort($this->query(), ['sort' => 'email'], ['email', 'id']);

        $this->assertStringContainsString('ORDER BY email ASC', $query->toSql());
    }

    #[Test]
    public function it_ignores_a_sort_column_that_is_not_allowlisted(): void
    {
        // This is the guard that keeps a hostile ?sort= out of the SQL entirely.
        $query = QueryFilter::sort(
            $this->query(),
            ['sort' => 'password; DROP TABLE users'],
            ['email', 'id']
        );

        $this->assertSame('SELECT * FROM users', $query->toSql());
    }

    #[Test]
    public function it_falls_back_to_the_default_sort_column(): void
    {
        $query = QueryFilter::sort($this->query(), ['sort' => 'nope'], ['email'], 'id');

        $this->assertStringContainsString('ORDER BY id ASC', $query->toSql());
    }

    #[Test]
    public function it_normalises_the_sort_direction(): void
    {
        $query = QueryFilter::sort($this->query(), ['sort' => 'id', 'direction' => 'desc'], ['id']);

        $this->assertStringContainsString('ORDER BY id DESC', $query->toSql());
    }

    #[Test]
    public function it_treats_an_unrecognised_direction_as_ascending(): void
    {
        $query = QueryFilter::sort(
            $this->query(),
            ['sort' => 'id', 'direction' => 'ASC, (SELECT 1)'],
            ['id']
        );

        $this->assertStringContainsString('ORDER BY id ASC', $query->toSql());
    }

    #[Test]
    public function it_clamps_the_page_size(): void
    {
        $query = QueryFilter::paginate($this->query(), ['limit' => 100000]);

        $this->assertStringContainsString('LIMIT ' . QueryFilter::MAX_LIMIT, $query->toSql());
    }

    #[Test]
    public function it_rejects_a_non_positive_page_size(): void
    {
        $query = QueryFilter::paginate($this->query(), ['limit' => 0]);

        $this->assertStringContainsString('LIMIT 1', $query->toSql());
    }

    #[Test]
    public function it_derives_an_offset_from_a_page_number(): void
    {
        $query = QueryFilter::paginate($this->query(), ['limit' => 10, 'page' => 3]);

        $this->assertStringContainsString('OFFSET 20', $query->toSql());
    }

    #[Test]
    public function it_treats_page_zero_as_the_first_page(): void
    {
        $query = QueryFilter::paginate($this->query(), ['limit' => 10, 'page' => 0]);

        $this->assertStringContainsString('OFFSET 0', $query->toSql());
    }

    #[Test]
    public function it_reports_the_applied_parameters(): void
    {
        $applied = QueryFilter::getParams(
            ['status' => ['column' => 'status']],
            ['status' => 'open', 'limit' => 500]
        );

        $this->assertSame('open', $applied['status']);
        $this->assertSame(QueryFilter::MAX_LIMIT, $applied['limit']);
    }
}
