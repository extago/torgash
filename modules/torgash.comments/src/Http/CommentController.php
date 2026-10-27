<?php

namespace Torgash\Comments\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Comments\Models\Comment;
use Torgash\Comments\Submit;

class CommentController extends Controller
{
    /**
     * One POST for every comment anywhere. What it is about comes from the
     * form and is CHECKED against the registry — a POST cannot address a table
     * this module has never heard of, and cannot comment on a page that does
     * not exist.
     *
     * The answer is a redirect back with a flash rather than JSON: the form is
     * a plain form, works with JavaScript off, and the visitor has to be told
     * whether their comment is live or waiting for moderation — which is the
     * one thing a silent 204 would hide.
     */
    public function store(Request $request, Submit $submit)
    {
        $comment = $submit->handle(
            $request,
            (string) $request->input('target_type', ''),
            (int) $request->input('target_id', 0),
        );

        return back()->with('status', $comment->status === Comment::APPROVED
            ? __('Thank you — your comment is published.')
            : __('Thank you — your comment will appear once it has been checked.'));
    }
}
