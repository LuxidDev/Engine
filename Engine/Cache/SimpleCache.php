<?php

namespace Luxid\Cache;

class SimpleCache
{
    private static array $cache = [];

    public static function remember(string $key, int $seconds, callable $callback)
    {
        $now = time();

        if (isset(self::$cache[$key]) && ($now - self::$cache[$key]['time']) < $seconds) {
            return self::$cache[$key]['data'];
        }

        $data = $callback();
        self::$cache[$key] = [
            'data' => $data,
            'time' => $now
        ];

        return $data;
    }

    public static function forget(string $key): void
    {
        unset(self::$cache[$key]);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}