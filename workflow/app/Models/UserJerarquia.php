<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserJerarquia extends Eloquent {
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $table = 'usersJerarquia';

    public function supervisor() {
        return $this->hasMany(UserJerarquiaSupervisor::class, 'jerarquiaId', 'id');
    }

    public function detalle() {
        return $this->hasMany(UserJerarquiaDetail::class, 'jerarquiaId', 'id');
    }
}
