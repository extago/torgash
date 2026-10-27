<?php

namespace Torgash\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/** One movement of stock, and why (see the migration that created the table). */
class Move extends Model
{
    const UPDATED_AT = null;

    protected $table = 'm_inventory_moves';

    protected $guarded = [];

    public static function auditLabel(): string
    {
        return 'Stock movement';
    }

    protected function casts(): array
    {
        return ['ref' => 'array', 'delta' => 'int', 'after' => 'int', 'created_at' => 'datetime'];
    }

    /** The words for why, in the reader's language. */
    public static function typeLabel(string $type): string
    {
        return __(self::TYPES[$type] ?? $type);
    }

    /** Every cause the engine itself puts in force; a module may add its own word. */
    public const TYPES = [
        'order' => 'Order',
        'return' => 'Return',
        'merge' => 'Orders merged',
        'document' => 'Document',
        'import' => 'Import',
        '1c' => '1C',
        'edit' => 'Edited by hand',
        'split' => 'Split into warehouses',
        'opening' => 'Opening balance',
    ];
}
