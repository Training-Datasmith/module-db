<?php

declare (strict_types=1);
namespace Codeception\Lib\Driver;

use PDO;
class Sql_Srv extends Db
{
    public function get_db(): false|string
    {
        $matches = [];
        $matched = preg_match('#Database=(.*);?#s', $this->dsn, $matches);
        if (!$matched) {
            return false;
        }
        return $matches[1];
    }
    public function cleanup(): void
    {
        $this->dbh->exec("\n            DECLARE constraints_cursor CURSOR FOR SELECT name, parent_object_id FROM sys.foreign_keys;\n            OPEN constraints_cursor\n            DECLARE @constraint sysname;\n            DECLARE @parent int;\n            DECLARE @table nvarchar(128);\n            FETCH NEXT FROM constraints_cursor INTO @constraint, @parent;\n            WHILE (@@FETCH_STATUS <> -1)\n            BEGIN\n                SET @table = OBJECT_NAME(@parent)\n                EXEC ('ALTER TABLE [' + @table + '] DROP CONSTRAINT [' + @constraint + ']')\n                FETCH NEXT FROM constraints_cursor INTO @constraint, @parent;\n            END\n            DEALLOCATE constraints_cursor;");
        $this->dbh->exec("\n            DECLARE tables_cursor CURSOR FOR SELECT name FROM sysobjects WHERE type = 'U';\n            OPEN tables_cursor DECLARE @tablename sysname;\n            FETCH NEXT FROM tables_cursor INTO @tablename;\n            WHILE (@@FETCH_STATUS <> -1)\n            BEGIN\n                EXEC ('DROP TABLE [' + @tablename + ']')\n                FETCH NEXT FROM tables_cursor INTO @tablename;\n            END\n            DEALLOCATE tables_cursor;");
    }
    public function get_quoted_name(string $name): string
    {
        return '[' . str_replace('.', '].[', $name) . ']';
    }
    /**
     * @return string[]
     */
    public function get_primary_key(string $table_name): array
    {
        if (!isset($this->primary_keys[$table_name])) {
            $primary_key = [];
            $query = "\n                SELECT Col.Column_Name from\n                    INFORMATION_SCHEMA.TABLE_CONSTRAINTS Tab,\n                    INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE Col\n                WHERE\n                    Col.Constraint_Name = Tab.Constraint_Name\n                    AND Col.Table_Name = Tab.Table_Name\n                    AND Constraint_Type = 'PRIMARY KEY' AND Col.Table_Name = ?";
            $stmt = $this->execute_query($query, [$table_name]);
            $columns = $stmt->fetch_all(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                $primary_key[] = $column['Column_Name'];
            }
            $this->primary_keys[$table_name] = $primary_key;
        }
        return $this->primary_keys[$table_name];
    }
}