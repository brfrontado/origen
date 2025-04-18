<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class PaginasAyudaAccess extends Eloquent {
    public $timestamps = false;
    protected $table = 'paginasAyudaAccess';
    protected $primaryKey = 'id';
}
