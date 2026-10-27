<?php

namespace Torgash\Automations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One way of delivering a verification code by an HTTP request
 * (Torgash\Automations\Codes\HttpCodeChannel). Its key in core's channel
 * registry is `http:<id>`, which is what verification_codes.channel holds.
 */
class CodeChannel extends Model
{
    protected $table = 'm_automations_code_channels';

    protected $guarded = ['id'];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'kinds' => 'array',
            'secrets' => 'encrypted:array',
            'enabled' => 'boolean',
            'daily_limit' => 'integer',
            'position' => 'integer',
        ];
    }

    public function key(): string
    {
        return 'http:'.$this->id;
    }

    /** @return list<string> ISO country codes; empty = any country */
    public function countryList(): array
    {
        return array_values(array_filter(array_map('strtoupper', preg_split('/[\s,;]+/', (string) $this->countries) ?: [])));
    }

    /** How a row is named in the guard's audit. */
    public static function auditLabel(): string
    {
        return 'Code delivery channel';
    }
}
