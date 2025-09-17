<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$mysqli = new mysqli(
    $config['db']['host'],
    $config['db']['username'],
    $config['db']['password'],
    $config['db']['database'],
    $config['db']['port']
);

if ($mysqli->connect_error) {
    die('Database connection failed: ' . $mysqli->connect_error);
}

$mysqli->set_charset($config['db']['charset']);
