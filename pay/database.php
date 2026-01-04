<?php
// database.php

class Database {
    private static $instance;
    private $pdo;

    private function __construct() {
        $host = "sql100.infinityfree.com";
        $user = "if0_39819081";
        $pass = "1HQjLkS1oLr3LsH";
        $dbname = "if0_39819081_echama";

        $this->pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }

    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }
}
