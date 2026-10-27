<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\Frame;
use App\Core\Mail\Letters;
use App\Core\Mail\MailEvents;
use App\Core\Mail\MailServer;
use App\Core\Settings\SettingsManager;
use App\Http\Controllers\Controller;
use App\Models\MailText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin → Letters (PLAN §41): every letter the shop sends, grouped by who
 * reads it; the words of one, per content language, with a preview on real
 * data and "send it to me"; and the frame they all share. What is typed is
 * only what DIFFERS from the default — an emptied field goes back to the
 * module's own words in that language.
 */
class LettersController extends Controller
{
    public function index()
    {
        // A letter whose words are its sender's own (a newsletter campaign) has no page here.
        $events = array_filter(MailEvents::all(), fn (array $event) => $event['editable']);
        $rows = MailText::whereIn('event', array_keys($events))->get()->keyBy('event');

        $groups = [];
        foreach ($events as $key => $event) {
            $groups[$event['audience']][__($event['group'])][$key] = $event;
        }
        ksort($groups);

        return view('admin.letters.index', [
            'groups' => $groups,
            'rows' => $rows,
            'frame' => Frame::settings(),
            'server' => MailServer::settings(),
            'serverCheck' => session('server_check'),
            'envMailer' => (string) config('mail.default').' · '.(string) config('mail.mailers.smtp.host').':'.(string) config('mail.mailers.smtp.port'),
        ]);
    }

    public function edit(string $event)
    {
        $definition = $this->definition($event);

        return view('admin.letters.edit', [
            'event' => $event,
            'definition' => $definition,
            'row' => Letters::row($event) ?? new MailText(['event' => $event, 'enabled' => true]),
            'locale' => admin_content_locale(),
            'hasSample' => $this->sample($definition) !== null,
        ]);
    }

    public function update(Request $request, string $event)
    {
        $definition = $this->definition($event);
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'heading' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'button' => ['nullable', 'string', 'max:100'],
            'recipients' => ['nullable', 'string', 'max:2000'],
        ]);

        $row = Letters::row($event) ?? new MailText(['event' => $event]);
        $this->fill($row, $data, $request);
        $row->enabled = ($definition['required'] ?? false) || $request->boolean('enabled');
        $row->save();

        if ($request->input('after_save') === 'close') {
            return redirect()->route('admin.letters.index')->with('status', __('Letter saved.'));
        }

        return redirect()->route('admin.letters.edit', $event)->with('status', __('Letter saved.'));
    }

    /**
     * The letter as it would go now — with the words in the form, saved or
     * not, and a real record of the shop's (the latest order) — drawn into
     * the preview frame.
     */
    public function preview(Request $request, string $event)
    {
        $definition = $this->definition($event);
        $context = $this->sample($definition);

        if ($context === null) {
            return response(view('admin.letters.no-sample')->render());
        }

        $draft = null;
        if ($request->isMethod('post')) {
            $draft = clone (Letters::row($event) ?? new MailText(['event' => $event]));
            $this->fill($draft, $request->only(['subject', 'preheader', 'heading', 'body', 'button']), $request);
        }

        $letter = Letters::compose($event, $context, admin_content_locale(), $draft);

        // The subject shown above the letter, as a mail client would.
        return response('<div style="font:13px sans-serif;color:#64748b;padding:10px 16px;border-bottom:1px solid #e2e8f0;background:#fff">'
            .e(__('Subject')).': <b style="color:#0f172a">'.e($letter['subject']).'</b></div>'.$letter['html'])
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
    }

    /** One real letter, to the admin's own address, in the content language. */
    public function test(Request $request, string $event)
    {
        $definition = $this->definition($event);
        $context = $this->sample($definition);

        if ($context === null) {
            return back()->with('error', __('There is nothing to write this letter about yet.'));
        }

        try {
            Letters::send($event, (string) $request->user()->email, $context, admin_content_locale(), now: true);
        } catch (\Throwable $e) {
            return back()->with('error', __('The letter could not be sent: :reason', ['reason' => Str::limit($e->getMessage(), 200)]));
        }

        return back()->with('status', __('Sent to :email.', ['email' => $request->user()->email]));
    }

    public function frame(Request $request, SettingsManager $settings)
    {
        $data = $request->validate([
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'background' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'topline' => ['nullable', 'string', 'max:255'],
            'footer' => ['nullable', 'string', 'max:1000'],
            // A picture a mail client draws: raster only — an SVG is a
            // document, and most clients refuse it anyway.
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:1024'],
            'logo_remove' => ['nullable', 'boolean'],
        ]);

        $settings->set('core', 'mail_color', strtolower($data['color']));
        $settings->set('core', 'mail_background', strtolower($data['background']));
        $settings->set('core', 'mail_topline', trim((string) ($data['topline'] ?? '')));
        $settings->set('core', 'mail_footer', trim((string) ($data['footer'] ?? '')) ?: Frame::DEFAULT_FOOTER);

        $old = (string) setting('core', 'mail_logo', '');
        if ($request->hasFile('logo') || $request->boolean('logo_remove')) {
            if ($old !== '') {
                Storage::disk('public')->delete($old);
            }
            $settings->set('core', 'mail_logo', $request->hasFile('logo')
                ? $request->file('logo')->storePublicly('mail', 'public')
                : '');
        }

        return back()->with('status', __('The letters\' frame is saved.'));
    }

    /**
     * The mail server: saved, or only checked (`check`), which connects and
     * logs in without sending anything. A blank password keeps the stored one.
     */
    public function server(Request $request, SettingsManager $settings)
    {
        $data = $request->validate([
            'smtp_enabled' => ['nullable', 'boolean'],
            'smtp_host' => ['nullable', 'string', 'max:255', 'required_if:smtp_enabled,1'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:tls,ssl,none'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:500'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $host = trim((string) ($data['smtp_host'] ?? ''));
        $port = (int) ($data['smtp_port'] ?? 587);
        $password = filled($data['smtp_password'] ?? null) ? $data['smtp_password'] : MailServer::password();

        if ($request->input('action') === 'check') {
            return back()->withInput($request->except('smtp_password'))
                ->with('server_check', MailServer::check($host, $port, $data['smtp_encryption'], trim((string) ($data['smtp_username'] ?? '')), $password));
        }

        $settings->set('core', 'smtp_enabled', $request->boolean('smtp_enabled'));
        $settings->set('core', 'smtp_host', $host);
        $settings->set('core', 'smtp_port', $port);
        $settings->set('core', 'smtp_encryption', $data['smtp_encryption']);
        $settings->set('core', 'smtp_username', trim((string) ($data['smtp_username'] ?? '')));
        if (filled($data['smtp_password'] ?? null)) {
            $settings->set('core', 'smtp_password', MailServer::encrypt($data['smtp_password']));
        }
        $settings->set('core', 'mail_from_address', trim((string) ($data['mail_from_address'] ?? '')));
        $settings->set('core', 'mail_from_name', trim((string) ($data['mail_from_name'] ?? '')));

        return back()->with('status', __('The mail server is saved. A running queue worker picks it up after a restart.'));
    }

    /** @return array<string, mixed> */
    protected function definition(string $event): array
    {
        $definition = MailEvents::find($event);
        abort_unless($definition !== null && $definition['editable'], 404);

        return $definition;
    }

    /** @return array<string, mixed>|null a real record to write about, or null when there is none */
    protected function sample(array $definition): ?array
    {
        $context = is_callable($definition['sample'] ?? null) ? (array) $definition['sample']() : [];

        foreach ($context as $value) {
            if ($value === null) {
                return null;
            }
        }

        return $context;
    }

    /** The typed words go into the CONTENT language only; blank means "the default". */
    protected function fill(MailText $row, array $data, Request $request): void
    {
        foreach (['subject', 'preheader', 'heading', 'body', 'button'] as $field) {
            if (array_key_exists($field, $data)) {
                $row->{$field} = put_translation($row->{$field} ?? [], $data[$field]) ?: null;
            }
        }

        if ($request->has('recipients')) {
            $row->recipients = trim((string) $request->input('recipients')) ?: null;
        }
    }
}
