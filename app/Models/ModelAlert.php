<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ModelAlert extends Model
{
    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function resolve(?string $note = null): void
    {
        $context = $this->context ?? [];
        if ($note !== null) {
            $context['resolution_note'] = $note;
        }
        $this->update([
            'resolved_at' => now(),
            'context' => $context,
        ]);
    }
}
