<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserJerarquiaSupervisor extends Eloquent {
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $table = 'usersJerarquiaSup';

    public function gruposUsuarios() {
        return $this->hasMany(UserGrupoUsuario::class, 'userGroupId', 'userGroupId');
    }

    public function gruposRol() {
        return $this->hasMany(UserGrupoRol::class, 'userGroupId', 'userGroupId');
    }

    public function rol() {
        return $this->belongsTo(Rol::class, 'rolId', 'id');
    }
}
