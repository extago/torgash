<?php

namespace Torgash\Newsletter\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Torgash\Newsletter\Models\Subscriber;
use Torgash\Newsletter\Subscriptions;

/**
 * Marketing → Subscribers: who is on the list and how they came, the list for
 * a mailing service (CSV — the same filter as the screen), addresses brought
 * from elsewhere, and taking one off.
 */
class SubscribersController extends Controller
{
    public const TABS = ['subscribed', 'pending', 'unsubscribed', 'all'];

    public function index(Request $request)
    {
        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'subscribed';

        return view('torgash.newsletter::admin.index', [
            'tab' => $tab,
            'subscribers' => $this->query($request, $tab)->latest()->latest('id')->paginate(50)->withQueryString(),
            'counts' => Subscriber::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            // The list's pulse over the last month: who came, who left.
            'joined' => Subscriber::query()->where('status', Subscriber::SUBSCRIBED)->where('confirmed_at', '>=', now()->subDays(30))->count(),
            'left' => Subscriber::query()->where('status', Subscriber::UNSUBSCRIBED)->where('unsubscribed_at', '>=', now()->subDays(30))->count(),
        ]);
    }

    /** CSV of what the screen shows — opened by Excel and every mailing service. */
    public function export(Request $request): StreamedResponse
    {
        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'subscribed';
        $query = $this->query($request, $tab)->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 by its mark
            fputcsv($out, ['email', 'status', 'source', 'locale', 'subscribed_at', 'unsubscribed_at']);
            foreach ($query->lazyById(500) as $row) {
                // A cell that starts like a formula is read as text, not run (exchange.md).
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'".$v : $v, [
                    $row->email, $row->status, $row->source, $row->locale,
                    $row->confirmed_at?->format('Y-m-d H:i'), $row->unsubscribed_at?->format('Y-m-d H:i'),
                ]));
            }
            fclose($out);
        }, 'subscribers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Addresses brought from elsewhere (a previous shop, a mailing service):
     * one per line or the first column of a CSV. Taken as subscribed — the
     * consent was given there; somebody who unsubscribed here stays so.
     */
    public function import(Request $request, Subscriptions $subscriptions)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $added = $kept = $bad = 0;
        $handle = fopen($request->file('file')->getRealPath(), 'r');

        while (($line = fgetcsv($handle, 0, $this->delimiter($request->file('file')->getRealPath()))) !== false) {
            $email = Str::lower(trim((string) ($line[0] ?? ''), " \t\"'\xEF\xBB\xBF"));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $bad += $email !== '' && $email !== 'email' ? 1 : 0;

                continue;
            }

            $existing = Subscriber::where('email', $email)->first();
            if ($existing && $existing->status === Subscriber::UNSUBSCRIBED) {
                $kept++;

                continue;
            }

            $subscriptions->subscribe($email, 'import', null, __('Imported by :name.', ['name' => $request->user()->name])) === 'subscribed' ? $added++ : $kept++;
        }
        fclose($handle);

        return redirect()->route('admin.newsletter.index')->with('status', __('Added: :added. Already on the list or unsubscribed: :kept. Not addresses: :bad.', compact('added', 'kept', 'bad')));
    }

    public function destroy(Subscriber $subscriber)
    {
        $subscriber->delete();

        return back()->with('status', __('The address is removed from the list.'));
    }

    protected function query(Request $request, string $tab)
    {
        return Subscriber::query()
            ->when($tab !== 'all', fn ($q) => $q->where('status', $tab))
            ->when($request->filled('q'), fn ($q) => $q->where('email', 'ilike', '%'.$request->input('q').'%'))
            ->when(in_array($request->input('source'), Subscriber::SOURCES, true), fn ($q) => $q->where('source', $request->input('source')));
    }

    protected function delimiter(string $path): string
    {
        $first = (string) fgets(fopen($path, 'r'));

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }
}
