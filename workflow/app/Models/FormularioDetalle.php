<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class FormularioDetalle extends Eloquent {
    public $timestamps = false;
    protected $table = 'formularioDetalle';
    protected $primaryKey = 'id';

    public function archivadorDetalle() {
        return $this->belongsTo(ArchivadorDetalle::class, 'archivadorDetalleId', 'id');
    }
}
