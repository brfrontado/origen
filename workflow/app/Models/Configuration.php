<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Configuration extends Eloquent {
    public $timestamps = false;
    protected $table = 'configuration';

    protected $fillable = [
        'slug',
        'dataText',
        'typeRow',
    ];
    protected $primaryKey = 'id';
}
