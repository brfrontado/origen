<?php
namespace app\models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Clientes extends Eloquent {
    public $timestamps = false;
    protected $table = 'clientes';
    protected $primaryKey = 'id';
    public function expedientes(){
        return $this->hasOne('App\Models\Expedientes', 'clienteId');
    }

    public function productos(){
        return $this->belongsToMany('App\Models\Productos', 'clientes_productos', 'idCliente', 'idProducto');
    }
}
