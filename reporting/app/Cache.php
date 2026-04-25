<?php
/**
 * Simple file-based caching system
 */
class Cache {
    private $cacheDir;
    private $ttl; // Time to live in seconds
    
    public function __construct($ttl = 3600) { // Default 1 hour
        // Use application cache directory instead of system temp
        $this->cacheDir = __DIR__ . '/../cache';
        $this->ttl = $ttl;
        
        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get cached value if it exists and is not expired
     */
    public function get($key) {
        $file = $this->getCacheFile($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        // Check if cache is expired
        $fileTime = filemtime($file);
        if (time() - $fileTime > $this->ttl) {
            return null;
        }
        
        // Return cached data
        $data = file_get_contents($file);
        return json_decode($data, true);
    }
    
    /**
     * Store value in cache
     */
    public function set($key, $value) {
        $file = $this->getCacheFile($key);
        $data = json_encode($value);
        file_put_contents($file, $data, LOCK_EX);
        return true;
    }
    
    /**
     * Clear specific cache entry
     */
    public function delete($key) {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
        return true;
    }
    
    /**
     * Clear all cache entries
     */
    public function clear() {
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }
    
    /**
     * Get cache file path
     */
    private function getCacheFile($key) {
        // Sanitize key to make it filesystem-safe
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.cache';
    }
}
