<?php

namespace Alncri2\LaravelProcedure\Services;

use Alncri2\LaravelProcedure\Models\ProcedureDefinition;
use Alncri2\LaravelProcedure\Models\ProcedureSnapshot;
use Alncri2\LaravelProcedure\Support\Checksum;

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

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (substr($file, -4) !== '.sql') {
                continue;
            }
            if (!preg_match('/^(\d+)(?:_(.+))?\.sql$/', $file, $m)) {
                continue;
            }

            $versionNumber = (int) $m[1];
            $label = isset($m[2]) ? $m[2] : null;
            $fullPath = $versionsPath . DIRECTORY_SEPARATOR . $file;
            $contents = file_get_contents($fullPath);
            if ($contents === false) {
                $contents = '';
            }
            $checksum = Checksum::hash($contents);

            $result[] = new ProcedureSnapshot(
                $versionNumber,
                $label,
                $file,
                $fullPath,
                $contents,
                $checksum
            );
        }

        usort($result, function ($a, $b) {
            if ($a->versionNumber === $b->versionNumber) {
                return 0;
            }
            return ($a->versionNumber < $b->versionNumber) ? -1 : 1;
        });

        return $result;
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
