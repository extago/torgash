<?php

namespace Torgash\Blog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Torgash\Blog\Models\Post;

/**
 * A like per browser (PLAN §47, the owner's choice): no account needed — an
 * article is not money and not a review — but one browser is one like, a
 * second press takes it back, and what looks like a crawler counts nothing.
 *
 * The browser is known by a long-lived random cookie of its own; only its
 * HMAC is stored, so the table says nothing about anybody. `likes_count` on
 * the post is kept beside the rows, because a feed of twelve cards must not
 * count twelve times.
 */
class Likes
{
    public const COOKIE = 'torgash_reader';

    public function key(Request $request): string
    {
        $raw = (string) $request->cookie(self::COOKIE, '');

        if (! preg_match('/^[a-z0-9]{32}$/', $raw)) {
            $raw = Str::lower(Str::random(32));
            $request->cookies->set(self::COOKIE, $raw);
            Cookie::queue(self::COOKIE, $raw, 60 * 24 * 730);
        }

        return substr(hash_hmac('sha256', 'blog-reader:'.$raw, (string) config('app.key')), 0, 40);
    }

    public function has(Request $request, Post $post): bool
    {
        $raw = (string) $request->cookie(self::COOKIE, '');

        return $raw !== '' && DB::table('m_blog_likes')->where('post_id', $post->id)->where('visitor', $this->key($request))->exists();
    }

    /** Like, or take it back. Returns whether this browser likes it now. */
    public function toggle(Request $request, Post $post): bool
    {
        if (app(\Torgash\Catalog\Support\Popularity::class)->isBot($request)) {
            return false;
        }

        $visitor = $this->key($request);

        return DB::transaction(function () use ($post, $visitor) {
            $deleted = DB::table('m_blog_likes')->where('post_id', $post->id)->where('visitor', $visitor)->delete();

            if (! $deleted) {
                DB::table('m_blog_likes')->insertOrIgnore(['post_id' => $post->id, 'visitor' => $visitor, 'created_at' => now()]);
            }

            // The counter is the rows, recounted — never drifts from them.
            DB::table('m_blog_posts')->where('id', $post->id)->update([
                'likes_count' => DB::table('m_blog_likes')->where('post_id', $post->id)->count(),
            ]);

            return ! $deleted;
        });
    }
}
