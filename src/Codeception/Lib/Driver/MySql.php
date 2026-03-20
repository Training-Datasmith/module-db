<?php

declare (strict_types=1);
namespace Codeception\Lib\Driver;

use PDO;
class My_Sql extends Db
{
    public function cleanup(): void
    {
        $this->dbh->exec('SET FOREIGN_KEY_CHECKS=0;');
        $res = $this->dbh->query("SHOW FULL TABLES WHERE TABLE_TYPE LIKE '%TABLE';")->fetch_all();
        foreach ($res as $row) {
            $this->dbh->exec('drop table `' . $row[0] . '`');
        }
        $this->dbh->exec('SET FOREIGN_KEY_CHECKS=1;');
    }
    protected function sql_query(string $query): void
    {
        $this->dbh->exec('SET FOREIGN_KEY_CHECKS=0;');
        parent::sql_query($query);
        $this->dbh->exec('SET FOREIGN_KEY_CHECKS=1;');
    }
    public function get_quoted_name(string $name): string
    {
        return '`' . str_replace('.', '`.`', $name) . '`';
    }
    /**
     * @return string[]
     */
    public function get_primary_key(string $table_name): array
    {
        if (!isset($this->primary_keys[$table_name])) {
            $primary_key = [];
            $stmt = $this->get_dbh()->query('SHOW KEYS FROM ' . $this->get_quoted_name($table_name) . " WHERE Key_name = 'PRIMARY'");
            $columns = $stmt->fetch_all(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                $primary_key[] = $column['Column_name'];
            }
            $this->primary_keys[$table_name] = $primary_key;
        }
        return $this->primary_keys[$table_name];
    }
}