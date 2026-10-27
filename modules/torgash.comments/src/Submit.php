<?php

namespace Torgash\Comments;

use App\Core\Support\FormGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Torgash\Comments\Models\Comment;

/**
 * The one place a comment is created — the same shape as the enquiries
 * module's Submit and PlaceOrder for an order, and for the same reason: the
 * guard, the moderation decision and the notification must not have a second
 * spelling anywhere.
 */
class Submit
{
    /** The form's name, so the rate limiter counts it apart from the enquiry forms. */
    public const FORM = 'comment';

    public function __construct(protected FormGuard $guard) {}

    /**
     * @throws ValidationException
     */
    public function handle(Request $request, string $targetType, int $targetId): Comment
    {
        abort_unless(CommentTargets::exists($targetType), 404);
        abort_unless(CommentTargets::resolve($targetType, $targetId) !== null, 404);

        $user = $request->user();

        if (setting('torgash.comments', 'registered_only') && ! $user) {
            throw ValidationException::withMessages([
                'comment' => __('Please sign in to leave a comment.'),
            ]);
        }

        $identity = $user?->email ?: (string) $request->input('author_email');

        // Honeypot, form age and the rate limit, counted per form — the same
        // guard the enquiry forms use, because a public POST that writes rows
        // is the most reliably abused thing a shop puts online.
        if ($refusal = $this->guard->check($request, $identity ?: null, self::FORM)) {
            throw ValidationException::withMessages(['comment' => $refusal['message'] ?? __('Please try again in a moment.')]);
        }

        $data = Validator::make($request->all(), [
            // A name is required of a guest and ignored from an account: a
            // signed-in author must not be able to sign as somebody else.
            'author_name' => $user ? 'nullable|string|max:120' : 'required|string|max:120',
            'author_email' => 'nullable|email|max:190',
            'body' => 'required|string|max:5000',
            'parent_id' => 'nullable|integer',
        ])->validate();

        $parent = $this->parent($data['parent_id'] ?? null, $targetType, $targetId);

        $comment = Comment::create([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'parent_id' => $parent?->id,
            'user_id' => $user?->id,
            'author_name' => $user ? ($user->name ?: __('Customer')) : $data['author_name'],
            'author_email' => $user?->email ?: ($data['author_email'] ?? null),
            'body' => $data['body'],
            'status' => setting('torgash.comments', 'premoderate', true) ? Comment::PENDING : Comment::APPROVED,
            'storefront_id' => current_storefront()?->id,
        ]);

        $this->guard->record($request, $identity ?: null, self::FORM);

        // The module tells the shop itself rather than leaving it to a rule: a
        // comment waiting for moderation that nobody knows about is exactly
        // what makes a merchant conclude the feature does not work.
        Notify::about($comment);

        hook_action('comments.comment.created', ['comment' => $comment]);

        return $comment;
    }

    /**
     * The comment being replied to — and only a REAL one: approved, on this
     * same target, and not itself a reply. Without those three checks a POST
     * could staple its text under any row in the table.
     */
    protected function parent(mixed $parentId, string $targetType, int $targetId): ?Comment
    {
        if (! $parentId) {
            return null;
        }

        return Comment::approved()
            ->for($targetType, $targetId)
            ->whereNull('parent_id')
            ->find((int) $parentId);
    }
}
