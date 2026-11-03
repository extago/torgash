<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;

/**
 * A backup plan (PLAN §67.2): WHAT goes in, WHEN, in what FORM, and the
 * destinations it is kept in — each with its own "how many to keep".
 */
class BackupPlan extends Model
{
    public const CONTENTS = ['database', 'files', 'code'];

    /** zip, tar, tar.gz, or a folder — "without an archive" (§67.3). */
    public const FORMATS = ['zip', 'tar', 'tar.gz', 'folder'];

    /** Compression levels: zip's deflate and gzip alike. */
    public const LEVELS = ['fast' => 1, 'normal' => 6, 'max' => 9];

    protected $guarded = ['id', 'secret'];

    protected $hidden = ['secret'];

    protected $casts = [
        'enabled' => 'boolean',
        'contents' => 'array',
        'schedule' => 'array',
        'options' => 'array',
    ];

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    /** The encryption password, '' when none (or this server's APP_KEY cannot read it). */
    public function password(): string
    {
        if (! $this->secret) {
            return '';
        }

        try {
            return (string) Crypt::decryptString($this->secret);
        } catch (\Throwable) {
            return '';
        }
    }

    public function passwordLost(): bool
    {
        return (bool) $this->secret && $this->password() === '';
    }

    public function putPassword(string $password): void
    {
        $this->secret = $password === '' ? null : Crypt::encryptString($password);
    }

    /** Whether the backups it makes now are encrypted: a folder never is. */
    public function encrypts(): bool
    {
        return $this->format !== 'folder' && (bool) $this->option('encrypt') && $this->password() !== '';
    }

    public function level(): int
    {
        return self::LEVELS[$this->option('level', 'normal')] ?? 6;
    }

    /** The file's extension; '' for a folder. */
    public function extension(): string
    {
        return match ($this->format) {
            'folder' => '',
            'tar', 'tar.gz' => $this->format.($this->encrypts() ? '.enc' : ''),
            default => 'zip',
        };
    }

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(BackupDestination::class, 'backup_plan_destination', 'plan_id', 'destination_id')
            ->withPivot('keep')->orderBy('backup_destinations.id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class, 'plan_id');
    }

    public function lastRun(): HasOne
    {
        return $this->hasOne(BackupRun::class, 'plan_id')->whereIn('status', [BackupRun::DONE, BackupRun::PARTIAL, BackupRun::FAILED])->latestOfMany('id');
    }

    public function has(string $part): bool
    {
        return in_array($part, $this->contents ?? [], true);
    }
}
