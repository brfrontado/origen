<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class ProductoMigracion extends Eloquent {
    public $timestamps = false;
    protected $table = 'productoMigracion';
    protected $primaryKey = 'id';

    public function producto() {
        return $this->belongsTo(Producto::class, 'productoId', 'id');
    }

    public function usuario(){
        return $this->belongsTo(User::class, 'usuarioId', 'id');
    }
}
