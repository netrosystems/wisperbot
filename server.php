<?php

// Local dev override for `php artisan serve`: silence E_DEPRECATED noise from
// PHP 8.5's PDO constant changes, which otherwise gets printed inline and
// corrupts JSON/Inertia responses. Delete this file to restore Laravel's
// default vendor/.../resources/server.php behavior.
error_reporting(E_ALL & ~E_DEPRECATED);

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

$formattedDateTime = date('D M j H:i:s Y');

$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';
