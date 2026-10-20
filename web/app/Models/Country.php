<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A target market. `name` doubles as the AI service's rules profile name
 * (ai-service/app/rules/profiles/<name>.json), so keep them in sync.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $timezone
 * @property list<string>|null $aliases
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['code', 'name', 'timezone', 'aliases', 'is_active', 'sort_order'])]
class Country extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aliases' => 'array', 'is_active' => 'boolean'];
    }

    /** @return Collection<int, Country> */
    public static function active(): Collection
    {
        return self::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Options for dropdowns.
     *
     * @return array<int, array{id: int, name: string, code: string}>
     */
    public static function options(): array
    {
        return self::active()->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code])->values()->all();
    }

    /** Find an active country by name, ISO code or alias (case-insensitive), e.g. "Dubai" -> UAE. */
    public static function resolve(?string $value): ?self
    {
        $needle = mb_strtolower(trim((string) $value));
        if ($needle === '') {
            return null;
        }

        return self::active()->first(fn (Country $c) => in_array($needle, array_map('mb_strtolower',
            [$c->name, $c->code, ...($c->aliases ?? [])]), true));
    }
}
