<?php

namespace Torgash\GoogleDrive;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Google Drive as a kind of backup storage (PLAN §67.1, the first one that
 * is not core's): registered on `core.backup.destinations`, drawn and opened
 * by core's own backup screens. The Google Cloud client (ID and secret) is
 * the shop's, typed once in the module's settings; the Google ACCOUNT is
 * connected per storage, so two storages can be two accounts.
 */
class GoogleDriveServiceProvider extends ServiceProvider
{
    public const ID = 'torgash.google-drive';

    public const DRIVER = 'google_drive';

    public function boot(): void
    {
        // The object is made on every call and captures nothing: a listener
        // outlives the request under a worker (CLAUDE.md on memos).
        hook_listen('core.backup.destinations', fn (array $kinds) => $kinds + [self::DRIVER => new GoogleDriveDestination]);

        Route::middleware(['web', 'auth', 'can:system.manage'])
            ->prefix('admin/google-drive')
            ->name('admin.google-drive.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // The address Google must be told, beside the field it belongs to:
        // the manifest cannot know the shop's domain.
        hook_listen('core.settings.fields', function (array $fields, array $context) {
            if (($context['module'] ?? null) !== self::ID) {
                return $fields;
            }

            return array_map(function (array $field) {
                if (($field['key'] ?? null) === 'client_id') {
                    $field['help'] = __($field['help']).' '.__('The redirect URI to register: :url', ['url' => route('admin.google-drive.callback')]);
                }

                return $field;
            }, $fields);
        });

        hook_listen('core.settings.saving', function (array $refusals, array $context) {
            if (($context['module'] ?? null) !== self::ID) {
                return $refusals;
            }

            $id = trim((string) ($context['values']['client_id'] ?? ''));
            if ($id !== '' && ! str_ends_with($id, '.apps.googleusercontent.com')) {
                $refusals['client_id'] = __('A Google client ID ends with .apps.googleusercontent.com — this is something else.');
            }

            return $refusals;
        });
    }
}
