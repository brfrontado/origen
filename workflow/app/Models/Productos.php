<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Productos extends Eloquent {
    public $timestamps = false;
    protected $table = 'productos';
    protected $primaryKey = 'id';
    public function clientes() {
        return $this->belongsToMany(Clientes::class, 'clientes_productos', 'idProducto', 'idCliente');
    }
    public function flujo() {
        return $this->hasMany(Flujos::class, 'productoId', 'id')->where('activo', true);
    }
}
