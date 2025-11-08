<?php

namespace ReactphpX\Session;

/**
 * Simple mutable session container used by SessionMiddleware.
 */
class Session implements \ArrayAccess
{
    /** @var string */
	private $id;

    /** @var array */
	private $data;

    /** @var bool */
	private $dirty = false;

    /** @var bool */
	private $destroyed = false;

    /** @var bool */
	private $regenerated = false;

    /** @var string|null */
	private $oldId;

	/** @var bool */
	private $begin = false;

    /**
	 * @param string|null $id
     * @param array $data
     */
	public function __construct($id, array $data = array())
    {
		$this->id = $id;
		$this->data = $data;
		$this->dirty = false;
		$this->begin = $id !== null && $id !== '';
    }

    /**
     * Get current session identifier.
     *
	 * @return string|null
     */
    public function getId()
    {
		return $this->id;
    }

    /**
     * Regenerate the session identifier.
     *
     * @param string $newId
     * @return void
     */
    public function regenerateId($newId)
    {
		if ($newId === $this->id) {
			return;
		}

		$this->oldId = $this->id;
		$this->id = $newId;
		$this->regenerated = true;
		$this->begin = true;
    }

    /**
     * Return previous session id if regenerated.
     *
     * @return string|null
     */
    public function getOldId()
    {
		return $this->oldId;
    }

    /**
     * Whether the session id was regenerated during this request.
     *
     * @return bool
     */
    public function isRegenerated()
    {
		return $this->regenerated;
    }

    /**
     * Mark session as destroyed; data will be cleared and deleted from storage.
     *
     * @return void
     */
    public function destroy()
    {
		$this->data = array();
		$this->destroyed = true;
		$this->dirty = true;
    }

    /**
     * Whether the session has been destroyed.
     *
     * @return bool
     */
    public function isDestroyed()
    {
		return $this->destroyed;
    }

    /**
     * Get a value from session data.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
		return \array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /**
     * Set a value in session data.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set($key, $value)
    {
		$this->data[$key] = $value;
		$this->dirty = true;
    }

    /**
     * Remove a value from session data.
     *
     * @param string $key
     * @return void
     */
    public function remove($key)
    {
		if (\array_key_exists($key, $this->data)) {
			unset($this->data[$key]);
			$this->dirty = true;
		}
    }

    /**
     * Replace entire session data.
     *
     * @param array $data
     * @return void
     */
    public function replace(array $data)
    {
		$this->data = $data;
		$this->dirty = true;
    }

    /**
     * Get all session data (by value).
     *
     * @return array
     */
    public function all()
    {
		return $this->data;
    }

    /**
     * Whether session data changed during this request.
     *
     * @return bool
     */
    public function isDirty()
    {
		return $this->dirty;
    }

	/**
	 * Alias of begin() to mirror PHP's session_start().
	 *
	 * @return void
	 */
	public function start()
	{
		$this->begin();
	}

	/**
	 * Convenience method similar to PHP's session_regenerate_id().
	 *
	 * @param bool $deleteOldSession Ignored here; old key cleanup handled by middleware.
	 * @return void
	 */
	public function regenerate($deleteOldSession = true)
	{
		$this->regenerateId(\bin2hex(\random_bytes(32)));
	}

	/**
	 * Mark the session as begun. Cookie and persistence will only occur if begun.
	 *
	 * @return void
	 */
	public function begin()
	{
		$this->begin = true;
	}

	/**
	 * Whether the session has begun in this or a previous request.
	 *
	 * @return bool
	 */
	public function isBegin()
	{
		return $this->begin;
	}


	// ArrayAccess implementation for $session['key'] usage
	public function offsetExists($offset): bool
	{
		return \array_key_exists($offset, $this->data);
	}

	/** @return mixed */
	public function offsetGet($offset)
	{
		return $this->get($offset);
	}

	public function offsetSet($offset, $value): void
	{
		$this->set($offset, $value);
	}

	public function offsetUnset($offset): void
	{
		$this->remove($offset);
	}
}


