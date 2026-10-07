<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A business-wide setting (see App\Support\Branding for the keys in use).
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    public static function many(array $keys): array
    {
        $stored = static::query()->whereKey($keys)->pluck('value', 'key');

        return array_combine($keys, array_map(fn (string $key) => $stored[$key] ?? null, $keys));
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
