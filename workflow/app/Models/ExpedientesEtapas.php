<?php
namespace app\models;

use App\Events\ExpedientesEtapasUpdated;
use Illuminate\Database\Eloquent\Model as Eloquent;


class ExpedientesEtapas extends Eloquent {
    public $timestamps = false;
    protected $table = 'expedientes_etapas';
    protected $primaryKey = 'id';
    /**
     * The event map for the model.
     *
     * @var array
     */
    protected $dispatchesEvents = [
        'updated' => ExpedientesEtapasUpdated::class,
    ];
}
