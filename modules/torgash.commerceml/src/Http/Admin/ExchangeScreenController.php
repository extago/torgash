<?php

namespace Torgash\CommerceMl\Http\Admin;

use App\Core\Settings\SettingsManager;
use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\Request;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\PriceType;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\CommerceMl\Import\Matching;
use Torgash\CommerceMl\Import\OffersImport;
use Torgash\CommerceMl\LegacyEndpoint;
use Torgash\CommerceMl\Models\Exchange;
use Torgash\CommerceMl\Models\Link;
use Torgash\CommerceMl\Models\OrderSync;

/**
 * Matching is DATA, not a setting, which is why it has a screen of its own
 * rather than a block on the settings page.
 *
 * The table is empty until 1C has sent something, and that is the honest state:
 * nobody can say what a shop's price types correspond to before seeing what the
 * other side calls its own. So the exchange fills the queue and the shop answers
 * it — the same shape as a to-do list, and for the same reason.
 */
class ExchangeScreenController extends Controller
{
    /** The sections of the queue, in the order a shop meets them. */
    public const KINDS = [
        Link::KIND_PRICE_TYPE,
        Link::KIND_FEATURE,
    ];

    public function index(Matching $matching)
    {
        return view('torgash.commerceml::admin.index', [
            'pending' => $matching->pending(),
            'exchanges' => Exchange::latest('id')->limit(15)->get(),
            'languages' => Language::where('enabled', true)->orderBy('sort')->get(),
            'contentLocale' => (string) setting('torgash.commerceml', 'content_locale', config('app.locale')),
            'legacy' => new LegacyEndpoint,
            'counts' => [
                Link::KIND_PRICE_TYPE => Link::where('kind', Link::KIND_PRICE_TYPE)->count(),
                Link::KIND_FEATURE => Link::where('kind', Link::KIND_FEATURE)->count(),
            ],
        ]);
    }

    public function matching(string $kind, Matching $matching)
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        return view('torgash.commerceml::admin.matching', [
            'kind' => $kind,
            'rows' => $this->rows($kind),
            'options' => $this->options($kind),
            'valueOptions' => $kind === Link::KIND_FEATURE ? $this->featureValueOptions() : [],
        ]);
    }

    /**
     * One form for the whole section, including the nested values.
     *
     * Saved together on purpose: a property and its variants are one decision
     * ("import this characteristic, and these of its values"), and splitting it
     * across two screens is how half of it gets forgotten.
     */
    public function save(Request $request, string $kind, Matching $matching)
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        foreach ((array) $request->input('rows', []) as $id => $choice) {
            $link = Link::find((int) $id);

            if (! $link) {
                continue;
            }

            // The select carries the decision and the target in one value —
            // `skip`, `create`, or `link:<id>` / `link:key:<key>` — because a
            // decision and what it points at are one answer, and two controls
            // for one answer is how they get out of step.
            $this->apply($link, (string) $choice);
        }

        return back()->with('status', __('Matching saved.'));
    }

    /**
     * When an order goes to 1C, and what 1C may do to it when it comes back.
     *
     * Here rather than in the settings page for the same reason the language
     * picker is: every control on it is a list of the shop's OWN rows —
     * statuses it invented and can rename — and a manifest cannot know them.
     * The free-text half of the same subject (which attribute names 1C reports
     * a shipment under) stays in the settings, beside the other attribute
     * names, because that is where a person looks for it.
     */
    public function orders()
    {
        $statuses = OrderStatus::orderBy('position')->get();

        return view('torgash.commerceml::admin.orders', [
            'statuses' => $statuses,
            'selected' => (array) setting('torgash.commerceml', 'sale_statuses', []),
            'enabled' => (bool) setting('torgash.commerceml', 'sale_enabled', true),
            'paid' => (bool) setting('torgash.commerceml', 'sale_paid', true),
            'match' => (string) setting('torgash.commerceml', 'sale_match', 'any'),
            'from' => (string) setting('torgash.commerceml', 'sale_from', ''),
            'shipped' => (string) setting('torgash.commerceml', 'sale_status_shipped', 'shipped'),
            'guestDomain' => (string) setting('torgash.commerceml', 'sale_guest_domain', ''),
            'deliveryGuid' => (string) setting('torgash.commerceml', 'sale_delivery_guid', ''),
            // What has actually passed, newest first: the answer to "has 1C
            // taken this one", which no setting can give.
            'recent' => OrderSync::with('order')->latest('id')->limit(20)->get(),
        ]);
    }

    public function saveOrders(Request $request, SettingsManager $settings)
    {
        $validated = $request->validate([
            'sale_statuses' => 'array',
            'sale_statuses.*' => 'string',
            'sale_match' => 'in:any,all',
            'sale_from' => 'nullable|date',
            'sale_status_shipped' => 'nullable|string',
            'sale_guest_domain' => 'nullable|string|max:190',
            'sale_delivery_guid' => 'nullable|string|max:190',
        ]);

        $settings->set('torgash.commerceml', 'sale_enabled', $request->boolean('sale_enabled'));
        $settings->set('torgash.commerceml', 'sale_paid', $request->boolean('sale_paid'));
        $settings->set('torgash.commerceml', 'sale_statuses', array_values($validated['sale_statuses'] ?? []));
        $settings->set('torgash.commerceml', 'sale_match', $validated['sale_match'] ?? 'any');
        $settings->set('torgash.commerceml', 'sale_from', $validated['sale_from'] ?? '');
        $settings->set('torgash.commerceml', 'sale_status_shipped', $validated['sale_status_shipped'] ?? '');
        $settings->set('torgash.commerceml', 'sale_guest_domain', trim($validated['sale_guest_domain'] ?? ''));
        $settings->set('torgash.commerceml', 'sale_delivery_guid', trim($validated['sale_delivery_guid'] ?? ''));

        return back()->with('status', __('Saved.'));
    }

    /**
     * Put the old address back, or take it away.
     *
     * Installing the module does this already; the button is for the two cases
     * installation cannot answer — public/ was read-only at the time, or the
     * shop deleted the file and wants it back — and for a shop that would
     * rather have its own rewrite rule and no file at all.
     */
    public function legacy(Request $request)
    {
        $endpoint = new LegacyEndpoint;

        if ($request->input('action') === 'remove') {
            return back()->with('status', $endpoint->remove()
                ? __('The old address has been removed.')
                : __('That file is not ours — it has been left alone.'));
        }

        return back()->with('status', $endpoint->install()
            ? __('The old address answers again.')
            : __('Could not write :path — check that the folder is writable, or add a rewrite rule instead.', ['path' => $endpoint->path()]));
    }

    public function language(Request $request, SettingsManager $settings)
    {
        $validated = $request->validate(['content_locale' => 'required|string|max:10']);

        $settings->set('torgash.commerceml', 'content_locale', $validated['content_locale']);

        return back()->with('status', __('Saved.'));
    }

    // ---------------------------------------------------------------- helpers

    protected function apply(Link $link, string $choice): void
    {
        if ($choice === Link::SKIP || $choice === Link::CREATE || $choice === Link::ASK) {
            $link->forceFill(['decision' => $choice, 'local_id' => null, 'local_key' => null])->save();

            return;
        }

        if (str_starts_with($choice, 'key:')) {
            $link->forceFill([
                'decision' => Link::LINK,
                'local_id' => null,
                'local_key' => substr($choice, 4),
            ])->save();

            return;
        }

        if (str_starts_with($choice, 'id:')) {
            $link->forceFill([
                'decision' => Link::LINK,
                'local_id' => (int) substr($choice, 3),
                'local_key' => null,
            ])->save();
        }
    }

    /**
     * The queue for one section: parents in the order they were seen, each
     * with its own children under it.
     *
     * @return array<int, array{link: Link, children: array<int, Link>}>
     */
    protected function rows(string $kind): array
    {
        $parents = Link::where('kind', $kind)->orderBy('name')->get();

        $children = $kind === Link::KIND_FEATURE
            ? Link::where('kind', Link::KIND_FEATURE_VALUE)->orderBy('name')->get()->groupBy('parent_guid')
            : collect();

        return $parents->map(fn (Link $link) => [
            'link' => $link,
            'children' => $children->get($link->guid, collect())->all(),
        ])->all();
    }

    /**
     * What a row of this kind may be matched TO.
     *
     * Price types are the interesting case: the local side is not always a row.
     * "The base price" and "the old price" are COLUMNS on the product, and they
     * are what a shop with one price type in 1C actually wants — so they are
     * offered beside the shop's own price types rather than instead of them.
     *
     * @return array<string, string>
     */
    protected function options(string $kind): array
    {
        if ($kind === Link::KIND_PRICE_TYPE) {
            $options = [
                'key:'.OffersImport::BASE => __('The base price'),
                'key:'.OffersImport::LIST => __('The old (struck-through) price'),
                'key:'.OffersImport::COST => __('The cost price'),
            ];

            foreach (PriceType::orderBy('sort')->get() as $type) {
                $options['id:'.$type->id] = $type->t('name');
            }

            return $options;
        }

        $options = [];

        foreach (Feature::orderBy('sort')->get() as $feature) {
            $options['id:'.$feature->id] = $feature->t('name');
        }

        return $options;
    }

    /** @return array<string, string> */
    protected function featureValueOptions(): array
    {
        $options = [];

        foreach (FeatureValue::with('feature')->get() as $value) {
            $options['id:'.$value->id] = $value->feature?->t('name').' · '.$value->t('value');
        }

        asort($options);

        return $options;
    }
}
