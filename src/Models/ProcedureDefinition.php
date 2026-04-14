<?php

namespace Alncris2\LaravelProcedure\Models;

class ProcedureDefinition
{
    public $group;
    public $name;
    public $basePath;
    public $currentPath;
    public $versionsPath;
    public $snapshots = array();

    public function __construct($group, $name, $basePath, $currentPath, $versionsPath, array $snapshots = array())
    {
        $this->group = $group;
        $this->name = $name;
        $this->basePath = $basePath;
        $this->currentPath = $currentPath;
        $this->versionsPath = $versionsPath;
        $this->snapshots = $snapshots;
    }

    public function hasCurrent()
    {
        return is_file($this->currentPath);
    }

    public function readCurrent()
    {
        if (!$this->hasCurrent()) {
            return '';
        }
        return file_get_contents($this->currentPath);
    }

    /**
     * @return ProcedureSnapshot|null
     */
    public function latestSnapshot()
    {
        if (empty($this->snapshots)) {
            return null;
        }
        $last = null;
        foreach ($this->snapshots as $snap) {
            if ($last === null || $snap->versionNumber > $last->versionNumber) {
                $last = $snap;
            }
        }
        return $last;
    }
}
