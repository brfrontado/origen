<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Cotizacion extends Eloquent {
    public $timestamps = false;
    protected $table = 'cotizaciones';
    protected $primaryKey = 'id';

    public function producto(){
        return $this->belongsTo(Productos::class, 'productoId', 'id');
    }

    public function campos(){
        return $this->hasMany(CotizacionDetalle::class, 'cotizacionId', 'id');
    }

    public function usuario(){
        return $this->belongsTo(User::class, 'usuarioId', 'id');
    }

    public function marca(){
        return $this->belongsTo(Marca::class, 'marcaId', 'id');
    }

    public function usuarioAsignado(){
        return $this->belongsTo(User::class, 'usuarioIdAsignado', 'id');
    }

    public function comentarios(){
        return $this->hasMany(CotizacionComentario::class, 'cotizacionId', 'id');
    }

}
