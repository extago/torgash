<?php

namespace Torgash\Automations\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Automations\Telegram\Bot;
use Torgash\Automations\Telegram\Listening;
use Torgash\Automations\Telegram\Updates;

/**
 * Automations → Telegram (PLAN §64.1): who presses the buttons, and how the
 * presses reach the shop.
 *
 * A staff member ties THEIR Telegram account to their shop account here: a
 * code shown once, sent to the bot. Nobody links another person — the code is
 * for whoever is signed in. Below, each bot that sends buttons and the way it
 * is heard from.
 */
class TelegramController extends Controller
{
    public function index(Request $request)
    {
        $bots = Listening::bots();
        $links = DB::table('m_automations_telegram_users')->get()->keyBy('user_id');

        return view('torgash.automations::admin.telegram', [
            'bots' => $bots,
            'mine' => $links[$request->user()->id] ?? null,
            'linked' => User::whereIn('id', $links->keys())->orderBy('name')->get()
                ->map(fn (User $user) => ['user' => $user, 'telegram' => (string) $links[$user->id]->telegram_name]),
            'code' => session('telegram_code'),
            'reachable' => Listening::reachable(),
        ]);
    }

    /** A fresh code for whoever is signed in. */
    public function code(Request $request)
    {
        return back()->with('telegram_code', Updates::linkCode($request->user()));
    }

    /** Take a link away — one's own, or anybody's for whoever manages the automations. */
    public function unlink(Request $request, User $user)
    {
        abort_unless($request->user()->id === $user->id || $request->user()->can('automations.manage'), 403);

        DB::table('m_automations_telegram_users')->where('user_id', $user->id)->delete();

        return back()->with('status', __('Unlinked: buttons pressed from that Telegram account do nothing now.'));
    }

    public function mode(Request $request, Bot $bot)
    {
        $webhook = $request->input('mode') === Bot::WEBHOOK;

        if ($webhook && ! Listening::reachable()) {
            return back()->withErrors(['mode' => __('Telegram cannot call this shop: its address has to be public and https. Polling works everywhere.')]);
        }

        try {
            $problem = $webhook ? Listening::useWebhook($bot) : Listening::usePolling($bot);
        } catch (\Throwable $e) {
            $problem = $bot->mask($e->getMessage());
        }

        return $problem
            ? back()->withErrors(['mode' => __('Telegram refused: :why', ['why' => $problem])])
            : back()->with('status', $webhook ? __('Telegram now calls the shop itself.') : __('The shop now asks Telegram itself.'));
    }
}
