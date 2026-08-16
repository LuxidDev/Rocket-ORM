<p align="center">
    <strong>Rocket ORM</strong><br>
    Attribute-driven ORM for the Luxid PHP Framework.
</p>

<p align="center">
    ⚠️ <strong>Pre-release:</strong> APIs are unstable and subject to change.
</p>

---

## About

Rocket maps PHP classes to database tables using PHP 8 attributes, so the schema
lives beside the properties it describes rather than in a separate mapping file.
It ships an Active Record entity, a fluent query builder, migrations, relations,
validation rules and database seeding.

Rocket has no dependency on the rest of Luxid and can be used on its own.

## Installation

```bash
composer require luxid/rocket
```

Requires PHP 8.1+ and `ext-pdo`.

## Connecting

```php
use Rocket\Connection\Connection;

Connection::configure([
    'dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=luxid',
    'user'     => 'root',
    'password' => '',
]);
```

`configure()` records the settings without opening a socket; the connection is
established the first time a query needs it, so an application that never
touches the database never pays for the handshake.

Use `initialize()` instead when you want to connect eagerly and fail fast.

## Entities

```php
use Rocket\ORM\Entity;
use Rocket\Attributes\Entity as EntityAttr;
use Rocket\Attributes\Column;
use Rocket\Attributes\Relations\HasMany;
use Rocket\Attributes\Rules\{Required, Email, Min, Unique};

#[EntityAttr(table: 'users')]
class User extends Entity
{
    #[Column(primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column]
    #[Required]
    #[Email]
    #[Unique]
    public string $email = '';

    #[Column(hidden: true)]
    #[Required]
    #[Min(8)]
    public string $password = '';

    #[Column(autoCreate: true)]
    public string $created_at = '';

    #[HasMany(Post::class, 'user_id', 'id')]
    protected $posts;
}
```

`#[Column(hidden: true)]` keeps a value out of `toArray()` and out of JSON
responses. `autoCreate` and `autoUpdate` mark timestamp columns the database
manages.

### Reading and writing

```php
$user = User::find(1);
$user = User::findOne(['email' => 'jhay@luxid.dev']);
$users = User::findAll(['active' => 1], ['created_at' => 'DESC'], limit: 10);

$result = User::create(['email' => 'jhay@luxid.dev', 'password' => 'secret123']);

if ($result->failed()) {
    return $result->errors();
}

$user->update(['email' => 'new@luxid.dev']);
$user->delete();
```

Relations are loaded on first access and cached for the life of the entity:

```php
foreach ($user->posts as $post) {
    echo $post->title;
}
```

## Query builder

```php
$posts = Post::query()
    ->where('status', 'published')
    ->where(function ($q) {
        $q->where('featured', 1)->orWhere('views', '>=', 1000);
    })
    ->whereIn('category_id', [1, 2, 3])
    ->whereBetween('created_at', $from, $to)
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->all();
```

Values are always bound as parameters.

### Identifiers are validated, not bound

No database driver can bind a table or column name, so Rocket validates every
identifier against a strict pattern before it reaches SQL. Passing user input
straight to `orderBy()` or `select()` throws rather than building the query:

```php
Post::query()->orderBy($_GET['sort']);   // InvalidArgumentException
```

When the sort column genuinely comes from a request, name the columns a request
is allowed to choose:

```php
use Rocket\Query\QueryFilter;

QueryFilter::sort(
    Post::query(),
    $request->query(),
    sortable: ['title', 'created_at', 'views'],
    default: 'created_at'
);
```

`QueryFilter` also handles filtering and pagination, escaping LIKE
metacharacters and clamping the page size so a request cannot ask for the whole
table:

```php
$query = Post::query();

QueryFilter::apply($query, [
    'status' => ['column' => 'status', 'values' => ['draft', 'published']],
    'q'      => ['column' => ['title', 'body'], 'operator' => 'LIKE'],
], $request->query());

QueryFilter::paginate($query, $request->query());
```

### Raw conditions

`whereRaw()` is the escape hatch for expressions the builder cannot model. The
fragment is trusted verbatim, so never build it from request data — pass values
through the bindings argument:

```php
$query->whereRaw('published_at > :since', ['since' => $since]);
```

## Validation

Rules are attributes on the property they guard, and every rule implements
`Rocket\Attributes\Rules\Rule`:

| Rule | Purpose |
|---|---|
| `#[Required]` | Value must be present; `0` and `"0"` count as present |
| `#[Email]` | Must be a valid email address |
| `#[Min(n)]` / `#[Max(n)]` | Character count for strings, value for numbers, count for arrays |
| `#[In([...])]` | Must be one of a fixed set, compared strictly |
| `#[Unique]` | No other row may hold this value |

```php
if (!$user->validate()) {
    return $user->getErrors();
}
```

`#[Unique]` is bound to the column it decorates by the metadata parser, so an
entity with several unique columns checks each against its own.

## Migrations

```php
use Rocket\Migration\{Migration, Rocket};

class m00001_create_users_table extends Migration
{
    public function up(): void
    {
        Rocket::table('users', function ($column) {
            $column->id('id');
            $column->string('email')->unique();
            $column->string('password');
            $column->timestamps();
        });
    }

    public function down(): void
    {
        Rocket::drop('users');
    }
}
```

Tables are created as InnoDB with `utf8mb4` and `utf8mb4_unicode_ci`, so the
full Unicode range — emoji included — stores correctly regardless of the
server's defaults.

## Seeding

```php
use Rocket\Seed\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'email' => 'admin@example.com',
            'password' => 'admin12345',
        ]);
    }
}
```

## Testing

```bash
composer install
composer test
```

## License

MIT.
