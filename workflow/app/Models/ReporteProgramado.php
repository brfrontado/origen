<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class ReporteProgramado extends Eloquent {
    public $timestamps = false;
    protected $table = 'reportesProgramados';
    protected $primaryKey = 'id';

    public function detalle() {
        return $this->hasMany(ReporteProgramadoDetalle::class, 'reporteProgramadoId', 'id');
    }

    public function producto() {
        return $this->belongsTo(Productos::class, 'productoId', 'id');
    }
}
