<?php

namespace Torgash\Privacy\Http\Admin;

use App\Core\Privacy\PersonalData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Torgash\Privacy\Models\PrivacyRequest;
use Torgash\Privacy\Requests;

/**
 * Requests to download or erase one's data (PLAN §49). A customer asks from
 * the account; a manager can also open one for a person who wrote or called —
 * by email or phone, for a guest with no account at all.
 */
class RequestsController extends Controller
{
    public function index(Request $request)
    {
        $view = $request->query('view') === 'all' ? 'all' : 'open';
        $q = trim((string) $request->query('q', ''));

        // The search but not the tab, so each tab's count answers "how many
        // of THESE" — a surname typed reads across both (every list's rule).
        $base = fn () => PrivacyRequest::query()->when($q !== '', function ($query) use ($q) {
            $query->where(function ($where) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $where->where('name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like);
                if (ctype_digit(ltrim($q, '#'))) {
                    $where->orWhere('id', (int) ltrim($q, '#'));
                }
            });
        });

        $counts = [
            'open' => $base()->open()->count(),
            'all' => $base()->count(),
        ];

        $requests = $base()
            ->with('user:id,name')
            ->when($view === 'open', fn ($query) => $query->open())
            ->orderByRaw("status = 'new' desc")
            ->orderBy('due_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('torgash.privacy::admin.requests.index', compact('requests', 'view', 'q', 'counts'));
    }

    public function create()
    {
        return view('torgash.privacy::admin.requests.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([PrivacyRequest::EXPORT, PrivacyRequest::ERASE])],
            'name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // The account these contacts belong to, if there is one.
        $user = ($validated['email'] ?? null)
            ? User::customers()->whereRaw('lower(email) = ?', [mb_strtolower($validated['email'])])->first()
            : null;

        $privacyRequest = Requests::open($user, $validated['type'], $validated['name'] ?? null, $validated['email'] ?? null, $validated['phone'] ?? null, $validated['note'] ?? null);

        return redirect()->route('admin.privacy.requests.show', $privacyRequest)->with('status', __('Request opened.'));
    }

    public function show(PrivacyRequest $privacyRequest, PersonalData $data)
    {
        // What would be found, before anything is done — counted, not shown:
        // the manager needs to know there IS something, not to read it here.
        $found = collect($data->export($privacyRequest->subject()))->map(fn (array $rows) => count($rows));

        return view('torgash.privacy::admin.requests.show', [
            'privacyRequest' => $privacyRequest->load('user', 'doneBy'),
            'found' => $found,
            'isStaff' => $privacyRequest->user_id && User::staff()->whereKey($privacyRequest->user_id)->exists(),
        ]);
    }

    public function download(PrivacyRequest $privacyRequest, Requests $requests)
    {
        $export = ['generated_at' => now()->toIso8601String()] + $requests->export($privacyRequest);

        if ($privacyRequest->type === PrivacyRequest::EXPORT && $privacyRequest->status === PrivacyRequest::NEW) {
            $privacyRequest->update(['status' => PrivacyRequest::DONE, 'done_at' => now(), 'done_by' => auth()->id()]);
        }

        return response()->streamDownload(
            fn () => print json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'personal-data-'.$privacyRequest->id.'.json',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function erase(PrivacyRequest $privacyRequest, Requests $requests)
    {
        abort_unless($privacyRequest->status === PrivacyRequest::NEW, 409);

        try {
            $requests->erase($privacyRequest, auth()->user());
        } catch (RuntimeException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('status', __('Erased. What was done is listed below.'));
    }

    public function reject(Request $request, PrivacyRequest $privacyRequest)
    {
        $privacyRequest->update([
            'status' => PrivacyRequest::REJECTED,
            'note' => trim(($privacyRequest->note ? $privacyRequest->note."\n\n" : '').$request->string('reason')->limit(2000)),
            'done_at' => now(),
            'done_by' => auth()->id(),
        ]);

        return back()->with('status', __('Request refused.'));
    }
}
