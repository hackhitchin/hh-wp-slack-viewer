<?php

// A nested cache using the WordPress transients system.

namespace HitchinHackspace\SlackViewer;

use ArrayAccess;

// Utility methods for things that have a getCache() method. 
trait HasCache {
    abstract function getCache();

    function cacheGet($key) {
        $cache = $this->getCache();

        return $cache->offsetExists($key) ? $cache->offsetGet($key) : null;
    }

    function cacheSet($key, $value) {
        $this->getCache()->offsetSet($key, $value);
    }

    function getSubcache($key) {
        return new SubCache($this->getCache(), $key);
    }

    function cached($key, $computeFn) {
        $value = $this->cacheGet($key);
        if ($value === null) {
            $value = $computeFn();
            $this->cacheSet($key, $value);
        }
        return $value;
    }
}

// A cache that saves its values to a (single) transient in the database.
class TransientCache implements ArrayAccess {
    // The transient key used.
    private $key;
 
    // The loaded transient.
    private $transient;
 
    function __construct($key) {
       $this->key = $key;
       $this->load();
    }
 
    private function load() {
       $this->transient = get_transient($this->key) ?? [];
    }
 
    public function offsetExists(mixed $offset): bool { 
       return array_key_exists($offset, $this->transient);
    }
 
    public function offsetGet(mixed $offset): mixed { 
       return $this->transient[$offset];
    }
 
    public function offsetSet(mixed $offset, mixed $value): void {
       $this->transient[$offset] = $value;
    }
 
    public function persist() {
       set_transient($this->key, $this->transient, 3600);
    }
 
    public function offsetUnset(mixed $offset): void { 
       unset($this->transient[$offset]);
    }
}

// A cache that represents a part of a larger backing cache.
class SubCache implements ArrayAccess {
    // The backing cache.
    private $backing;
 
    // The subkey used.
    private $key;
 
    function __construct($backing, $key) {
       $this->backing = $backing;
       $this->key = $key;
    }
 
    public function offsetExists(mixed $offset): bool { 
       if (!$this->backing->offsetExists($this->key))
          return false;
 
       $cache = $this->backing->offsetGet($this->key);
       return array_key_exists($offset, $cache);
    }
 
    public function offsetGet(mixed $offset): mixed { 
       if (!$this->backing->offsetExists($this->key))
          return null;
 
       $cache = $this->backing->offsetGet($this->key);
       return $cache[$offset];
    }
 
    public function offsetSet(mixed $offset, mixed $value): void { 
       $cache = [];
       if ($this->backing->offsetExists($this->key))
          $cache = $this->backing->offsetGet($this->key);
 
       $cache[$offset] = $value;
       $this->backing->offsetSet($this->key, $cache);
    }
 
    public function offsetUnset(mixed $offset): void {
       if (!$this->backing->offsetExists($this->key))
          return;
 
       $cache = $this->backing->offsetGet($this->key);
       unset($cache[$offset]);
       $this->backing->offsetSet($this->key, $cache);
    }
}