<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $guarded = [];

    protected $casts = [
        'team_tags' => 'array',
        'published_at' => 'datetime',
        'fetched_at' => 'datetime',
    ];
}
