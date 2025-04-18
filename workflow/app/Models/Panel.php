<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;


class Panel extends Eloquent {
    public $timestamps = false;
    protected $table = 'panels';
    protected $primaryKey = 'id';

    public function access() {
        return $this->hasMany(UserPanel::class, 'panelId', 'id');
    }

}
