<?php

namespace Alncri2\LaravelProcedure\Support;

class Checksum
{
    /**
     * Calcula sha256 do conteúdo.
     *
     * @param string $contents
     * @return string
     */
    public static function hash($contents)
    {
        return hash('sha256', (string) $contents);
    }
}
