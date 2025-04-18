<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserGrupoRol extends Eloquent {
    public $timestamps = false;
    protected $table = 'usersGroupRoles';
    protected $primaryKey = 'id';

    public function rol() {
        return $this->belongsTo(Rol::class, 'rolId', 'id');
    }
}
