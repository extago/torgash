<?php

// PsySH's settings for `artisan tinker` where it must not fork: FrankenPHP's
// `php-cli` has pcntl but not cli_set_process_title(), which PsySH's forked
// loop calls. Handed over by CoreServiceProvider::tinkerWithoutFork().
return ['usePcntl' => false];
