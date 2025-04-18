<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class ClientesProductos extends Eloquent {
    public $timestamps = false;
    protected $table = 'clientes_productos';
    protected $primaryKey = 'id';
}
