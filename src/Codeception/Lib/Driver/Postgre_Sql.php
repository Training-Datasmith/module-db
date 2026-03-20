<?php

declare (strict_types=1);
namespace Codeception\Lib\Driver;

use Codeception\Exception\Module_Exception;
use PDO;
use PDOException;
class Postgre_Sql extends Db
{
    protected bool $putline = false;
    /**
     * @var null|resource|bool
     */
    protected $connection;
    /**
     * @var mixed|null
     */
    protected $search_path;
    /**
     * Loads a SQL file.
     *
     * @param string[] $sql sql file
     */
    public function load(array $sql): void
    {
        $query = '';
        $delimiter = ';';
        $delimiter_length = 1;
        $dollars_open = false;
        foreach ($sql as $single_sql) {
            if (preg_match('#DELIMITER ([\;\$\|\\\\]+)#i', $single_sql, $match)) {
                $delimiter = $match[1];
                $delimiter_length = strlen($delimiter);
                continue;
            }
            $parsed = trim($query) == '' && $this->sql_line($single_sql);
            if ($parsed) {
                continue;
            }
            // Ignore $$ inside SQL standard string syntax such as in INSERT statements.
            if (!preg_match('#\'.*\$\$.*\'#', $single_sql)) {
                $pos = strpos($single_sql, '$$');
                if ($pos !== false && $pos >= 0) {
                    $dollars_open = !$dollars_open;
                }
            }
            if (preg_match('#SET search_path = .*#i', $single_sql, $match)) {
                $this->search_path = $match[0];
            }
            $query .= "\n" . rtrim($single_sql);
            if (!$dollars_open && substr($query, -1 * $delimiter_length, $delimiter_length) == $delimiter) {
                $this->sql_query(substr($query, 0, -1 * $delimiter_length));
                $query = '';
            }
        }
        if ($query !== '') {
            $this->sql_query($query);
        }
    }
    public function cleanup(): void
    {
        $this->dbh->exec('DROP SCHEMA IF EXISTS public CASCADE;');
        $this->dbh->exec('CREATE SCHEMA public;');
    }
    public function sql_line(string $sql): bool
    {
        if (!$this->putline) {
            return parent::sql_line($sql);
        }
        if ($sql == '\.') {
            $this->putline = false;
            pg_put_line($this->connection, $sql . "\n");
            pg_end_copy($this->connection);
            pg_close($this->connection);
        } else {
            pg_put_line($this->connection, $sql . "\n");
        }
        return true;
    }
    public function sql_query(string $query): void
    {
        if (str_starts_with(trim($query), 'COPY ')) {
            if (!extension_loaded('pgsql')) {
                throw new Module_Exception(\Codeception\Module\Db::class, "To run 'COPY' commands 'pgsql' extension should be installed");
            }
            $str_conn = str_replace(';', ' ', substr($this->dsn, 6));
            $str_conn .= ' user=' . $this->user;
            $str_conn .= ' password=' . $this->password;
            $this->connection = pg_connect($str_conn);
            if ($this->search_path !== null) {
                pg_query($this->connection, $this->search_path);
            }
            pg_query($this->connection, $query);
            $this->putline = true;
        } else {
            $this->dbh->exec($query);
        }
    }
    /**
     * Get the last inserted ID of table.
     */
    public function last_insert_id(string $table_name): string
    {
        /**
         * We make an assumption that the sequence name for this table
         * is based on how postgres names sequences for SERIAL columns
         */
        $sequence_name = $this->get_quoted_name($table_name . '_id_seq');
        $last_sequence = null;
        try {
            $last_sequence = $this->get_dbh()->last_insert_id($sequence_name);
        } catch (PDOException) {
            // in this case, the sequence name might be combined with the primary key name
        }
        // here we check if for instance, it's something like table_primary_key_seq instead of table_id_seq
        // this could occur when you use some kind of import tool like pgloader
        if (!$last_sequence) {
            $primary_keys = $this->get_primary_key($table_name);
            $pk_name = array_shift($primary_keys);
            $last_sequence = $this->get_dbh()->last_insert_id($this->get_quoted_name($table_name . '_' . $pk_name . '_seq'));
        }
        return $last_sequence;
    }
    /**
     * Returns the primary key(s) of the table, based on:
     * https://wiki.postgresql.org/wiki/Retrieve_primary_key_columns.
     *
     * @return string[]
     */
    public function get_primary_key(string $table_name): array
    {
        if (!isset($this->primary_keys[$table_name])) {
            $primary_key = [];
            $query = "SELECT a.attname\n                FROM   pg_index i\n                JOIN   pg_attribute a ON a.attrelid = i.indrelid\n                                     AND a.attnum = ANY(i.indkey)\n                WHERE  i.indrelid = '" . $this->get_quoted_name($table_name) . "'::regclass\n                AND    i.indisprimary";
            $stmt = $this->execute_query($query, []);
            $columns = $stmt->fetch_all(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                $primary_key[] = $column['attname'];
            }
            $this->primary_keys[$table_name] = $primary_key;
        }
        return $this->primary_keys[$table_name];
    }
}