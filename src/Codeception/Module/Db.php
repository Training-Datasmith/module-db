<?php

declare (strict_types=1);
namespace Codeception\Module;

use Codeception\Configuration;
use Codeception\Exception\Module_Config_Exception;
use Codeception\Exception\Module_Exception;
use Codeception\Lib\Db_Populator;
use Codeception\Lib\Driver\Db as Driver;
use Codeception\Lib\Interfaces\Db as DbInterface;
use Codeception\Lib\Notification;
use Codeception\Module;
use Codeception\Test_Interface;
use Codeception\Util\Action_Sequence;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOException;
/**
 * Access a database.
 *
 * The most important function of this module is to clean a database before each test.
 * This module also provides actions to perform checks in a database, e.g. [seeInDatabase()](https://codeception.com/docs/modules/Db#seeInDatabase)
 *
 * In order to have your database populated with data you need a raw SQL dump.
 * Simply put the dump in the `tests/Support/Data` directory (by default) and specify the path in the config.
 * The next time after the database is cleared, all your data will be restored from the dump.
 * Don't forget to include `CREATE TABLE` statements in the dump.
 *
 * Supported and tested databases are:
 *
 * * MySQL
 * * SQLite (i.e. just one file)
 * * PostgreSQL
 *
 * Also available:
 *
 * * MS SQL
 * * Oracle
 *
 * Connection is done by database drivers, which are stored in the `Codeception\Lib\Driver` namespace.
 * Check out the drivers if you run into problems loading dumps and cleaning databases.
 *
 * ## Example `Functional.suite.yml`
 * ```yaml
 * modules:
 *     enabled:
 *         - Db:
 *             dsn: 'mysql:host=localhost;dbname=testdb'
 *             user: 'root'
 *             password: ''
 *             dump: 'tests/Support/Data/dump.sql'
 *             populate: true # whether the dump should be loaded before the test suite is started
 *             cleanup: true # whether the dump should be reloaded before each test
 *             reconnect: true # whether the module should reconnect to the database before each test
 *             waitlock: 10 # wait lock (in seconds) that the database session should use for DDL statements
 *             databases: # include more database configs and switch between them in tests.
 *             skip_cleanup_if_failed: true # Do not perform the cleanup if the tests failed. If this is used, manual cleanup might be required when re-running
 *             ssl_key: '/path/to/client-key.pem' # path to the SSL key (MySQL specific, see https://php.net/manual/de/ref.pdo-mysql.php#pdo.constants.mysql-attr-key)
 *             ssl_cert: '/path/to/client-cert.pem' # path to the SSL certificate (MySQL specific, see https://php.net/manual/de/ref.pdo-mysql.php#pdo.constants.mysql-attr-ssl-cert)
 *             ssl_ca: '/path/to/ca-cert.pem' # path to the SSL certificate authority (MySQL specific, see https://php.net/manual/de/ref.pdo-mysql.php#pdo.constants.mysql-attr-ssl-ca)
 *             ssl_verify_server_cert: false # disables certificate CN verification (MySQL specific, see https://php.net/manual/de/ref.pdo-mysql.php)
 *             ssl_cipher: 'AES256-SHA' # list of one or more permissible ciphers to use for SSL encryption (MySQL specific, see https://php.net/manual/de/ref.pdo-mysql.php#pdo.constants.mysql-attr-cipher)
 *             initial_queries: # list of queries to be executed right after connection to the database has been initiated, i.e. creating the database if it does not exist or preparing the database collation
 *                 - 'CREATE DATABASE IF NOT EXISTS temp_db;'
 *                 - 'USE temp_db;'
 *                 - 'SET NAMES utf8;'
 * ```
 *
 * ## Example with multi-dumps
 * ```yaml
 * modules:
 *     enabled:
 *         - Db:
 *             dsn: 'mysql:host=localhost;dbname=testdb'
 *             user: 'root'
 *             password: ''
 *             dump:
 *                 - 'tests/Support/Data/dump.sql'
 *                 - 'tests/Support/Data/dump-2.sql'
 * ```
 *
 * ## Example with multi-databases
 * ```yaml
 * modules:
 *     enabled:
 *         - Db:
 *             dsn: 'mysql:host=localhost;dbname=testdb'
 *             user: 'root'
 *             password: ''
 *             databases:
 *                 db2:
 *                     dsn: 'mysql:host=localhost;dbname=testdb2'
 *                     user: 'userdb2'
 *                     password: ''
 * ```
 *
 * ## Example with SQLite
 * ```yaml
 * modules:
 *     enabled:
 *         - Db:
 *             dsn: 'sqlite:relative/path/to/sqlite-database.db'
 *             user: ''
 *             password: ''
 * ```
 *
 * ## SQL data dump
 *
 * There are two ways of loading the dump into your database:
 *
 * ### Populator
 *
 * The recommended approach is to configure a `populator`, an external command to load a dump. Command parameters like host, username, password, database
 * can be obtained from the config and inserted into placeholders:
 *
 * For MySQL:
 *
 * ```yaml
 * modules:
 *     enabled:
 *         - Db:
 *             dsn: 'mysql:host=localhost;dbname=testdb'
 *             user: 'root'
 *             password: ''
 *             dump: 'tests/Support/Data/dump.sql'
 *             populate: true # run populator before all tests
 *             cleanup: true # run populator before each test
 *             populator: 'mysql -u $user -h $host $dbname < $dump'
 * ```
 *
 * For PostgreSQL (using `pg_restore`)
 *
 * ```yaml
 * modules:
 *     enabled:
 *         - Db:
 *             dsn: 'pgsql:host=localhost;dbname=testdb'
 *             user: 'root'
 *             password: ''
 *             dump: 'tests/Support/Data/db_backup.dump'
 *             populate: true # run populator before all tests
 *             cleanup: true # run populator before each test
 *             populator: 'pg_restore -u $user -h $host -D $dbname < $dump'
 * ```
 *
 *  Variable names are being taken from config and DSN which has a `keyword=value` format, so you should expect to have a variable named as the
 *  keyword with the full value inside it.
 *
 *  PDO dsn elements for the supported drivers:
 *  * MySQL: [PDO_MYSQL DSN](https://secure.php.net/manual/en/ref.pdo-mysql.connection.php)
 *  * SQLite: [PDO_SQLITE DSN](https://secure.php.net/manual/en/ref.pdo-sqlite.connection.php) - use _relative_ path from the project root
 *  * PostgreSQL: [PDO_PGSQL DSN](https://secure.php.net/manual/en/ref.pdo-pgsql.connection.php)
 *  * MSSQL: [PDO_SQLSRV DSN](https://secure.php.net/manual/en/ref.pdo-sqlsrv.connection.php)
 *  * Oracle: [PDO_OCI DSN](https://secure.php.net/manual/en/ref.pdo-oci.connection.php)
 *
 * ### Dump
 *
 * Db module by itself can load SQL dump without external tools by using current database connection.
 * This approach is system-independent, however, it is slower than using a populator and may have parsing issues (see below).
 *
 * Provide a path to SQL file in `dump` config option:
 *
 * ```yaml
 * modules:
 *    enabled:
 *       - Db:
 *          dsn: 'mysql:host=localhost;dbname=testdb'
 *          user: 'root'
 *          password: ''
 *          populate: true # load dump before all tests
 *          cleanup: true # load dump for each test
 *          dump: 'tests/_data/dump.sql'
 * ```
 *
 *  To parse SQL Db file, it should follow this specification:
 *  * Comments are permitted.
 *  * The `dump.sql` may contain multiline statements.
 *  * The delimiter, a semi-colon in this case, must be on the same line as the last statement:
 *
 * ```sql
 * -- Add a few contacts to the table.
 * REPLACE INTO `Contacts` (`created`, `modified`, `status`, `contact`, `first`, `last`) VALUES
 * (NOW(), NOW(), 1, 'Bob Ross', 'Bob', 'Ross'),
 * (NOW(), NOW(), 1, 'Fred Flintstone', 'Fred', 'Flintstone');
 *
 * -- Remove existing orders for testing.
 * DELETE FROM `Order`;
 * ```
 * ## Query generation
 *
 * `seeInDatabase`, `dontSeeInDatabase`, `seeNumRecords`, `grabFromDatabase` and `grabNumRecords` methods
 * accept arrays as criteria. WHERE condition is generated using item key as a field name and
 * item value as a field value.
 *
 * Example:
 * ```php
 * <?php
 * $I->seeInDatabase('users', ['name' => 'Davert', 'email' => 'davert@mail.com']);
 *
 * ```
 * Will generate:
 *
 * ```sql
 * SELECT COUNT(*) FROM `users` WHERE `name` = 'Davert' AND `email` = 'davert@mail.com'
 * ```
 * Since version 2.1.9 it's possible to use LIKE in a condition, as shown here:
 *
 * ```php
 * <?php
 * $I->seeInDatabase('users', ['name' => 'Davert', 'email like' => 'davert%']);
 *
 * ```
 * Will generate:
 *
 * ```sql
 * SELECT COUNT(*) FROM `users` WHERE `name` = 'Davert' AND `email` LIKE 'davert%'
 * ```
 * Null comparisons are also available, as shown here:
 *
 * ```php
 * <?php
 * $I->seeInDatabase('users', ['name' => null, 'email !=' => null]);
 *
 * ```
 * Will generate:
 *
 * ```sql
 * SELECT COUNT(*) FROM `users` WHERE `name` IS NULL AND `email` IS NOT NULL
 * ```
 * ## Public Properties
 * * dbh - contains the PDO connection
 * * driver - contains the Connection Driver
 *
 */
class Db extends Module implements Db_Interface
{
    /**
     * @var array<string, mixed>
     */
    protected array $config = ['populate' => false, 'cleanup' => false, 'reconnect' => false, 'waitlock' => 0, 'dump' => null, 'populator' => null, 'skip_cleanup_if_failed' => false];
    /**
     * @var string[]
     */
    protected array $required_fields = ['dsn', 'user', 'password'];
    /**
     * @var string
     */
    public const DEFAULT_DATABASE = 'default';
    /**
     * @var Driver[]
     */
    public array $drivers = [];
    /**
     * @var PDO[]
     */
    public array $dbhs = [];
    public array $databases_populated = [];
    public array $databases_sql = [];
    protected array $inserted_rows = [];
    public string $current_database = self::DEFAULT_DATABASE;
    protected function get_databases(): array
    {
        $databases = [$this->current_database => $this->config];
        if (!empty($this->config['databases'])) {
            foreach ($this->config['databases'] as $database_key => $database_config) {
                $databases[$database_key] = array_merge(['populate' => false, 'cleanup' => false, 'reconnect' => false, 'waitlock' => 0, 'dump' => null, 'populator' => null], $database_config);
            }
        }
        return $databases;
    }
    protected function connect_to_databases(): void
    {
        foreach ($this->get_databases() as $database_key => $database_config) {
            $this->connect($database_key, $database_config);
        }
    }
    protected function clean_up_databases(): void
    {
        foreach ($this->get_databases() as $database_key => $database_config) {
            $this->_cleanup($database_key, $database_config);
        }
    }
    protected function populate_databases($config_key): void
    {
        foreach ($this->get_databases() as $database_key => $database_config) {
            if ($database_config[$config_key]) {
                if (!$database_config['populate']) {
                    return;
                }
                if (isset($this->databases_populated[$database_key]) && $this->databases_populated[$database_key]) {
                    return;
                }
                $this->_load_dump($database_key, $database_config);
            }
        }
    }
    protected function read_sql_for_databases(): void
    {
        foreach ($this->get_databases() as $database_key => $database_config) {
            $this->read_sql($database_key, $database_config);
        }
    }
    protected function remove_inserted_for_databases(): void
    {
        foreach (array_keys($this->get_databases()) as $database_key) {
            $this->am_connected_to_database($database_key);
            $this->remove_inserted($database_key);
        }
    }
    protected function disconnect_databases(): void
    {
        foreach (array_keys($this->get_databases()) as $database_key) {
            $this->disconnect($database_key);
        }
    }
    protected function reconnect_databases(): void
    {
        foreach ($this->get_databases() as $database_key => $database_config) {
            if ($database_config['reconnect']) {
                $this->disconnect($database_key);
                $this->connect($database_key, $database_config);
            }
        }
    }
    public function __get($name)
    {
        Notification::deprecate('Properties dbh and driver are deprecated in favor of Db::_getDbh and Db::_getDriver', 'Db module');
        if ($name == 'driver') {
            return $this->_get_driver();
        }
        if ($name == 'dbh') {
            return $this->_get_dbh();
        }
    }
    public function _get_driver(): Driver
    {
        return $this->drivers[$this->current_database];
    }
    public function _get_dbh(): PDO
    {
        return $this->dbhs[$this->current_database];
    }
    /**
     * Make sure you are connected to the right database.
     *
     * ```php
     * <?php
     * $I->seeNumRecords(2, 'users');   //executed on default database
     * $I->amConnectedToDatabase('db_books');
     * $I->seeNumRecords(30, 'books');  //executed on db_books database
     * //All the next queries will be on db_books
     * ```
     *
     * @throws ModuleConfigException
     */
    public function am_connected_to_database(string $database_key): void
    {
        if (empty($this->get_databases()[$database_key]) && $database_key != self::DEFAULT_DATABASE) {
            throw new Module_Config_Exception(self::class, "\nNo database {$database_key} in the key databases.\n");
        }
        $this->current_database = $database_key;
    }
    /**
     * Can be used with a callback if you don't want to change the current database in your test.
     *
     * ```php
     * <?php
     * $I->seeNumRecords(2, 'users');   //executed on default database
     * $I->performInDatabase('db_books', function($I) {
     *     $I->seeNumRecords(30, 'books');  //executed on db_books database
     * });
     * $I->seeNumRecords(2, 'users');  //executed on default database
     * ```
     * List of actions can be pragmatically built using `Codeception\Util\ActionSequence`:
     *
     * ```php
     * <?php
     * $I->performInDatabase('db_books', ActionSequence::build()
     *     ->seeNumRecords(30, 'books')
     * );
     * ```
     * Alternatively an array can be used:
     *
     * ```php
     * $I->performInDatabase('db_books', ['seeNumRecords' => [30, 'books']]);
     * ```
     *
     * Choose the syntax you like the most and use it,
     *
     * Actions executed from array or ActionSequence will print debug output for actions, and adds an action name to
     * exception on failure.
     *
     * @param $databaseKey
     * @param ActionSequence|array|callable $actions
     * @throws ModuleConfigException
     */
    public function perform_in_database($database_key, $actions): void
    {
        $backup_database = $this->current_database;
        $this->am_connected_to_database($database_key);
        if (is_callable($actions)) {
            $actions($this);
            $this->am_connected_to_database($backup_database);
            return;
        }
        if (is_array($actions)) {
            $actions = Action_Sequence::build()->from_array($actions);
        }
        if (!$actions instanceof Action_Sequence) {
            throw new InvalidArgumentException('2nd parameter, actions should be callback, ActionSequence or array');
        }
        $actions->run($this);
        $this->am_connected_to_database($backup_database);
    }
    public function _initialize(): void
    {
        $this->connect_to_databases();
    }
    public function __destruct()
    {
        $this->disconnect_databases();
    }
    public function _before_suite($settings = []): void
    {
        $this->read_sql_for_databases();
        $this->connect_to_databases();
        $this->clean_up_databases();
        $this->populate_databases('populate');
    }
    private function read_sql($database_key = null, $database_config = null): void
    {
        if ($database_config['populator']) {
            return;
        }
        if (!$database_config['cleanup'] && !$database_config['populate']) {
            return;
        }
        if (empty($database_config['dump'])) {
            return;
        }
        if (!is_array($database_config['dump'])) {
            $database_config['dump'] = [$database_config['dump']];
        }
        $sql = '';
        foreach ($database_config['dump'] as $file_path) {
            $sql .= $this->read_sql_file($file_path);
        }
        if (!empty($sql)) {
            // split SQL dump into lines
            $this->databases_sql[$database_key] = preg_split('#\r\n|\n|\r#', $sql, -1, PREG_SPLIT_NO_EMPTY);
        }
    }
    /**
     * @throws ModuleConfigException|ModuleException
     */
    private function read_sql_file(string $file_path): ?string
    {
        if (!file_exists(Configuration::project_dir() . $file_path)) {
            throw new Module_Config_Exception(self::class, "\nFile with dump doesn't exist.\n" . 'Please, check path for sql file: ' . $file_path);
        }
        $sql = file_get_contents(Configuration::project_dir() . $file_path);
        // remove C-style comments (except MySQL directives)
        $replaced = preg_replace('#/\*(?!!\d+).*?\*/#s', '', $sql);
        if (!empty($sql) && is_null($replaced)) {
            throw new Module_Exception(self::class, 'Please, increase pcre.backtrack_limit value in PHP CLI config');
        }
        return $replaced;
    }
    private function connect(int|string $database_key, array $database_config): void
    {
        if (!empty($this->drivers[$database_key]) && !empty($this->dbhs[$database_key])) {
            return;
        }
        $options = [];
        if (array_key_exists('ssl_key', $database_config) && !empty($database_config['ssl_key']) && defined(PDO::class . '::MYSQL_ATTR_SSL_KEY')) {
            $options[PDO::MYSQL_ATTR_SSL_KEY] = (string) $database_config['ssl_key'];
        }
        if (array_key_exists('ssl_cert', $database_config) && !empty($database_config['ssl_cert']) && defined(PDO::class . '::MYSQL_ATTR_SSL_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_CERT] = (string) $database_config['ssl_cert'];
        }
        if (array_key_exists('ssl_ca', $database_config) && !empty($database_config['ssl_ca']) && defined(PDO::class . '::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = (string) $database_config['ssl_ca'];
        }
        if (array_key_exists('ssl_cipher', $database_config) && !empty($database_config['ssl_cipher']) && defined(PDO::class . '::MYSQL_ATTR_SSL_CIPHER')) {
            $options[PDO::MYSQL_ATTR_SSL_CIPHER] = (string) $database_config['ssl_cipher'];
        }
        if (array_key_exists('ssl_verify_server_cert', $database_config) && defined(PDO::class . '::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) $database_config['ssl_verify_server_cert'];
        }
        try {
            $this->debug_section('Connecting To Db', ['config' => $database_config, 'options' => $options]);
            $this->drivers[$database_key] = Driver::create($database_config['dsn'], $database_config['user'], $database_config['password'], $options);
        } catch (PDOException $exception) {
            $message = $exception->get_message();
            if ($message === 'could not find driver') {
                [$missing_driver] = explode(':', $database_config['dsn'], 2);
                $message = sprintf('could not find %s driver', $missing_driver);
            }
            throw new Module_Exception(self::class, $message . ' while creating PDO connection');
        }
        if ($database_config['waitlock']) {
            $this->_get_driver()->set_wait_lock($database_config['waitlock']);
        }
        if (isset($database_config['initial_queries'])) {
            foreach ($database_config['initial_queries'] as $initial_query) {
                $this->drivers[$database_key]->execute_query($initial_query, []);
            }
        }
        $this->debug_section('Db', 'Connected to ' . $database_key . ' ' . $this->drivers[$database_key]->get_db());
        $this->dbhs[$database_key] = $this->drivers[$database_key]->get_dbh();
    }
    private function disconnect(int|string $database_key): void
    {
        $this->debug_section('Db', 'Disconnected from ' . $database_key);
        $this->dbhs[$database_key] = null;
        $this->drivers[$database_key] = null;
    }
    public function _before(Test_Interface $test): void
    {
        $this->reconnect_databases();
        $this->am_connected_to_database(self::DEFAULT_DATABASE);
        $this->clean_up_databases();
        $this->populate_databases('cleanup');
        parent::_before($test);
    }
    public function _failed(Test_Interface $test, $fail): void
    {
        foreach ($this->get_databases() as $database_key => $database_config) {
            if ($database_config['skip_cleanup_if_failed'] ?? false) {
                $this->inserted_rows[$database_key] = [];
            }
        }
    }
    public function _after(Test_Interface $test): void
    {
        $this->remove_inserted_for_databases();
        parent::_after($test);
    }
    protected function remove_inserted($database_key = null): void
    {
        $database_key = empty($database_key) ? self::DEFAULT_DATABASE : $database_key;
        if (empty($this->inserted_rows[$database_key])) {
            return;
        }
        foreach (array_reverse($this->inserted_rows[$database_key]) as $row) {
            try {
                $this->_get_driver()->delete_query_by_criteria($row['table'], $row['primary']);
            } catch (Exception) {
                $this->debug("Couldn't delete record " . json_encode($row['primary'], JSON_THROW_ON_ERROR) . " from {$row['table']}");
            }
        }
        $this->inserted_rows[$database_key] = [];
    }
    public function _cleanup(?string $database_key = null, ?array $database_config = null): void
    {
        $database_key = empty($database_key) ? self::DEFAULT_DATABASE : $database_key;
        $database_config = empty($database_config) ? $this->config : $database_config;
        if (!$database_config['populate']) {
            return;
        }
        if (!$database_config['cleanup']) {
            return;
        }
        if (isset($this->databases_populated[$database_key]) && !$this->databases_populated[$database_key]) {
            return;
        }
        $dbh = $this->dbhs[$database_key];
        if (!$dbh) {
            throw new Module_Config_Exception(self::class, "No connection to database. Remove this module from config if you don't need database repopulation");
        }
        try {
            if (!$this->should_cleanup($database_config, $database_key)) {
                return;
            }
            $this->drivers[$database_key]->cleanup();
            $this->databases_populated[$database_key] = false;
        } catch (Exception $e) {
            throw new Module_Exception(self::class, $e->get_message());
        }
    }
    protected function should_cleanup(array $database_config, string $database_key): bool
    {
        // If using populator and it's not empty, clean up regardless
        if (!empty($database_config['populator'])) {
            return true;
        }
        // If no sql dump for $databaseKey or sql dump is empty, don't clean up
        return !empty($this->databases_sql[$database_key]);
    }
    public function _is_populated()
    {
        return $this->databases_populated[$this->current_database];
    }
    public function _load_dump(?string $database_key = null, ?array $database_config = null): void
    {
        $database_key = empty($database_key) ? self::DEFAULT_DATABASE : $database_key;
        $database_config = empty($database_config) ? $this->config : $database_config;
        if (!empty($database_config['populator'])) {
            $this->load_dump_using_populator($database_key, $database_config);
            return;
        }
        $this->load_dump_using_driver($database_key);
    }
    protected function load_dump_using_populator(string $database_key, array $database_config): void
    {
        $populator = new Db_Populator($database_config);
        $this->databases_populated[$database_key] = $populator->run();
    }
    protected function load_dump_using_driver(string $database_key): void
    {
        if (!isset($this->databases_sql[$database_key])) {
            return;
        }
        if (!$this->databases_sql[$database_key]) {
            $this->debug_section('Db', 'No SQL loaded, loading dump skipped');
            return;
        }
        $this->drivers[$database_key]->load($this->databases_sql[$database_key]);
        $this->databases_populated[$database_key] = true;
    }
    /**
     * Inserts an SQL record into a database. This record will be erased after the test,
     * unless you've configured "skip_cleanup_if_failed", and the test fails.
     *
     * ```php
     * <?php
     * $I->haveInDatabase('users', ['name' => 'miles', 'email' => 'miles@davis.com']);
     * ```
     */
    public function have_in_database(string $table, array $data): int
    {
        $last_insert_id = $this->_insert_in_database($table, $data);
        $this->add_inserted_row($table, $data, $last_insert_id);
        return $last_insert_id;
    }
    public function _insert_in_database(string $table, array $data): int
    {
        $query = $this->_get_driver()->insert($table, $data);
        $parameters = array_values($data);
        $this->debug_section('Query', $query);
        $this->debug_section('Parameters', $parameters);
        $this->_get_driver()->execute_query($query, $parameters);
        try {
            $last_insert_id = (int) $this->_get_driver()->last_insert_id($table);
        } catch (PDOException $e) {
            // ignore errors due to uncommon DB structure,
            // such as tables without _id_seq in PGSQL
            $last_insert_id = 0;
            $this->debug_section('DB error', $e->get_message());
        }
        return $last_insert_id;
    }
    private function add_inserted_row(string $table, array $row, int $id): void
    {
        $primary_key = $this->_get_driver()->get_primary_key($table);
        $primary = [];
        if ($primary_key !== []) {
            $filled_keys = array_intersect($primary_key, array_keys($row));
            $missing_primary_key_columns = array_diff_key($primary_key, $filled_keys);
            if (count($missing_primary_key_columns) === 0) {
                $primary = array_intersect_key($row, array_flip($primary_key));
            } elseif (count($missing_primary_key_columns) === 1) {
                $primary = array_intersect_key($row, array_flip($primary_key));
                $missing_column = reset($missing_primary_key_columns);
                $primary[$missing_column] = $id;
            } else {
                foreach ($primary_key as $column) {
                    if (isset($row[$column])) {
                        $primary[$column] = $row[$column];
                    } else {
                        throw new InvalidArgumentException('Primary key field ' . $column . ' is not set for table ' . $table);
                    }
                }
            }
        } else {
            $primary = $row;
        }
        $this->inserted_rows[$this->current_database][] = ['table' => $table, 'primary' => $primary];
    }
    public function see_in_database(string $table, array $criteria = []): void
    {
        $res = $this->count_in_database($table, $criteria);
        $this->assert_greater_than(0, $res, 'No matching records found for criteria ' . json_encode($criteria, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . ' in table ' . $table);
    }
    /**
     * Asserts that the given number of records were found in the database.
     *
     * ```php
     * <?php
     * $I->seeNumRecords(1, 'users', ['name' => 'davert'])
     * ```
     *
     * @param int $expectedNumber Expected number
     * @param string $table Table name
     * @param array $criteria Search criteria [Optional]
     */
    public function see_num_records(int $expected_number, string $table, array $criteria = []): void
    {
        $actual_number = $this->count_in_database($table, $criteria);
        $this->assert_same($expected_number, $actual_number, sprintf('The number of found rows (%d) does not match expected number %d for criteria %s in table %s', $actual_number, $expected_number, json_encode($criteria, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $table));
    }
    public function dont_see_in_database(string $table, array $criteria = []): void
    {
        $count = $this->count_in_database($table, $criteria);
        $this->assert_less_than(1, $count, 'Unexpectedly found matching records for criteria ' . json_encode($criteria, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . ' in table ' . $table);
    }
    /**
     * Count rows in a database
     *
     * @param string $table    Table name
     * @param array  $criteria Search criteria [Optional]
     */
    protected function count_in_database(string $table, array $criteria = []): int
    {
        return (int) $this->proceed_see_in_database($table, 'count(*)', $criteria);
    }
    /**
     * Fetches all values from the column in database.
     * Provide table name, desired column and criteria.
     *
     * @return mixed
     */
    protected function proceed_see_in_database(string $table, string $column, array $criteria)
    {
        $query = $this->_get_driver()->select($column, $table, $criteria);
        $parameters = array_values($criteria);
        $this->debug_section('Query', $query);
        if (!empty($parameters)) {
            $this->debug_section('Parameters', $parameters);
        }
        $sth = $this->_get_driver()->execute_query($query, $parameters);
        return $sth->fetch_column();
    }
    /**
     * Fetches all values from the column in database.
     * Provide table name, desired column and criteria.
     *
     * ``` php
     * <?php
     * $mails = $I->grabColumnFromDatabase('users', 'email', ['name' => 'RebOOter']);
     * ```
     */
    public function grab_column_from_database(string $table, string $column, array $criteria = []): array
    {
        $query = $this->_get_driver()->select($column, $table, $criteria);
        $parameters = array_values($criteria);
        $this->debug_section('Query', $query);
        $this->debug_section('Parameters', $parameters);
        $sth = $this->_get_driver()->execute_query($query, $parameters);
        return $sth->fetch_all(PDO::FETCH_COLUMN, 0);
    }
    /**
     * Fetches a single column value from a database.
     * Provide table name, desired column and criteria.
     *
     * ``` php
     * <?php
     * $mail = $I->grabFromDatabase('users', 'email', ['name' => 'Davert']);
     * ```
     * Comparison expressions can be used as well:
     *
     * ```php
     * <?php
     * $postNum = $I->grabFromDatabase('posts', 'num_comments', ['num_comments >=' => 100]);
     * $mail = $I->grabFromDatabase('users', 'email', ['email like' => 'miles%']);
     * ```
     *
     * Supported operators: `<`, `>`, `>=`, `<=`, `!=`, `like`.
     *
     * @return mixed Returns a single column value or false
     */
    public function grab_from_database(string $table, string $column, array $criteria = [])
    {
        return $this->proceed_see_in_database($table, $column, $criteria);
    }
    /**
     * Fetches a whole entry from a database.
     * Make the test fail if the entry is not found.
     * Provide table name, desired column and criteria.
     *
     * ``` php
     * <?php
     * $mail = $I->grabEntryFromDatabase('users', ['name' => 'Davert']);
     * ```
     * Comparison expressions can be used as well:
     *
     * ```php
     * <?php
     * $post = $I->grabEntryFromDatabase('posts', ['num_comments >=' => 100]);
     * $user = $I->grabEntryFromDatabase('users', ['email like' => 'miles%']);
     * ```
     *
     * Supported operators: `<`, `>`, `>=`, `<=`, `!=`, `like`.
     *
     * @return array Returns a single entry value
     * @throws PDOException|Exception
     */
    public function grab_entry_from_database(string $table, array $criteria = []): array
    {
        $query = $this->_get_driver()->select('*', $table, $criteria);
        $parameters = array_values($criteria);
        $this->debug_section('Query', $query);
        $this->debug_section('Parameters', $parameters);
        $sth = $this->_get_driver()->execute_query($query, $parameters);
        $result = $sth->fetch(PDO::FETCH_ASSOC, 0);
        if ($result === false) {
            throw new \AssertionError('No matching row found');
        }
        return $result;
    }
    /**
     * Fetches a set of entries from a database.
     * Provide table name and criteria.
     *
     * ``` php
     * <?php
     * $mail = $I->grabEntriesFromDatabase('users', ['name' => 'Davert']);
     * ```
     * Comparison expressions can be used as well:
     *
     * ```php
     * <?php
     * $post = $I->grabEntriesFromDatabase('posts', ['num_comments >=' => 100]);
     * $user = $I->grabEntriesFromDatabase('users', ['email like' => 'miles%']);
     * ```
     *
     * Supported operators: `<`, `>`, `>=`, `<=`, `!=`, `like`.
     *
     * @return array<array<string, mixed>> Returns an array of all matched rows
     * @throws PDOException|Exception
     */
    public function grab_entries_from_database(string $table, array $criteria = []): array
    {
        $query = $this->_get_driver()->select('*', $table, $criteria);
        $parameters = array_values($criteria);
        $this->debug_section('Query', $query);
        $this->debug_section('Parameters', $parameters);
        $sth = $this->_get_driver()->execute_query($query, $parameters);
        return $sth->fetch_all(PDO::FETCH_ASSOC);
    }
    /**
     * Returns the number of rows in a database
     *
     * @param string $table    Table name
     * @param array  $criteria Search criteria [Optional]
     */
    public function grab_num_records(string $table, array $criteria = []): int
    {
        return $this->count_in_database($table, $criteria);
    }
    /**
     * Update an SQL record into a database.
     *
     * ```php
     * <?php
     * $I->updateInDatabase('users', ['isAdmin' => true], ['email' => 'miles@davis.com']);
     * ```
     */
    public function update_in_database(string $table, array $data, array $criteria = []): void
    {
        $query = $this->_get_driver()->update($table, $data, $criteria);
        $parameters = [...array_values($data), ...array_values($criteria)];
        $this->debug_section('Query', $query);
        if (!empty($parameters)) {
            $this->debug_section('Parameters', $parameters);
        }
        $this->_get_driver()->execute_query($query, $parameters);
    }
}