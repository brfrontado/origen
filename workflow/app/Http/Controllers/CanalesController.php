<?php
namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Canales;
use App\Models\Segmentos;
use App\Models\Tareas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CanalesController extends Controller {

    use Response;



    /**
     * Get Steps
     * @param Request $request
     * @return array|false|string
     */
    public function getLeadChannels(Request $request) {

        try {
            $canales = Canales::orderBy('id', 'ASC')->get();
            if(!empty($canales)){
                return $this->ResponseSuccess( 'Ok', $canales);
            }
            else{
                return $this->ResponseSuccess( 'Ok', []);
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al generar canales');
        }
    }
    public function getTareas(Request $request) {

        try {
            $canales = Tareas::orderBy('id', 'ASC')->get();
            if(!empty($canales)){
                return $this->ResponseSuccess( 'Ok', $canales);
            }
            else{
                return $this->ResponseSuccess( 'Ok', []);
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al generar canales');
        }
    }
    /**
     * Get Steps
     * @param Request $request
     * @return array|false|string
     */
    public function addLeadChannels(Request $request) {

        try {
            $validateForm = Validator::make($request->all(),
                [
                    'nombre' => 'required|string',
                    'correo' => 'required|email',
                    'telefono' => 'required',
                    'categoria' => '',
                    'contactoName' => 'required'
                ]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos');
            }
            $canal = new Canales();
            $canal->nombre = $request->nombre??'';
            $canal->correo = $request->correo??'';
            $canal->telefono = $request->telefono??'';
            $canal->categoria = $request->categoria??'';
            $canal->contactoName = $request->contactoName??'';
            if($canal->save()){
                return $this->ResponseSuccess( 'Ok', $canal);
            }
            else{
                return $this->ResponseSuccess( 'Ok', []);
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al generar canales');
        }
    }
    public function addLeadSegmento(Request $request) {

        try {
            $validateForm = Validator::make($request->all(),
                [
                    'nombre' => 'required|string',
                    'descripcion' => '',
                    'contactoName' => 'required'
                ]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos');
            }
            $canal = new Segmentos();
            $canal->nombre = $request->nombre??'';
            $canal->correo = $request->correo??'';
            $canal->telefono = $request->telefono??'';
            $canal->categoria = $request->categoria??'';
            $canal->contactoName = $request->contactoName??'';
            if($canal->save()){
                return $this->ResponseSuccess( 'Ok', $canal);
            }
            else{
                return $this->ResponseSuccess( 'Ok', []);
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF6440F', 'Error al generar canales');
        }
    }
}
