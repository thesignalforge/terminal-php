<?php

declare(strict_types=1);

namespace Signalforge\Terminal;

/**
 * Progress bar component.
 */
final class ProgressBar
{
    private int $total;
    private int $current = 0;
    private ?string $label;
    private float $startTime;
    private bool $finished = false;

    public function __construct(int $total, ?string $label = null)
    {
        $this->total = max(1, $total);
        $this->label = $label;
        $this->startTime = microtime(true);

        $this->render();
    }

    /**
     * Advance the progress bar.
     */
    public function advance(int $step = 1): void
    {
        if ($this->finished) {
            return;
        }

        $this->current += $step;
        if ($this->current > $this->total) {
            $this->current = $this->total;
        }

        $this->render();
    }

    /**
     * Set progress bar position.
     */
    public function set(int $current): void
    {
        if ($this->finished) {
            return;
        }

        $this->current = max(0, min($current, $this->total));
        $this->render();
    }

    /**
     * Finish the progress bar.
     */
    public function finish(?string $message = null): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->current = $this->total;

        echo "\r\x1b[K";

        if ($message !== null && $message !== '') {
            echo "\x1b[32m✓\x1b[0m {$message}";
        } elseif ($this->label !== null && $this->label !== '') {
            echo "\x1b[32m✓\x1b[0m {$this->label} - Done!";
        } else {
            echo "\x1b[32m✓\x1b[0m Done!";
        }
        echo "\n";
    }

    /**
     * Render the progress bar.
     */
    private function render(): void
    {
        $size = Terminal::size();
        $width = $size['cols'];
        $elapsed = microtime(true) - $this->startTime;

        // Calculate rate and ETA
        $rate = $elapsed > 0 ? $this->current / $elapsed : 0;
        $etaSec = $rate > 0 ? (int) (($this->total - $this->current) / $rate) : 0;

        // Format info
        $percent = $this->total > 0 ? (int) ($this->current * 100 / $this->total) : 0;
        $info = sprintf(' %d%% (%d/%d) %.1f/s ETA: %02d:%02d',
            $percent,
            $this->current,
            $this->total,
            $rate,
            intdiv($etaSec, 60),
            $etaSec % 60
        );

        $labelWidth = $this->label !== null ? mb_strlen($this->label) + 1 : 0;
        $barWidth = $width - $labelWidth - strlen($info) - 3; // 3 for [ ]

        if ($barWidth < 10) {
            $barWidth = 10;
        }

        $filled = $this->total > 0 ? (int) ($this->current * $barWidth / $this->total) : 0;
        if ($filled > $barWidth) {
            $filled = $barWidth;
        }

        // Clear line and render
        echo "\r\x1b[K";

        if ($this->label !== null && $this->label !== '') {
            echo $this->label . ' ';
        }

        echo '[';
        for ($i = 0; $i < $barWidth; $i++) {
            if ($i < $filled) {
                echo '=';
            } elseif ($i === $filled) {
                echo '>';
            } else {
                echo ' ';
            }
        }
        echo ']' . $info;
    }
}
