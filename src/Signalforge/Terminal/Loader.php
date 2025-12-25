<?php

declare(strict_types=1);

namespace Signalforge\Terminal;

/**
 * Spinner/loader component.
 */
final class Loader
{
    private const FRAMES_DOTS = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private const FRAMES_LINE = ['-', '\\', '|', '/'];
    private const FRAMES_ARROW = ['←', '↖', '↑', '↗', '→', '↘', '↓', '↙'];
    private const FRAME_TIME_US = 60000; // 60ms between frames

    private ?string $message;
    private string $style;
    private int $frame = 0;
    private bool $running = false;
    private float $lastFrame = 0;

    /** @var array<string> */
    private array $frames;

    public function __construct(?string $message = null, ?string $style = null)
    {
        $this->message = $message;
        $this->style = $style ?? 'dots';

        $this->frames = match ($this->style) {
            'line' => self::FRAMES_LINE,
            'arrow' => self::FRAMES_ARROW,
            default => self::FRAMES_DOTS,
        };
    }

    /**
     * Start the loader animation.
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->frame = 0;
        $this->lastFrame = microtime(true);

        Terminal::cursor(false);
        $this->renderFrame();
    }

    /**
     * Update loader message.
     */
    public function text(string $message): void
    {
        $this->message = $message;

        if ($this->running) {
            $this->renderFrame();
        }
    }

    /**
     * Advance the spinner by one frame - call this in your loop.
     */
    public function tick(): void
    {
        if (!$this->running) {
            return;
        }

        $now = microtime(true);
        $elapsedUs = (int) (($now - $this->lastFrame) * 1000000);

        if ($elapsedUs >= self::FRAME_TIME_US) {
            $this->frame++;
            $this->renderFrame();
            $this->lastFrame = $now;
        }
    }

    /**
     * Stop the loader.
     */
    public function stop(?string $message = null): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        echo "\r\x1b[K";

        if ($message !== null && $message !== '') {
            echo "\x1b[32m✓\x1b[0m {$message}\n";
        }

        Terminal::cursor(true);
    }

    /**
     * Render the current frame.
     */
    private function renderFrame(): void
    {
        $frameChar = $this->frames[$this->frame % count($this->frames)];

        echo "\r\x1b[K{$frameChar} ";

        if ($this->message !== null && $this->message !== '') {
            echo $this->message;
        }
    }
}
