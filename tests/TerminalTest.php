<?php

declare(strict_types=1);

namespace Signalforge\Terminal\Tests;

use PHPUnit\Framework\TestCase;
use Signalforge\Terminal\Terminal;
use Signalforge\Terminal\ProgressBar;
use Signalforge\Terminal\Loader;
use Signalforge\Terminal\Command;
use Signalforge\Terminal\TerminalException;

final class TerminalTest extends TestCase
{
    public function testSizeReturnsArray(): void
    {
        $size = Terminal::size();

        $this->assertIsArray($size);
        $this->assertArrayHasKey('cols', $size);
        $this->assertArrayHasKey('rows', $size);
        $this->assertIsInt($size['cols']);
        $this->assertIsInt($size['rows']);
        $this->assertGreaterThan(0, $size['cols']);
        $this->assertGreaterThan(0, $size['rows']);
    }

    public function testSupportsColorReturnsBool(): void
    {
        $result = Terminal::supportsColor();
        $this->assertIsBool($result);
    }

    public function testSupports256ColorReturnsBool(): void
    {
        $result = Terminal::supports256Color();
        $this->assertIsBool($result);
    }

    public function testSupportsTrueColorReturnsBool(): void
    {
        $result = Terminal::supportsTrueColor();
        $this->assertIsBool($result);
    }

    public function testStyleWithNoStyles(): void
    {
        $result = Terminal::style('Hello', []);
        $this->assertSame('Hello', $result);
    }

    public function testStyleWithBold(): void
    {
        $result = Terminal::style('Hello', ['bold' => true]);
        $this->assertStringContainsString("\x1b[", $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString("\x1b[0m", $result);
    }

    public function testStyleWithForegroundColor(): void
    {
        $result = Terminal::style('Hello', ['fg' => 'red']);
        $this->assertStringContainsString("\x1b[", $result);
        $this->assertStringContainsString('Hello', $result);
    }

    public function testStyleWithBackgroundColor(): void
    {
        $result = Terminal::style('Hello', ['bg' => 'blue']);
        $this->assertStringContainsString("\x1b[", $result);
        $this->assertStringContainsString('Hello', $result);
    }

    public function testStyleWithHexColor(): void
    {
        $result = Terminal::style('Hello', ['fg' => '#ff0000']);
        $this->assertStringContainsString('Hello', $result);
    }

    public function testStyleWithRgbArray(): void
    {
        $result = Terminal::style('Hello', ['fg' => [255, 0, 0]]);
        $this->assertStringContainsString('Hello', $result);
    }

    public function testStyleWithMultipleAttributes(): void
    {
        $result = Terminal::style('Hello', [
            'fg' => 'green',
            'bold' => true,
            'underline' => true,
        ]);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString("\x1b[0m", $result);
    }

    public function testDisplayWidthAscii(): void
    {
        $width = Terminal::displayWidth('Hello');
        $this->assertSame(5, $width);
    }

    public function testDisplayWidthUtf8(): void
    {
        $width = Terminal::displayWidth('Привет');
        $this->assertSame(6, $width);
    }

    public function testDisplayWidthStripAnsi(): void
    {
        $styled = "\x1b[31mHello\x1b[0m";
        $width = Terminal::displayWidth($styled);
        $this->assertSame(5, $width);
    }

    public function testProgressBarCreation(): void
    {
        ob_start();
        $bar = Terminal::progressBar(100, 'Test');
        $output = ob_get_clean();

        $this->assertInstanceOf(ProgressBar::class, $bar);
        $this->assertStringContainsString('0%', $output);
    }

    public function testLoaderCreation(): void
    {
        $loader = Terminal::loader('Loading...', 'dots');
        $this->assertInstanceOf(Loader::class, $loader);
    }

    public function testLoaderWithDifferentStyles(): void
    {
        $dots = Terminal::loader('Loading', 'dots');
        $line = Terminal::loader('Loading', 'line');
        $arrow = Terminal::loader('Loading', 'arrow');

        $this->assertInstanceOf(Loader::class, $dots);
        $this->assertInstanceOf(Loader::class, $line);
        $this->assertInstanceOf(Loader::class, $arrow);
    }
}

final class CommandTest extends TestCase
{
    public function testCommandSetName(): void
    {
        $cmd = new Command();
        $result = $cmd->setName('test');

        $this->assertSame($cmd, $result);
    }

    public function testCommandSetDescription(): void
    {
        $cmd = new Command();
        $result = $cmd->setDescription('A test command');

        $this->assertSame($cmd, $result);
    }

    public function testCommandAddArgument(): void
    {
        $cmd = new Command();
        $result = $cmd->addArgument('name', 'The name', true, 'default');

        $this->assertSame($cmd, $result);
    }

    public function testCommandAddOption(): void
    {
        $cmd = new Command();
        $result = $cmd->addOption('verbose', 'v', 'Verbose output', false);

        $this->assertSame($cmd, $result);
    }

    public function testCommandRunWithHelp(): void
    {
        $cmd = new Command();
        $cmd->setName('myapp')
            ->setDescription('My application')
            ->addArgument('input', 'Input file')
            ->addOption('output', 'o', 'Output file', true);

        ob_start();
        $result = $cmd->run(['--help']);
        $output = ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Description:', $output);
        $this->assertStringContainsString('My application', $output);
        $this->assertStringContainsString('input', $output);
        $this->assertStringContainsString('--output', $output);
    }

    public function testCommandRunWithArguments(): void
    {
        $cmd = new Command();
        $cmd->addArgument('name', 'Name argument', false, 'World');

        $cmd->run(['Hello']);

        $this->assertSame('Hello', $cmd->getArgument('name'));
    }

    public function testCommandRunWithOptions(): void
    {
        $cmd = new Command();
        $cmd->addOption('verbose', 'v', 'Verbose', false);

        $cmd->run(['-v']);

        $this->assertTrue($cmd->getOption('verbose'));
    }

    public function testCommandRunWithOptionValue(): void
    {
        $cmd = new Command();
        $cmd->addOption('output', 'o', 'Output file', true);

        $cmd->run(['--output=test.txt']);

        $this->assertSame('test.txt', $cmd->getOption('output'));
    }

    public function testCommandOutputMethods(): void
    {
        $cmd = new Command();

        ob_start();
        $cmd->info('Info message');
        $output = ob_get_clean();
        $this->assertStringContainsString('Info message', $output);

        ob_start();
        $cmd->success('Success message');
        $output = ob_get_clean();
        $this->assertStringContainsString('Success message', $output);
        $this->assertStringContainsString("\033[32m", $output);

        ob_start();
        $cmd->error('Error message');
        $output = ob_get_clean();
        $this->assertStringContainsString('Error message', $output);
        $this->assertStringContainsString("\033[31m", $output);

        ob_start();
        $cmd->warning('Warning message');
        $output = ob_get_clean();
        $this->assertStringContainsString('Warning message', $output);
        $this->assertStringContainsString("\033[33m", $output);

        ob_start();
        $cmd->comment('Comment message');
        $output = ob_get_clean();
        $this->assertStringContainsString('Comment message', $output);
        $this->assertStringContainsString("\033[2m", $output);
    }

    public function testCommandNewLine(): void
    {
        $cmd = new Command();

        ob_start();
        $cmd->newLine(3);
        $output = ob_get_clean();

        $this->assertSame("\n\n\n", $output);
    }
}
