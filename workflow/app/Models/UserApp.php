<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserApp extends Eloquent {
    public $timestamps = false;
    protected $table = 'users_apps';
    protected $primaryKey = 'id';

    public function user() {
        return $this->belongsTo(User::class, 'userId', 'id');
    }
}
