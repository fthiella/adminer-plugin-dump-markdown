<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * AdminerDumpMarkdown's formatting/escaping logic lives in private methods.
 * That's the right visibility for production code (it's not part of the
 * plugin's public contract with Adminer), but it means tests need Reflection
 * to reach it directly instead of going through the Adminer-coupled public
 * methods for every single case.
 *
 * Note: ReflectionMethod/ReflectionProperty have been accessible by default
 * since PHP 8.1, so the old setAccessible(true) call is unnecessary and, as
 * of PHP 8.5, deprecated.
 */
trait InvokesPrivateMembers
{
    /**
     * @param array<mixed> $args
     */
    protected function callPrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        return $ref->invokeArgs($object, $args);
    }

    protected function getPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        return $ref->getValue($object);
    }

    protected function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }
}
