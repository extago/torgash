<?php

namespace App\Core\Verification;

/**
 * Every way a code can travel: core's email plus whatever modules answer
 * `core.verification.channels` with (`['key' => Channel]`). Asked lazily —
 * provider boot order is not ours to depend on.
 */
class Channels
{
    /** @return array<string, Channel> */
    public function all(): array
    {
        $channels = ['email' => app(EmailChannel::class)];

        foreach ((array) hook_filter('core.verification.channels', []) as $key => $channel) {
            if ($channel instanceof Channel && ! isset($channels[$key])) {
                $channels[(string) $key] = $channel;
            }
        }

        return $channels;
    }

    public function get(string $key): ?Channel
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Whether anything can carry a code to this kind of address at all —
     * what decides if a form takes a phone, and if the profile offers one.
     */
    public function offers(string $kind): bool
    {
        foreach ($this->all() as $channel) {
            if (in_array($kind, $channel->kinds(), true) && $channel->available()) {
                return true;
            }
        }

        return false;
    }

    /**
     * What can deliver to this address now, in the shop's order.
     *
     * @return array<string, Channel>
     */
    public function for(string $kind, string $address): array
    {
        return array_filter(
            $this->all(),
            fn (Channel $channel) => in_array($kind, $channel->kinds(), true)
                && $channel->available()
                && $channel->supports($kind, $address),
        );
    }
}
