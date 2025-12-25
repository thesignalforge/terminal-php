<?php

declare(strict_types=1);

namespace Signalforge\Terminal;

/**
 * High-performance terminal control for PHP CLI applications.
 *
 * This is a pure PHP implementation matching the signalforge_terminal C extension.
 * Provides raw mode, styling, tables, progress bars, and interactive input.
 */
final class Terminal
{
    private const STATE_NONE = 0x00;
    private const STATE_RAW = 0x01;
    private const STATE_ALT_SCREEN = 0x02;
    private const STATE_CURSOR_HIDDEN = 0x04;

    private const COLOR_NONE = 0;
    private const COLOR_16 = 1;
    private const COLOR_256 = 2;
    private const COLOR_TRUECOLOR = 3;

    private static int $stateFlags = self::STATE_NONE;
    private static int $colorSupport = self::COLOR_NONE;
    private static int $cols = 80;
    private static int $rows = 24;
    private static ?string $originalStty = null;
    private static mixed $resizeCallback = null;

    /** @var array<string, int> Color name to ANSI code mapping */
    private static array $colorMap = [
        'black' => 0,
        'red' => 1,
        'green' => 2,
        'yellow' => 3,
        'blue' => 4,
        'magenta' => 5,
        'cyan' => 6,
        'white' => 7,
    ];

    /**
     * Enter raw terminal mode.
     *
     * @throws TerminalException If terminal is not a TTY
     */
    public static function enter(): void
    {
        if (self::$stateFlags & self::STATE_RAW) {
            return; // Already in raw mode
        }

        if (!posix_isatty(STDIN)) {
            throw new TerminalException('Failed to enter raw mode: terminal may not be a TTY');
        }

        // Save original terminal settings
        self::$originalStty = shell_exec('stty -g 2>/dev/null');

        // Enter raw mode
        $result = shell_exec('stty raw -echo 2>&1');
        if ($result !== null && $result !== '') {
            throw new TerminalException('Failed to enter raw mode: ' . trim($result));
        }

        self::$stateFlags |= self::STATE_RAW;

        // Update terminal size
        self::updateSize();

        // Detect color support
        self::$colorSupport = self::detectColorSupport();

        // Register shutdown function to restore terminal
        register_shutdown_function([self::class, 'restoreOnShutdown']);
    }

    /**
     * Exit raw terminal mode.
     *
     * @throws TerminalException If restoration fails
     */
    public static function exit(): void
    {
        if (!(self::$stateFlags & self::STATE_RAW)) {
            return; // Not in raw mode
        }

        // Restore cursor if hidden
        if (self::$stateFlags & self::STATE_CURSOR_HIDDEN) {
            self::cursor(true);
        }

        // Exit alternate screen if active
        if (self::$stateFlags & self::STATE_ALT_SCREEN) {
            self::alternateScreen(false);
        }

        // Restore original terminal settings
        if (self::$originalStty !== null) {
            shell_exec('stty ' . escapeshellarg(trim(self::$originalStty)) . ' 2>/dev/null');
        } else {
            shell_exec('stty cooked echo 2>/dev/null');
        }

        self::$stateFlags &= ~self::STATE_RAW;
    }

    /**
     * Restore terminal on shutdown.
     *
     * @internal
     */
    public static function restoreOnShutdown(): void
    {
        if (self::$stateFlags & self::STATE_RAW) {
            self::exit();
        }
    }

    /**
     * Get terminal size.
     *
     * @return array{cols: int, rows: int}
     */
    public static function size(): array
    {
        self::updateSize();

        return [
            'cols' => self::$cols,
            'rows' => self::$rows,
        ];
    }

    /**
     * Update cached terminal size.
     */
    private static function updateSize(): void
    {
        // Try stty size first
        $size = shell_exec('stty size 2>/dev/null');
        if ($size !== null && preg_match('/(\d+)\s+(\d+)/', $size, $matches)) {
            self::$rows = (int) $matches[1];
            self::$cols = (int) $matches[2];
            return;
        }

        // Try tput
        $cols = shell_exec('tput cols 2>/dev/null');
        $rows = shell_exec('tput lines 2>/dev/null');
        if ($cols !== null && $rows !== null) {
            self::$cols = (int) trim($cols) ?: 80;
            self::$rows = (int) trim($rows) ?: 24;
            return;
        }

        // Try environment variables
        $cols = getenv('COLUMNS');
        $rows = getenv('LINES');
        if ($cols !== false && $rows !== false) {
            self::$cols = (int) $cols ?: 80;
            self::$rows = (int) $rows ?: 24;
        }
    }

    /**
     * Detect terminal color support level.
     */
    private static function detectColorSupport(): int
    {
        $colorterm = getenv('COLORTERM');

        // Check for true color support
        if ($colorterm === 'truecolor' || $colorterm === '24bit') {
            return self::COLOR_TRUECOLOR;
        }

        $term = getenv('TERM');
        if ($term === false) {
            return posix_isatty(STDOUT) ? self::COLOR_16 : self::COLOR_NONE;
        }

        // True color terminals
        if (str_contains($term, 'truecolor') || str_contains($term, '24bit')) {
            return self::COLOR_TRUECOLOR;
        }

        // 256 color terminals
        if (str_contains($term, '256color') || str_contains($term, '256')) {
            return self::COLOR_256;
        }

        // Basic color support
        if (str_contains($term, 'color') || str_contains($term, 'xterm') ||
            str_contains($term, 'screen') || str_contains($term, 'vt100') ||
            str_contains($term, 'linux') || str_contains($term, 'ansi')) {
            return self::COLOR_16;
        }

        // No color support (dumb terminals)
        if ($term === 'dumb') {
            return self::COLOR_NONE;
        }

        // Default to basic color if we have a TTY
        return posix_isatty(STDOUT) ? self::COLOR_16 : self::COLOR_NONE;
    }

    /**
     * Check if terminal supports basic colors.
     */
    public static function supportsColor(): bool
    {
        if (self::$colorSupport === self::COLOR_NONE) {
            self::$colorSupport = self::detectColorSupport();
        }
        return self::$colorSupport >= self::COLOR_16;
    }

    /**
     * Check if terminal supports 256 colors.
     */
    public static function supports256Color(): bool
    {
        if (self::$colorSupport === self::COLOR_NONE) {
            self::$colorSupport = self::detectColorSupport();
        }
        return self::$colorSupport >= self::COLOR_256;
    }

    /**
     * Check if terminal supports true color (24-bit).
     */
    public static function supportsTrueColor(): bool
    {
        if (self::$colorSupport === self::COLOR_NONE) {
            self::$colorSupport = self::detectColorSupport();
        }
        return self::$colorSupport >= self::COLOR_TRUECOLOR;
    }

    /**
     * Clear the screen.
     */
    public static function clear(): void
    {
        echo "\x1b[2J\x1b[H";
    }

    /**
     * Clear the current line.
     */
    public static function clearLine(): void
    {
        echo "\x1b[2K\r";
    }

    /**
     * Enable or disable alternate screen buffer.
     */
    public static function alternateScreen(bool $enable): void
    {
        if ($enable) {
            echo "\x1b[?1049h";
            self::$stateFlags |= self::STATE_ALT_SCREEN;
        } else {
            echo "\x1b[?1049l";
            self::$stateFlags &= ~self::STATE_ALT_SCREEN;
        }
    }

    /**
     * Show or hide cursor.
     */
    public static function cursor(bool $visible): void
    {
        if ($visible) {
            echo "\x1b[?25h";
            self::$stateFlags &= ~self::STATE_CURSOR_HIDDEN;
        } else {
            echo "\x1b[?25l";
            self::$stateFlags |= self::STATE_CURSOR_HIDDEN;
        }
    }

    /**
     * Move cursor to position (0-indexed).
     */
    public static function cursorTo(int $col, int $row): void
    {
        echo "\x1b[" . ($row + 1) . ';' . ($col + 1) . 'H';
    }

    /**
     * Move cursor up.
     */
    public static function cursorUp(int $n = 1): void
    {
        if ($n > 0) {
            echo "\x1b[{$n}A";
        }
    }

    /**
     * Move cursor down.
     */
    public static function cursorDown(int $n = 1): void
    {
        if ($n > 0) {
            echo "\x1b[{$n}B";
        }
    }

    /**
     * Move cursor forward.
     */
    public static function cursorForward(int $n = 1): void
    {
        if ($n > 0) {
            echo "\x1b[{$n}C";
        }
    }

    /**
     * Move cursor back.
     */
    public static function cursorBack(int $n = 1): void
    {
        if ($n > 0) {
            echo "\x1b[{$n}D";
        }
    }

    /**
     * Get current cursor position.
     *
     * @return array{col: int, row: int}
     * @throws TerminalException If position cannot be determined
     */
    public static function cursorPosition(): array
    {
        if (!(self::$stateFlags & self::STATE_RAW)) {
            throw new TerminalException('Failed to get cursor position');
        }

        // Request cursor position
        echo "\x1b[6n";

        // Read response: ESC [ rows ; cols R
        $response = '';
        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            throw new TerminalException('Failed to get cursor position');
        }

        stream_set_blocking($stdin, false);

        $timeout = microtime(true) + 0.1;
        while (microtime(true) < $timeout) {
            $char = fgetc($stdin);
            if ($char !== false) {
                $response .= $char;
                if ($char === 'R') {
                    break;
                }
            }
            usleep(1000);
        }

        fclose($stdin);

        if (!preg_match('/\x1b\[(\d+);(\d+)R/', $response, $matches)) {
            throw new TerminalException('Failed to get cursor position');
        }

        return [
            'row' => (int) $matches[1] - 1,
            'col' => (int) $matches[2] - 1,
        ];
    }

    /**
     * Register callback for terminal resize.
     */
    public static function onResize(callable $callback): void
    {
        self::$resizeCallback = $callback;
    }

    /**
     * Apply styles to text.
     *
     * @param string $text Text to style
     * @param array<string, mixed> $styles Style options (fg, bg, bold, dim, italic, underline, blink, reverse)
     * @return string Styled text with ANSI codes
     */
    public static function style(string $text, array $styles): string
    {
        $codes = [];

        // Foreground color
        if (isset($styles['fg'])) {
            $code = self::parseColor($styles['fg'], false);
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        // Background color
        if (isset($styles['bg'])) {
            $code = self::parseColor($styles['bg'], true);
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        // Style attributes
        if (!empty($styles['bold'])) {
            $codes[] = '1';
        }
        if (!empty($styles['dim'])) {
            $codes[] = '2';
        }
        if (!empty($styles['italic'])) {
            $codes[] = '3';
        }
        if (!empty($styles['underline'])) {
            $codes[] = '4';
        }
        if (!empty($styles['blink'])) {
            $codes[] = '5';
        }
        if (!empty($styles['reverse'])) {
            $codes[] = '7';
        }

        if (empty($codes)) {
            return $text;
        }

        return "\x1b[" . implode(';', $codes) . 'm' . $text . "\x1b[0m";
    }

    /**
     * Parse a color value to ANSI code.
     */
    private static function parseColor(mixed $color, bool $isBg): ?string
    {
        $colorSupport = self::$colorSupport ?: self::detectColorSupport();

        if (is_string($color)) {
            // Hex color (#RRGGBB or #RGB)
            if (str_starts_with($color, '#')) {
                $hex = substr($color, 1);
                if (strlen($hex) === 3) {
                    $r = hexdec($hex[0] . $hex[0]);
                    $g = hexdec($hex[1] . $hex[1]);
                    $b = hexdec($hex[2] . $hex[2]);
                } elseif (strlen($hex) === 6) {
                    $r = hexdec(substr($hex, 0, 2));
                    $g = hexdec(substr($hex, 2, 2));
                    $b = hexdec(substr($hex, 4, 2));
                } else {
                    return null;
                }

                if ($colorSupport >= self::COLOR_TRUECOLOR) {
                    $prefix = $isBg ? 48 : 38;
                    return "{$prefix};2;{$r};{$g};{$b}";
                } elseif ($colorSupport >= self::COLOR_256) {
                    $code = 16 + intdiv($r, 51) * 36 + intdiv($g, 51) * 6 + intdiv($b, 51);
                    $prefix = $isBg ? 48 : 38;
                    return "{$prefix};5;{$code}";
                } else {
                    $bright = ($r + $g + $b) > 384;
                    $index = (($r > 127) ? 1 : 0) | (($g > 127) ? 2 : 0) | (($b > 127) ? 4 : 0);
                    return (string) (($isBg ? 40 : 30) + $index + ($bright ? 60 : 0));
                }
            }

            // Named color
            $color = strtolower($color);
            $bright = false;

            if (str_starts_with($color, 'bright_')) {
                $bright = true;
                $color = substr($color, 7);
            }

            if ($color === 'default') {
                return (string) ($isBg ? 49 : 39);
            }

            if (isset(self::$colorMap[$color])) {
                $code = self::$colorMap[$color];
                $base = $isBg ? 40 : 30;
                return (string) ($base + $code + ($bright ? 60 : 0));
            }

            return null;
        }

        // RGB array [r, g, b]
        if (is_array($color) && count($color) === 3) {
            $r = max(0, min(255, (int) ($color[0] ?? 0)));
            $g = max(0, min(255, (int) ($color[1] ?? 0)));
            $b = max(0, min(255, (int) ($color[2] ?? 0)));

            if ($colorSupport >= self::COLOR_TRUECOLOR) {
                $prefix = $isBg ? 48 : 38;
                return "{$prefix};2;{$r};{$g};{$b}";
            } elseif ($colorSupport >= self::COLOR_256) {
                $code = 16 + intdiv($r, 51) * 36 + intdiv($g, 51) * 6 + intdiv($b, 51);
                $prefix = $isBg ? 48 : 38;
                return "{$prefix};5;{$code}";
            } else {
                $bright = ($r + $g + $b) > 384;
                $index = (($r > 127) ? 1 : 0) | (($g > 127) ? 2 : 0) | (($b > 127) ? 4 : 0);
                return (string) (($isBg ? 40 : 30) + $index + ($bright ? 60 : 0));
            }
        }

        return null;
    }

    /**
     * Render a table.
     *
     * @param array<string> $headers Table headers
     * @param array<array<mixed>> $rows Table rows
     * @param array{border?: string, padding?: int, align?: array<string>} $options Table options
     */
    public static function table(array $headers, array $rows, ?array $options = null): void
    {
        $borderStyle = $options['border'] ?? 'single';
        $padding = $options['padding'] ?? 1;
        $align = $options['align'] ?? [];

        // Box drawing characters
        $box = match ($borderStyle) {
            'none' => null,
            'ascii' => [
                'h' => '-', 'v' => '|',
                'tl' => '+', 'tr' => '+', 'bl' => '+', 'br' => '+',
                'lt' => '+', 'rt' => '+', 'tt' => '+', 'bt' => '+', 'c' => '+',
            ],
            'double' => [
                'h' => '═', 'v' => '║',
                'tl' => '╔', 'tr' => '╗', 'bl' => '╚', 'br' => '╝',
                'lt' => '╠', 'rt' => '╣', 'tt' => '╦', 'bt' => '╩', 'c' => '╬',
            ],
            'rounded' => [
                'h' => '─', 'v' => '│',
                'tl' => '╭', 'tr' => '╮', 'bl' => '╰', 'br' => '╯',
                'lt' => '├', 'rt' => '┤', 'tt' => '┬', 'bt' => '┴', 'c' => '┼',
            ],
            default => [
                'h' => '─', 'v' => '│',
                'tl' => '┌', 'tr' => '┐', 'bl' => '└', 'br' => '┘',
                'lt' => '├', 'rt' => '┤', 'tt' => '┬', 'bt' => '┴', 'c' => '┼',
            ],
        };

        $numCols = count($headers);
        if ($numCols === 0) {
            return;
        }

        // Calculate column widths
        $colWidths = [];
        foreach ($headers as $i => $header) {
            $colWidths[$i] = self::displayWidth((string) $header);
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                if ($i < $numCols) {
                    $width = self::displayWidth((string) $cell);
                    $colWidths[$i] = max($colWidths[$i] ?? 0, $width);
                }
            }
        }

        // Render table
        $renderRow = function (array $cells, bool $isHeader = false) use ($box, $padding, $colWidths, $numCols, $align): void {
            if ($box !== null) {
                echo $box['v'];
            }

            foreach ($cells as $i => $cell) {
                if ($i >= $numCols) {
                    break;
                }

                $str = (string) $cell;
                $displayW = self::displayWidth($str);
                $space = ($colWidths[$i] ?? 0) - $displayW;

                $cellAlign = $align[$i] ?? 'left';
                $padLeft = $padding;
                $padRight = $padding;

                if ($cellAlign === 'right') {
                    $padLeft += $space;
                } elseif ($cellAlign === 'center') {
                    $padLeft += intdiv($space, 2);
                    $padRight += $space - intdiv($space, 2);
                } else {
                    $padRight += $space;
                }

                echo str_repeat(' ', $padLeft);
                if ($isHeader) {
                    echo "\x1b[1m{$str}\x1b[0m";
                } else {
                    echo $str;
                }
                echo str_repeat(' ', $padRight);

                if ($box !== null) {
                    echo $box['v'];
                }
            }
            echo "\n";
        };

        $renderBorder = function (string $left, string $mid, string $right) use ($box, $padding, $colWidths, $numCols): void {
            if ($box === null) {
                return;
            }

            echo $left;
            foreach ($colWidths as $i => $width) {
                echo str_repeat($box['h'], $width + $padding * 2);
                if ($i < $numCols - 1) {
                    echo $mid;
                }
            }
            echo $right . "\n";
        };

        // Top border
        if ($box !== null) {
            $renderBorder($box['tl'], $box['tt'], $box['tr']);
        }

        // Headers
        $renderRow($headers, true);

        // Header separator
        if ($box !== null) {
            $renderBorder($box['lt'], $box['c'], $box['rt']);
        }

        // Rows
        foreach ($rows as $row) {
            $renderRow($row);
        }

        // Bottom border
        if ($box !== null) {
            $renderBorder($box['bl'], $box['bt'], $box['br']);
        }
    }

    /**
     * Read a single keypress.
     *
     * @param float|null $timeout Timeout in seconds, null for blocking
     * @return array{key: string, char?: string}|null Key info or null on timeout
     * @throws TerminalException If not in raw mode
     */
    public static function readKey(?float $timeout = null): ?array
    {
        if (!(self::$stateFlags & self::STATE_RAW)) {
            throw new TerminalException('Failed to read key');
        }

        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            throw new TerminalException('Failed to read key');
        }

        stream_set_blocking($stdin, false);

        // Wait for input with timeout
        $read = [$stdin];
        $write = $except = null;

        if ($timeout === null) {
            $result = stream_select($read, $write, $except, null);
        } else {
            $sec = (int) $timeout;
            $usec = (int) (($timeout - $sec) * 1000000);
            $result = stream_select($read, $write, $except, $sec, $usec);
        }

        if ($result === false || $result === 0) {
            fclose($stdin);
            return null; // Timeout
        }

        // Read available bytes
        $buf = fread($stdin, 32);
        fclose($stdin);

        if ($buf === false || $buf === '') {
            throw new TerminalException('Failed to read key');
        }

        // Parse input
        $ord = ord($buf[0]);

        // Escape sequence
        if ($buf[0] === "\x1b") {
            if (strlen($buf) === 1) {
                return ['key' => 'esc'];
            }

            if ($buf[1] === '[') {
                return match ($buf[2] ?? '') {
                    'A' => ['key' => 'up'],
                    'B' => ['key' => 'down'],
                    'C' => ['key' => 'right'],
                    'D' => ['key' => 'left'],
                    'H' => ['key' => 'home'],
                    'F' => ['key' => 'end'],
                    '1' => strlen($buf) >= 4 && $buf[3] === '~' ? ['key' => 'home'] : ['key' => 'esc'],
                    '2' => strlen($buf) >= 4 && $buf[3] === '~' ? ['key' => 'insert'] : ['key' => 'esc'],
                    '3' => strlen($buf) >= 4 && $buf[3] === '~' ? ['key' => 'delete'] : ['key' => 'esc'],
                    '4' => strlen($buf) >= 4 && $buf[3] === '~' ? ['key' => 'end'] : ['key' => 'esc'],
                    '5' => strlen($buf) >= 4 && $buf[3] === '~' ? ['key' => 'pageup'] : ['key' => 'esc'],
                    '6' => strlen($buf) >= 4 && $buf[3] === '~' ? ['key' => 'pagedown'] : ['key' => 'esc'],
                    default => ['key' => 'esc'],
                };
            }

            if ($buf[1] === 'O') {
                return match ($buf[2] ?? '') {
                    'P' => ['key' => 'f1'],
                    'Q' => ['key' => 'f2'],
                    'R' => ['key' => 'f3'],
                    'S' => ['key' => 'f4'],
                    default => ['key' => 'esc'],
                };
            }

            return ['key' => 'esc'];
        }

        // Control characters
        if ($ord < 32) {
            return match ($ord) {
                10, 13 => ['key' => 'enter'],
                9 => ['key' => 'tab'],
                8 => ['key' => 'backspace'],
                default => ['key' => 'ctrl+' . chr($ord + 96)],
            };
        }

        // DEL
        if ($ord === 127) {
            return ['key' => 'backspace'];
        }

        // Regular character (possibly UTF-8)
        $charLen = self::utf8CharLen($buf);
        $char = substr($buf, 0, $charLen);

        return ['key' => 'char', 'char' => $char];
    }

    /**
     * Single-select UI.
     *
     * @param string $prompt Selection prompt
     * @param array<string> $options Available options
     * @param int $default Default selected index
     * @return string|null Selected option or null if cancelled
     * @throws TerminalException If not in raw mode
     */
    public static function select(string $prompt, array $options, int $default = 0): ?string
    {
        if (!(self::$stateFlags & self::STATE_RAW)) {
            throw new TerminalException('Terminal must be in raw mode for select()');
        }

        $numOptions = count($options);
        if ($numOptions === 0) {
            return null;
        }

        $options = array_values($options);
        $selected = max(0, min($default, $numOptions - 1));

        self::cursor(false);

        // Draw prompt
        echo $prompt . "\n";

        $running = true;
        $cancelled = false;

        while ($running) {
            // Draw options
            foreach ($options as $i => $option) {
                if ($i === $selected) {
                    echo "  \x1b[36m● {$option}  ←\x1b[0m\n";
                } else {
                    echo "  ○ {$option}\n";
                }
            }

            // Read key
            $key = self::readKey();

            if ($key !== null) {
                switch ($key['key']) {
                    case 'up':
                        $selected = ($selected - 1 + $numOptions) % $numOptions;
                        break;
                    case 'down':
                        $selected = ($selected + 1) % $numOptions;
                        break;
                    case 'enter':
                        $running = false;
                        break;
                    case 'esc':
                    case 'ctrl+c':
                        $running = false;
                        $cancelled = true;
                        break;
                }
            }

            // Move cursor up to redraw
            if ($running) {
                self::cursorUp($numOptions);
            }
        }

        self::cursor(true);

        return $cancelled ? null : $options[$selected];
    }

    /**
     * Multi-select UI.
     *
     * @param string $prompt Selection prompt
     * @param array<string> $options Available options
     * @param array<int> $defaults Default selected indices
     * @return array<string>|null Selected options or null if cancelled
     * @throws TerminalException If not in raw mode
     */
    public static function multiSelect(string $prompt, array $options, array $defaults = []): ?array
    {
        if (!(self::$stateFlags & self::STATE_RAW)) {
            throw new TerminalException('Terminal must be in raw mode for multiSelect()');
        }

        $numOptions = count($options);
        if ($numOptions === 0) {
            return [];
        }

        $options = array_values($options);
        $selected = array_fill(0, $numOptions, false);

        // Process defaults
        foreach ($defaults as $idx) {
            if ($idx >= 0 && $idx < $numOptions) {
                $selected[$idx] = true;
            }
        }

        $cursor = 0;

        self::cursor(false);

        // Draw prompt
        echo $prompt . " (space to toggle, enter to confirm)\n";

        $running = true;
        $cancelled = false;

        while ($running) {
            // Draw options
            foreach ($options as $i => $option) {
                $check = $selected[$i] ? "\x1b[32m☑\x1b[0m" : "☐";
                if ($i === $cursor) {
                    echo "  {$check} \x1b[4m{$option}\x1b[0m ←\n";
                } else {
                    echo "  {$check} {$option}\n";
                }
            }

            // Read key
            $key = self::readKey();

            if ($key !== null) {
                switch ($key['key']) {
                    case 'up':
                        $cursor = ($cursor - 1 + $numOptions) % $numOptions;
                        break;
                    case 'down':
                        $cursor = ($cursor + 1) % $numOptions;
                        break;
                    case 'char':
                        if (($key['char'] ?? '') === ' ') {
                            $selected[$cursor] = !$selected[$cursor];
                        }
                        break;
                    case 'enter':
                        $running = false;
                        break;
                    case 'esc':
                    case 'ctrl+c':
                        $running = false;
                        $cancelled = true;
                        break;
                }
            }

            // Move cursor up to redraw
            if ($running) {
                self::cursorUp($numOptions);
            }
        }

        self::cursor(true);

        if ($cancelled) {
            return null;
        }

        $result = [];
        foreach ($options as $i => $option) {
            if ($selected[$i]) {
                $result[] = $option;
            }
        }

        return $result;
    }

    /**
     * Create a progress bar.
     */
    public static function progressBar(int $total, ?string $label = null): ProgressBar
    {
        return new ProgressBar($total, $label);
    }

    /**
     * Create a spinner/loader.
     */
    public static function loader(?string $message = null, ?string $style = null): Loader
    {
        return new Loader($message, $style);
    }

    /**
     * Calculate display width of a UTF-8 string.
     */
    public static function displayWidth(string $str): int
    {
        // Strip ANSI escape sequences
        $str = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $str) ?? $str;

        $width = 0;
        $len = strlen($str);
        $pos = 0;

        while ($pos < $len) {
            $charLen = self::utf8CharLen(substr($str, $pos));
            $char = substr($str, $pos, $charLen);

            // Simple width calculation (wide chars get 2, others 1)
            $ord = mb_ord($char, 'UTF-8');
            if ($ord !== false) {
                // CJK and wide characters
                if (($ord >= 0x1100 && $ord <= 0x115F) ||  // Hangul Jamo
                    ($ord >= 0x2E80 && $ord <= 0x9FFF) ||  // CJK
                    ($ord >= 0xAC00 && $ord <= 0xD7A3) ||  // Hangul Syllables
                    ($ord >= 0xF900 && $ord <= 0xFAFF) ||  // CJK Compatibility
                    ($ord >= 0xFE10 && $ord <= 0xFE1F) ||  // Vertical Forms
                    ($ord >= 0xFE30 && $ord <= 0xFE6F) ||  // CJK Compatibility Forms
                    ($ord >= 0xFF00 && $ord <= 0xFF60) ||  // Fullwidth Forms
                    ($ord >= 0xFFE0 && $ord <= 0xFFE6) ||  // Fullwidth Signs
                    ($ord >= 0x1F300 && $ord <= 0x1F9FF) || // Emoji
                    ($ord >= 0x2600 && $ord <= 0x27BF)) {  // Misc Symbols + Dingbats
                    $width += 2;
                } elseif ($ord >= 32) {
                    $width += 1;
                }
            }

            $pos += $charLen;
        }

        return $width;
    }

    /**
     * Get byte length of a UTF-8 character.
     */
    private static function utf8CharLen(string $str): int
    {
        if ($str === '') {
            return 0;
        }

        $byte = ord($str[0]);

        if ($byte < 0x80) {
            return 1;
        } elseif (($byte & 0xE0) === 0xC0) {
            return 2;
        } elseif (($byte & 0xF0) === 0xE0) {
            return 3;
        } elseif (($byte & 0xF8) === 0xF0) {
            return 4;
        }

        return 1; // Invalid UTF-8
    }
}
