<?php

namespace Torgash\Checkout\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A question of the shop's own at checkout — "Who takes the parcel" for a
 * courier, "How did you find us", a company's bank details. Asked where the
 * shop placed it, of the buyers and the deliveries it names; the answers go
 * onto the order as a list (Orders\CheckoutFields), never into its notes.
 *
 * `options` is one text per language, a choice per line, matched across
 * languages by POSITION — the order keeps the base language's words, so a
 * count of "Google" answers is one count whatever language they were given in.
 */
class CheckoutField extends Model
{
    use HasTranslations;

    public const TYPES = [
        'text' => 'Line of text',
        'textarea' => 'Several lines',
        'select' => 'Drop-down list',
        'radio' => 'One of several',
        'checkbox' => 'Tick box',
        'date' => 'Date',
    ];

    /** The types that offer a list of answers. */
    public const WITH_OPTIONS = ['select', 'radio'];

    public const PLACES = [
        'recipient' => 'Under the recipient',
        'delivery' => 'Under the delivery',
        'notes' => 'Above the order notes',
    ];

    public const BUYERS = [
        'all' => 'Everyone',
        'person' => 'Private persons only',
        'company' => 'Companies only',
    ];

    protected $table = 'm_checkout_fields';

    protected $guarded = [];

    public static function auditLabel(): string
    {
        return 'Checkout field';
    }

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'help' => 'array',
            'placeholder' => 'array',
            'options' => 'array',
            'shipping_methods' => 'array',
            'required' => 'bool',
            'enabled' => 'bool',
        ];
    }

    public function scopeAsked(Builder $query): Builder
    {
        return $query->where('enabled', true)->orderBy('sort')->orderBy('id');
    }

    /** @return list<string> the choices in a language (the base one's where it has none) */
    public function choices(?string $locale = null): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->t('options', $locale)) ?: []), fn ($line) => $line !== ''));
    }

    /** @return list<int|string> the shipping method ids it is asked for; [] = any */
    public function methodIds(): array
    {
        return array_values(array_filter((array) ($this->shipping_methods ?? [])));
    }

    /**
     * Whether this order is asked it: the buyer it names, the delivery it
     * names (an offer id is "method" or "method:offer"), and a delivery
     * question only of an order that is delivered.
     */
    public function appliesTo(?string $buyer, ?string $offerId, bool $needsDelivery = true): bool
    {
        if ($this->buyer !== 'all' && $this->buyer !== ($buyer ?: 'person')) {
            return false;
        }

        if ($this->place === 'delivery' && ! $needsDelivery) {
            return false;
        }

        $methods = array_map('strval', $this->methodIds());
        if ($methods === []) {
            return true;
        }

        return $needsDelivery && $offerId !== null && $offerId !== ''
            && in_array(explode(':', $offerId, 2)[0], $methods, true);
    }
}
