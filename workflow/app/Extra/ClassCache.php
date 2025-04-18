<?php

namespace App\Extra;
// General singleton class.
class ClassCache {

    private static $instance = null;
    private $data = [];

    private function __construct() {
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

}
