<?php

declare(strict_types=1);

namespace Rocket\Tests\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rocket\Query\QueryBuilder;
use Rocket\Tests\Fixtures\TestUser;

/**
 * Tests for SQL generation and parameter binding.
 *
 * These assert on the rendered statement rather than a live database, so the
 * binding and identifier rules are covered without any driver present.
 *
 * @package Rocket\Tests\Query
 */
final class QueryBuilderTest extends TestCase
{
    /**
     * Start a builder for the fixture entity.
     */
    private function query(): QueryBuilder
    {
        return new QueryBuilder(TestUser::class);
    }

    #[Test]
    public function it_selects_everything_by_default(): void
    {
        $this->assertSame('SELECT * FROM users', $this->query()->toSql());
    }

    #[Test]
    public function it_renders_a_simple_where(): void
    {
        $query = $this->query()->where('email', 'a@b.c');

        $this->assertStringContainsString('WHERE email = :', $query->toSql());
        $this->assertSame(['a@b.c'], array_values($query->getBindings()));
    }

    #[Test]
    public function it_renders_an_explicit_operator(): void
    {
        $this->assertStringContainsString('id >= :', $this->query()->where('id', '>=', 5)->toSql());
    }

    #[Test]
    public function it_rejects_an_operator_that_is_not_allowlisted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->where('id', 'UNION SELECT', 1);
    }

    #[Test]
    public function it_rejects_an_injected_column_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->where('id = 1 OR 1', '=', 1);
    }

    #[Test]
    public function it_rejects_an_injected_order_column(): void
    {
        // Regression: orderBy() interpolated its argument verbatim, so a `?sort=`
        // parameter forwarded to it was a direct injection point.
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->orderBy('id; DROP TABLE users');
    }

    #[Test]
    public function it_rejects_an_injected_sort_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->orderBy('id', 'ASC, (SELECT 1)');
    }

    #[Test]
    public function it_accepts_a_qualified_column(): void
    {
        $this->assertStringContainsString('users.id', $this->query()->orderBy('users.id')->toSql());
    }

    #[Test]
    public function it_renders_order_by_with_a_normalised_direction(): void
    {
        $this->assertStringContainsString('ORDER BY id DESC', $this->query()->orderBy('id', 'desc')->toSql());
    }

    #[Test]
    public function nested_conditions_do_not_overwrite_outer_bindings(): void
    {
        // Regression: nested builders restarted placeholder numbering at zero, so
        // merging their bindings silently replaced the outer query's values.
        $query = $this->query()
            ->where('status', 'active')
            ->where(function (QueryBuilder $q): void {
                $q->where('role', 'admin')->orWhere('role', 'owner');
            });

        $bindings = array_values($query->getBindings());

        $this->assertCount(3, $query->getBindings());
        $this->assertContains('active', $bindings);
        $this->assertContains('admin', $bindings);
        $this->assertContains('owner', $bindings);
    }

    #[Test]
    public function nested_conditions_are_parenthesised(): void
    {
        $sql = $this->query()
            ->where('status', 'active')
            ->where(function (QueryBuilder $q): void {
                $q->orWhere('role', 'admin')->orWhere('role', 'owner');
            })
            ->toSql();

        $this->assertMatchesRegularExpression('/WHERE status = :\w+ AND \(role = :\w+ OR role = :\w+\)/', $sql);
    }

    #[Test]
    public function repeated_where_in_calls_on_one_column_keep_distinct_bindings(): void
    {
        // Regression: placeholders were named after the column and index, so a
        // second whereIn on the same column overwrote the first one's values.
        $query = $this->query()
            ->whereIn('id', [1, 2])
            ->whereIn('id', [3, 4]);

        $this->assertCount(4, $query->getBindings());
        $this->assertSame([1, 2, 3, 4], array_values($query->getBindings()));
    }

    #[Test]
    public function an_empty_where_in_matches_nothing(): void
    {
        $this->assertStringContainsString('1 = 0', $this->query()->whereIn('id', [])->toSql());
    }

    #[Test]
    public function it_renders_where_null_checks(): void
    {
        $sql = $this->query()->whereNull('deleted_at')->whereNotNull('email')->toSql();

        $this->assertStringContainsString('deleted_at IS NULL', $sql);
        $this->assertStringContainsString('email IS NOT NULL', $sql);
    }

    #[Test]
    public function it_renders_a_between_range(): void
    {
        $query = $this->query()->whereBetween('id', 1, 10);

        $this->assertMatchesRegularExpression('/id BETWEEN :\w+ AND :\w+/', $query->toSql());
        $this->assertSame([1, 10], array_values($query->getBindings()));
    }

    #[Test]
    public function it_renders_limit_and_offset(): void
    {
        $sql = $this->query()->limit(10)->offset(20)->toSql();

        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    #[Test]
    public function it_supplies_a_limit_when_only_an_offset_is_given(): void
    {
        // MySQL rejects OFFSET without LIMIT.
        $sql = $this->query()->offset(20)->toSql();

        $this->assertStringContainsString('LIMIT', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    #[Test]
    public function it_clamps_a_negative_limit(): void
    {
        $this->assertStringContainsString('LIMIT 0', $this->query()->limit(-5)->toSql());
    }

    #[Test]
    public function it_combines_and_with_or_conditions(): void
    {
        // Conditions render in the order they were declared, so an orWhere()
        // after a where() reads as `a OR b` rather than `a AND (b)`.
        $sql = $this->query()->where('a', 1)->orWhere('b', 2)->toSql();

        $this->assertMatchesRegularExpression('/WHERE a = :\w+ OR b = :\w+/', $sql);
    }

    #[Test]
    public function it_preserves_declaration_order_across_connectors(): void
    {
        $sql = $this->query()
            ->where('a', 1)
            ->orWhere('b', 2)
            ->where('c', 3)
            ->toSql();

        $this->assertMatchesRegularExpression('/WHERE a = :\w+ OR b = :\w+ AND c = :\w+/', $sql);
    }

    #[Test]
    public function a_closure_mixing_where_and_orwhere_reads_in_order(): void
    {
        // Regression: separate AND and OR buckets rendered this as
        // `(a AND (c))`, an impossible condition that matched nothing.
        $sql = $this->query()
            ->where('status', 'active')
            ->where(function (QueryBuilder $q): void {
                $q->where('email', 'a')->orWhere('email', 'c');
            })
            ->toSql();

        $this->assertMatchesRegularExpression(
            '/WHERE status = :\w+ AND \(email = :\w+ OR email = :\w+\)/',
            $sql
        );
    }

    #[Test]
    public function a_leading_orwhere_renders_without_a_connector(): void
    {
        $sql = $this->query()->orWhere('a', 1)->toSql();

        $this->assertMatchesRegularExpression('/WHERE a = :\w+$/', $sql);
    }

    #[Test]
    public function it_renders_a_count_statement(): void
    {
        $query = $this->query()->where('status', 'active');
        $reflection = new \ReflectionMethod($query, 'buildCount');

        $this->assertStringContainsString('COUNT(*) as aggregate', $reflection->invoke($query));
    }

    #[Test]
    public function it_restricts_the_selected_columns(): void
    {
        $this->assertSame('SELECT id, email FROM users', $this->query()->select(['id', 'email'])->toSql());
    }

    #[Test]
    public function it_rejects_an_injected_select_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->select(['id, (SELECT password FROM users)']);
    }

    #[Test]
    public function raw_conditions_carry_their_own_bindings(): void
    {
        $query = $this->query()->whereRaw('created_at > :since', ['since' => '2026-01-01']);

        $this->assertStringContainsString('(created_at > :since)', $query->toSql());
        $this->assertSame(['since' => '2026-01-01'], $query->getBindings());
    }

    #[Test]
    public function it_renders_random_ordering(): void
    {
        $this->assertStringContainsString('ORDER BY RAND()', $this->query()->inRandomOrder()->toSql());
    }

    #[Test]
    public function it_renders_group_by(): void
    {
        $this->assertStringContainsString('GROUP BY role', $this->query()->groupBy('role')->toSql());
    }
}
