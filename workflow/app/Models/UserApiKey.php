<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserApiKey extends Eloquent {
    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $table = 'userApiKey';
    protected $primaryKey = 'id';

    public function user() {
        return $this->belongsTo(User::class, 'userId', 'id');
    }
}
