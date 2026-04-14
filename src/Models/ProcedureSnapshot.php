<?php

namespace Alncris2\LaravelProcedure\Models;

class ProcedureSnapshot
{
    public $versionNumber;
    public $label;
    public $fileName;
    public $fullPath;
    public $contents;
    public $checksum;

    public function __construct($versionNumber, $label, $fileName, $fullPath, $contents, $checksum)
    {
        $this->versionNumber = $versionNumber;
        $this->label = $label;
        $this->fileName = $fileName;
        $this->fullPath = $fullPath;
        $this->contents = $contents;
        $this->checksum = $checksum;
    }
}
