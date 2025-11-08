<?php

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Clue\React\Redis\Factory as RedisFactory;
use ReactphpX\RedisCache\RedisCache;
use ReactphpX\Session\SessionMiddleware;

// Create Redis client (lazy) and wrap with our RedisCache
$redisFactory = new RedisFactory();
$redisClient = $redisFactory->createLazyClient('redis://127.0.0.1:6379');
$cache = new RedisCache($redisClient);

// Create session middleware with sensible defaults
$sessionMiddleware = new SessionMiddleware($cache, array(
	'ttl' => 1800, // 30 minutes sliding expiration
	'cookie_name' => 'SID',
	'cookie_path' => '/',
	'cookie_domain' => '',
	'cookie_secure' => false, // set true if you serve via HTTPS and/or use SameSite=None
	'cookie_http_only' => true,
	'cookie_same_site' => 'lax', // '', 'lax', 'strict', 'none' (none requires secure=true)
	'key_prefix' => 'sess:',
));

// Build HTTP server with middleware + handler
$http = new HttpServer(
	$sessionMiddleware,
	function (ServerRequestInterface $request) {
		/** @var \ReactphpX\Session\Session $session */
		$session = $request->getAttribute('session');

		$path = $request->getUri()->getPath();

		// Example endpoint: regenerate session id (e.g. after login)
		if ($path === '/regenerate') {
			$session->begin();
			$session->regenerateId(\bin2hex(\random_bytes(32)));
			return new Response(
				200,
				array('Content-Type' => 'text/plain'),
				"Session ID regenerated.\n"
			);
		}

		// Example endpoint: destroy session (e.g. logout)
		if ($path === '/logout') {
			$session->destroy();
			return new Response(
				200,
				array('Content-Type' => 'text/plain'),
				"Logged out and session destroyed.\n"
			);
		}

		// Default: increment and display visit counter from session
		$session->begin();
		$visits = (int)$session->get('visits', 0) + 1;
		$session->set('visits', $visits);

		return new Response(
			200,
			array('Content-Type' => 'text/plain'),
			"Hello from ReactPHP Session!\nVisits this session: {$visits}\n"
		);
	}
);

// Listen on port 8080
$socket = new SocketServer('0.0.0.0:8022');
$http->listen($socket);

echo "Server running at http://0.0.0.0:8022\n";


