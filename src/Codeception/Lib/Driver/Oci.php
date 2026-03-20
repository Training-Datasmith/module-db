<?php

declare (strict_types=1);
namespace Codeception\Lib\Driver;

class Oci extends Db
{
    public function set_wait_lock(int $seconds): void
    {
        $this->dbh->exec('ALTER SESSION SET ddl_lock_timeout = ' . $seconds);
    }
    public function cleanup(): void
    {
        $this->dbh->exec("BEGIN\n                        FOR i IN (SELECT trigger_name FROM user_triggers)\n                          LOOP\n                            EXECUTE IMMEDIATE('DROP TRIGGER ' || user || '.\"' || i.trigger_name || '\"');\n                          END LOOP;\n                      END;");
        $this->dbh->exec("BEGIN\n                        FOR i IN (SELECT table_name FROM user_tables)\n                          LOOP\n                            EXECUTE IMMEDIATE('DROP TABLE ' || user || '.\"' || i.table_name || '\" CASCADE CONSTRAINTS');\n                          END LOOP;\n                      END;");
        $this->dbh->exec("BEGIN\n                        FOR i IN (SELECT sequence_name FROM user_sequences)\n                          LOOP\n                            EXECUTE IMMEDIATE('DROP SEQUENCE ' || user || '.\"' || i.sequence_name || '\"');\n                          END LOOP;\n                      END;");
        $this->dbh->exec("BEGIN\n                        FOR i IN (SELECT view_name FROM user_views)\n                          LOOP\n                            EXECUTE IMMEDIATE('DROP VIEW ' || user || '.\"' || i.view_name || '\"');\n                          END LOOP;\n                      END;");
    }
    /**
     * SQL commands should ends with `//` in the dump file
     * IF you want to load triggers too.
     * IF you do not want to load triggers you can use the `;` characters
     * but in this case you need to change the $delimiter from `//` to `;`
     *
     * @param string[] $sql
     */
    public function load(array $sql): void
    {
        $query = '';
        $delimiter = '//';
        $delimiter_length = 2;
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
    /**
     * @return string[]
     */
    public function get_primary_key(string $table_name): array
    {
        if (!isset($this->primary_keys[$table_name])) {
            $primary_key = [];
            $query = "SELECT cols.column_name\n                FROM all_constraints cons, all_cons_columns cols\n                WHERE cols.table_name = ?\n                AND cons.constraint_type = 'P'\n                AND cons.constraint_name = cols.constraint_name\n                AND cons.owner = cols.owner\n                ORDER BY cols.table_name, cols.position";
            $stmt = $this->execute_query($query, [$table_name]);
            $columns = $stmt->fetch_all(\PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                $primary_key[] = $column['COLUMN_NAME'];
            }
            $this->primary_keys[$table_name] = $primary_key;
        }
        return $this->primary_keys[$table_name];
    }
}