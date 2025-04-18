<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserCanal extends Eloquent {
    public $timestamps = false;
    protected $table = 'usersCanal';
    protected $primaryKey = 'id';

    public function grupos() {
        return $this->hasMany(UserCanalGrupo::class, 'userCanalId', 'id');
    }
}
