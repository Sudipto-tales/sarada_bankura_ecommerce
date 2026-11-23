# Vayu Usage

## Setup

```bash
cp .env.example .env
php vayu setup            # app key, storage dirs, migrations (--seed for demo data, --fresh to reset)
php vayu run              # dev server on http://127.0.0.1:8000
```

`composer install` is optional — it's only needed for PHPMailer, phpdotenv and
Workerman. The framework itself runs on a bare checkout.

## Routing

Define routes in `routes/web.php` and `routes/api.php` (both receive `$router`):

```php
$router->get('/', [WelcomeController::class, 'index'])->name('home');
$router->get('/hello/{name?}', [WelcomeController::class, 'hello']);
$router->post('/contact', [ContactController::class, 'submit']);

$router->group(['prefix' => 'api', 'middleware' => ['auth', 'throttle:60,1']], function ($router) {
    $router->get('/user', [AuthController::class, 'me']);
});
```

- Params: `{id}` required, `{id?}` optional. They inject into the handler by name.
- Type-hint `Vayu\Http\Request` (or a `FormRequest`) and it is injected.
- `php vayu route:list` prints the table.

## Controllers

```php
namespace App\Controllers;

use Vayu\Http\Request;
use Vayu\Http\Response;

class WelcomeController
{
    public function hello(Request $request, ?string $name = null): Response
    {
        return view('welcome', ['name' => $name ?? 'world']);
    }
}
```

Return a `Response`, a string (HTML), or an array/object (JSON).

## Views

PHP views live in `resources/views/`. Render with `view('name', $data)` and
escape output with `e()`:

```php
<h1>Hello, <?= e($name) ?></h1>
<?= csrf_field() ?>
```

## Forms, validation & CSRF

A `FormRequest` validates before the controller runs; failures redirect back
with flashed errors and old input (or return 422 JSON for API clients):

```php
class ContactRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => 'required|email', 'message' => 'required|min:10'];
    }
}
```

In views: `old('email')`, `error('email')`, `errors()`.

## Database

```php
use App\Models\User;

$user  = User::create(['name' => 'Ada', 'email' => 'ada@x.io']);
$found = User::find(1);
$admins = User::where('role', 'admin')->orderBy('name')->get();

// Query builder / facade
DB::table('users')->where('active', 1)->count();
```

Migrations and seeders:

```bash
php vayu make:migration create_posts_table
php vayu migrate            # rollback | status | fresh
php vayu make:seeder PostSeeder
php vayu db:seed
```

## APIs, auth & throttling

```php
// Issue a token
$token = auth()->attempt($email, $password);     // -> store/return it

// Protect routes
$router->get('/api/user', [AuthController::class, 'me'])
    ->middleware(['auth', 'throttle:60,1']);       // 60 requests / minute
```

Clients send `Authorization: Bearer <token>`. Rate-limited responses carry
`X-RateLimit-*` / `Retry-After`. JSON helpers: `json($data, $status)`.

## Cache & events

```php
$value = cache()->remember('key', 300, fn () => expensive());
event(new UserRegistered($user));                  // listeners in config/events.php
```

## Real-time

```bash
composer require workerman/workerman
php vayu websocket
```

Broadcast from anywhere: `broadcast('room.1', 'chat.message', ['text' => 'hi'])`.
See [realtime.md](realtime.md).

## Console commands

Framework commands live in `core/Console/Kernel.php`. Application commands extend
`Vayu\Console\Command` and are registered in `config/app.php`:

```php
'commands' => [
    App\Console\Commands\InventoryCheckCommand::class,
],
```

They then appear in `php vayu list` like any built-in command. See
[inventory.md](inventory.md) for the scheduled inventory check.

## Environment keys

See `.env.example`. Common: `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`,
`APP_KEY`, `DB_*`, `CACHE_DRIVER`, `MAIL_*`, `WS_*`. Never commit real secrets —
`.env` is git-ignored.

## Production

```bash
composer install --no-dev
php vayu optimize          # compile config cache
```

Serve with the document root at `public/`. Re-run `optimize` (or `config:clear`)
after editing `.env`, since cached config freezes env values.

### Production checklist (Maa Sarada)

Production runs on **MySQL** and **Indian time only (IST)**.

1. **`.env`**: `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`, a fresh `APP_KEY`,
   `APP_TIMEZONE=Asia/Kolkata`, `DB_TYPE=mysql` + `DB_*`, `MAIL_*`, `BUSINESS_*`.
2. **MySQL**: create the database as `utf8mb4` / `utf8mb4_unicode_ci`. Each connection runs
   `SET NAMES utf8mb4` and `SET time_zone = '+05:30'` (offset of `APP_TIMEZONE`), so the
   server's own time zone doesn't matter and no tz tables are needed. Keep strict mode on.
   Blank numeric input is saved as NULL (`num_or_null()`), and uniqueness checks are case-insensitive.
3. **Migrate + seed**: `php vayu migrate`, then `php vayu db:seed`, then
   `php vayu db:seed CustomerSeeder` (default customer tags + the six default segments; idempotent).
   The pincode auto-fill on customer addresses needs outbound HTTPS to `api.dharun.dev`; if it is
   blocked the form falls back to manual entry (results are cached in the `pincodes` table).
4. **PHP limits** (php.ini or `.user.ini` in `public/`) for product media and sheet imports:
   ```ini
   upload_max_filesize = 20M
   post_max_size = 64M
   max_file_uploads = 30
   memory_limit = 256M
   max_execution_time = 120
   date.timezone = Asia/Kolkata
   ```
5. **Writable**: `storage/` and `public/assets/uploads/` by the web-server user.
6. **Cron** (inventory check, every 15 min): `bin/install-cron.sh` adds it idempotently.
   Manual line:
   ```
   */15 * * * * cd /path/to/app && php vayu inventory:check >> storage/logs/cron.log 2>&1
   ```
7. **Optimise**: `composer install --no-dev && php vayu optimize`. Re-run after every `.env` change.
