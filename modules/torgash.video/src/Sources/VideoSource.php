<?php

namespace Torgash\Video\Sources;

/**
 * A platform a video can play on. The registry (`Sources`) asks each in turn
 * whether it recognises an address; the first that does keeps the video.
 *
 * A new platform is one class answering `video.sources` from any module —
 * nothing here, in core or in the catalogue changes. What a source stores is
 * its own business (`ref`, a small map); what it hands back is an address the
 * engine draws its own <iframe> around, never markup.
 */
interface VideoSource
{
    /** The word stored in `m_video_videos.source`. Never change it once videos exist. */
    public function key(): string;

    /**
     * What this platform needs to find the video, read off an address — the
     * page's, the player's, or the `src` of pasted embed code — or null when
     * the address is not this platform's.
     *
     * @return array<string, scalar>|null
     */
    public function parse(string $url): ?array;

    /** The player's address, set to start playing: it is loaded by a press of "play" and nothing else. */
    public function playerUrl(array $ref): string;

    /** Who plays it, for a person: "YouTube", or the host of somebody's embed. */
    public function platform(array $ref): string;

    /**
     * What the platform says about the video, as far as it will say it:
     * `title`, `poster` (an image address, or several to try in order),
     * `duration` (seconds). Best effort — a platform that answers nothing
     * gives an empty array, and the video is added without them.
     *
     * @return array{title?: string, poster?: string|list<string>, duration?: int}
     */
    public function details(array $ref, string $url): array;

    /**
     * Whether the player is put in a sandbox. True for code from a site the
     * engine knows nothing about; the platforms it does know break inside
     * one (fullscreen, their own consent windows).
     */
    public function sandboxed(): bool;
}
