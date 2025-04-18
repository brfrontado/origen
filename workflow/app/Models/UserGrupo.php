<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserGrupo extends Eloquent {
    public $timestamps = false;
    protected $table = 'usersGroup';
    protected $primaryKey = 'id';

    public function users() {
        return $this->hasMany(UserGrupoUsuario::class, 'userGroupId', 'id');
    }

    public function roles() {
        return $this->hasMany(UserGrupoRol::class, 'userGroupId', 'id');
    }
}
