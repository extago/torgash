<?php

namespace Torgash\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product on a stock document. */
class DocumentLine extends Model
{
    public $timestamps = false;

    protected $table = 'm_inventory_document_lines';

    protected $guarded = [];

    public static function auditLabel(): string
    {
        return 'Stock document line';
    }

    protected function casts(): array
    {
        return ['qty' => 'int', 'expected' => 'int', 'unit_cost' => 'int', 'unit_cost_original' => 'int', 'rate' => 'float'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
