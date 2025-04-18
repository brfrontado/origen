<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class UserCanalGrupo extends Eloquent {
    public $timestamps = false;
    protected $table = 'usersCanalGrupos';
    protected $primaryKey = 'id';

    public function canal() {
        return $this->belongsTo(UserCanal::class, 'userCanalId', 'id');
    }
    public function grupo() {
        return $this->belongsTo(UserGrupo::class, 'userGroupId', 'id');
    }
}
