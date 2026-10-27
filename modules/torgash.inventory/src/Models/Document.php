<?php

namespace Torgash\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock document — receipt, write-off, transfer, count (see the migration
 * that created the table). A draft is edited freely; posting it moves the
 * stock once (`Posting`) and it never changes again.
 */
class Document extends Model
{
    protected $table = 'm_inventory_documents';

    protected $guarded = [];

    public const RECEIPT = 'receipt';

    public const WRITEOFF = 'writeoff';

    public const TRANSFER = 'transfer';

    public const COUNT = 'count';

    /** Type => [English name, number prefix, icon]. */
    public const TYPES = [
        self::RECEIPT => ['Receipt', 'IN', 'package-plus'],
        self::WRITEOFF => ['Write-off', 'WO', 'package-minus'],
        self::TRANSFER => ['Transfer', 'TR', 'arrow-right-left'],
        self::COUNT => ['Stock count', 'CT', 'list-checks'],
    ];

    public static function auditLabel(): string
    {
        return 'Stock document';
    }

    protected function casts(): array
    {
        return ['document_date' => 'date', 'posted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('position')->orderBy('id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    /** Taken back by `Revoking`: the stock is as if it had never been posted. */
    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    /** The only state anything about a document may still be changed in. */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function typeLabel(): string
    {
        return __(self::TYPES[$this->type][0] ?? $this->type);
    }

    public function icon(): string
    {
        return self::TYPES[$this->type][2] ?? 'file';
    }

    /** The number is given once the row exists: the prefix of its kind and its id. */
    public function assignNumber(): void
    {
        if ($this->number === null) {
            $this->forceFill(['number' => (self::TYPES[$this->type][1] ?? 'DOC').'-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
        }
    }

    /** The total cost of a receipt's lines, in the books; null where a price is missing. */
    public function total(): ?int
    {
        $sum = 0;
        foreach ($this->lines as $line) {
            if ($line->unit_cost === null) {
                return null;
            }
            $sum += (int) $line->unit_cost * (int) $line->qty;
        }

        return $sum;
    }
}
