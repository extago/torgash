<?php

namespace Torgash\Inventory\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Product;
use Torgash\Inventory\Models\Document;
use Torgash\Inventory\Models\DocumentLine;
use Torgash\Inventory\Posting;
use Torgash\Inventory\Revoking;

/**
 * Stock documents (PLAN §42): the list, a draft being filled in — quick entry
 * by SKU (a scanner types into the same box) with Enter, or a search by name
 * — posting it with one button, and printing it. A posted document is read
 * only; every write here refuses one. The one way back is revoking it, behind
 * its own permission and the person's password (`Revoking`).
 */
class DocumentsController extends Controller
{
    public function index(Request $request)
    {
        $type = (string) $request->query('type', '');
        $type = array_key_exists($type, Document::TYPES) || $type === 'revoked' ? $type : '';

        return view('torgash.inventory::admin.documents.index', [
            'documents' => Document::query()
                // Revoked documents are out of the working lists, on a tab of their own.
                ->when($type === 'revoked',
                    fn ($query) => $query->where('status', 'revoked'),
                    fn ($query) => $query->where('status', '<>', 'revoked')->when($type !== '', fn ($query) => $query->where('type', $type)))
                ->withCount('lines')
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString(),
            'type' => $type,
            'counts' => Document::query()->where('status', '<>', 'revoked')->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type'),
            'revokedCount' => Document::query()->where('status', 'revoked')->count(),
            'warehouses' => $this->warehouses(),
        ]);
    }

    public function create(Request $request, string $type)
    {
        abort_unless(array_key_exists($type, Document::TYPES), 404);

        $document = Document::create([
            'type' => $type,
            'warehouse_id' => (int) (DB::table('m_warehouses')->where('is_default', true)->value('id') ?? DB::table('m_warehouses')->orderBy('sort')->value('id')),
            'document_date' => now()->toDateString(),
            'user_id' => $request->user()?->id,
            'user_name' => $request->user()?->name,
        ]);
        $document->assignNumber();

        return redirect()->route('admin.inventory.documents.edit', $document);
    }

    public function edit(Request $request, Document $document, Revoking $revoking)
    {
        return view('torgash.inventory::admin.documents.edit', [
            'document' => $document->load('lines'),
            // Asked only of someone who may act on the answer.
            'revokeObstacles' => $document->isPosted() && $request->user()?->can('inventory.revoke') ? $revoking->obstacles($document) : null,
            'warehouses' => $this->warehouses(),
            'currencies' => collect(app(\App\Core\Support\Currencies::class)->all())->pluck('code')->all(),
        ]);
    }

    public function update(Request $request, Document $document)
    {
        $this->refusePosted($document);

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('m_warehouses', 'id')],
            'to_warehouse_id' => ['nullable', 'integer', Rule::exists('m_warehouses', 'id')],
            'document_date' => ['required', 'date'],
            'counterparty' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:5000'],
            'lines' => ['array'],
            'lines.*.qty' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'lines.*.cost' => ['nullable', 'string', 'max:32'],
            'lines.*.currency' => ['nullable', 'string', 'size:3'],
        ]);

        DB::transaction(function () use ($document, $validated) {
            $document->update([
                'warehouse_id' => $validated['warehouse_id'],
                'to_warehouse_id' => $document->type === Document::TRANSFER ? ($validated['to_warehouse_id'] ?? null) : null,
                'document_date' => $validated['document_date'],
                'counterparty' => $validated['counterparty'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);

            foreach ((array) ($validated['lines'] ?? []) as $id => $typed) {
                $line = $document->lines()->find((int) $id);
                if (! $line) {
                    continue;
                }

                $line->update([
                    'qty' => (int) ($typed['qty'] ?? 0),
                    ...($document->type === Document::RECEIPT ? [
                        'unit_cost_original' => $this->minor($typed['cost'] ?? null),
                        'currency' => strtoupper((string) ($typed['currency'] ?? '')) ?: null,
                    ] : []),
                ]);
            }
        });

        // "Save and post": what was just typed is what gets posted.
        if ($request->input('after') === 'post') {
            return $this->post($request, $document, app(Posting::class));
        }

        return redirect()->route('admin.inventory.documents.edit', $document)->with('status', __('Saved.'));
    }

    /**
     * Add a product: by its exact SKU (what a scanner types) or by the id a
     * search result carries. The same product again adds to its line — one
     * line per product, as on the paper.
     */
    public function addLine(Request $request, Document $document)
    {
        $this->refusePosted($document);

        $qty = max(1, (int) $request->input('qty', 1));
        $code = trim((string) $request->input('code', ''));
        $product = $request->filled('product_id')
            ? Product::find((int) $request->input('product_id'))
            : ($code === '' ? null : Product::whereRaw('lower(sku) = ?', [mb_strtolower($code)])->first());

        if (! $product) {
            return $this->lines($document, $code === '' ? null : __('No product with SKU :sku.', ['sku' => $code]));
        }

        $line = $document->lines()->where('product_id', $product->id)->first();

        if ($line) {
            $line->increment('qty', $qty);
        } else {
            $document->lines()->create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->t('name'),
                'qty' => $qty,
                'position' => (int) $document->lines()->max('position') + 1,
                // A receipt starts from what the product last cost the shop.
                ...($document->type === Document::RECEIPT ? $this->lastCost($product->id) : []),
            ]);
        }

        return $this->lines($document);
    }

    public function removeLine(Document $document, DocumentLine $line)
    {
        $this->refusePosted($document);
        abort_unless($line->document_id === $document->id, 404);

        $line->delete();

        return $this->lines($document);
    }

    public function search(Request $request, Document $document)
    {
        // The quick-entry box itself asks, under its own name.
        $needle = trim((string) $request->query('q', $request->query('code', '')));
        $like = '%'.addcslashes($needle, '%_\\').'%';

        return view('torgash.inventory::admin.documents._search', [
            'document' => $document,
            'needle' => $needle,
            'products' => $needle === '' ? collect() : Product::query()
                ->where(fn ($q) => $q->where('sku', 'ilike', $like)
                    ->orWhereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where t.v ilike ?)', [$like]))
                ->orderBy('sku')
                ->limit(10)
                ->get(['id', 'sku', 'name', 'stock']),
        ]);
    }

    public function post(Request $request, Document $document, Posting $posting)
    {
        try {
            $posting->post($document, $request->user());
        } catch (ValidationException $e) {
            return redirect()->route('admin.inventory.documents.edit', $document)->withErrors($e->errors());
        }

        return redirect()->route('admin.inventory.documents.edit', $document)
            ->with('status', __('Posted: the stock has moved.'));
    }

    /**
     * Take a posted document back. Its own permission — given to nobody by
     * default — and the person's own password at that moment: an admin
     * session left open is not enough to rewrite the stock's history.
     */
    public function revoke(Request $request, Document $document, Revoking $revoking)
    {
        abort_unless($request->user()?->can('inventory.revoke'), 403);

        $validated = $request->validateWithBag('revoke', [
            'reason' => ['required', 'string', 'max:500'],
            'current_password' => ['required', 'current_password'],
        ]);

        try {
            $revoking->revoke($document, $request->user(), $validated['reason']);
        } catch (ValidationException $e) {
            return redirect()->route('admin.inventory.documents.edit', $document)->withErrors($e->errors(), 'revoke');
        }

        return redirect()->route('admin.inventory.documents.edit', $document)
            ->with('status', __('Revoked: the stock is as it was before this document.'));
    }

    public function destroy(Document $document)
    {
        $this->refusePosted($document);
        $document->delete();

        return redirect()->route('admin.inventory.documents.index')->with('status', __('Draft deleted.'));
    }

    public function print(Document $document)
    {
        $document->load('lines');

        return view('torgash.inventory::admin.documents.print', [
            'document' => $document,
            'warehouses' => $this->warehouses(),
            'values' => app(\Torgash\Inventory\DocumentValue::class)->lines($document),
        ]);
    }

    protected function lines(Document $document, ?string $error = null)
    {
        return view('torgash.inventory::admin.documents._lines', [
            'document' => $document->fresh()->load('lines'),
            'currencies' => collect(app(\App\Core\Support\Currencies::class)->all())->pluck('code')->all(),
            'lineError' => $error,
        ]);
    }

    protected function refusePosted(Document $document): void
    {
        abort_unless($document->isDraft(), 409, __('A posted document cannot be changed — correct it with another document.'));
    }

    /** @return array<string, mixed> the price the product was last received at */
    protected function lastCost(int $productId): array
    {
        $last = DocumentLine::query()
            ->join('m_inventory_documents as d', 'd.id', '=', 'm_inventory_document_lines.document_id')
            ->where('d.type', Document::RECEIPT)->where('d.status', 'posted')
            ->where('m_inventory_document_lines.product_id', $productId)
            ->whereNotNull('m_inventory_document_lines.unit_cost_original')
            ->orderByDesc('d.posted_at')
            ->first(['m_inventory_document_lines.unit_cost_original', 'm_inventory_document_lines.currency']);

        return $last ? ['unit_cost_original' => $last->unit_cost_original, 'currency' => $last->currency] : [];
    }

    protected function minor(?string $typed): ?int
    {
        $typed = trim(str_replace([' ', ','], ['', '.'], (string) $typed));

        return $typed === '' || ! is_numeric($typed) ? null : (int) round((float) $typed * 100);
    }

    protected function warehouses()
    {
        return DB::table('m_warehouses')->orderBy('sort')->orderBy('id')->get(['id', 'name', 'is_default'])->keyBy('id');
    }
}
