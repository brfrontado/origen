<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class FormularioSeccion extends Eloquent {
    public $timestamps = false;
    protected $table = 'formularioSeccion';
    protected $primaryKey = 'id';

    public function campos() {
        return $this->hasMany(FormularioDetalle::class, 'seccionId', 'id');
    }
}
