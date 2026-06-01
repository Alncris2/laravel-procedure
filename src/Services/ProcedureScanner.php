<?php

namespace Alncris2\LaravelProcedure\Services;

use Alncris2\LaravelProcedure\Models\ProcedureDefinition;
use Alncris2\LaravelProcedure\Models\ProcedureSnapshot;
use Alncris2\LaravelProcedure\Support\Checksum;

class ProcedureScanner
{
    protected $basePath;

    public function __construct($basePath = null)
    {
        if ($basePath === null) {
            $basePath = config('procedure.base_path');
        }
        $this->basePath = rtrim((string) $basePath, '/\\');
    }

    /**
     * @return string
     */
    public function basePath()
    {
        return $this->basePath;
    }

    /**
     * Retorna todas as procedures encontradas.
     *
     * @return ProcedureDefinition[]
     */
    public function all()
    {
        $result = array();

        if ($this->basePath === '' || !is_dir($this->basePath)) {
            return $result;
        }

        $groups = $this->listDirs($this->basePath);
        foreach ($groups as $group) {
            $groupPath = $this->basePath . DIRECTORY_SEPARATOR . $group;
            $procedures = $this->listDirs($groupPath);
            foreach ($procedures as $procedureName) {
                $def = $this->buildDefinition($group, $procedureName);
                if ($def !== null) {
                    $result[] = $def;
                }
            }
        }

        return $result;
    }

    /**
     * @param string $group
     * @return ProcedureDefinition[]
     */
    public function findByGroup($group)
    {
        $all = $this->all();
        $out = array();
        foreach ($all as $def) {
            if ($def->group === $group) {
                $out[] = $def;
            }
        }
        return $out;
    }

    /**
     * @param string $name
     * @return ProcedureDefinition|null
     */
    public function findByName($name)
    {
        $all = $this->all();
        foreach ($all as $def) {
            if ($def->name === $name) {
                return $def;
            }
        }
        return null;
    }

    /**
     * @param string $group
     * @param string $name
     * @return ProcedureDefinition
     */
    public function buildDefinition($group, $name)
    {
        $basePath = $this->basePath . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . $name;
        $currentPath = $basePath . DIRECTORY_SEPARATOR . 'current.sql';
        $versionsPath = $basePath . DIRECTORY_SEPARATOR . 'versions';

        $snapshots = $this->listSnapshots($versionsPath);

        return new ProcedureDefinition($group, $name, $basePath, $currentPath, $versionsPath, $snapshots);
    }

    /**
     * @param string $versionsPath
     * @return ProcedureSnapshot[]
     */
    protected function listSnapshots($versionsPath)
    {
        $result = array();
        if (!is_dir($versionsPath)) {
            return $result;
        }

        $files = scandir($versionsPath);
        if ($files === false) {
            return $result;
        }

        $sqlFiles = array();
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (substr($file, -4) !== '.sql') {
                continue;
            }
            $sqlFiles[] = $file;
        }

        sort($sqlFiles);

        $position = 1;
        foreach ($sqlFiles as $file) {
            $label = $this->extractLabel($file);
            $fullPath = $versionsPath . DIRECTORY_SEPARATOR . $file;
            $contents = file_get_contents($fullPath);
            if ($contents === false) {
                $contents = '';
            }

            $result[] = new ProcedureSnapshot(
                $position,
                $label,
                $file,
                $fullPath,
                $contents,
                Checksum::hash($contents)
            );
            $position++;
        }

        return $result;
    }

    /**
     * Extrai o label do nome do arquivo de snapshot.
     *
     * Suporta dois formatos:
     *   Novo: YYYYMMdd_HHmmss_label.sql  → label
     *   Legado: NNN_label.sql            → label
     *
     * @param string $fileName
     * @return string|null
     */
    protected function extractLabel($fileName)
    {
        $base = substr($fileName, 0, -4);

        // Novo formato: 20260601_143022_label ou 20260601_143022
        if (preg_match('/^\d{8}_\d{6}_(.+)$/', $base, $m)) {
            return $m[1];
        }
        if (preg_match('/^\d{8}_\d{6}$/', $base)) {
            return null;
        }

        // Legado: 002_label ou 002
        if (preg_match('/^\d+_(.+)$/', $base, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param string $path
     * @return array
     */
    protected function listDirs($path)
    {
        $out = array();
        if (!is_dir($path)) {
            return $out;
        }
        $items = scandir($path);
        if ($items === false) {
            return $out;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $out[] = $item;
            }
        }
        sort($out);
        return $out;
    }
}
