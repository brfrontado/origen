<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class SegmentosAsignacion extends Eloquent {
    public $timestamps = false;
    protected $table = 'canales_segmentos_asignacion';
    protected $primaryKey = 'id';
}
