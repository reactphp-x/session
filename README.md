# reactphp-x/session

Redis-backed session middleware for ReactPHP HTTP with PSR-7 cookies.

- HTTP middleware for `react/http`
- Stores session data in Redis via `reactphp-x/redis-cache`
- Cookie handling via `hansott/psr7-cookies`
- "Begin-only" semantics: a session is created and persisted only after you call `begin()`/`start()` (or when a valid incoming session cookie exists)
- Sliding TTL refresh on each response

## Install

```bash
composer require reactphp-x/session -vvv
```

## Quick start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Clue\React\Redis\Factory as RedisFactory;
use ReactphpX\RedisCache\RedisCache;
use ReactphpX\Session\SessionMiddleware;

$redisFactory = new RedisFactory();
$redisClient = $redisFactory->createLazyClient('redis://127.0.0.1:6379');
$cache = new RedisCache($redisClient);

$sessionMiddleware = new SessionMiddleware($cache, array(
	'ttl' => 1800,
	'cookie_name' => 'SID',
	'cookie_path' => '/',
	'cookie_domain' => '',
	'cookie_secure' => false,
	'cookie_http_only' => true,
	'cookie_same_site' => 'lax',
	'key_prefix' => 'sess:',
));

$http = new HttpServer(
	$sessionMiddleware,
	function (ServerRequestInterface $request) {
		/** @var \ReactphpX\Session\Session $session */
		$session = $request->getAttribute('session');

		// Create and persist session only after begin()/start() is called
		$session->start();

		$visits = (int)$session->get('visits', 0) + 1;
		$session->set('visits', $visits);

		return new Response(
			200,
			array('Content-Type' => 'text/plain'),
			"Visits this session: {$visits}\n"
		);
	}
);

$socket = new SocketServer('0.0.0.0:8080');
$http->listen($socket);

echo "Server running at http://0.0.0.0:8080\n";
```

See a complete runnable sample in `examples/server.php`.

## Middleware options

- `ttl` (int, default `3600`): Redis TTL (seconds). Refreshed on each response (sliding expiration).
- `cookie_name` (string, default `SID`): Session cookie name.
- `cookie_path` (string, default `/`): Cookie path.
- `cookie_domain` (string, default `''`): Cookie domain.
- `cookie_secure` (bool, default `false`): Cookie `Secure` attribute. Required when `cookie_same_site = 'none'`.
- `cookie_http_only` (bool, default `true`): Cookie `HttpOnly` attribute.
- `cookie_same_site` (string, default `'lax'`): One of `'' | 'lax' | 'strict' | 'none'` (`'none'` requires `cookie_secure=true`).
- `key_prefix` (string, default `'sess:'`): Redis key prefix.

## Session API

`ReactphpX\Session\Session` is injected into the request attribute `session`. It is mutable and implements `ArrayAccess`:

- Creation:
  - `begin()` / `start()`: mark session as active for this request. Only after this is called (or when an incoming valid cookie exists), an ID is issued, cookie is set, and data is persisted.
- Identification:
  - `getId(): ?string`
  - `regenerateId(string $newId): void`
  - `regenerate(bool $deleteOldSession = true): void` (generates a secure random ID; old key cleanup handled by middleware)
- Lifecycle:
  - `destroy(): void` (clears data, deletes Redis key if present, and expires cookie)
  - `isBegin(): bool` (whether session has begun in this or a previous request)
  - `isRegenerated(): bool`
  - `getOldId(): ?string`
- Data:
  - `get(string $key, $default = null)`
  - `set(string $key, $value): void`
  - `remove(string $key): void`
  - `replace(array $data): void`
  - `all(): array`
  - Array style: `$session['key']`, `isset($session['key'])`, `unset($session['key'])`

## Behavior

- Only-begin persistence: If there is no valid incoming session cookie, nothing is created or persisted unless `begin()`/`start()` is called by your handler.
- With an incoming cookie: The session is considered begun automatically; data is loaded from Redis.
- Regeneration: When `regenerate()`/`regenerateId()` is called, the old Redis key is removed and the new cookie is issued.
- Destroy: Deletes Redis key (if present) and sends an expired cookie.
- TTL: Redis TTL is refreshed on every response (sliding expiration).
- Cookies: Written using `hansott/psr7-cookies` to ensure correct attributes and formatting.

## References

- ReactPHP HTTP: https://github.com/reactphp/http
- PSR-7 cookies: https://github.com/hansott/psr7-cookies
- Redis cache adapter: https://github.com/reactphp-x/redis-cache

## License

MIT


