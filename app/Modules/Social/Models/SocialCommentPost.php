<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

class SocialCommentPost extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }
}
