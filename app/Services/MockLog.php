<?php

namespace App\Services;

class MockLog
{
    public static function info($message, $context = []) {}
    public static function error($message, $context = []) {}
    public static function warning($message, $context = []) {}
    public static function debug($message, $context = []) {}
}
