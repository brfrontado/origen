<?php

namespace App\Extra;
// General singleton class.
use Illuminate\Support\Facades\Storage;

class ClassCache {

    private static $instance = null;
    private $data = [];
    private $memCached = false;

    private $diskCachePath = false;

    private function __construct() {
        if (class_exists('Memcached')) {
            $this->memCached = new \Memcached();
            $this->memCached->addServer("127.0.0.1", 11211);
        }

        $storagePath = Storage::disk('local')->path('');
        if (!file_exists($storagePath."diskCache")){
            mkdir($storagePath."diskCache", 0775);
        }
        $this->diskCachePath = $storagePath."diskCache";
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new ClassCache();
        }

        return self::$instance;
    }

    public function get($key) {
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }
        else {
            return null;
        }
    }

    public function set($key, $data) {
        $this->data[$key] = $data;
    }

    public function getDisk($key) {
        $keyTmp = SSO_BRAND_ID."_".$key;
        $filePath = "{$this->diskCachePath}/{$keyTmp}.ch";
        $tmp = null;
        if (file_exists($filePath)) {
            $tmp = file_get_contents($filePath);
        }
        return $tmp;
    }

    public function setDisk($key, $data) {
        $keyTmp = SSO_BRAND_ID."_".$key;
        $filePath = "{$this->diskCachePath}/{$keyTmp}.ch";
        $file = fopen($filePath,"a+");
        fwrite($file, $data);
        fclose($file);
    }

    public function getMemcached($key) {
        if ($this->memCached !== false) {
            $response = $this->memCached->get($key);
            if (!empty($response)) {
                return $response;
            }
            else {
                return null;
            }
        }
        else {
            if (isset($this->data[$key])) {
                return $this->data[$key];
            }
            else {
                return null;
            }
        }
    }

    public function setMemcached($key, $data, $expiration = 86400) {
        if ($this->memCached !== false) {
            $this->memCached->set($key, $data, $expiration); // expiración de 1 día por defecto
        }
        else {
            $this->data[$key] = $data;
        }
    }
}
