<?php

namespace Alncris2\LaravelProcedure\Services;

/**
 * Infere o grupo de destino para cada procedure a partir de heurísticas
 * determinísticas (prefixo de nome, tabelas referenciadas, schema/owner).
 *
 * O resolver é puro: não faz IO, recebe os dados já carregados pelo caller.
 */
class AutoGroupResolver
{
    const STRATEGY_CASCADE = 'cascade';
    const STRATEGY_PREFIX = 'prefix';
    const STRATEGY_TABLES = 'tables';
    const STRATEGY_SCHEMA = 'schema';

    /** @var int */
    protected $minClusterSize;

    /** @var string */
    protected $prefixSeparator;

    /** @var string[] lowercase */
    protected $noisePrefixes;

    /** @var string[] lowercase */
    protected $noiseTables;

    /** @var string */
    protected $fallback;

    /**
     * @param array $config {
     *     @var int      $min_cluster_size  Mínimo de procedures num grupo para o prefixo valer (default 2)
     *     @var string   $prefix_separator  Separador de tokens no nome (default '_')
     *     @var string[] $noise_prefixes    Prefixos de tipo a ignorar (sp, usp, prc, ...)
     *     @var string[] $noise_tables      Tabelas a ignorar no clustering (dual, ...)
     *     @var string   $fallback          Nome do grupo quando nada funciona (default 'ungrouped')
     * }
     */
    public function __construct(array $config = array())
    {
        $this->minClusterSize = isset($config['min_cluster_size']) ? (int) $config['min_cluster_size'] : 2;
        $this->prefixSeparator = isset($config['prefix_separator']) ? (string) $config['prefix_separator'] : '_';

        $noisePrefixes = isset($config['noise_prefixes'])
            ? $config['noise_prefixes']
            : array('sp', 'usp', 'prc', 'proc', 'fn', 'fnc', 'p');
        $this->noisePrefixes = array_map('strtolower', $noisePrefixes);

        $noiseTables = isset($config['noise_tables'])
            ? $config['noise_tables']
            : array('dual');
        $this->noiseTables = array_map('strtolower', $noiseTables);

        $this->fallback = isset($config['fallback']) ? (string) $config['fallback'] : 'ungrouped';
    }

    /**
     * Resolve grupos para o lote de procedures.
     *
     * @param array  $procedures Lista de arrays com chaves 'name', 'source', 'owner' (owner opcional)
     * @param string $strategy   cascade|prefix|tables|schema
     * @return array<string, array> Mapa nome => ['group' => string, 'strategy' => string, 'tables' => array]
     */
    public function resolve(array $procedures, $strategy = self::STRATEGY_CASCADE)
    {
        $result = array();
        foreach ($procedures as $proc) {
            $name = isset($proc['name']) ? (string) $proc['name'] : '';
            $result[$name] = array(
                'group' => null,
                'strategy' => null,
                'tables' => array(),
            );
        }

        if ($strategy === self::STRATEGY_PREFIX || $strategy === self::STRATEGY_CASCADE) {
            $this->applyPrefix($procedures, $result);
        }

        if ($strategy === self::STRATEGY_TABLES || $strategy === self::STRATEGY_CASCADE) {
            $this->applyTables($procedures, $result);
        }

        if ($strategy === self::STRATEGY_SCHEMA || $strategy === self::STRATEGY_CASCADE) {
            $this->applySchema($procedures, $result);
        }

        // Último recurso
        foreach ($result as $name => $info) {
            if ($info['group'] === null) {
                $result[$name]['group'] = $this->fallback;
                $result[$name]['strategy'] = 'fallback';
            }
        }

        return $result;
    }

    /**
     * Preenche o grupo pela convenção de prefixo.
     */
    protected function applyPrefix(array $procedures, array &$result)
    {
        // Conta ocorrências de cada candidato a prefixo.
        $counts = array();
        $candidateByProc = array();

        foreach ($procedures as $proc) {
            $name = isset($proc['name']) ? (string) $proc['name'] : '';
            $candidate = $this->extractPrefixCandidate($name);
            $candidateByProc[$name] = $candidate;
            if ($candidate !== null) {
                if (!isset($counts[$candidate])) {
                    $counts[$candidate] = 0;
                }
                $counts[$candidate]++;
            }
        }

        foreach ($candidateByProc as $name => $candidate) {
            if ($candidate === null) {
                continue;
            }
            if (!isset($result[$name]) || $result[$name]['group'] !== null) {
                continue;
            }
            if ($counts[$candidate] >= $this->minClusterSize) {
                $result[$name]['group'] = strtolower($candidate);
                $result[$name]['strategy'] = self::STRATEGY_PREFIX;
            }
        }
    }

    /**
     * Extrai o token útil do nome (pula prefixos de "tipo" como SP_, PRC_).
     *
     * @param string $name
     * @return string|null
     */
    protected function extractPrefixCandidate($name)
    {
        if ($name === '') {
            return null;
        }

        // Normaliza camelCase em tokens: "UpdateCustomer" -> "Update_Customer"
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $parts = explode($this->prefixSeparator, $normalized);
        $parts = array_values(array_filter($parts, function ($p) { return $p !== ''; }));

        if (empty($parts)) {
            return null;
        }

        $first = $parts[0];
        if (in_array(strtolower($first), $this->noisePrefixes, true) && count($parts) >= 2) {
            return $parts[1];
        }
        return $first;
    }

    /**
     * Preenche o grupo pela análise de tabelas referenciadas no SQL.
     * Procedures que compartilham tabelas são unidas via union-find; o grupo
     * é nomeado pela tabela mais frequente no componente.
     */
    protected function applyTables(array $procedures, array &$result)
    {
        $tablesByProc = array();
        foreach ($procedures as $proc) {
            $name = isset($proc['name']) ? (string) $proc['name'] : '';
            $source = isset($proc['source']) ? (string) $proc['source'] : '';
            $tables = $this->extractTables($source);
            $tablesByProc[$name] = $tables;
            $result[$name]['tables'] = $tables;
        }

        // Union-find: índice = nome da procedure
        $names = array_keys($tablesByProc);
        $parent = array();
        foreach ($names as $n) {
            $parent[$n] = $n;
        }

        $find = function ($x) use (&$parent, &$find) {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        $union = function ($a, $b) use (&$parent, $find) {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };

        // Invertido: tabela => procedures que a referenciam
        $procsByTable = array();
        foreach ($tablesByProc as $proc => $tables) {
            foreach ($tables as $t) {
                $procsByTable[$t][] = $proc;
            }
        }

        foreach ($procsByTable as $procs) {
            if (count($procs) < 2) {
                continue;
            }
            $first = $procs[0];
            for ($i = 1; $i < count($procs); $i++) {
                $union($first, $procs[$i]);
            }
        }

        // Agrupa por root e nomeia pelo voto majoritário de tabelas
        $components = array();
        foreach ($names as $n) {
            $root = $find($n);
            $components[$root][] = $n;
        }

        foreach ($components as $root => $members) {
            if (count($members) < $this->minClusterSize) {
                continue;
            }
            // Só faz sentido nomear se o componente tem tabelas
            $tableVotes = array();
            foreach ($members as $m) {
                foreach ($tablesByProc[$m] as $t) {
                    if (!isset($tableVotes[$t])) {
                        $tableVotes[$t] = 0;
                    }
                    $tableVotes[$t]++;
                }
            }
            if (empty($tableVotes)) {
                continue;
            }
            arsort($tableVotes);
            reset($tableVotes);
            $groupName = strtolower((string) key($tableVotes));

            foreach ($members as $m) {
                if ($result[$m]['group'] === null) {
                    $result[$m]['group'] = $groupName;
                    $result[$m]['strategy'] = self::STRATEGY_TABLES;
                }
            }
        }
    }

    /**
     * Fallback: usa owner/schema retornado pelo reader.
     */
    protected function applySchema(array $procedures, array &$result)
    {
        foreach ($procedures as $proc) {
            $name = isset($proc['name']) ? (string) $proc['name'] : '';
            $owner = isset($proc['owner']) ? (string) $proc['owner'] : '';
            if ($owner === '') {
                continue;
            }
            if (!isset($result[$name]) || $result[$name]['group'] !== null) {
                continue;
            }
            $result[$name]['group'] = strtolower($owner);
            $result[$name]['strategy'] = self::STRATEGY_SCHEMA;
        }
    }

    /**
     * Extrai identificadores de tabelas do SQL (pós FROM/JOIN/UPDATE/INSERT INTO/DELETE FROM/MERGE INTO).
     *
     * @param string $sql
     * @return string[] Tabelas em lowercase, sem schema, sem duplicatas, sem ruído.
     */
    public function extractTables($sql)
    {
        if ($sql === '') {
            return array();
        }

        // Remove comentários de linha e bloco para reduzir ruído
        $clean = preg_replace('~/\*.*?\*/~s', ' ', $sql);
        $clean = preg_replace('~--[^\n]*~', ' ', $clean);

        $pattern = '/\b(?:FROM|JOIN|UPDATE|INSERT\s+INTO|DELETE\s+FROM|MERGE\s+INTO)\s+([A-Za-z_][A-Za-z0-9_\.]*)/i';
        if (!preg_match_all($pattern, $clean, $matches)) {
            return array();
        }

        $tables = array();
        foreach ($matches[1] as $raw) {
            $t = strtolower($raw);
            // Remove schema prefix: "schema.table" -> "table"
            if (strpos($t, '.') !== false) {
                $parts = explode('.', $t);
                $t = end($parts);
            }
            if ($t === '') {
                continue;
            }
            if (in_array($t, $this->noiseTables, true)) {
                continue;
            }
            // Ignora tabelas temporárias convencionais
            if (strpos($t, 'temp_') === 0 || strpos($t, 'tmp_') === 0) {
                continue;
            }
            $tables[$t] = true;
        }

        return array_keys($tables);
    }
}
