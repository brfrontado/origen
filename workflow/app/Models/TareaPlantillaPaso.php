<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class TareaPlantillaPaso extends Eloquent {
    public $timestamps = false;
    protected $table = 'tareaPlantillaPasos';
    protected $primaryKey = 'id';

    public function campos() {
        return $this->hasMany(TareaPlantillaDetalle::class, 'pasoId', 'id');
    }
}
