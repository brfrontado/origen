<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserLog extends Eloquent {
    public $timestamps = true;
    protected $table = 'usersLog';
    protected $primaryKey = 'id';

    public function user() {
        return $this->belongsTo(User::class, 'userId', 'id');
    }
}
