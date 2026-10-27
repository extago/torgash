<?php

namespace Torgash\Newsletter\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Torgash\Newsletter\Campaigns\CampaignLetter;
use Torgash\Newsletter\Campaigns\CampaignMail;
use Torgash\Newsletter\Campaigns\Segments;
use Torgash\Newsletter\Campaigns\SendCampaigns;
use Torgash\Newsletter\Models\Campaign;

/**
 * Marketing → Campaigns (PLAN §57.4): a letter, who it goes to, when; a test
 * to one's own address, a preview of the letter as it will be read; then the
 * numbers. A campaign is edited while it is a draft or waiting for its time;
 * once it is going out its words stay as they were sent.
 */
class CampaignsController extends Controller
{
    public const TABS = ['drafts', 'scheduled', 'sent', 'all'];

    public function index(Request $request)
    {
        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'all';
        $byTab = [
            'drafts' => [Campaign::DRAFT],
            'scheduled' => [Campaign::SCHEDULED, Campaign::SENDING],
            'sent' => [Campaign::SENT, Campaign::CANCELLED],
        ];
        $counts = Campaign::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('torgash.newsletter::admin.campaigns.index', [
            'tab' => $tab,
            'campaigns' => Campaign::query()
                ->when($tab !== 'all', fn ($q) => $q->whereIn('status', $byTab[$tab]))
                ->when($request->filled('q'), fn ($q) => $q->where('name', 'ilike', '%'.addcslashes((string) $request->input('q'), '%_\\').'%'))
                ->orderByRaw("case status when 'sending' then 0 when 'scheduled' then 1 when 'draft' then 2 else 3 end")
                ->orderByDesc('id')
                ->paginate(30)->withQueryString(),
            'counts' => collect($byTab)->map(fn ($statuses) => $counts->only($statuses)->sum())->put('all', $counts->sum()),
        ]);
    }

    public function create()
    {
        return view('torgash.newsletter::admin.campaigns.form', $this->formData(new Campaign(['segment' => ['rules' => []]])));
    }

    public function store(Request $request)
    {
        $campaign = new Campaign;
        $this->fill($campaign, $request);
        $campaign->save();

        return redirect()->route('admin.newsletter.campaigns.edit', $campaign)->with('status', __('The campaign is saved.'));
    }

    public function edit(Campaign $campaign)
    {
        return view('torgash.newsletter::admin.campaigns.form', $this->formData($campaign));
    }

    public function update(Request $request, Campaign $campaign)
    {
        abort_unless($campaign->isEditable(), 409, __('A campaign that is going out cannot be changed.'));

        $this->fill($campaign, $request);
        $campaign->save();

        return $request->input('after_save') === 'close'
            ? redirect()->route('admin.newsletter.campaigns.index')->with('status', __('Saved.'))
            : redirect()->route('admin.newsletter.campaigns.edit', $campaign)->with('status', __('Saved.'));
    }

    public function destroy(Campaign $campaign)
    {
        abort_if($campaign->status === Campaign::SENDING, 409, __('Cancel the campaign first.'));
        $campaign->delete();

        return redirect()->route('admin.newsletter.campaigns.index')->with('status', __('The campaign is deleted.'));
    }

    /** How many addresses the rules on the form mean right now (HTMX, while the rules are edited). */
    public function count(Request $request, Segments $segments)
    {
        $count = $segments->query($segments->clean((array) $request->input('rules', [])))->count();

        return response(e(trans_choice(':count address|:count addresses', $count)));
    }

    /**
     * The letter as it will be read — the stored campaign (GET), or the form
     * as it stands now (POST, the preview button), in the content language.
     */
    public function preview(Request $request, Campaign $campaign)
    {
        if (! $request->isMethod('get') && $campaign->isEditable()) {
            $campaign = $campaign->replicate();
            $this->fillWords($campaign, $request);
        }

        $letter = CampaignLetter::compose($campaign, null, admin_content_locale());

        return response('<div style="font:13px sans-serif;color:#64748b;padding:10px 16px;border-bottom:1px solid #e2e8f0;background:#fff">'
            .e(__('Subject')).': <b style="color:#0f172a">'.e($letter['subject']).'</b></div>'.$letter['html'])
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
    }

    /** One real letter to the admin's own address (or the one typed), in the content language; counts nothing. */
    public function test(Request $request, Campaign $campaign)
    {
        $data = $request->validate(['test_email' => ['nullable', 'email']]);
        $this->saveTyped($request, $campaign);
        $to = ($data['test_email'] ?? null) ?: (string) $request->user()->email;

        try {
            $letter = CampaignLetter::compose($campaign, null, admin_content_locale());
            Mail::to($to)->send(new CampaignMail('['.__('Test letter').'] '.$letter['subject'], $letter['html']));
        } catch (\Throwable $e) {
            return back()->withErrors(['test_email' => __('The letter could not be sent: :reason', ['reason' => Str::limit($e->getMessage(), 200)])]);
        }

        return back()->with('status', __('Sent to :email.', ['email' => $to]));
    }

    /** "Send now" or "Send at": the campaign waits for its time; now means the next moment. */
    public function send(Request $request, Campaign $campaign)
    {
        abort_unless($campaign->isEditable(), 409);

        $data = $request->validate([
            'when' => ['required', 'in:now,at'],
            'scheduled_at' => ['nullable', 'required_if:when,at', 'date', 'after:now'],
        ]);
        $this->saveTyped($request, $campaign);

        $baseBody = (string) $campaign->t('body', content_base_locale());
        if (trim((string) $campaign->t('subject', content_base_locale())) === '' || (trim(strip_tags($baseBody)) === '' && ! str_contains($baseBody, '<img'))) {
            return back()->withErrors(['subject' => __('A campaign needs its subject and its letter in the shop\'s base language before it can go out.')]);
        }

        $campaign->update([
            'status' => Campaign::SCHEDULED,
            'scheduled_at' => $data['when'] === 'now' ? now() : $data['scheduled_at'],
        ]);

        if ($data['when'] === 'now') {
            SendCampaigns::startIfIdle();
        }

        return redirect()->route('admin.newsletter.campaigns.edit', $campaign)->with('status', $data['when'] === 'now'
            ? __('The campaign is going out. The numbers below fill in as the letters go.')
            : __('The campaign will go out on :date.', ['date' => $campaign->scheduled_at->format('d.m.Y H:i')]));
    }

    /** A scheduled campaign back to a draft; a sending one stopped where it is. */
    public function cancel(Campaign $campaign)
    {
        if ($campaign->status === Campaign::SCHEDULED) {
            $campaign->update(['status' => Campaign::DRAFT, 'scheduled_at' => null]);

            return back()->with('status', __('The campaign is a draft again.'));
        }

        abort_unless($campaign->status === Campaign::SENDING, 409);
        $campaign->update(['status' => Campaign::CANCELLED, 'finished_at' => now()]);

        return back()->with('status', __('Sending is stopped. The letters already sent stay sent.'));
    }

    /** A new draft with the same letter and the same rules — the next issue of a newsletter. */
    public function duplicate(Campaign $campaign)
    {
        $copy = $campaign->replicate(['status', 'scheduled_at', 'started_at', 'finished_at', 'recipients', 'sent', 'failed', 'opened', 'clicked', 'unsubscribed']);
        $copy->name = __(':name (copy)', ['name' => $campaign->name]);
        $copy->status = Campaign::DRAFT;
        $copy->save();

        // The letter's pictures belong to the campaign: the copy gets its own.
        foreach ($campaign->getMedia('content') as $media) {
            $media->copy($copy, 'content');
        }

        return redirect()->route('admin.newsletter.campaigns.edit', $copy)->with('status', __('A copy is made — a draft.'));
    }

    /**
     * "Send" and "Send a test" are buttons of the campaign's own form: what
     * is typed there is saved first — a test of the text before the last edit
     * would test the wrong letter.
     */
    protected function saveTyped(Request $request, Campaign $campaign): void
    {
        if ($request->has('name') && $campaign->isEditable()) {
            $this->fill($campaign, $request);
            $campaign->save();
        }
    }

    protected function fill(Campaign $campaign, Request $request): void
    {
        $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'rules' => ['array'],
        ]);

        $this->fillWords($campaign, $request);
        $campaign->name = (string) $request->input('name');
        $campaign->segment = app(Segments::class)->clean((array) $request->input('rules', []));
    }

    /** The letter's words in the content language picked at the top — one language per save. */
    protected function fillWords(Campaign $campaign, Request $request): void
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'heading' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:500000'],
        ]);

        foreach (['subject', 'preheader', 'heading', 'body'] as $field) {
            $campaign->{$field} = put_translation($campaign->{$field}, (string) ($data[$field] ?? ''));
        }
    }

    protected function formData(Campaign $campaign): array
    {
        $segments = app(Segments::class);

        return [
            'campaign' => $campaign,
            'segmentKinds' => $segments->forForm(),
            'audience' => $segments->query($campaign->segment)->count(),
            'topLinks' => $campaign->exists ? $campaign->links()->where('clicks', '>', 0)->orderByDesc('clicks')->limit(10)->get() : collect(),
        ];
    }
}
