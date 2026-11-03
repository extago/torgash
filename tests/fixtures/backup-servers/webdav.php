<?php

// A WebDAV server for the backup tests, run by `php -S` (BackupStoragesTest).
// Root folder and login from the environment; sabre/dav is the client's own
// library, so it is already in vendor/.

require getenv('TORGASH_VENDOR').'/autoload.php';

$server = new Sabre\DAV\Server(new Sabre\DAV\FS\Directory(getenv('DAV_ROOT')));
$server->setBaseUri('/');

$server->addPlugin(new Sabre\DAV\Auth\Plugin(new Sabre\DAV\Auth\Backend\BasicCallBack(
    fn (string $user, string $password) => $user === getenv('DAV_USER') && $password === getenv('DAV_PASSWORD'),
)));

$server->start();
