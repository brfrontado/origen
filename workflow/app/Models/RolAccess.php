<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class RolAccess extends Eloquent {
    public $timestamps = false;
    protected $table = 'roles_access';
    protected $primaryKey = 'id';

    public function rol() {
        return $this->belongsTo(Rol::class, 'rolId', 'id');
    }
}
