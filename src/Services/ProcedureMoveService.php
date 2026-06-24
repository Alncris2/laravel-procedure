<?php

namespace Alncris2\LaravelProcedure\Services;

use Alncris2\LaravelProcedure\Repositories\ProcedureVersionRepository;
use RuntimeException;

class ProcedureMoveService
{
    const RESULT_MOVED   = 'moved';
    const RESULT_SKIPPED = 'skipped';
    const RESULT_FAILED  = 'failed';

    /** @var ProcedureScanner */
    protected $scanner;

    /** @var ProcedureVersionRepository */
    protected $repository;

    public function __construct(ProcedureScanner $scanner, ProcedureVersionRepository $repository)
    {
        $this->scanner    = $scanner;
        $this->repository = $repository;
    }

    /**
     * Move uma lista de procedures para um novo grupo.
     *
     * @param string[] $procedureNames
     * @param string   $targetGroup
     * @return array
     */
    public function moveMany(array $procedureNames, $targetGroup)
    {
        $results = array();
        foreach ($procedureNames as $name) {
            $results[] = $this->moveOne((string) $name, $targetGroup);
        }
        return $results;
    }

    /**
     * @param string $procedureName
     * @param string $targetGroup
     * @return array
     */
    public function moveOne($procedureName, $targetGroup)
    {
        $def = $this->scanner->findByName($procedureName);

        if ($def === null) {
            return array(
                'procedure' => $procedureName,
                'from' => '',
                'to' => $targetGroup,
                'result' => self::RESULT_FAILED,
                'reason' => 'procedure não encontrada no código',
            );
        }

        if ($def->group === $targetGroup) {
            return array(
                'procedure' => $procedureName,
                'from' => $def->group,
                'to' => $targetGroup,
                'result' => self::RESULT_SKIPPED,
                'reason' => 'já está no grupo alvo',
            );
        }

        $targetDef = $this->scanner->buildDefinition($targetGroup, $procedureName);

        if (is_dir($targetDef->basePath)) {
            return array(
                'procedure' => $procedureName,
                'from' => $def->group,
                'to' => $targetGroup,
                'result' => self::RESULT_FAILED,
                'reason' => 'já existe um diretório em ' . $targetDef->basePath,
            );
        }

        try {
            $this->ensureGroupDir($targetGroup);
            $this->moveDir($def->basePath, $targetDef->basePath);
            $this->repository->moveGroup($def->group, $procedureName, $targetGroup);
        } catch (\Exception $e) {
            return array(
                'procedure' => $procedureName,
                'from' => $def->group,
                'to' => $targetGroup,
                'result' => self::RESULT_FAILED,
                'reason' => $e->getMessage(),
            );
        }

        return array(
            'procedure' => $procedureName,
            'from' => $def->group,
            'to' => $targetGroup,
            'result' => self::RESULT_MOVED,
            'reason' => '',
        );
    }

    /**
     * Cria o diretório do grupo de destino se ainda não existir.
     *
     * @param string $group
     * @return void
     */
    protected function ensureGroupDir($group)
    {
        $basePath = config('procedure.base_path');
        $groupPath = $basePath . DIRECTORY_SEPARATOR . $group;
        if (!is_dir($groupPath)) {
            if (!@mkdir($groupPath, 0775, true) && !is_dir($groupPath)) {
                throw new RuntimeException('Falha ao criar diretório do grupo: ' . $groupPath);
            }
        }
    }

    /**
     * @param string $from
     * @param string $to
     * @return void
     */
    protected function moveDir($from, $to)
    {
        if (!rename($from, $to)) {
            throw new RuntimeException(
                'Falha ao mover diretório: ' . $from . ' → ' . $to
            );
        }
    }
}
