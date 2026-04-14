<?php

namespace Alncri2\LaravelProcedure\Support;

class Slugger
{
    /**
     * Converte uma mensagem arbitrária em slug seguro para nome de arquivo.
     *
     * @param string|null $message
     * @param string      $fallback
     * @return string
     */
    public static function slug($message, $fallback = 'snapshot')
    {
        $message = (string) $message;
        $message = strtolower($message);

        // transliteração básica (latin)
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message);
            if ($converted !== false) {
                $message = $converted;
            }
        }

        $message = preg_replace('/[^a-z0-9]+/', '_', $message);
        $message = trim($message, '_');

        if ($message === '' || $message === null) {
            return $fallback;
        }

        // limita tamanho
        if (strlen($message) > 60) {
            $message = substr($message, 0, 60);
            $message = rtrim($message, '_');
        }

        return $message;
    }
}
