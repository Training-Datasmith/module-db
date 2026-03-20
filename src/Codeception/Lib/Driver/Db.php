<?php

declare (strict_types=1);
namespace Codeception\Lib\Driver;

use Codeception\Exception\Module_Exception;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
class Db
{
    protected ?PDO $dbh = null;
    protected string $dsn;
    protected string $user;
    protected string $password;
    /**
     * @see https://www.php.net/manual/de/pdo.construct.php
     */
    protected ?array $options = [];
    /**
     * Associative array with table name => primary-key
     */
    protected array $primary_keys = [];
    public static function connect(string $dsn, ?string $user = null, ?string $password = null, ?array $options = null): PDO
    {
        $dbh = new PDO($dsn, $user, $password, $options);
        $dbh->set_attribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $dbh;
    }
    /**
     * @static
     *
     * @see https://www.php.net/manual/en/pdo.construct.php
     * @see https://www.php.net/manual/de/ref.pdo-mysql.php#pdo-mysql.constants
     */
    public static function create(string $dsn, ?string $user = null, ?string $password = null, ?array $options = null): Db
    {
        $provider = self::get_provider($dsn);
        return match ($provider) {
            'sqlite' => new Sqlite($dsn, $user, $password, $options),
            'mysql' => new My_Sql($dsn, $user, $password, $options),
            'pgsql' => new Postgre_Sql($dsn, $user, $password, $options),
            'mssql', 'dblib', 'sqlsrv' => new Sql_Srv($dsn, $user, $password, $options),
            'oci' => new Oci($dsn, $user, $password, $options),
            default => new Db($dsn, $user, $password, $options),
        };
    }
    public static function get_provider($dsn): string
    {
        return substr($dsn, 0, strpos($dsn, ':'));
    }
    /**
     * @see https://www.php.net/manual/en/pdo.construct.php
     * @see https://www.php.net/manual/de/ref.pdo-mysql.php#pdo-mysql.constants
     */
    public function __construct(string $dsn, ?string $user = null, ?string $password = null, ?array $options = null)
    {
        $this->dbh = new PDO($dsn, $user, $password, $options);
        $this->dbh->set_attribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dsn = $dsn;
        $this->user = $user;
        $this->password = $password;
        $this->options = $options;
    }
    public function __destruct()
    {
        if ($this->dbh !== null && $this->dbh->in_transaction()) {
            $this->dbh->roll_back();
        }
        $this->dbh = null;
    }
    public function get_dbh(): PDO
    {
        return $this->dbh;
    }
    public function get_db(): false|string
    {
        $matches = [];
        $matched = preg_match('#dbname=(\w+)#s', $this->dsn, $matches);
        if (!$matched) {
            return false;
        }
        return $matches[1];
    }
    public function cleanup(): void
    {
    }
    /**
     * Set the lock waiting interval for the database session
     */
    public function set_wait_lock(int $seconds): void
    {
    }
    /**
     * @param string[] $sql
     */
    public function load(array $sql): void
    {
        $query = '';
        $delimiter = ';';
        $delimiter_length = 1;
        foreach ($sql as $single_sql) {
            if (preg_match('#DELIMITER ([\;\$\|\\\\]+)#i', $single_sql, $match)) {
                $delimiter = $match[1];
                $delimiter_length = strlen($delimiter);
                continue;
            }
            $parsed = $this->sql_line($single_sql);
            if ($parsed) {
                continue;
            }
            $query .= "\n" . rtrim($single_sql);
            if (substr($query, -1 * $delimiter_length, $delimiter_length) == $delimiter) {
                $this->sql_query(substr($query, 0, -1 * $delimiter_length));
                $query = '';
            }
        }
        if ($query !== '') {
            $this->sql_query($query);
        }
    }
    public function insert(string $table_name, array &$data): string
    {
        $columns = array_map(fn(int|string $name): string => $this->get_quoted_name($name), array_keys($data));
        return sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->get_quoted_name($table_name), implode(', ', $columns), implode(', ', array_fill(0, count($data), '?')));
    }
    public function select(string $column, string $table_name, array &$criteria): string
    {
        $where = $this->generate_where_clause($criteria);
        $query = 'SELECT %s FROM %s %s';
        return sprintf($query, $column, $this->get_quoted_name($table_name), $where);
    }
    /**
     * @return string[]
     */
    private function get_supported_operators(): array
    {
        return ['like', '!=', '<=', '>=', '<', '>'];
    }
    protected function generate_where_clause(array &$criteria): string
    {
        if (empty($criteria)) {
            return '';
        }
        $operands = $this->get_supported_operators();
        $params = [];
        foreach ($criteria as $k => $v) {
            if ($v === null) {
                if (strpos($k, ' !=') > 0) {
                    $params[] = $this->get_quoted_name(str_replace(' !=', '', $k)) . ' IS NOT NULL ';
                } else {
                    $params[] = $this->get_quoted_name($k) . ' IS NULL ';
                }
                unset($criteria[$k]);
                continue;
            }
            $has_operand = false;
            // search for equals - no additional operand given
            foreach ($operands as $operand) {
                if (!stripos($k, " {$operand}") > 0) {
                    continue;
                }
                $has_operand = true;
                $k = str_ireplace(" {$operand}", '', $k);
                $operand = strtoupper($operand);
                $params[] = $this->get_quoted_name($k) . " {$operand} ? ";
                break;
            }
            if (!$has_operand) {
                $params[] = $this->get_quoted_name($k) . ' = ? ';
            }
        }
        return 'WHERE ' . implode('AND ', $params);
    }
    public function delete_query_by_criteria(string $table_name, array $criteria): void
    {
        $where = $this->generate_where_clause($criteria);
        $query = 'DELETE FROM ' . $this->get_quoted_name($table_name) . ' ' . $where;
        $this->execute_query($query, array_values($criteria));
    }
    public function last_insert_id(string $table_name): string
    {
        return (string) $this->get_dbh()->last_insert_id();
    }
    public function get_quoted_name(string $name): string
    {
        return '"' . str_replace('.', '"."', $name) . '"';
    }
    protected function sql_line(string $sql): bool
    {
        $sql = trim($sql);
        return $sql === '' || $sql === ';' || preg_match('#^((--.*?)|(\#))#s', $sql);
    }
    protected function sql_query(string $query): void
    {
        try {
            $this->dbh->exec($query);
        } catch (PDOException $exception) {
            throw new Module_Exception(\Codeception\Module\Db::class, $exception->get_message() . "\nSQL query being executed: " . $query);
        }
    }
    public function execute_query($query, array $params): PDOStatement
    {
        $pdo_statement = $this->dbh->prepare($query);
        if (!$pdo_statement) {
            throw new Exception("Query '{$query}' can't be prepared.");
        }
        $i = 0;
        foreach ($params as $param) {
            ++$i;
            if (is_null($param)) {
                $type = PDO::PARAM_NULL;
            } elseif (is_bool($param)) {
                $type = PDO::PARAM_BOOL;
            } elseif (is_int($param)) {
                $type = PDO::PARAM_INT;
            } elseif (is_string($param) && $this->is_binary($param)) {
                $type = PDO::PARAM_LOB;
            } else {
                $type = PDO::PARAM_STR;
            }
            $pdo_statement->bind_value($i, $param, $type);
        }
        $pdo_statement->execute();
        return $pdo_statement;
    }
    /**
     * @return string[]
     */
    public function get_primary_key(string $table_name): array
    {
        return [];
    }
    protected function flush_primary_column_cache(): bool
    {
        $this->primary_keys = [];
        return empty($this->primary_keys);
    }
    public function update(string $table_name, array $data, array $criteria): string
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Query update can't be prepared without data.");
        }
        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = $this->get_quoted_name($column) . ' = ?';
        }
        $where = $this->generate_where_clause($criteria);
        return sprintf('UPDATE %s SET %s %s', $this->get_quoted_name($table_name), implode(', ', $set), $where);
    }
    public function get_options(): array
    {
        return $this->options;
    }
    protected function is_binary(string $string): bool
    {
        return false === mb_detect_encoding($string, null, true);
    }
}