<?php

namespace Torgash\ShopReviews\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The shop's mark on another site — Google, Hotline, a marketplace — as the
 * shop typed it, shown with a link to where a reader can check it. Never
 * fetched: those sites' terms and keys are the shop's business, and a number
 * that silently stopped updating would be worse than one typed by hand.
 */
class Platform extends Model
{
    protected $table = 'm_shopreviews_platforms';

    protected $fillable = ['name', 'url', 'rating', 'reviews_count', 'sort', 'enabled'];

    public static function auditLabel(): string
    {
        return 'Shop rating on another site';
    }

    public static function auditRevertible(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return ['rating' => 'float', 'reviews_count' => 'int', 'sort' => 'int', 'enabled' => 'bool'];
    }
}
