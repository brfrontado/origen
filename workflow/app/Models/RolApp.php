<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class RolApp extends Eloquent {
    public $timestamps = false;
    protected $table = 'roles_apps';
    protected $primaryKey = 'id';

    public function rol() {
        return $this->belongsTo(Rol::class, 'rolId', 'id');
    }
}
