<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Paginas extends Eloquent {
    public $timestamps = false;
    protected $table = 'paginas';
    protected $primaryKey = 'id';

    public function accesos(){
        return $this->hasMany(PaginasAccess::class, 'paginaId', 'id');
    }
}
