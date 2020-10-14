<?php

declare(strict_types=1);

namespace kuiper\swoole\coroutine;

use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Runtime;

final class Coroutine
{
    private const NOT_COROUTINE_ID = 0;

    /**
     * @var int
     */
    private static $HOOK_FLAGS = SWOOLE_HOOK_ALL | SWOOLE_HOOK_CURL;

    /**
     * @var \ArrayObject|null
     */
    private static $CONTEXT;

    public static function isEnabled(): bool
    {
        return SwooleCoroutine::getCid() > self::NOT_COROUTINE_ID;
    }

    public static function enable(): void
    {
        Runtime::enableCoroutine(true, self::$HOOK_FLAGS);
    }

    public static function disable(): void
    {
        Runtime::enableCoroutine(false);
    }

    public static function getCoroutineId(): int
    {
        if (self::isEnabled()) {
            return SwooleCoroutine::getCid();
        }

        return self::NOT_COROUTINE_ID;
    }

    public static function setHookFlags(int $flags): void
    {
        self::$HOOK_FLAGS = $flags;
    }

    public static function getContext(int $coroutineId = null): \ArrayObject
    {
        if (self::isEnabled()) {
            return isset($coroutineId) ? SwooleCoroutine::getContext($coroutineId) : SwooleCoroutine::getContext();
        }

        if (null === self::$CONTEXT) {
            self::$CONTEXT = new \ArrayObject();
        }

        return self::$CONTEXT;
    }

    public static function clearContext(): void
    {
        if (self::isEnabled()) {
            return;
        }
        self::$CONTEXT = null;
    }

    public static function defer(callable $callback): void
    {
        if (self::isEnabled()) {
            SwooleCoroutine::defer($callback);
        } else {
            register_shutdown_function($callback);
        }
    }
}
