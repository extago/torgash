{{-- "Share" (PLAN §47): core's <x-share> — the networks are the shop's own
     list (Settings → core, "Share to"), the same for every page that shares. --}}
<x-share :url="$post->url()" :title="(string) $post->t('title')" :label="__('Share the article')" />
