<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class ReporteProgramadoDetalle extends Eloquent {
    public $timestamps = false;
    protected $table = 'reportesProgramadosDetalle';
    protected $primaryKey = 'id';

    public function reporte() {
        return $this->belongsTo(ReporteProgramado::class, 'reporteProgramadoId', 'id');
    }
}
