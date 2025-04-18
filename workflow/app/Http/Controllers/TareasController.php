<?php
namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Canales;
use App\Models\CanalesSegmentos;
use App\Models\CanalesUsuarios;
use App\Models\Clientes;
use App\Models\ClientesProductos;
use App\Models\Etapas;
use App\Models\ExpedientesDetail;
use App\Models\Productos;
use App\Models\RequisitosAsignacion;
use App\Models\Tareas;
use App\Models\ExpedientesTareasRespuestas;
use App\Models\Expedientes;
use App\Models\Requisitos;
use App\Models\ExpedientesEtapas;
use App\Models\RequisitosCategorias;
use App\Models\TareasCanales;
use App\Models\TareasEtapas;
use App\Models\TareasUsuarios;
use Carbon\Carbon;
use http\Client\Curl\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use PhpParser\Node\Expr\New_;


class TareasController extends Controller {

    use Response;
    /**
     * Get Steps
     * @param Request $request
     * @return array|false|string
     */
    public function getTareasDisponibles(Request $request) {
        try {
            // Realizar la consulta RAW
            $usuario = auth('sanctum')->user();
            $rolName = $usuario->getRoleNames()[0] ?? '';
            $results = DB::table('tareas')
                ->leftJoin('tareas_etapas', 'tareas.id', '=', 'tareas_etapas.idTarea')
                ->leftJoin('etapas', 'etapas.id', '=', 'tareas_etapas.idEtapa')
                ->select('tareas.id as id', 'tareas.nombre as nombre', 'etapas.id as etapa_id', 'etapas.nombre as etapa_nombre')
                ->get()
                ->groupBy('id')
                ->mapWithKeys(function ($items, $id) use ($rolName) {
                    $etapas = $items->pluck('etapa_id')->toArray();
                    return [$id => [
                        'title' => $items[0]->nombre,
                        'id' => $items[0]->id,
                        'rolName' => $rolName,
                        'etapas' => $etapas,
                    ]];
                })
                ->toArray();


            return $this->ResponseSuccess( 'Ok', $results);
        }
        catch (\Throwable $th) {
                return $this->ResponseError('AUTH-SLIDUTYWEOIUT', 'Error al generar tareas'.$th );
        }
    }
    public function addLeadTask(Request $request) {
        try {
            $validateForm = Validator::make($request->all(), [
                'title' => 'nullable|string',
                'id' => '',
                'delete' => 'nullable|boolean',
                'etapas' => '',
            ]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-OIWEURY5', 'Faltan Campos');
            }
            if(empty($request->delete)) if(empty($request->id)){
                $etapas = new Tareas();
                $etapas->nombre = $request->title??'';
                $etapas->slug = Str::slug($request->nombre??'');

                if($etapas->save()){
                    if(is_array($request->etapas)){
                        foreach ($request->etapas as $etapa) {
                            $etapaTarea = new TareasEtapas();
                            $etapaTarea->idTarea = $etapas->id;
                            $etapaTarea->idEtapa = $etapa;
                            $etapaTarea->save();
                        }
                    }
                    return $this->ResponseSuccess( 'Ok', $etapas);
                }
                else{
                    return $this->ResponseSuccess( 'Ok', []);
                }
            }
            else{
                $etapas = Tareas::where('id', '=', $request->id)->first();
                $etapas->nombre = $request->title??$etapas->nombre;
                //Borro las asignaciones:

                //$etapa->slug = Str::slug($request->nombre??'');

                if($etapas->save()){
                    TareasEtapas::where('idTarea',$request->id)->delete();
                    if(is_array($request->etapas)){
                        foreach ($request->etapas as $etapa) {
                            $etapaTarea = new TareasEtapas();
                            $etapaTarea->idTarea = $etapas->id;
                            $etapaTarea->idEtapa = $etapa;
                            $etapaTarea->save();
                        }
                    }
                    return $this->ResponseSuccess( 'Ok', $etapas);
                }
                else{
                    return $this->ResponseSuccess( 'Ok', []);
                }
            }
            else{
                if(!empty($request->id)){
                    Tareas::where('id', '=', $request->id)->delete();
                    TareasEtapas::where('idTarea',$request->id)->delete();
                    return $this->ResponseSuccess( 'Ok', []);
                }
            }



        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-LKSAUYDI38', 'Error al generar tarea'.$th, );
        }
    }
}
