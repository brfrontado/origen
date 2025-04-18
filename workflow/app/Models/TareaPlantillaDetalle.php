<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class TareaPlantillaDetalle extends Eloquent {
    public $timestamps = false;
    protected $table = 'TareaPlantillaDetalle';
    protected $primaryKey = 'id';

    public function archivadorDetalle() {
        return $this->belongsTo(ArchivadorDetalle::class, 'archivadorDetalleId', 'id');
    }
}
