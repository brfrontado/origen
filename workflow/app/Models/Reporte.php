<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Reporte extends Eloquent {
    public $timestamps = false;
    protected $table = 'reportes';
    protected $primaryKey = 'id';

    public function producto() {
        return $this->belongsTo(Productos::class, 'productoId', 'id');
    }
   
}
