<?php

declare(strict_types=1);

namespace Signalforge\Terminal;

/**
 * Base command class for CLI applications.
 */
class Command
{
    private string $name = 'command';
    private string $description = '';

    /** @var array<string, array{name: string, description: string, required: bool, default: ?string}> */
    private array $arguments = [];

    /** @var array<string, array{name: string, shortcut: ?string, description: string, requiresValue: bool, default: ?string}> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $argValues = [];

    /** @var array<string, mixed> */
    private array $optValues = [];

    /**
     * Set command name.
     *
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set command description.
     *
     * @return $this
     */
    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Add a positional argument.
     *
     * @return $this
     */
    public function addArgument(
        string $name,
        string $description = '',
        bool $required = true,
        ?string $default = null
    ): static {
        $this->arguments[$name] = [
            'name' => $name,
            'description' => $description,
            'required' => $required,
            'default' => $default,
        ];
        return $this;
    }

    /**
     * Add a command option.
     *
     * @return $this
     */
    public function addOption(
        string $name,
        ?string $shortcut = null,
        string $description = '',
        bool $requiresValue = false,
        ?string $default = null
    ): static {
        $this->options[$name] = [
            'name' => $name,
            'shortcut' => $shortcut,
            'description' => $description,
            'requiresValue' => $requiresValue,
            'default' => $default,
        ];
        return $this;
    }

    /**
     * Get argument value.
     */
    public function getArgument(string $name): mixed
    {
        return $this->argValues[$name] ?? null;
    }

    /**
     * Get option value.
     */
    public function getOption(string $name): mixed
    {
        return $this->optValues[$name] ?? null;
    }

    /**
     * Output info message.
     */
    public function info(string $message): void
    {
        echo $message . "\n";
    }

    /**
     * Output success message (green).
     */
    public function success(string $message): void
    {
        echo "\033[32m{$message}\033[0m\n";
    }

    /**
     * Output error message (red).
     */
    public function error(string $message): void
    {
        echo "\033[31m{$message}\033[0m\n";
    }

    /**
     * Output warning message (yellow).
     */
    public function warning(string $message): void
    {
        echo "\033[33m{$message}\033[0m\n";
    }

    /**
     * Output comment message (dim).
     */
    public function comment(string $message): void
    {
        echo "\033[2m{$message}\033[0m\n";
    }

    /**
     * Output blank lines.
     */
    public function newLine(int $count = 1): void
    {
        echo str_repeat("\n", $count);
    }

    /**
     * Display help text.
     */
    public function help(): void
    {
        // Description
        echo "\033[33mDescription:\033[0m\n";
        if ($this->description !== '') {
            echo "  {$this->description}\n\n";
        } else {
            echo "  (no description)\n\n";
        }

        // Usage
        echo "\033[33mUsage:\033[0m\n";
        echo "  {$this->name}";

        if (!empty($this->options)) {
            echo " [options]";
        }

        foreach ($this->arguments as $arg) {
            if ($arg['required']) {
                echo " <{$arg['name']}>";
            } else {
                echo " [{$arg['name']}]";
            }
        }
        echo "\n\n";

        // Arguments
        if (!empty($this->arguments)) {
            echo "\033[33mArguments:\033[0m\n";
            foreach ($this->arguments as $arg) {
                echo sprintf("  \033[32m%-20s\033[0m", $arg['name']);
                if ($arg['description'] !== '') {
                    echo " {$arg['description']}";
                }
                if ($arg['default'] !== null) {
                    echo " \033[2m[default: {$arg['default']}]\033[0m";
                }
                echo "\n";
            }
            echo "\n";
        }

        // Options
        echo "\033[33mOptions:\033[0m\n";
        echo sprintf("  \033[32m%-20s\033[0m Display this help message\n", "-h, --help");

        foreach ($this->options as $opt) {
            $optStr = $opt['shortcut'] !== null
                ? "-{$opt['shortcut']}, --{$opt['name']}"
                : "    --{$opt['name']}";

            if ($opt['requiresValue']) {
                $optStr .= '=VALUE';
            }

            echo sprintf("  \033[32m%-20s\033[0m", $optStr);
            if ($opt['description'] !== '') {
                echo " {$opt['description']}";
            }
            if ($opt['default'] !== null) {
                echo " \033[2m[default: {$opt['default']}]\033[0m";
            }
            echo "\n";
        }
    }

    /**
     * Parse arguments and run the command.
     *
     * @param array<string>|null $args Command line arguments (defaults to $argv)
     * @return int Exit code
     */
    public function run(?array $args = null): int
    {
        global $argv;
        $args = $args ?? array_slice($argv, 1);

        // Initialize option values with defaults
        foreach ($this->options as $name => $opt) {
            $this->optValues[$name] = $opt['default'] ?? ($opt['requiresValue'] ? null : false);
        }

        // Initialize argument values with defaults
        foreach ($this->arguments as $name => $arg) {
            $this->argValues[$name] = $arg['default'];
        }

        // Build shortcut map
        $shortcuts = [];
        foreach ($this->options as $name => $opt) {
            if ($opt['shortcut'] !== null) {
                $shortcuts[$opt['shortcut']] = $name;
            }
        }

        // Parse arguments
        $positional = [];
        $i = 0;

        while ($i < count($args)) {
            $arg = $args[$i];

            // Help flag
            if ($arg === '-h' || $arg === '--help') {
                $this->help();
                return 0;
            }

            // Long option
            if (str_starts_with($arg, '--')) {
                $rest = substr($arg, 2);
                $equalsPos = strpos($rest, '=');

                if ($equalsPos !== false) {
                    $optName = substr($rest, 0, $equalsPos);
                    $value = substr($rest, $equalsPos + 1);
                } else {
                    $optName = $rest;
                    $value = null;
                }

                if (!isset($this->options[$optName])) {
                    $this->error("Unknown option: --{$optName}");
                    return 1;
                }

                if ($this->options[$optName]['requiresValue']) {
                    if ($value === null) {
                        $i++;
                        if ($i >= count($args)) {
                            $this->error("Option --{$optName} requires a value");
                            return 1;
                        }
                        $value = $args[$i];
                    }
                    $this->optValues[$optName] = $value;
                } else {
                    $this->optValues[$optName] = true;
                }

                $i++;
                continue;
            }

            // Short option
            if (str_starts_with($arg, '-') && strlen($arg) > 1) {
                $short = substr($arg, 1, 1);

                if (!isset($shortcuts[$short])) {
                    $this->error("Unknown option: -{$short}");
                    return 1;
                }

                $optName = $shortcuts[$short];

                if ($this->options[$optName]['requiresValue']) {
                    // Value can be attached (-vVALUE) or next arg (-v VALUE)
                    if (strlen($arg) > 2) {
                        $value = substr($arg, 2);
                    } else {
                        $i++;
                        if ($i >= count($args)) {
                            $this->error("Option -{$short} requires a value");
                            return 1;
                        }
                        $value = $args[$i];
                    }
                    $this->optValues[$optName] = $value;
                } else {
                    $this->optValues[$optName] = true;
                }

                $i++;
                continue;
            }

            // Positional argument
            $positional[] = $arg;
            $i++;
        }

        // Assign positional arguments
        $argKeys = array_keys($this->arguments);
        foreach ($positional as $idx => $value) {
            if ($idx < count($argKeys)) {
                $this->argValues[$argKeys[$idx]] = $value;
            }
        }

        // Check required arguments
        foreach ($this->arguments as $name => $arg) {
            if ($arg['required'] && $this->argValues[$name] === null) {
                $this->error("Missing required argument: {$name}");
                echo "\n";
                $this->help();
                return 1;
            }
        }

        // Call execute method if defined
        if (method_exists($this, 'execute')) {
            return $this->execute();
        }

        return 0;
    }
}
