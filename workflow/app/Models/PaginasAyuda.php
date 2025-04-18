<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class PaginasAyuda extends Eloquent {
    public $timestamps = false;
    protected $table = 'paginas_ayuda';
    protected $primaryKey = 'id';

    public function accesos(){
        return $this->hasMany(PaginasAyudaAccess::class, 'paginaId', 'id');
    }
}
