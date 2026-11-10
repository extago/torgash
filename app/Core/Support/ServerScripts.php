<?php

namespace App\Core\Support;

/**
 * File names a web server may hand to an interpreter, or read as its own
 * configuration. One list: the module that refuses an upload and the module
 * that goes looking for such a file afterwards have to mean the same thing.
 */
final class ServerScripts
{
    public const EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtml', 'phar',
        'htaccess', 'cgi', 'pl', 'py', 'sh',
    ];
}
