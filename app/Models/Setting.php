<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value app settings, editable from the admin panel.
 *
 * Read via Setting::read($key, $default) — values are cached per-request and the
 * lookup is guarded so it degrades gracefully to the default (e.g. before the
 * table exists), which lets services fall back to config/.env.
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = ['key', 'value'];

    /** @var array<string, string|null>|null */
    protected static ?array $cache = null;

    /**
     * @return array<string, string|null>
     */
    protected static function loaded(): array
    {
        if (static::$cache === null) {
            try {
                static::$cache = static::query()->pluck('value', 'key')->all();
            } catch (\Throwable $e) {
                static::$cache = [];
            }
        }

        return static::$cache;
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        $value = static::loaded()[$key] ?? null;

        return $value === null ? $default : $value;
    }

    public static function readBool(string $key, bool $default = false): bool
    {
        $value = static::loaded()[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function write(string $key, string|bool|null $value): void
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        if (static::$cache !== null) {
            static::$cache[$key] = $value;
        }
    }
}
