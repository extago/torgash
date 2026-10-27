<?php

namespace Torgash\Giftcards\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Giftcards\Delivery;
use Torgash\Giftcards\Issuer;
use Torgash\Giftcards\Ledger;
use Torgash\Giftcards\Models\Card;
use Torgash\Giftcards\Rules;

class CardsController extends Controller
{
    public const STATES = ['active', 'pending', 'redeemed', 'expired', 'disabled'];

    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'state' => in_array($request->query('state'), self::STATES, true) ? (string) $request->query('state') : '',
            'batch' => trim((string) $request->query('batch', '')),
        ];

        $base = Card::query()
            ->when($filters['batch'] !== '', fn ($q) => $q->where('batch', $filters['batch']))
            ->when($filters['q'] !== '', fn ($q) => $this->search($q, $filters['q']));

        $counts = ['' => (clone $base)->count()];
        foreach (self::STATES as $state) {
            $counts[$state] = $this->inState(clone $base, $state)->count();
        }

        $cards = ($filters['state'] !== '' ? $this->inState($base, $filters['state']) : $base)
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('torgash.giftcards::admin.index', [
            'cards' => $cards,
            'filters' => $filters,
            'stateCounts' => $counts,
            'outstanding' => (int) $this->inState(Card::query(), 'active')->sum('balance'),
            'batches' => Card::whereNotNull('batch')->distinct()->orderBy('batch')->pluck('batch'),
        ]);
    }

    public function create()
    {
        return view('torgash.giftcards::admin.create', [
            'categoryOptions' => app(CategoryTree::class)->options(),
            'defaultExpiry' => Issuer::defaultExpiry()?->toDateString(),
        ]);
    }

    public function store(Request $request, Issuer $issuer, Delivery $delivery)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['single', 'batch'])],
            'amount' => 'required|numeric|min:0.01',
            'count' => 'required_if:mode,batch|nullable|integer|min:1|max:1000',
            'batch' => 'required_if:mode,batch|nullable|string|max:64',
            'active' => 'nullable|boolean',
            'expires_at' => 'nullable|date|after:today',
            'recipient_name' => 'nullable|string|max:190',
            'recipient_email' => 'nullable|email|max:190',
            'sender_name' => 'nullable|string|max:190',
            'message' => 'nullable|string|max:500',
            'deliver_at' => 'nullable|date',
            'owner_email' => 'nullable|email|max:190',
            'note' => 'nullable|string|max:1000',
            ...$this->ruleValidation(),
        ]);

        $amount = (int) round((float) $data['amount'] * 100);
        $common = [
            'expires_at' => ! empty($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
            'rules' => $this->rulesFrom($request),
            'note' => $data['note'] ?? null,
        ];

        if ($data['mode'] === 'batch') {
            $cards = $issuer->batch((int) $data['count'], $amount, (string) $data['batch'], $common + [
                'status' => $request->boolean('active') ? Card::ACTIVE : Card::PENDING,
            ]);

            return redirect()->route('admin.giftcards.index', ['batch' => $data['batch']])
                ->with('status', trans_choice(':count card issued. Download the codes to print them.|:count cards issued. Download the codes to print them.', count($cards), ['count' => count($cards)]));
        }

        $owner = ! empty($data['owner_email'])
            ? \App\Models\User::whereRaw('lower(email) = ?', [mb_strtolower($data['owner_email'])])->first()
            : null;

        if (! empty($data['owner_email']) && ! $owner) {
            return back()->withInput()->withErrors(['owner_email' => __('No account uses this email.')]);
        }

        [$card] = $issuer->issue($amount, $common + [
            'source' => 'issued',
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_email' => $data['recipient_email'] ?? null,
            'sender_name' => $data['sender_name'] ?? null,
            'message' => $data['message'] ?? null,
            'deliver_at' => ! empty($data['deliver_at']) ? Carbon::parse($data['deliver_at']) : null,
            'owner_user_id' => $owner?->id,
        ]);

        $delivery->sendDue([$card]);

        return redirect()->route('admin.giftcards.show', $card)->with('status', $card->delivered_at
            ? __('Card issued and sent to :email.', ['email' => $card->recipient_email])
            : __('Card issued.'));
    }

    public function show(Card $card, Rules $rules)
    {
        $card->load(['entries', 'owner']);

        return view('torgash.giftcards::admin.show', [
            'card' => $card,
            'rulesSaid' => $rules->describe($card),
            'categoryOptions' => app(CategoryTree::class)->options(),
            'orders' => \Torgash\Checkout\Models\Order::withTrashed()
                ->whereIn('id', $card->entries->pluck('order_id')->filter()->push($card->order_id)->filter()->unique())
                ->get(['id', 'order_number'])->keyBy('id'),
        ]);
    }

    public function update(Request $request, Card $card)
    {
        $data = $request->validate([
            'expires_at' => 'nullable|date',
            'recipient_name' => 'nullable|string|max:190',
            'recipient_email' => 'nullable|email|max:190',
            'sender_name' => 'nullable|string|max:190',
            'message' => 'nullable|string|max:500',
            'deliver_at' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
            ...$this->ruleValidation(),
        ]);

        $card->update([
            'expires_at' => ! empty($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_email' => $data['recipient_email'] ?? null,
            'sender_name' => $data['sender_name'] ?? null,
            'message' => $data['message'] ?? null,
            'deliver_at' => ! empty($data['deliver_at']) ? Carbon::parse($data['deliver_at']) : null,
            'note' => $data['note'] ?? null,
            'rules' => $this->rulesFrom($request),
        ]);

        return $request->input('after_save') === 'close'
            ? redirect()->route('admin.giftcards.index')->with('status', __('Saved.'))
            : back()->with('status', __('Saved.'));
    }

    /** A correction by hand: a signed amount and a reason nobody can skip. */
    public function adjust(Request $request, Card $card, Ledger $ledger)
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['add', 'take'])],
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        $delta = (int) round((float) $data['amount'] * 100) * ($data['direction'] === 'take' ? -1 : 1);

        try {
            $ledger->adjust($card, $delta, $data['reason']);
        } catch (\InvalidArgumentException) {
            return back()->withErrors(['amount' => __('A balance cannot go below zero.')]);
        }

        return back()->with('status', __('Balance corrected.'));
    }

    /** Handing a printed card over at the till: from now on it is money. */
    public function activate(Card $card)
    {
        if ($card->status === Card::PENDING) {
            $card->forceFill(['status' => Card::ACTIVE, 'activated_at' => now()])->save();
        }

        return back()->with('status', __('The card is active now.'));
    }

    public function status(Request $request, Card $card)
    {
        $on = $request->boolean('enabled');
        $card->forceFill(['status' => $on ? Card::ACTIVE : Card::DISABLED, 'activated_at' => $card->activated_at ?? ($on ? now() : null)])->save();

        return back()->with('status', $on ? __('The card is switched on.') : __('The card is switched off.'));
    }

    public function send(Card $card, Delivery $delivery)
    {
        if (! $card->recipient_email) {
            return back()->withErrors(['recipient_email' => __('Write the recipient\'s email first.')]);
        }

        $delivery->send($card);

        return back()->with('status', __('Sent to :email.', ['email' => $card->recipient_email]));
    }

    /** The code, for the manager who asked to see it — never in a list. */
    public function code(Card $card)
    {
        return response($card->formattedCode(), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** A batch's codes for the printer: code, amount, valid until, the card's page. */
    public function export(Request $request)
    {
        $batch = trim((string) $request->query('batch', ''));
        abort_if($batch === '', 404);

        $cards = Card::where('batch', $batch)->orderBy('id')->get();
        $name = 'giftcards-'.\Illuminate\Support\Str::slug($batch).'.csv';

        return response()->streamDownload(function () use ($cards) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['code', 'amount', 'currency', 'valid_until', 'state', 'url']);

            foreach ($cards as $card) {
                fputcsv($out, [
                    $card->formattedCode(),
                    number_format($card->initial_amount / 100, 2, '.', ''),
                    shop_currency(),
                    $card->expires_at?->toDateString(),
                    $card->state(),
                    $card->publicUrl(),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    protected function ruleValidation(): array
    {
        return [
            'rule_categories' => 'nullable|array',
            'rule_categories.*' => 'integer',
            'rule_shipping' => ['nullable', Rule::in(['', 'yes', 'no'])],
            'rule_max_percent' => 'nullable|integer|min:1|max:100',
            'rule_min_order' => 'nullable|numeric|min:0',
        ];
    }

    /** @return array<string, mixed>|null the card's rules; null when it says nothing */
    protected function rulesFrom(Request $request): ?array
    {
        $rules = array_filter([
            'categories' => array_values(array_map('intval', (array) $request->input('rule_categories', []))) ?: null,
            'shipping' => match ($request->input('rule_shipping')) {
                'yes' => true,
                'no' => false,
                default => null,
            },
            'max_percent' => (int) $request->input('rule_max_percent') ?: null,
            'min_order' => $request->filled('rule_min_order') ? (int) round((float) $request->input('rule_min_order') * 100) ?: null : null,
        ], fn ($value) => $value !== null);

        return $rules ?: null;
    }

    protected function search(Builder $query, string $q): Builder
    {
        $digits = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q));

        return $query->where(function (Builder $w) use ($q, $digits) {
            // The whole code finds its card; its last four find the ones that end so.
            if (\Torgash\Giftcards\Codes::looksLikeCard($digits)) {
                $w->orWhere('code_hash', \Torgash\Giftcards\Codes::hash($digits));
            }

            $w->orWhere('last4', strtoupper(substr($digits, -4)))
                ->orWhere('recipient_email', 'ilike', '%'.$q.'%')
                ->orWhere('recipient_name', 'ilike', '%'.$q.'%')
                ->orWhere('batch', 'ilike', '%'.$q.'%')
                ->orWhereIn('owner_user_id', \App\Models\User::where('email', 'ilike', '%'.$q.'%')->orWhere('phone', 'like', '%'.(preg_replace('/\D+/', '', $q) ?: '~').'%')->select('id'))
                ->orWhereIn('order_id', \Torgash\Checkout\Models\Order::where('order_number', 'ilike', '%'.$q.'%')->select('id'));
        });
    }

    protected function inState(Builder $query, string $state): Builder
    {
        return $query->inState($state);
    }
}
