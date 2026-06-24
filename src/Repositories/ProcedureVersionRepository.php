<?php

namespace Alncris2\LaravelProcedure\Repositories;

use Alncris2\LaravelProcedure\Support\PathHelper;
use Illuminate\Support\Facades\DB;

class ProcedureVersionRepository
{
    /**
     * @return string
     */
    protected function table()
    {
        return config('procedure.history_table', 'procedure_versions');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    protected function query()
    {
        return DB::table($this->table());
    }

    /**
     * @param string $group
     * @param string $procedureName
     * @return object|null
     */
    public function getCurrentApplied($group, $procedureName)
    {
        return $this->query()
            ->where('group_name', $group)
            ->where('procedure_name', $procedureName)
            ->where('is_current', true)
            ->first();
    }

    /**
     * @param string $group
     * @param string $procedureName
     * @return object|null
     */
    public function getLatestVersion($group, $procedureName)
    {
        return $this->query()
            ->where('group_name', $group)
            ->where('procedure_name', $procedureName)
            ->orderBy('version_number', 'desc')
            ->first();
    }

    /**
     * @param string   $group
     * @param string   $procedureName
     * @param int      $versionNumber
     * @return object|null
     */
    public function findVersion($group, $procedureName, $versionNumber)
    {
        return $this->query()
            ->where('group_name', $group)
            ->where('procedure_name', $procedureName)
            ->where('version_number', $versionNumber)
            ->first();
    }

    /**
     * Próximo version_number considerando o histórico no banco (inclui
     * linhas sem arquivo físico em versions/, como as de importação baseline).
     *
     * @param string $group
     * @param string $procedureName
     * @return int
     */
    public function getNextVersionNumber($group, $procedureName)
    {
        $max = $this->query()
            ->where('group_name', $group)
            ->where('procedure_name', $procedureName)
            ->max('version_number');
        return $max ? ((int) $max) + 1 : 1;
    }

    /**
     * Grava o registro de uma aplicação (success ou failed).
     *
     * @param array $data
     * @return int inserted id
     */
    public function storeAppliedVersion(array $data)
    {
        $now = date('Y-m-d H:i:s');
        $row = array(
            'group_name' => $data['group_name'],
            'procedure_name' => $data['procedure_name'],
            'version_number' => $data['version_number'],
            'version_label' => isset($data['version_label']) ? $data['version_label'] : null,
            'file_name' => $data['file_name'],
            'file_path' => PathHelper::relativize($data['file_path'], config('procedure.base_path')),
            'checksum' => $data['checksum'],
            'execution_status' => $data['execution_status'],
            'execution_time_ms' => isset($data['execution_time_ms']) ? $data['execution_time_ms'] : null,
            'error_message' => isset($data['error_message']) ? $data['error_message'] : null,
            'applied_at' => isset($data['applied_at']) ? $data['applied_at'] : $now,
            'rolled_back_at' => null,
            'is_current' => false,
            'created_at' => $now,
            'updated_at' => $now,
        );

        return $this->query()->insertGetId($row);
    }

    /**
     * Marca apenas uma versão como current para uma procedure.
     *
     * @param string $group
     * @param string $procedureName
     * @param int    $id
     * @return void
     */
    public function markCurrent($group, $procedureName, $id)
    {
        $now = date('Y-m-d H:i:s');
        $this->query()
            ->where('group_name', $group)
            ->where('procedure_name', $procedureName)
            ->where('is_current', true)
            ->update(array('is_current' => false, 'updated_at' => $now));

        $this->query()
            ->where('id', $id)
            ->update(array('is_current' => true, 'updated_at' => $now));
    }

    /**
     * @param int $id
     * @return void
     */
    public function markRolledBack($id)
    {
        $now = date('Y-m-d H:i:s');
        $this->query()
            ->where('id', $id)
            ->update(array(
                'rolled_back_at' => $now,
                'is_current' => false,
                'updated_at' => $now,
            ));
    }

    /**
     * @param string $group
     * @param string $procedureName
     * @return \Illuminate\Support\Collection
     */
    public function getHistory($group, $procedureName)
    {
        return $this->query()
            ->where('group_name', $group)
            ->where('procedure_name', $procedureName)
            ->orderBy('version_number', 'desc')
            ->get();
    }

    /**
     * Atualiza group_name e corrige o prefixo de group no file_path de todas
     * as versões de uma procedure ao mover de grupo.
     *
     * @param string $oldGroup
     * @param string $procedureName
     * @param string $newGroup
     * @return void
     */
    public function moveGroup($oldGroup, $procedureName, $newGroup)
    {
        $now = date('Y-m-d H:i:s');
        $rows = $this->query()
            ->where('group_name', $oldGroup)
            ->where('procedure_name', $procedureName)
            ->get();

        $oldPrefix = $oldGroup . '/' . $procedureName . '/';
        $newPrefix = $newGroup . '/' . $procedureName . '/';

        foreach ($rows as $row) {
            $filePath = $row->file_path;
            if (strpos($filePath, $oldPrefix) === 0) {
                $filePath = $newPrefix . substr($filePath, strlen($oldPrefix));
            }
            $this->query()->where('id', $row->id)->update(array(
                'group_name' => $newGroup,
                'file_path' => $filePath,
                'updated_at' => $now,
            ));
        }
    }
}
