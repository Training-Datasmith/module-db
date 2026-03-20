<?php

declare (strict_types=1);
namespace Codeception\Lib\Driver;

use Codeception\Configuration;
use Codeception\Exception\Module_Exception;
use PDO;
class Sqlite extends Db
{
    protected bool $has_snapshot = false;
    protected string $filename = '';
    public function __construct(string $dsn, ?string $user = null, ?string $password = null, ?array $options = null)
    {
        $filename = substr($dsn, 7);
        if ($filename === ':memory:') {
            throw new Module_Exception(self::class, ':memory: database is not supported');
        }
        $this->filename = Configuration::project_dir() . $filename;
        $this->dsn = 'sqlite:' . $this->filename;
        parent::__construct($this->dsn, $user, $password, $options);
    }
    public function cleanup(): void
    {
        $this->dbh = null;
        gc_collect_cycles();
        file_put_contents($this->filename, '');
        $this->dbh = self::connect($this->dsn, $this->user, $this->password);
    }
    /**
     * @param string[] $sql
     */
    public function load(array $sql): void
    {
        if ($this->has_snapshot) {
            $this->dbh = null;
            copy($this->filename . '_snapshot', $this->filename);
            $this->dbh = new PDO($this->dsn, $this->user, $this->password);
        } else {
            if (file_exists($this->filename . '_snapshot')) {
                unlink($this->filename . '_snapshot');
            }
            parent::load($sql);
            copy($this->filename, $this->filename . '_snapshot');
            $this->has_snapshot = true;
        }
    }
    /**
     * @return string[]
     */
    public function get_primary_key(string $table_name): array
    {
        if (!isset($this->primary_keys[$table_name])) {
            if ($this->has_row_id($table_name)) {
                return $this->primary_keys[$table_name] = ['_ROWID_'];
            }
            $primary_key = [];
            $query = 'PRAGMA table_info(' . $this->get_quoted_name($table_name) . ')';
            $stmt = $this->execute_query($query, []);
            $columns = $stmt->fetch_all(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if ($column['pk'] !== '0' && $column['pk'] !== 0) {
                    $primary_key[] = $column['name'];
                }
            }
            $this->primary_keys[$table_name] = $primary_key;
        }
        return $this->primary_keys[$table_name];
    }
    private function has_row_id(string $table_name): bool
    {
        $params = ['type' => 'table', 'name' => $table_name];
        $select = $this->select('sql', 'sqlite_master', $params);
        $result = $this->execute_query($select, $params);
        $sql = $result->fetch_column();
        return !str_contains($sql, ') WITHOUT ROWID');
    }
}