<?php

// Router für `php -S`: vorhandene Dateien (public/_assets, typo3temp …) direkt ausliefern, alles andere an TYPO3.
$pfad = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$datei = $_SERVER['DOCUMENT_ROOT'] . $pfad;
if ($pfad !== '/' && is_file($datei) && !str_ends_with($datei, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
