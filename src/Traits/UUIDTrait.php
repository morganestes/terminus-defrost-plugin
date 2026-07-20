<?php

namespace MorganEstes\Terminus\Traits;

trait UUIDTrait
{
    /**
     * A regular expression for matching a UUID.
     */
    public const UUID_REGEX = '/[\da-f]{8}-(?:[\da-f]{4}-){3}[\da-f]{12}/i';

    /**
     * Checks to see if this is a valid UUID format. It does not check for Site ID validity.
     *
     * @param string $maybeUUID The string to check.
     * @return bool Whether this matches the UUID format.
     */
    public static function isUUID(string $maybeUUID): bool
    {
        return (preg_match(self::UUID_REGEX, trim($maybeUUID)) === 1);
    }
}
