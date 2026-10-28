{{-- The heart as this visitor has it: a personal part of the article
     (PLAN §61), drawn alone — it asks the browser's own cookie whether they
     pressed it. --}}
@include('torgash.blog::partials.like', ['post' => $post, 'liked' => app(\Torgash\Blog\Likes::class)->has(request(), $post)])
