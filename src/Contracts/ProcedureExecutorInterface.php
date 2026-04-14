<?php

namespace Alncri2\LaravelProcedure\Contracts;

interface ProcedureExecutorInterface
{
    /**
     * Executa o SQL de uma procedure. Deve retornar um array com:
     *   - status: 'success' | 'failed'
     *   - execution_time_ms: int|null
     *   - error_message: string|null
     *
     * @param string $sql
     * @return array
     */
    public function execute($sql);

    /**
     * Normaliza o SQL conforme o driver atual.
     *
     * @param string $sql
     * @return string
     */
    public function normalize($sql);

    /**
     * Retorna um template inicial de procedure para o driver atual.
     *
     * @param string $procedureName
     * @return string
     */
    public function makeTemplate($procedureName);

    /**
     * @return string
     */
    public function driver();
}
