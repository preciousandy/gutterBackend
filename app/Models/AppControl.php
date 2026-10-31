<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppControl extends Model
{
    use HasFactory;

    protected $table = 'app_controls';

    protected $fillable = [
        'key',
        'label',
        'description',
        'category',
        'is_enabled',
        'metadata',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Check if a specific feature is enabled.
     */
    public static function isFeatureEnabled(string $key, bool $default = true): bool
    {
        $control = static::where('key', $key)->first();
        return $control ? $control->is_enabled : $default;
    }

    /**
     * Get a setting value by key.
     */
    public static function getSetting(string $key, mixed $default = null): mixed
    {
        $control = static::where('key', $key)->first();
        if (!$control) {
            return $default;
        }

        if (is_array($control->metadata) && array_key_exists('value', $control->metadata)) {
            return $control->metadata['value'];
        }

        return $control->metadata ?? $default;
    }

    /**
     * Set or update a setting value.
     */
    public static function setSetting(string $key, mixed $value, array $attributes = []): static
    {
        $control = static::firstOrNew(['key' => $key]);
        $control->label = $attributes['label'] ?? ($control->label ?? ucwords(str_replace('_', ' ', $key)));
        if (isset($attributes['description'])) {
            $control->description = $attributes['description'];
        }
        if (isset($attributes['category'])) {
            $control->category = $attributes['category'];
        }
        if (isset($attributes['is_enabled'])) {
            $control->is_enabled = (bool) $attributes['is_enabled'];
        }
        $control->metadata = is_array($value) ? $value : ['value' => $value];
        if (!empty($attributes['updated_by'])) {
            $control->updated_by = $attributes['updated_by'];
        }
        $control->save();

        return $control;
    }
}
