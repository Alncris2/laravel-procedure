<?php

namespace Alncris2\LaravelProcedure\Support;

class PathHelper
{
    /**
     * Converte um caminho absoluto em relativo ao basePath.
     *
     * Normaliza separadores para "/" antes de comparar, garantindo
     * portabilidade entre Windows (\\) e Unix (/).
     *
     * @param string $absolutePath
     * @param string $basePath
     * @return string
     */
    public static function relativize($absolutePath, $basePath)
    {
        if ((string) $basePath === '') {
            return $absolutePath;
        }

        $base = rtrim(str_replace('\\', '/', (string) $basePath), '/');
        $path = str_replace('\\', '/', (string) $absolutePath);

        if (strpos($path, $base . '/') === 0) {
            return substr($path, strlen($base) + 1);
        }

        return $absolutePath;
    }
}
