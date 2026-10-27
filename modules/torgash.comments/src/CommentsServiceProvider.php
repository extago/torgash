<?php

namespace Torgash\Comments;

use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Blocks\BlockTypes;

class CommentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // What it keeps about a person, for core's personal-data registry (PLAN §49).
        hook_listen('core.personal_data', fn (array $context) => CommentPersonalData::register($context['registry']));

        // The public form this module owns — named so a CAPTCHA can be put on
        // comments without being put on the checkout.
        // Its letter in core's registry (PLAN §41).
        hook_listen('core.mail.events', fn (array $events) => $events + \Torgash\Comments\Mail\CommentLetters::events());

        hook_listen('core.forms', fn (array $forms) => $forms + [
            Submit::FORM => 'Comments',
        ]);

        // The admin's bell: comments nobody has read yet — the same row
        // reviews add, so a moderator is told about both in one place.
        hook_listen('core.admin.notifications', function (array $rows, array $context) {
            if (! ($context['user'] ?? null)?->can('comments.moderate')) {
                return $rows;
            }

            return [...$rows, [
                'key' => 'comments',
                'label' => __('Comments to moderate'),
                'count' => Models\Comment::query()->whereNull('parent_id')->where('status', Models\Comment::PENDING)->count(),
                'url' => route('admin.comments.index'),
                'icon' => 'message-circle',
            ]];
        });

        Route::middleware(['web', 'auth', 'can:comments.moderate'])
            ->prefix('admin/comments')
            ->name('admin.comments.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        $this->blockType();

        hook_listen('api.resources', fn () => (new Api\CommentResources)->register());
    }

    /**
     * Comments are a BLOCK, not a section this module staples onto somebody
     * else's template.
     *
     * That is what decides where they appear: the merchant puts the block in
     * whichever zone they want it in — under a page's text today, under a
     * product tomorrow — and the block reads what it is commenting on from the
     * zone's context. Subscribing to another module's zone by name would have
     * meant this module knowing every template that might ever want comments,
     * and a switch on every one of them.
     */
    protected function blockType(): void
    {
        BlockTypes::register('comments', [
            'description' => 'Comments under the page, with the form to leave one.',
            'fits' => ['wide'],
            'label' => 'Comments',
            'icon' => 'message-square',
            'view' => 'torgash.comments::blocks.comments',
            'schema' => [
                [
                    'key' => 'heading',
                    'type' => 'text',
                    'translatable' => true,
                    'label' => 'Heading',
                    'help' => 'Empty for "Comments".',
                ],
                [
                    'key' => 'closed',
                    'type' => 'checkbox',
                    'default' => false,
                    'label' => 'Read only',
                    'help' => 'Shows what is already there and takes nothing new. Existing comments stay visible, which is the difference from removing the block.',
                ],
            ],
        ]);
    }
}
