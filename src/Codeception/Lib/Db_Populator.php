<?php

declare (strict_types=1);
namespace Codeception\Lib;

/**
 * Populates a db using a parameterized command built from the Db module configuration.
 */
class Db_Populator
{
    protected array $commands = [];
    /**
     * Constructs a DbPopulator object for the given command and Db module.
     *
     * @internal param string $command The parameterized command to evaluate and execute later.
     * @internal param Codeception\Module\Db|null $dbModule The Db module used to build the populator command or null.
     */
    public function __construct(protected array $config)
    {
        //Convert To Array Format
        if (!isset($this->config['dump'])) {
            return;
        }
        if (is_array($this->config['dump'])) {
            return;
        }
        $this->config['dump'] = [$this->config['dump']];
    }
    /**
     * Builds out a command replacing any found `$key` with its value if found in the given configuration.
     *
     * Process any $key found in the configuration array as a key of the array and replaces it with
     * the found value for the key. Example:
     *
     * ```php
     * <?php
     *
     * $command = 'Hello $name';
     * $config = ['name' => 'Mauro'];
     *
     * // With the above parameters it will return `'Hello Mauro'`.
     * ```
     *
     * @param string $command The command to be evaluated using the given config
     * @param string|null $dumpFile The dump file to build the command with.
     * @return string The resulting command string after evaluating any configuration's key
     */
    protected function build_command(string $command, ?string $dump_file = null): string
    {
        $dsn = $this->config['dsn'] ?? '';
        $dsn_vars = [];
        $dsn_without_driver = preg_replace('#^[a-z]+:#i', '', $dsn);
        foreach (explode(';', $dsn_without_driver) as $item) {
            $key_value_tuple = explode('=', $item);
            if (count($key_value_tuple) > 1) {
                [$k, $v] = array_values($key_value_tuple);
                $dsn_vars[$k] = $v;
            }
        }
        $vars = array_merge($dsn_vars, $this->config);
        if ($dump_file !== null) {
            $vars['dump'] = $dump_file;
        }
        foreach ($vars as $key => $value) {
            if (!is_array($value)) {
                $vars['$' . $key] = $value;
            }
            unset($vars[$key]);
        }
        return str_replace(array_keys($vars), $vars, $command);
    }
    /**
     * Executes the command built using the Db module configuration.
     *
     * Uses the PHP `exec` to spin off a child process for the built command.
     */
    public function run(): bool
    {
        foreach ($this->build_commands() as $command) {
            $this->run_command($command);
        }
        return true;
    }
    private function run_command($command): void
    {
        codecept_debug("[Db] Executing Populator: `{$command}`");
        exec($command, $output, $exit_code);
        if (0 !== $exit_code) {
            throw new \RuntimeException("The populator command did not end successfully: \n" . "  Exit code: {$exit_code} \n" . '  Output:' . implode("\n", $output));
        }
        codecept_debug('[Db] Populator Finished.');
    }
    public function build_commands(): array
    {
        if ($this->commands !== []) {
            return $this->commands;
        }
        if (!isset($this->config['dump']) || $this->config['dump'] === false) {
            return [$this->build_command($this->config['populator'])];
        }
        $this->commands = [];
        foreach ($this->config['dump'] as $dump_file) {
            $this->commands[] = $this->build_command($this->config['populator'], $dump_file);
        }
        return $this->commands;
    }
}