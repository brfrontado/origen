<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserGrupoUsuario extends Eloquent {
    public $timestamps = false;
    protected $table = 'usersGroupUsuarios';
    protected $primaryKey = 'id';

    public function grupo(){
        return $this->belongsTo('App\Models\UserGrupo','userGroupId');
    }

    public function rol(){
        return $this->belongsTo('App\Models\Rol','rolId');
    }

    public function user(){
        return $this->belongsTo(User::class,'userId', 'id');
    }
}
