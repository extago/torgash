<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Backups;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A backup is a file in somebody's storage, and restoring one runs it.
 *
 * Whoever can write to that bucket or SFTP account must not get the shop's
 * server out of it. psql obeys backslash commands wherever a statement could
 * begin, and the check by restoring runs unattended, so a line like `\! …` in a
 * swapped `database.sql` used to be a program started on a schedule. Two
 * defences, tested apart: the journal knows what was made, and psql is told to
 * take SQL only.
 */
class BackupTamperingTest extends TestCase
{
    protected const DATABASE = 'torgash_tamper_check';

    /** Forward slashes: quoted, cmd takes them too, and `\copy` reads no escapes in them. */
    protected string $proof;

    /** @var list<string> */
    protected array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Backups::toolVersion('psql') || ! Backups::toolVersion('pg_dump')) {
            $this->markTestSkipped('psql / pg_dump are not available on this machine.');
        }

        Storage::fake('local');

        $this->proof = str_replace('\\', '/', sys_get_temp_dir()).'/torgash-tamper-'.uniqid().'.txt';
        $this->freshDatabase();
    }

    protected function tearDown(): void
    {
        $this->superuser(fn ($pdo) => $pdo->exec('drop database if exists '.self::DATABASE.' with (force)'));
        @unlink($this->proof);

        foreach ($this->made as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_plain_dump_cannot_make_psql_do_anything_but_sql(): void
    {
        $proof = $this->proof;

        $ways = [
            'at the start of a line' => "\\! echo ran> \"$proof\"\n",
            // A filter of lines that begin with a backslash passes these two.
            'after a statement' => "SELECT 1; \\! echo ran> \"$proof\"\n",
            'indented' => "   \\! echo ran> \"$proof\"\n",
            'writing a file' => "\\o $proof\nSELECT 1;\n",
            'through copy' => "\\copy (select 1) to '$proof'\n",
            'guessing its way out' => "\\unrestrict abc\n\\! echo ran> \"$proof\"\n",
            // The one command \restrict leaves open. Before 18.6, 17.11, 16.15
            // and 15.19 psql evaluates its argument before it looks at the key
            // (CVE-2026-18408): these three ran on 18.3 when tried by hand.
            'through the key' => "SELECT 1; \\unrestrict `echo ran> \"$proof\"`\n",
            'through the key, at the start of a line' => "\\unrestrict `echo ran> \"$proof\"`\n",
            'through the key, misread as COPY data' => "/*\nCOPY x FROM stdin;\n*/\nSELECT 1; \\unrestrict `echo ran> \"$proof\"`\n\\.\n",
        ];

        foreach ($ways as $how => $sql) {
            $this->assertRefused('', fn () => app(Backups::class)->restore('x', self::DATABASE, false, null, false, $this->archive($sql)), $how);
            $this->assertFileDoesNotExist($this->proof, $how);
        }
    }

    /**
     * Inside COPY a line is somebody's text. `\.` and `\N` live there, a value
     * beginning with a carriage return is written `\r…`, one sentence in a
     * description can begin with the words the extension filter looks for, and
     * a customer who types `\unrestrict` must not make the backup unrestorable.
     */
    public function test_the_lines_of_copy_data_are_left_as_they_are(): void
    {
        app(Backups::class)->restore('x', self::DATABASE, false, null, false, $this->archive(implode("\n", [
            '\\restrict keyofthedump',
            'CREATE TABLE notes (v text);',
            'COPY public.notes (v) FROM stdin;',
            '\\restrict me',
            'DROP EXTENSION nothing',
            'CREATE EXTENSION IF NOT EXISTS nothing',
            'see \\\\unrestrict `no`',
            '\\N',
            '\\.',
            '',
            '\\unrestrict keyofthedump',
            '',
        ])));

        $values = $this->superuser(fn ($pdo) => $pdo->query('select v from notes order by ctid')->fetchAll(\PDO::FETCH_COLUMN), self::DATABASE);

        $this->assertSame(["\restrict me", 'DROP EXTENSION nothing', 'CREATE EXTENSION IF NOT EXISTS nothing', 'see \\unrestrict `no`', null], $values);
    }

    /** A real pg_dump's file, given by its path: nothing vouches for it, and it comes back. */
    public function test_a_real_plain_dump_named_by_its_path_restores(): void
    {
        $run = $this->makeRun($this->plan('zip', ['db_format' => 'plain']));
        $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torgash-tamper-'.uniqid().'.zip';
        $this->made[] = $copy;
        copy(Storage::disk('local')->path(Backups::DIR.'/'.$run->name), $copy);

        app(Backups::class)->restore(basename($copy), self::DATABASE, false, null, false, $copy);

        $tables = $this->superuser(fn ($pdo) => (int) $pdo->query("select count(*) from information_schema.tables where table_schema = 'public'")->fetchColumn(), self::DATABASE);
        $this->assertGreaterThan(50, $tables);
    }

    public function test_a_stray_unrestrict_never_reaches_psql(): void
    {
        $this->underPsql('psql (PostgreSQL) 18.6', $arguments);

        $this->assertRefused('pg_dump did not write', fn () => app(Backups::class)->restore('x', self::DATABASE, false, null, false, $this->archive("SELECT 1; \\unrestrict x\n")));
        $this->assertSame([], $arguments, 'psql was never handed the file');
    }

    /** What the shop made is not searched: a word in a function must not cost it its backup. */
    public function test_a_dump_the_journal_vouches_for_is_not_searched_for_the_word(): void
    {
        $run = $this->makeRun($this->plan('zip'));
        $file = Storage::disk('local')->path(Backups::DIR.'/'.$run->name);
        copy($this->archive("CREATE FUNCTION f() RETURNS text LANGUAGE sql AS \$\$ SELECT '\\unrestrict' \$\$;\n"), $file);
        $run->update(['sha256' => hash_file('sha256', $file)]);
        $this->underPsql('psql (PostgreSQL) 18.3', $arguments);

        app(Backups::class)->restore($run->name, self::DATABASE, false);

        $this->assertContains('-f', $arguments);
    }

    /** On S3 a name can be a file and a prefix at once; the journal says which this one is. */
    public function test_a_folder_laid_over_a_file_is_not_read_as_the_backup(): void
    {
        $run = $this->makeRun($this->plan('zip'));
        $path = Storage::disk('local')->path(Backups::DIR.'/'.$run->name);
        unlink($path);
        mkdir($path);
        file_put_contents($path.'/manifest.json', json_encode(['contents' => ['database'], 'database_format' => 'custom']));
        file_put_contents($path.'/database.sql', "SELECT 1;\n");
        $this->underPsql('psql (PostgreSQL) 18.3', $arguments);

        $checked = app(Backups::class)->verify($run);

        $this->assertSame('failed', $checked->verify_status);
        $this->assertSame([], $arguments, 'psql was never handed what lay in that folder');
    }

    /** The whole attack: nobody is there, and the shop is told. */
    public function test_a_backup_swapped_where_it_is_kept_fails_the_check_and_runs_nothing(): void
    {
        $run = $this->makeRun($this->plan('zip'));

        copy($this->archive("\\! echo ran> \"$this->proof\"\n"), Storage::disk('local')->path(Backups::DIR.'/'.$run->name));

        $checked = app(Backups::class)->verify($run);

        $this->assertSame('failed', $checked->verify_status);
        $this->assertStringContainsString('not the file that was made', (string) $checked->verify_error);
        $this->assertFileDoesNotExist($this->proof);
    }

    /** A dump in pg_dump's own format runs SQL too, and SQL can start programs for a superuser. */
    public function test_a_swapped_backup_is_refused_whatever_is_in_it(): void
    {
        $run = $this->makeRun($this->plan('zip'));

        $other = $this->makeRun($this->plan('zip'), '2026-10-02 03:40:00');
        copy(Storage::disk('local')->path(Backups::DIR.'/'.$other->name), Storage::disk('local')->path(Backups::DIR.'/'.$run->name));

        $this->assertRefused('not the file that was made', fn () => app(Backups::class)->restore($run->name, self::DATABASE, false));
    }

    /** A password protects nothing if the file can be swapped for one that has none. */
    public function test_an_encrypted_backup_cannot_be_swapped_for_a_plain_archive(): void
    {
        $run = $this->makeRun($this->plan('tar.gz', ['encrypt' => true], 'correct horse battery'));

        copy($this->archive("\\! echo ran> \"$this->proof\"\n"), Storage::disk('local')->path(Backups::DIR.'/'.$run->name));

        $this->assertRefused('not the file that was made', fn () => app(Backups::class)->restore($run->name, self::DATABASE, false));
        $this->assertFileDoesNotExist($this->proof);
    }

    public function test_a_folder_whose_dump_was_changed_is_refused(): void
    {
        $run = $this->makeRun($this->plan('folder', ['db_format' => 'plain']));

        $this->assertNotNull($run->sha256, 'a folder has no one file: the journal vouches for its dump');

        file_put_contents(Storage::disk('local')->path(Backups::DIR.'/'.$run->name.'/database.sql'), "\\! echo ran> \"$this->proof\"\n");

        $this->assertRefused('not the file that was made', fn () => app(Backups::class)->restore($run->name, self::DATABASE, false));
        $this->assertFileDoesNotExist($this->proof);
    }

    public function test_psql_is_given_no_startup_file_and_a_key_only_this_restore_knows(): void
    {
        $first = $this->underPsql('psql (PostgreSQL) 18.3', $arguments);

        app(Backups::class)->restore('x', self::DATABASE, false, null, false, $this->archive("SELECT 1;\n"));

        $this->assertMatchesRegularExpression('/^\\\\restrict [0-9a-f]{64}$/', trim((string) $first()));
        $this->assertContains('-X', $arguments);
    }

    public function test_a_psql_too_old_to_restrict_refuses_a_dump_nobody_vouches_for(): void
    {
        $this->underPsql('psql (PostgreSQL) 15.13 (Debian 15.13-1)', $arguments);

        $this->assertRefused('cannot be told to run SQL', fn () => app(Backups::class)->restore('x', self::DATABASE, false, null, false, $this->archive("SELECT 1;\n")));
        $this->assertSame([], $arguments, 'psql was never handed the file');
    }

    public function test_a_psql_too_old_to_restrict_still_restores_what_the_journal_vouches_for(): void
    {
        $run = $this->makeRun($this->plan('zip', ['db_format' => 'plain']));
        $first = $this->underPsql('psql (PostgreSQL) 15.13', $arguments);

        app(Backups::class)->restore($run->name, self::DATABASE, false);

        $this->assertContains('-f', $arguments);
        $this->assertStringNotContainsString('restrict', (string) $first());
    }

    public function test_which_psql_can_restrict(): void
    {
        $backups = app(Backups::class);
        $asks = new \ReflectionMethod($backups, 'psqlCanRestrict');

        foreach ([
            'psql (PostgreSQL) 15.13' => false,
            'psql (PostgreSQL) 15.14 (Debian 15.14-1.pgdg120+1)' => true,
            'psql (PostgreSQL) 16.9' => false,
            'psql (PostgreSQL) 16.10' => true,
            'psql (PostgreSQL) 17.5' => false,
            'psql (PostgreSQL) 17.6' => true,
            'psql (PostgreSQL) 18.0' => true,
            'psql (PostgreSQL) 19beta1' => true,
            'nonsense' => false,
        ] as $version => $can) {
            Process::fake(['*' => Process::result($version)]);

            $this->assertSame($can, $asks->invoke($backups), $version);
        }
    }

    // ------------------------------------------------------------------

    /**
     * Stands in for the PostgreSQL tools. `$arguments` is filled with what the
     * last run with a file was given; the closure returned reads that file's
     * first line, kept before the restore deletes it.
     */
    protected function underPsql(string $version, ?array &$arguments): \Closure
    {
        $arguments = [];
        $line = null;

        Process::fake(function (PendingProcess $process) use ($version, &$arguments, &$line) {
            $command = (array) $process->command;

            if (in_array('--version', $command, true)) {
                return Process::result($version);
            }

            if (($at = array_search('-f', $command, true)) !== false) {
                $arguments = $command;
                $line = strtok((string) file_get_contents($command[$at + 1]), "\n");
            }

            return Process::result();
        });

        return function () use (&$line) {
            return $line;
        };
    }

    protected function archive(string $sql): string
    {
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torgash-tamper-'.uniqid().'.zip';
        $this->made[] = $file;

        $zip = new \ZipArchive;
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['contents' => ['database'], 'database_format' => 'custom']));
        $zip->addFromString('database.sql', $sql);
        $zip->close();

        return $file;
    }

    protected function plan(string $format, array $options = [], string $password = ''): BackupPlan
    {
        static $n = 0;
        $n++;

        $plan = new BackupPlan([
            'name' => 'Tamper '.$n, 'code' => 'tamper'.$n, 'contents' => ['database'], 'schedule' => [],
            'name_template' => 't'.$n.'-{date}-{time}',
        ]);
        $plan->format = $format;
        $plan->options = $options + ['db_format' => 'custom', 'level' => 'fast'];
        $plan->putPassword($password);
        $plan->save();
        $plan->destinations()->attach(app(Backups::class)->systemDestination()->id, ['keep' => json_encode(['last' => 5])]);

        return $plan;
    }

    protected function makeRun(BackupPlan $plan, string $at = '2026-10-01 03:40:00'): BackupRun
    {
        \Illuminate\Support\Carbon::setTestNow($at);

        try {
            $run = app(Backups::class)->runNow($plan);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }

        $this->assertSame(BackupRun::DONE, $run->status, (string) $run->error);

        return $run;
    }

    protected function assertRefused(string $part, \Closure $do, string $how = ''): void
    {
        try {
            $do();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($part, $e->getMessage(), $how);

            return;
        }

        $this->fail('Restored, and should have been refused. '.$how);
    }

    protected function freshDatabase(): void
    {
        $this->superuser(function ($pdo) {
            $pdo->exec('drop database if exists '.self::DATABASE.' with (force)');
            $pdo->exec('create database '.self::DATABASE." template template0 encoding 'UTF8'");
        });
    }

    /** A plain PDO as the suite's own (super)user — outside the test's transaction. */
    protected function superuser(\Closure $do, string $database = 'postgres'): mixed
    {
        $c = config('database.connections.pgsql');
        $pdo = new \PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$database}", $c['username'], (string) $c['password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $do($pdo);
    }
}
