<?php

namespace Torgash\Api\Http;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Torgash\Api\ApiResources;

/**
 * Every registered resource, listed, read, written and acted upon — by one
 * controller, so the conventions are written once.
 *
 * Paging, sorting, filtering, the envelope, the error shapes and the permission
 * check are here; what a resource IS lives in the module that owns it. A module
 * adding an endpoint writes a serializer, not a controller.
 */
class ResourceController extends Controller
{
    public function index(Request $request, string $resource)
    {
        $definition = $this->definition($request, $resource);
        $this->authorizeResource($request, $definition, 'read');

        $query = ($definition['query'])();

        $this->filter($request, $query, $definition);
        $this->sort($request, $query, $definition);

        $perPage = $this->perPage($request);
        $page = $query->paginate($perPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        // The page's whole set, announced before a serializer is asked about
        // any one row — the idiom `catalog.products.listed` already follows on
        // the storefront. A serializer that needs a per-row lookup answers it
        // in one query here instead of one query per row.
        hook_action('api.resource.listed', [
            'resource' => $resource,
            'items' => collect($page->items()),
        ]);

        return response()->json([
            'data' => collect($page->items())->map($definition['serialize'])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'itemsPerPage' => $page->perPage(),
                'total' => $page->total(),
                'pages' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $resource, string $key)
    {
        $definition = $this->definition($request, $resource);
        $this->authorizeResource($request, $definition, 'read');

        return response()->json(['data' => ($definition['serialize'])($this->findOrFail($definition, $key))]);
    }

    /**
     * A partial update, and only ever partial: a PATCH carrying three fields
     * changes three fields. An exchange must be able to push a stock level
     * without also having to send — and therefore risk overwriting — the name,
     * the price and the description.
     */
    public function update(Request $request, string $resource, string $key)
    {
        $definition = $this->definition($request, $resource);
        $this->authorizeResource($request, $definition, 'write');

        if (! $definition['update']) {
            return response()->json(['message' => __('This resource is read-only.')], 405);
        }

        $model = $this->findOrFail($definition, $key);

        ($definition['update'])($model, $request->all());

        return response()->json(['data' => ($definition['serialize'])($model->fresh())]);
    }

    /**
     * A new row, only for a resource that says how to make one — and made by
     * the shop's own code, which the definition's `create` calls.
     */
    public function store(Request $request, string $resource)
    {
        $definition = $this->definition($request, $resource);
        $this->authorizeResource($request, $definition, 'write');

        if (! $definition['create']) {
            return response()->json(['message' => __('Rows of this resource are not created through the API.')], 405);
        }

        $model = ($definition['create'])($request->all());

        return response()->json(['data' => ($definition['serialize'])($model)], 201);
    }

    /**
     * A named transition — `/orders/TG-1/actions/status` — rather than a PATCH
     * that sets a state column.
     *
     * The difference is the whole reason for it: an action runs the shop's own
     * code (OrderStatusChanger takes stock, logs, emails), while writing a
     * column would only look like it had. Sylius made the same call for the
     * same reason, and it is the one place their API design is worth copying
     * outright.
     */
    public function action(Request $request, string $resource, string $key, string $action)
    {
        $definition = $this->definition($request, $resource);
        $this->authorizeResource($request, $definition, 'write');

        $handler = $definition['actions'][$action] ?? null;

        if (! $handler) {
            return response()->json(['message' => __('No such action.')], 404);
        }

        $model = $this->findOrFail($definition, $key);
        $result = ($handler['apply'])($model, $request->all());

        return response()->json(['data' => ($definition['serialize'])($result instanceof Model ? $result : $model->fresh())]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The resource, within the section this route belongs to.
     *
     * The section comes from the URL and not from the caller: `/shop/products`
     * can never reach the admin definition however the request is shaped.
     *
     * @return array<string, mixed>
     */
    protected function definition(Request $request, string $resource): array
    {
        $definition = ApiResources::find($this->section($request).'.'.$resource);

        abort_unless($definition && $definition['query'], 404, __('No such resource.'));

        return $definition;
    }

    /**
     * The token's owner must hold the resource's permission, and the token
     * itself must not have been narrowed away from this kind of call. Two
     * different questions: who you are, and which of your rights this key
     * carries.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function authorizeResource(Request $request, array $definition, string $mode): void
    {
        // The shop section is public, exactly as the storefront's own HTML is:
        // a visitor has no token, and demanding one would make a catalogue
        // harder to read over HTTP than over a browser. Its routes are
        // registered read-only, so 'public' here can never mean 'writable'.
        if ($this->section($request) === 'shop') {
            return;
        }

        $token = $request->attributes->get('api_token');

        abort_unless($token && $token->allows($mode), 403, __('This token may not :mode.', ['mode' => $mode]));

        if ($definition['permission']) {
            abort_unless(auth()->user()?->can($definition['permission']), 403, __('Not allowed.'));
        }
    }

    /**
     * By our id, or by whatever key the caller's own system knows the row as.
     *
     * That is what `lookup` is for, and why an integration never has to store
     * our autoincrements: `/products/1c-42` finds the product whose
     * external_id is `1c-42`.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function findOrFail(array $definition, string $key): Model
    {
        $query = ($definition['query'])();

        if (ctype_digit($key)) {
            $found = (clone $query)->whereKey((int) $key)->first();

            if ($found) {
                return $found;
            }
        }

        foreach ($definition['lookup'] as $column) {
            $found = (clone $query)->where($column, $key)->first();

            if ($found) {
                return $found;
            }
        }

        abort(404, __('Not found.'));
    }

    /** Which half of the API this route belongs to — decided by the URL, never by the caller. */
    protected function section(Request $request): string
    {
        // The PATH, not the route's prefix: a prefix is a property of how the
        // group was declared and quietly becomes something else the day the
        // routes are nested differently. The URL is what the caller asked for.
        return $request->is('api/v2/shop/*') ? 'shop' : 'admin';
    }

    /** @param array<string, mixed> $definition */
    protected function filter(Request $request, Builder $query, array $definition): void
    {
        foreach ($definition['filters'] as $name => $filter) {
            if ($request->query->has($name)) {
                ($filter['apply'])($query, $request->query($name));
            }
        }
    }

    /**
     * `?order[price]=desc`, and only over columns the resource allows: an
     * unsorted list is a hazard on a big table, and an arbitrary one is a way
     * to sort by a column with no index.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function sort(Request $request, Builder $query, array $definition): void
    {
        $order = (array) $request->query('order', []);
        $sorted = false;

        foreach ($order as $column => $direction) {
            if (in_array($column, $definition['sortable'], true)) {
                $query->orderBy($column, strtolower((string) $direction) === 'desc' ? 'desc' : 'asc');
                $sorted = true;
            }
        }

        // Paging over an unordered query is what makes page 2 repeat rows from
        // page 1 — Postgres owes no order without one.
        if (! $sorted) {
            $query->orderBy($query->getModel()->getKeyName());
        }
    }

    /**
     * Capped rather than refused: an integration asking for 10 000 should get
     * the ceiling and keep working, not fail at three in the morning.
     */
    protected function perPage(Request $request): int
    {
        $default = (int) setting('torgash.api', 'items_per_page', 30);
        $max = (int) setting('torgash.api', 'max_items_per_page', 200);

        $asked = (int) $request->query('itemsPerPage', $default);

        return max(1, min($asked ?: $default, $max));
    }
}
