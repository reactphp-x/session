<?php

namespace ReactphpX\Session;

use HansOtt\PSR7Cookies\SetCookie;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise;
use React\Promise\PromiseInterface;
use ReactphpX\RedisCache\RedisCache;

/**
 * ReactPHP HTTP middleware that provides PSR-7 session handling backed by Redis.
 *
 * $http = new React\Http\HttpServer(
 *     new React\Http\Middleware\StreamingRequestMiddleware(),
 *     new ReactphpX\Session\SessionMiddleware($redisCache, [
 *         'ttl' => 3600,
 *         'cookie_name' => 'SID',
 *         'cookie_path' => '/',
 *         'cookie_domain' => '',
 *         'cookie_secure' => false,
 *         'cookie_http_only' => true,
 *         'cookie_same_site' => 'lax', // '', 'lax', 'strict', 'none' (none requires secure)
 *         'key_prefix' => 'sess:',
 *     ]),
 *     $handler
 * );
 */
final class SessionMiddleware
{
    /** @var RedisCache */
    private $cache;

    /** @var array */
    private $options;

    /**
     * @param RedisCache $cache
     * @param array $options
     */
    public function __construct(RedisCache $cache, array $options = array())
    {
        $this->cache = $cache;
        $this->options = $this->normalizeOptions($options);
    }

    /**
     * @param ServerRequestInterface $request
     * @param callable $next
     * @return ResponseInterface|PromiseInterface
     */
    public function __invoke(ServerRequestInterface $request, $next)
    {
        $cookieName = $this->options['cookie_name'];
        $cookies = $request->getCookieParams();
        $incomingId = isset($cookies[$cookieName]) ? (string)$cookies[$cookieName] : null;

		$sessionId = $this->validateSessionId($incomingId) ? $incomingId : null;
		$key = $sessionId ? ($this->options['key_prefix'] . $sessionId) : null;

		// Read from cache if we have a valid incoming id, otherwise skip and start empty.
		if ($key !== null) {
			$loadPromise = $this->cache->get($key, null)->then(function ($raw) {
				if ($raw === null || $raw === '') {
					return array();
				}
				if (\is_array($raw)) {
					return $raw;
				}
				$decoded = \json_decode((string)$raw, true);
				return \is_array($decoded) ? $decoded : array();
			});
		} else {
			$loadPromise = Promise\resolve(array());
		}

		return $loadPromise->then(function (array $data) use ($request, $next, $sessionId, $key) {
			$session = new Session($sessionId, $data);
            $request = $request->withAttribute('session', $session);

            // Invoke next and then persist session. Support both sync response and promise-returning handlers.
            try {
                $result = $next($request);
            } catch (\Throwable $e) {
                // Do not attempt to persist if handler throws before response is created.
                throw $e;
            }

            return Promise\resolve($result)->then(function ($response) use ($session, $key) {
                if (!$response instanceof ResponseInterface) {
                    return $response;
                }

				// If session not begun (and no incoming id), do nothing: no ID, no cookie, no persistence.
				if (!$session->isBegin() && $session->getId() === null) {
					return $response;
				}

				// Handle destroy/regenerate, then upsert session data with sliding TTL.
				$persist = null;
				if ($session->isDestroyed()) {
					// If we have a key/id, delete from storage and expire cookie.
					if ($key !== null) {
						$persist = $this->cache->delete($key);
					}
					$response = $this->expireCookie($response);
					if ($persist instanceof Promise\PromiseInterface) {
						return $persist->then(function () use ($response) {
							return $response;
						});
					}
					return $response;
				}

				// Ensure we have a session id if session is begun but no id yet (deferred creation).
				if ($session->isBegin() && $session->getId() === null) {
					$session->regenerateId($this->generateSessionId());
                }

				$effectiveId = $session->getId();
				$effectiveKey = $this->options['key_prefix'] . $effectiveId;

				if ($session->isRegenerated() && $session->getOldId() !== null) {
					// Delete old key, write new key
					$oldKey = $this->options['key_prefix'] . $session->getOldId();
					$persist = $this->cache->delete($oldKey);
					$response = $this->attachCookie($response, $effectiveId);
				} else {
					// Ensure client has the cookie (also refresh attributes/expiry)
					$response = $this->attachCookie($response, $effectiveId);
				}

                // Always refresh TTL (sliding expiration). Only write payload if dirty to save bandwidth.
                $payload = $session->all();
                $encoded = \json_encode($payload);
                $write = $this->cache->set($effectiveKey, $encoded, $this->options['ttl']);

                if ($persist instanceof Promise\PromiseInterface) {
                    return $persist->then(function () use ($write, $response) {
                        return Promise\resolve($write)->then(function () use ($response) {
                            return $response;
                        });
                    });
                }

                return Promise\resolve($write)->then(function () use ($response) {
                    return $response;
                });
            });
        });
    }

    /**
     * Normalize and validate options, applying defaults.
     *
     * @param array $options
     * @return array
     */
    private function normalizeOptions(array $options)
    {
        $normalized = array(
            'ttl' => isset($options['ttl']) ? (int)$options['ttl'] : 3600,
            'cookie_name' => isset($options['cookie_name']) ? (string)$options['cookie_name'] : 'SID',
            'cookie_path' => isset($options['cookie_path']) ? (string)$options['cookie_path'] : '/',
            'cookie_domain' => isset($options['cookie_domain']) ? (string)$options['cookie_domain'] : '',
            'cookie_secure' => isset($options['cookie_secure']) ? (bool)$options['cookie_secure'] : false,
            'cookie_http_only' => isset($options['cookie_http_only']) ? (bool)$options['cookie_http_only'] : true,
            'cookie_same_site' => isset($options['cookie_same_site']) ? \strtolower((string)$options['cookie_same_site']) : 'lax',
            'key_prefix' => isset($options['key_prefix']) ? (string)$options['key_prefix'] : 'sess:',
        );

        // Enforce SameSite=None requires Secure=true (as per spec and psr7-cookies lib)
        if ($normalized['cookie_same_site'] === 'none') {
            $normalized['cookie_secure'] = true;
        }

        return $normalized;
    }

    /**
     * Add Set-Cookie header for the session id.
     *
     * @param ResponseInterface $response
     * @param string $sessionId
     * @return ResponseInterface
     */
    private function attachCookie(ResponseInterface $response, $sessionId)
    {
        $expires = $this->options['ttl'] > 0 ? (\time() + $this->options['ttl']) : 0;
        $cookie = new SetCookie(
            $this->options['cookie_name'],
            $sessionId,
            $expires,
            $this->options['cookie_path'],
            $this->options['cookie_domain'],
            $this->options['cookie_secure'],
            $this->options['cookie_http_only'],
            $this->options['cookie_same_site']
        );

        return $cookie->addToResponse($response);
    }

    /**
     * Expire the session cookie on client.
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    private function expireCookie(ResponseInterface $response)
    {
        $cookie = SetCookie::thatDeletesCookie(
            $this->options['cookie_name'],
            $this->options['cookie_path'],
            $this->options['cookie_domain'],
            $this->options['cookie_secure'],
            $this->options['cookie_http_only'],
            $this->options['cookie_same_site']
        );

        return $cookie->addToResponse($response);
    }

    /**
     * Validate that a session id is a hex string of reasonable length.
     *
     * @param string|null $id
     * @return bool
     */
    private function validateSessionId($id)
    {
        if (!\is_string($id)) {
            return false;
        }
        if (\strlen($id) < 16 || \strlen($id) > 128) {
            return false;
        }
        return (bool)\preg_match('/^[A-Fa-f0-9]+$/', $id);
    }

    /**
     * Generate a cryptographically secure random session id (hex).
     *
     * @return string
     */
    private function generateSessionId()
    {
        return \bin2hex(\random_bytes(32));
    }
}


