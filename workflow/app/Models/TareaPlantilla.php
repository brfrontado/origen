<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class TareaPlantilla extends Eloquent {
    public $timestamps = false;
    protected $table = 'tareaPlantilla';
    protected $primaryKey = 'id';

    public function detalle() {
        return $this->hasMany(TareaPlantillaDetalle::class, 'tareaPlantillaId', 'id');
    }
    public function paso() {
        return $this->hasMany(TareaPlantillaPaso::class, 'tareaPlantillaId', 'id');
    }
    /*

    public function rolesAsig() {
        return $this->hasMany(RolApp::class, 'appId', 'id');
    }*/

    /*public function access() {
        return $this->hasMany(RolAccess::class, 'rolId', 'id');
    }*/

    /*public function detalle() {
        return $this->hasMany(ReglaDetalle::class, 'ruleId', 'id');
    }

    public function modificadorCampo() {
        return $this->belongsTo(CatalogoCampos::class, 'fieldCatalogIdModifierField', 'id');
    }*/
}
