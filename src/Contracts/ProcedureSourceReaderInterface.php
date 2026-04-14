<?php

namespace Alncri2\LaravelProcedure\Contracts;

interface ProcedureSourceReaderInterface
{
    /**
     * Retorna a lista de nomes de procedures existentes no banco.
     *
     * @param array $options Chaves suportadas:
     *                       - owner: string (oracle) filtra por schema/owner
     *                       - only:  string filtra por um nome específico
     * @return string[]
     */
    public function listProcedures(array $options = array());

    /**
     * Retorna o SQL completo (executável) da procedure.
     *
     * Para o conteúdo ser reexecutável pelo executor, este método deve:
     *   - oracle: prefixar "CREATE OR REPLACE " ao conteúdo do USER_SOURCE
     *   - mysql:  retornar o "Create Procedure" do SHOW CREATE PROCEDURE
     *             precedido de DROP PROCEDURE IF EXISTS para idempotência
     *
     * @param string $name
     * @param array  $options
     * @return string
     */
    public function getProcedureSource($name, array $options = array());

    /**
     * @return string
     */
    public function driver();

    /**
     * @return bool
     */
    public function supportsDump();
}
