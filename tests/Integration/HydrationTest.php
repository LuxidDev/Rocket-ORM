<?php

declare(strict_types=1);

namespace Rocket\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rocket\Connection\Connection;
use Rocket\Tests\Fixtures\TestUser;

/**
 * End-to-end tests against a real database.
 *
 * SQLite in memory is close enough to exercise hydration, dirty tracking and
 * the insert/update decision without needing a MySQL server.
 *
 * @package Rocket\Tests\Integration
 */
final class HydrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Connection::reset();
        Connection::initialize(['dsn' => 'sqlite::memory:']);

        Connection::getInstance()->getPdo()->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                firstname TEXT NOT NULL,
                lastname TEXT NOT NULL,
                created_at TEXT DEFAULT "",
                updated_at TEXT DEFAULT ""
            )'
        );
    }

    protected function tearDown(): void
    {
        Connection::reset();

        parent::tearDown();
    }

    /**
     * Insert a row directly, bypassing the ORM.
     *
     * @param string $email Email address for the row
     */
    private function givenRow(string $email = 'jhay@luxid.dev'): void
    {
        Connection::getInstance()->insert('users', [
            'email' => $email,
            'password' => 'hashed-password',
            'firstname' => 'Jhay',
            'lastname' => 'Dev',
            'created_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
        ]);
    }

    #[Test]
    public function it_hydrates_a_row_into_an_entity(): void
    {
        $this->givenRow();

        $user = TestUser::query()->where('email', 'jhay@luxid.dev')->first();

        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertSame('jhay@luxid.dev', $user->email);
        $this->assertSame('Jhay', $user->firstname);
        $this->assertGreaterThan(0, $user->id);
    }

    #[Test]
    public function a_hydrated_entity_is_not_marked_as_new(): void
    {
        // Regression: rows read through the query builder stayed flagged as new,
        // so saving one issued an INSERT and silently duplicated the record.
        $this->givenRow();

        $user = TestUser::query()->first();

        $this->assertFalse($user->isNew());
    }

    #[Test]
    public function saving_an_unchanged_hydrated_entity_does_not_duplicate_it(): void
    {
        $this->givenRow();

        $user = TestUser::query()->first();
        $user->save();

        $this->assertSame(1, TestUser::query()->count());
    }

    #[Test]
    public function a_hydrated_entity_starts_clean(): void
    {
        $this->givenRow();

        $user = TestUser::query()->first();

        $this->assertSame([], $user->getDirty());
        $this->assertFalse($user->isDirty('email'));
    }

    #[Test]
    public function changing_a_field_marks_only_that_field_dirty(): void
    {
        $this->givenRow();

        $user = TestUser::query()->first();
        $user->firstname = 'Changed';

        $this->assertSame(['firstname' => 'Changed'], $user->getDirty());
        $this->assertTrue($user->isDirty('firstname'));
        $this->assertFalse($user->isDirty('email'));
    }

    #[Test]
    public function it_updates_an_existing_row_rather_than_inserting(): void
    {
        $this->givenRow();

        $user = TestUser::query()->first();
        $id = $user->id;
        $user->firstname = 'Updated';
        $user->save();

        $this->assertSame(1, TestUser::query()->count());
        $this->assertSame('Updated', TestUser::find($id)->firstname);
    }

    #[Test]
    public function findOne_returns_a_clean_persisted_entity(): void
    {
        $this->givenRow();

        $user = TestUser::findOne(['email' => 'jhay@luxid.dev']);

        $this->assertFalse($user->isNew());
        $this->assertSame([], $user->getDirty());
    }

    #[Test]
    public function findAll_returns_clean_persisted_entities(): void
    {
        $this->givenRow('one@luxid.dev');
        $this->givenRow('two@luxid.dev');

        $users = TestUser::findAll();

        $this->assertCount(2, $users);

        foreach ($users as $user) {
            $this->assertFalse($user->isNew());
            $this->assertSame([], $user->getDirty());
        }
    }

    #[Test]
    public function a_partial_select_still_yields_a_clean_entity(): void
    {
        // Columns the query did not select must not look dirty afterwards.
        $this->givenRow();

        $users = TestUser::query()->select(['id', 'email'])->all();

        $this->assertSame([], $users[0]->getDirty());
        $this->assertSame('jhay@luxid.dev', $users[0]->email);
    }

    #[Test]
    public function hidden_columns_stay_out_of_the_array_form(): void
    {
        $this->givenRow();

        $array = TestUser::query()->first()->toArray();

        $this->assertArrayHasKey('email', $array);
        $this->assertArrayNotHasKey('password', $array);
    }

    #[Test]
    public function nested_conditions_bind_the_values_they_declare(): void
    {
        $this->givenRow('a@luxid.dev');
        $this->givenRow('b@luxid.dev');
        $this->givenRow('c@luxid.dev');

        $users = TestUser::query()
            ->where('lastname', 'Dev')
            ->where(function ($q): void {
                $q->where('email', 'a@luxid.dev')->orWhere('email', 'c@luxid.dev');
            })
            ->all();

        $emails = array_map(static fn (TestUser $u): string => $u->email, $users);
        sort($emails);

        $this->assertSame(['a@luxid.dev', 'c@luxid.dev'], $emails);
    }

    #[Test]
    public function repeated_where_in_calls_keep_their_own_values(): void
    {
        $this->givenRow('a@luxid.dev');
        $this->givenRow('b@luxid.dev');

        $count = TestUser::query()
            ->whereIn('email', ['a@luxid.dev', 'b@luxid.dev'])
            ->whereIn('lastname', ['Dev'])
            ->count();

        $this->assertSame(2, $count);
    }

    #[Test]
    public function it_counts_and_reports_existence(): void
    {
        $this->assertSame(0, TestUser::query()->count());
        $this->assertFalse(TestUser::query()->exists());

        $this->givenRow();

        $this->assertSame(1, TestUser::query()->count());
        $this->assertTrue(TestUser::query()->exists());
    }

    #[Test]
    public function it_plucks_a_single_column(): void
    {
        $this->givenRow('a@luxid.dev');
        $this->givenRow('b@luxid.dev');

        $emails = TestUser::query()->orderBy('email')->pluck('email');

        $this->assertSame(['a@luxid.dev', 'b@luxid.dev'], $emails);
    }

    #[Test]
    public function it_applies_limit_and_offset(): void
    {
        foreach (['a', 'b', 'c'] as $letter) {
            $this->givenRow("{$letter}@luxid.dev");
        }

        $users = TestUser::query()->orderBy('email')->limit(1)->offset(1)->all();

        $this->assertCount(1, $users);
        $this->assertSame('b@luxid.dev', $users[0]->email);
    }
}
