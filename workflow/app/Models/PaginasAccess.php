<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class PaginasAccess extends Eloquent {
    public $timestamps = false;
    protected $table = 'paginasAccess';
    protected $primaryKey = 'id';
}
