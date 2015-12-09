<?php

/*
 * This file is heavily inspired and use code from Composer(getcomposer.org),
 * in particular Composer/Cache and Composer/Util/FileSystem from 1.0.0-alpha7
 *
 * The original code and this file are both released under MIT license.
 *
 * The copyright holders of the original code are:
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 */

namespace Terminus;

use Symfony\Component\Finder\Finder;

/**
 * Reads/writes to a filesystem cache
 */
class FileCache {

  /**
   * @var string cache path
   */
  protected $root;
  /**
   * @var bool
   */
  protected $enabled = true;
  /**
   * @var int files time to live
   */
  protected $ttl = 36000;
  /**
   * @var int max total size
   */
  protected $maxSize;
  /**
   * @var string key allowed chars (regex class)
   */
  protected $whitelist;

  /**
   * Object constructor. Sets properties.
   *
   * @param [string]  $cacheDir  The location of the cache
   * @param [integer] $ttl       The cache file's default expiry time
   * @param [integer] $maxSize   The max total cache size
   * @param [string]  $whitelist A list of characters that are allowed in path
   * @return [FileCache] $this
   */
  public function __construct(
    $cacheDir,
    $ttl,
    $maxSize,
    $whitelist = 'a-z0-9._-'
  ) {
    $this->root      = rtrim($cacheDir, '/\\') . '/';
    $this->ttl       = (int)$ttl;
    $this->maxSize   = (int)$maxSize;
    $this->whitelist = $whitelist;

    if (!$this->ensureDirExists($this->root)) {
      $this->enabled = false;
    }

  }

  /**
   * Clean cache based on time to live and max size
   *
   * @return [boolean] True if cache clean succeeded
   */
  public function clean() {
    if (!$this->enabled) {
      return false;
    }

    $ttl     = $this->ttl;
    $maxSize = $this->maxSize;

    // unlink expired files
    if ($ttl > 0) {
      $expire = new \DateTime();
      $expire->modify('-' . $ttl . ' seconds');

      $finder = $this->getFinder()->date(
        'until ' . $expire->format('Y-m-d H:i:s')
      );
      foreach ($finder as $file) {
        unlink($file->getRealPath());
      }
    }

    // unlink older files if max cache size is exceeded
    if ($maxSize > 0) {
      $files = array_reverse(
        iterator_to_array(
          $this->getFinder()->sortByAccessedTime()->getIterator()
        )
      );
      $total = 0;

      foreach ($files as $file) {
        if ($total + $file->getSize() <= $maxSize) {
          $total += $file->getSize();
        } else {
          unlink($file->getRealPath());
        }
      }
    }

    return true;
  }

  /**
   * Copies a file out of the cache
   *
   * @param [string]  $key    Cache key
   * @param [string]  $target Target filename
   * @param [integer] $ttl    Time to live
   * @return [boolean] $export True if export succeeded
   */
  public function export($key, $target, $ttl = null) {
    $filename = $this->has($key, $ttl);

    $export = false;
    if ($filename) {
      $export = copy($filename, $target);
    }
    return $export;
  }

  /**
   * Flushes all caches
   *
   * @return [void]
   */
  public function flush() {
    $finder = $this->getFinder();
    foreach ($finder as $file) {
      unlink($file->getRealPath());
    }
  }

  /**
   * Reads retrieves data from cache
   *
   * @param [string] $key     A cache key
   * @param [array]  $options Elements as follows:
   *        [boolean] decode_array Argument 2 for json_decode
   *        [boolean] ttl          TTL for file read
   * @return [boolean|string] $data The file contents or false
   */
  public function getData($key, $options = array()) {
    $defaults = array(
      'decode_array' => false,
      'ttl'          => null
    );
    $options  = array_merge($defaults, $options);

    $contents = $this->read($key, $options['ttl']);

    $data = false;
    if ($contents) {
      $data = json_decode($contents, $options['decode_array']);
    }
    return $data;
  }

  /**
   * Returns the cache root
   *
   * @return [string] $this->root
   */
  public function getRoot() {
    return $this->root;
  }

  /**
   * Checks if a file is in cache and return its filename
   *
   * @param [string]  $key Cache key
   * @param [integer] $ttl Time to live
   * @return [boolean|string] The filename or false
   */
  public function has($key, $ttl = null) {
    if (!$this->enabled) {
      return false;
    }

    $filename = $this->filename($key);

    if (!file_exists($filename)) {
      return false;
    }

    // use ttl param or global ttl
    if ($ttl === null) {
      $ttl = $this->ttl;
    } elseif ($this->ttl > 0) {
      $ttl = min((int)$ttl, $this->ttl);
    } else {
      $ttl = (int)$ttl;
    }

    //
    if ($ttl > 0 && filemtime($filename) + $ttl < time()) {
      if ($this->ttl > 0 && $ttl >= $this->ttl) {
        unlink($filename);
      }
      return false;
    }

    return $filename;
  }

  /**
   * Copies a file into the cache
   *
   * @param [string] $key    Cache key
   * @param [string] $source Source filename
   * @return [boolean] $import True if import succeeded
   */
  public function import($key, $source) {
    $filename = $this->prepareWrite($key);

    $import = false;
    if ($filename) {
      $import = (copy($source, $filename) && touch($filename));
    }
    return $import;
  }

  /**
   * Returns whether cache is enabled
   *
   * @return [boolean] $this->enabled
   */
  public function isEnabled() {
    return $this->enabled;
  }

  /**
   * Saves data to the cache, JSON-encoded
   *
   * @param [string] $key  A cache key
   * @param [mixed]  $data Data to save to cache
   * @return [boolean] $result True if write succeeded
   */
  public function putData($key, $data) {
    $json   = json_encode($data);
    $result = $this->write($key, $json);
    return $result;
  }

  /**
   * Reads from the cache file
   *
   * @param [string]  $key A cache key
   * @param [integer] $ttl The time to live
   * @return [boolean|string] $data The file contents or false
   */
  public function read($key, $ttl = null) {
    $filename = $this->has($key, $ttl);

    $data = false;
    if ($filename) {
      $data = file_get_contents($filename);
    }
    return $data;
  }

  /**
   * Remove file from cache
   *
   * @param [string] $key Cache key
   * @return [boolean]
   */
  public function remove($key) {
    if (!$this->enabled) {
      return false;
    }

    $filename = $this->filename($key);

    if (file_exists($filename)) {
      $unlinking = unlink($filename);
      return $unlinking;
    } else {
      return false;
    }
  }

  /**
   * Writes to cache file
   *
   * @param [string] $key      A cache key
   * @param [string] $contents The file contents
   * @return [boolean] $written True if write was successful
   */
  public function write($key, $contents) {
    $filename = $this->prepareWrite($key);

    $written = false;
    if ($filename) {
      $written = (file_put_contents($filename, $contents) && touch($filename));
    }
    return $written;
  }

  /**
   * Ensures a directory exists
   *
   * @param [string] $dir Directory to ensure existence of
   * @return [boolean] $dir_exists
   */
  protected function ensureDirExists($dir) {
    $dir_exists = (
      is_dir($dir)
      || (!file_exists($dir) && mkdir($dir, 0777, true))
    );
    return $dir_exists;
  }

  /**
   * Filename from key
   *
   * @param [string] $key Key to validate
   * @return [string] $filename
   */
  protected function filename($key) {
    $filename = $this->root . $this->validateKey($key);
    return $filename;
  }

  /**
   * Get a Finder that iterates in cache root only the files
   *
   * @return [Finder] $finder
   */
  protected function getFinder() {
    $finder = Finder::create()->in($this->root)->files();
    return $finder;
  }

  /**
   * Prepare cache write
   *
   * @param [string] $key A cache key
   * @return [bool|string] A filename or false
   */
  protected function prepareWrite($key) {
    if (!$this->enabled) {
      return false;
    }
    $filename = $this->filename($key);
    if (!$this->ensureDirExists(dirname($this->filename($key)))) {
      return false;
    }
    return $filename;
  }

  /**
   * Validate cache key
   *
   * @param [string] $key A cache key
   * @return [string] $parts_string A relative filename
   */
  protected function validateKey($key) {
    $url_parts = parse_url($key);
    if (! empty($url_parts['scheme'])) { // is url
      $parts = array('misc');

      $part_parts = array($url_parts['scheme'] . '-');
      if (isset($url_parts['host'])) {
        $part_parts[] = $url_parts['host'];
      }
      if (!empty($url_parts['port'])) {
        $part_parts[] = '-' . $url_parts['port'];
      }
      $parts[] = implode('', $part_parts);

      $part_parts = array(substr($url_parts['path'], 1));
      if (!empty($url_parts['query'])) {
        $part_parts[] = '-' . $url_parts['query'];
      }
      $parts[] = implode('', $part_parts);
    } else {
      $key   = str_replace('\\', '/', $key);
      $parts = explode('/', ltrim($key));
    }
    $parts = preg_replace("#[^{$this->whitelist}]#i", '-', $parts);

    $parts_string = implode('/', $parts);
    return $parts_string;
  }

}
