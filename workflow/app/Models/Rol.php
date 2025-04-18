<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Rol extends Eloquent {
    public $timestamps = false;
    protected $table = 'roles';
    protected $primaryKey = 'id';

    public function access() {
        return $this->hasMany(RolAccess::class, 'rolId', 'id');
    }

    public function apps() {
        return $this->hasMany(RolApp::class, 'rolId', 'id');
    }

    public function usersAsig() {
        return $this->hasMany(UserRol::class, 'rolId', 'id');
    }
    /*public function detalle() {
        return $this->hasMany(ReglaDetalle::class, 'ruleId', 'id');
    }

    public function modificadorCampo() {
        return $this->belongsTo(CatalogoCampos::class, 'fieldCatalogIdModifierField', 'id');
    }*/
}
