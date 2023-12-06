<?php


namespace Umino\Anime;


class Cache
{
    protected static array $cache = [];

    /**
     * Получает кеш значения по ключу
     *
     * @param string $key
     * @return mixed
     */
    public static function get(string $key = '')
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $next = next($trace);
        return self::$cache[md5($next['class'].$next['function'].$key)];
    }

    /**
     * Записывает кеш значения по ключу
     *
     * @param string $key
     * @param mixed $value
     */
    public static function set($value, string $key = '')
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $next = next($trace);
        self::$cache[md5($next['class'].$next['function'].$key)] = $value;
        return $value;
    }
}