<?php

namespace App\Core\Backups;

use App\Models\BackupDestination;

/**
 * A kind of backup storage a MODULE adds (PLAN §67.1): Google Drive, Dropbox,
 * OneDrive — whatever signs in with OAuth or speaks an API of its own. Core
 * keeps the folder, S3, SFTP, FTP and WebDAV kinds; a module registers its
 * own on the `core.backup.destinations` filter, keyed by driver name:
 *
 *     hook_listen('core.backup.destinations',
 *         fn (array $kinds) => $kinds + ['google_drive' => new GoogleDriveDestination]);
 *
 * The listener makes the object anew on every call and captures nothing: a
 * worker serves request after request, and a memo captured at boot would
 * serve the next shop's request the last one's state.
 *
 * The screen is core's: it draws the kind's card around `form()`, the list's
 * "Where" column from `where()`, and the proxy fields when `proxied()` says
 * the kind can take one. Secrets (tokens, passwords) go in the row's
 * encrypted `secrets`; everything else in `settings`.
 */
interface Destination
{
    /** The kind's English name — "Google Drive" — translated by lang files. */
    public function label(): string;

    /** The storage opened: a filesystem rooted at the destination's folder. */
    public function open(BackupDestination $destination): Store;

    /**
     * The kind's form fields checked: [settings, secrets, errors], the same
     * shape as `Destinations::fromInput()`. `$input` is the form's
     * `settings[…]` array; a secret left blank keeps the stored one
     * (`$existing->secrets()`), and an error is keyed by field.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    public function fromInput(array $input, ?BackupDestination $existing = null): array;

    /**
     * The view drawn INSIDE the kind's card on the storage form, with
     * `$destination`, `$value(key, default)` (old input or the stored
     * setting) and `$kept(key)` (the "kept — type to change" placeholder of
     * a stored secret). It is inside the storage's own <form>: never a
     * <form> of its own — a button that must go elsewhere says
     * `formaction` (docs/LESSONS.md on nested forms).
     */
    public function form(): string;

    /** One line for the list's "Where" column: the account, the folder. */
    public function where(BackupDestination $destination): string;

    /** Whether the kind can reach its service through the proxy fields. */
    public function proxied(): bool;
}
