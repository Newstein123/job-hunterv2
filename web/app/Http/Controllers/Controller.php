<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;

abstract class Controller
{
    /** Users only ever see their own records; anything else is a 404. */
    protected function ensureOwns(Request $request, Model $model): void
    {
        abort_unless($model->getAttribute('user_id') === $request->user()->id, 404);
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }

    /**
     * Split a textarea into trimmed, non-empty lines.
     *
     * @return list<string>
     */
    protected function lines(?string $value): array
    {
        $lines = preg_split('/\R/', $value ?? '') ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn (string $v) => $v !== ''));
    }

    /**
     * Split a comma-separated list into trimmed, unique, non-empty values.
     *
     * @return list<string>
     */
    protected function csv(?string $value): array
    {
        $values = array_map('trim', explode(',', $value ?? ''));

        return array_values(array_unique(array_filter($values, fn (string $v) => $v !== '')));
    }
}
