<?php

/*
| Router for `php artisan serve` (Laravel uses this file when it exists).
|
| Only real files are served as they are. Laravel's own router also hands
| directories to the built-in server, and public/<path>/ exists (the build
| goes to public/<path>/build), so /<path>/ — the product's home — would
| be a 404 instead of a route. Production web servers always go through
| index.php for /<path>/ and never read this file.
*/

$publicPath = getcwd();

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

$formattedDateTime = date('D M j H:i:s Y');
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[{$formattedDateTime}] {$remoteAddress} [{$_SERVER['REQUEST_METHOD']}] URI: {$uri}\n");

require_once $publicPath.'/index.php';
