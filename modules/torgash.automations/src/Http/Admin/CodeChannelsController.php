<?php

namespace Torgash\Automations\Http\Admin;

use App\Core\Verification\SendResult;
use App\Core\Verification\Verifier;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Automations\Codes\HttpCodeChannel;
use Torgash\Automations\Codes\Presets;
use Torgash\Automations\Models\CodeChannel;

/**
 * Admin → Code delivery: the HTTP requests that carry a verification code
 * (PLAN §44.3). A preset fills the form and is forgotten; the row is what is
 * sent. Secrets are typed as `name=value` lines and never drawn back — the
 * box shows the names with an empty value, and an empty value on save keeps
 * the stored one (the rule of every credential form in this engine).
 *
 * Two buttons that spend nothing or one message: "Check the connection" runs
 * the channel's free request (a balance), "Send a test code" goes through the
 * real Verifier — its limits, its log, and the channel's day budget.
 */
class CodeChannelsController extends Controller
{
    public function index()
    {
        $channels = CodeChannel::orderBy('position')->orderBy('id')->get();

        return view('torgash.automations::admin.code-channels.index', [
            'channels' => $channels,
            'today' => $channels->mapWithKeys(fn (CodeChannel $c) => [$c->id => (new HttpCodeChannel($c))->sentToday()]),
            'presets' => Presets::options(),
        ]);
    }

    public function create(Request $request)
    {
        $preset = Presets::all()[$request->query('preset', 'blank')] ?? Presets::all()['blank'];
        $channel = new CodeChannel(['preset' => $request->query('preset', 'blank'), 'daily_limit' => 50, 'enabled' => false]
            + collect($preset)->except('secrets')->all());

        return view('torgash.automations::admin.code-channels.form', [
            'channel' => $channel,
            'secretsText' => $preset['secrets'] ?? '',
            'presets' => Presets::options(),
        ]);
    }

    public function store(Request $request)
    {
        $channel = new CodeChannel;
        $this->fill($channel, $request);
        if (! $request->filled('position')) {
            $channel->position = (int) CodeChannel::max('position') + 1;
        }
        $channel->save();

        return $this->afterSave($request, $channel);
    }

    /** Back to the same tab, or to the list for "Save and close" — the admin's form shape. */
    protected function afterSave(Request $request, CodeChannel $channel)
    {
        if ($request->input('after_save') === 'close') {
            return redirect()->route('admin.automations.channels.index')->with('status', __('Channel saved.'));
        }

        return redirect()->route('admin.automations.channels.edit', ['channel' => $channel, 'tab' => $request->input('tab')])
            ->with('status', __('Channel saved.'));
    }

    public function edit(CodeChannel $channel)
    {
        return view('torgash.automations::admin.code-channels.form', [
            'channel' => $channel,
            'secretsText' => collect((array) $channel->secrets)->keys()->map(fn ($name) => $name.'=')->implode("\n"),
            'presets' => Presets::options(),
            'check' => session('check'),
            'sentToday' => (new HttpCodeChannel($channel))->sentToday(),
        ]);
    }

    public function update(Request $request, CodeChannel $channel)
    {
        $this->fill($channel, $request);
        $channel->save();

        return $this->afterSave($request, $channel);
    }

    /** The list's badge, switched in place. */
    public function setStatus(Request $request, CodeChannel $channel)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $channel->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.automations::admin.code-channels._status', ['channel' => $channel]);
    }

    public function destroy(CodeChannel $channel)
    {
        $channel->delete();

        return redirect()->route('admin.automations.channels.index')->with('status', __('Channel deleted.'));
    }

    public function check(CodeChannel $channel)
    {
        return back()->with('check', (new HttpCodeChannel($channel))->check());
    }

    /**
     * One real code to a number the admin types — through Verifier::start, so
     * it is limited, logged and counted against the channel's day like any
     * other; the channel need not be switched on for its own test.
     */
    public function test(Request $request, CodeChannel $channel, Verifier $verifier)
    {
        $data = $request->validate(['address' => ['required', 'string', 'max:255']]);
        $kind = in_array('phone', (array) $channel->kinds, true) ? 'phone' : 'email';

        $wasEnabled = $channel->enabled;
        $channel->enabled = true;
        hook_listen('core.verification.channels', fn (array $all) => [$channel->key() => new HttpCodeChannel($channel)] + $all, 1);

        $started = $verifier->start($request, 'channel_test', $kind, $data['address'], $channel->key(), $request->user());
        $channel->enabled = $wasEnabled;

        if ($started['error']) {
            return back()->with('error', $started['error']);
        }

        $row = $started['code'];

        return $row->status === 'sent'
            ? back()->with('status', __('Sent — the phone should get it within a minute. The log has the provider\'s answer.'))
            : back()->with('error', __('Not sent (:status): :error', [
                'status' => match ($row->status) {
                    SendResult::INVALID_ADDRESS => __('Wrong address'),
                    SendResult::UNREACHABLE => __('Could not reach'),
                    default => __('Failed to send'),
                },
                'error' => (string) $row->error,
            ]));
    }

    protected function fill(CodeChannel $channel, Request $request): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'preset' => ['nullable', 'string', 'max:40'],
            'kinds' => ['required', 'array', 'min:1'],
            'kinds.*' => [Rule::in(['phone', 'email'])],
            'method' => ['required', Rule::in(['GET', 'POST', 'PUT'])],
            'url' => ['required', 'string', 'max:2000'],
            'headers' => ['nullable', 'string', 'max:4000'],
            'body_format' => ['required', Rule::in(['json', 'form', 'none'])],
            'body' => ['nullable', 'string', 'max:8000'],
            'success_when' => ['nullable', 'string', 'max:2000'],
            'invalid_when' => ['nullable', 'string', 'max:2000'],
            'unreachable_when' => ['nullable', 'string', 'max:2000'],
            'check_url' => ['nullable', 'string', 'max:2000'],
            'secrets' => ['nullable', 'string', 'max:4000'],
            'countries' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z\s,;]*$/'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:100000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $secrets = (array) ($channel->secrets ?? []);
        $typed = [];

        foreach (preg_split('/\R/', (string) ($data['secrets'] ?? '')) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);

            if (preg_match('/^[a-z0-9_]+$/i', $name)) {
                // Blank keeps what is stored; a name left out of the box is removed.
                $typed[$name] = trim($value) === '' ? ($secrets[$name] ?? '') : trim($value);
            }
        }

        $channel->fill([
            ...collect($data)->except(['secrets', 'enabled', 'position'])->all(),
            ...($request->filled('position') ? ['position' => (int) $data['position']] : []),
            'countries' => strtoupper(trim((string) ($data['countries'] ?? ''))),
            'enabled' => $request->boolean('enabled'),
        ]);
        $channel->secrets = $typed;
    }
}
