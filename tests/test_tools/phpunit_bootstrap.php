<?php

/**
 * Common settings for all unit tests of the php-image library.
 *
 * Autoloads the library via Composer's PSR-4 map and loads the shared test tools.
 * The library's error message file is resolved by the exception layer itself.
 */

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/ICCProfileBuilder.php');
require_once(__DIR__ . '/PseudoRandomBytes.php');
require_once(__DIR__ . '/TestIOHelper.php');
require_once(__DIR__ . '/TestScriptedStream.php');
require_once(__DIR__ . '/TestPsr7Stream.php');
