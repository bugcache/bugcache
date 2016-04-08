<?php

namespace Bugcache\Encoding;

class Base64Url {
    public static function encode(string $str): string {
        return strtr(base64_encode($str), ["+" => "-", "/" => "_", "=" => ""]);
    }

    public static function decode(string $str): string {
        return base64_decode(strtr($str, ["-" => "+", "_" => "/"]));
    }
}