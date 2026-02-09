<?php

use App\Support\Util;

if (! function_exists('flash')) {
    /**
     * Set a flash message.
     *
     * @param string $message
     * @param string $title
     *
     * @return \App\Support\FlashManager|null
     */
    function flash($message = null, $title = null)
    {
        return Util::flash($message, $title);
    }
}

if (! function_exists('trimQuotes')) {
    /**
     * Remove surrounding quotes from a string value.
     *
     * Only strips the quotes when both the first and last character
     * are the same quote character (" or ').
     *
     * @param  mixed  $value
     * @return string
     */
    function trimQuotes($value): string
    {
        if (! is_string($value) || strlen($value) < 2) {
            return (string) ($value ?? '');
        }

        $first = $value[0];
        $last  = $value[strlen($value) - 1];

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}

if (! function_exists('setting')) {
    /**
     * Get the setting.
     *
     * @param string $key
     * @param string $locale
     * @param bool   $fallbackToDefault
     *
     * @throws InvalidArgumentException
     *
     * @return \App\Support\SettingManager|mixed
     */
    function setting($key = null, $locale = null, $fallbackToDefault = true)
    {
        return Util::setting($key, $locale, $fallbackToDefault);
    }
}
