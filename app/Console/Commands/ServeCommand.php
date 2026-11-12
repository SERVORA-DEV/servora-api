<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand as LaravelServeCommand;
use Symfony\Component\Console\Attribute\AsCommand;

// `php artisan serve`, with PHP's code cache (OPcache) switched on for the
// server it starts.
//
// Without it PHP re-reads and re-compiles every Laravel file on every
// request — about half a second each on this project. A stock XAMPP install
// ships the extension but leaves it off, and switching it on normally means
// editing the machine's php.ini. This passes the same settings on the command
// line instead, so they apply to this one server process only.
//
// Local only, and only when OPcache isn't on already. Files are still checked
// for changes on every request (revalidate_freq=0), so editing code works as
// before.
//
// Also on Windows XAMPP: browser pop-ups (Web Push, App\Service\PushSender) need
// PHP to find openssl.cnf, which stock XAMPP only does when OPENSSL_CONF points
// at it BEFORE the server process starts (setting it later has no effect). So the
// server this starts is given it, when the file is there and nothing set it already.
#[AsCommand(name: 'serve')]
class ServeCommand extends LaravelServeCommand
{
    private const OPCACHE = [
        'zend_extension=opcache',
        'opcache.enable=1',
        'opcache.enable_cli=1',
        'opcache.validate_timestamps=1',
        'opcache.revalidate_freq=0',
        'opcache.memory_consumption=192',
        'opcache.max_accelerated_files=20000',
    ];

    protected function startProcess($hasEnvironment)
    {
        $this->giveServerOpensslConfig();

        return parent::startProcess($hasEnvironment);
    }

    // OPENSSL_CONF for the server process (and kept through the .env reload filter).
    private function giveServerOpensslConfig(): void
    {
        if (! $this->laravel->environment('local') || PHP_OS_FAMILY !== 'Windows' || getenv('OPENSSL_CONF')) {
            return;
        }

        $config = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        if (! is_file($config)) {
            return;
        }

        putenv('OPENSSL_CONF=' . $config);
        $_ENV['OPENSSL_CONF'] = $config;
        if (! in_array('OPENSSL_CONF', static::$passthroughVariables, true)) {
            static::$passthroughVariables[] = 'OPENSSL_CONF';
        }
    }

    protected function serverCommand()
    {
        $command = parent::serverCommand();

        if (! $this->canSwitchOnOpcache()) {
            return $command;
        }

        $php = array_shift($command);
        $flags = [];
        foreach (self::OPCACHE as $setting) {
            array_push($flags, '-d', $setting);
        }

        return [$php, ...$flags, ...$command];
    }

    private function canSwitchOnOpcache(): bool
    {
        if (! $this->laravel->environment('local') || extension_loaded('Zend OPcache')) {
            return false;
        }

        // The extension has to be there to load: php_opcache.dll on Windows,
        // opcache.so elsewhere, in PHP's extension folder.
        $dir = (string) ini_get('extension_dir');
        if ($dir !== '' && ! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir)) {
            $dir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $dir;
        }

        return is_file($dir . DIRECTORY_SEPARATOR . 'php_opcache.dll') || is_file($dir . DIRECTORY_SEPARATOR . 'opcache.so');
    }
}
