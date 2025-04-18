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
use App\Models\TareasUsuarios;
use Carbon\Carbon;
use http\Client\Curl\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Mailgun\Mailgun;
use Matrix\Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Spatie\ArrayToXml\ArrayToXml;
use Illuminate\Support\Facades\Mail as mailer;
use Illuminate\Mail\Message;
use Dompdf\Dompdf;


class EtapasController extends Controller {

    use Response;

    /**
     * Get Steps
     * @param Request $request
     * @return array|false|string
     */
    public function getLeadSteps(Request $request) {
        $validateForm = Validator::make($request->all(), ['canal' => '', 'searchTerm' => '', 'step' => '',]);

        if ($validateForm->fails()) {
            return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos');
        }
        $user = auth('sanctum')->user();
        // dd($request->canal);
        if (!empty($request->canal)) {

            $canalIds = CanalesUsuarios::where('idUser', $user->id)->where('idCanal', $request->canal)->pluck('idCanal')->toArray();
        }
        else {
            $canalIds = CanalesUsuarios::where('idUser', $user->id)->pluck('idCanal')->toArray();
        }
        if (!empty($request->step)) {
            $step = Etapas::where('slug', $request->step)->first();
            $request->step = $step->id ?? 0;
        }
        else {
            $request->step = 0;
        }
        $etapas = Etapas::with(['expedientes' => function ($query) use ($canalIds, $request) {
            $query->whereHas('cliente', function ($query) use ($canalIds, $request) {
                $query->whereIn('canalId', $canalIds);

                if (!empty($request->searchTerm)) {
                    $query->where(function ($query) use ($request) {
                        $query->where('nombres', 'like', '%' . $request->searchTerm . '%')->orWhere('nit', 'like', '%' . $request->searchTerm . '%')->orWhere('identificacion', 'like', '%' . $request->searchTerm . '%')->orWhere('nombreComercial', 'like', '%' . $request->searchTerm . '%');
                    });
                }
            })->with(['cliente' => function ($query) {
                $query->with('productos');
            }, 'expedienteDetail', 'expedienteDetail.requisito'])->leftJoin('clientes', 'expedientes.clienteId', '=', 'clientes.id')->orderBy('clientes.ordenGeneral', 'ASC')->orderBy('expedientes_etapas.dateUpdated', 'ASC');

            // Verificar si $request->step no está vacío
            if (!empty($request->step)) {
                $query->where('expedientes_etapas.etapaId', $request->step);
            }

            // Verificar si $request->signed no está vacío
            if (!empty($request->signed)) {
                //dd('hola');
                $query->whereHas('tareasRespuestas', function ($query) {
                    $query->whereNotNull('vistoBueno');
                });
            }

            $query->take(75);
        }])->orderBy('orden', 'ASC')->get()->all();


        //dd($etapas);

        try {

            if (!empty($etapas)) {
                $arrFinal = array_map(function ($value) {
                    return $this->mapEtapa($value);
                }, $etapas);

                $arrFinal = array_filter($arrFinal);

                return $this->ResponseSuccess('Ok', $arrFinal);
            }


        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF60F', 'Error al generar pasos' . $th);
        }
    }

    public function mapEtapa($value) {
        //if ($value->orden === 1) return;

        $items = [];
        $user = auth('sanctum')->user();
        $etapas = DB::table('etapas')->leftJoin('tareas_etapas', 'etapas.id', '=', 'tareas_etapas.idEtapa')->leftJoin('tareas', 'tareas_etapas.idTarea', '=', 'tareas.id')->leftJoin('tareas_usuarios', 'tareas.id', '=', 'tareas_usuarios.tareaId')->where('tareas_usuarios.usuarioId', '=', $user->id)->select('etapas.nombre as nombreEtapa', 'tareas.nombre as nombreTarea', 'etapas.id as etapaId', 'tareas.id as tareaId', 'tareas.slug as slug')->orderBy('etapas.nombre')->orderBy('tareas.nombre')->get()->toArray();
        $reqControllerHandler = new RequisitosController();
        //dd($etapas);
        $arrIssetEtapa = $reqControllerHandler->array_search_recursive($etapas, 'etapaId', $value->id);

        if (!empty($arrIssetEtapa)) {
            foreach ($value->expedientes as $expediente) {
                $requisitos = [];
                if (!empty($expediente->requisitoCategoriaId)) {
                    $categoria = RequisitosCategorias::find($expediente->requisitoCategoriaId);
                }
                $requisitosBase = RequisitosAsignacion::with('requisito')->where('categoriaRequisitoId', $expediente->requisitoCategoriaId)->get();
                $tareasRespuestas = ExpedientesTareasRespuestas::where('idExpediente', $expediente->id)->join('tareas', 'expedientes_tareas_respuestas.idTarea', '=', 'tareas.id')->join('users', 'expedientes_tareas_respuestas.usuario', '=', 'users.id')->get()->mapToGroups(function ($item) {
                    return [$item->slug => $item];
                })->map(function ($item) {
                    return ['nombre' => $item->pluck('nombre')->first(), 'idTarea' => $item->pluck('idTarea')->first(), 'slug' => $item->pluck('slug')->first(), 'usuario' => $item->pluck('name')->first(), 'fecha' => Carbon::parse($item->pluck('fecha')->first(), 'America/New_York')->setTimezone('America/Guatemala')->toDateTimeString(), 'respuesta' => json_decode($item->pluck('respuesta')->first(), true), 'vistoBueno' => $item->pluck('vistoBueno')->first(),];
                });
                $productosClientes = ClientesProductos::where('idCliente', $expediente->clienteId)->join('productos', 'clientes_productos.idProducto', '=', 'productos.id')->get()->groupBy('sucursal')->map(function ($items) {
                    return $items->groupBy('idProducto')->map(function ($items) {
                        return ['nombreProducto' => $items->pluck('nombreProducto')->first(), 'idProducto' => $items->pluck('idProducto')->first(), 'isVirtual' => $items->pluck('isVirtual')->first(), 'cantidad' => $items->pluck('cantidad')->first(), 'direccion' => $items->pluck('direccion')->first(), 'afiliacion' => ''];
                    });
                });


                foreach ($expediente->expedienteDetail as $detalle) {
                    $requisito = $detalle->requisito;

                    // Verificamos si el requisito ya existe en el array, de lo contrario lo creamos
                    if (!array_key_exists($requisito->id, $requisitos)) {
                        $requisitos[$requisito->id] = ['requisito' => $requisito, 'detalles' => []];
                    }

                    // Creamos la url temporal de los archivos
                    if (!empty($detalle->requisitoS3Key)) {
                        $detalle->url = Storage::disk('s3')->temporaryUrl($detalle->requisitoS3Key, now()->addMinutes(60));
                        $extension = pathinfo($detalle->requisitoS3Key, PATHINFO_EXTENSION);
                        $detalle->extension = strtolower($extension);
                    }
                    else {
                        $detalle->url = '';
                        $detalle->extension = '';
                    }

                    // Agregamos el detalle al array de detalles del requisito
                    if (isset($detalle['requisitoOCR'])) {
                        unset($detalle['requisitoOCR']);
                    }

                    $requisitos[$requisito->id]['detalles'][] = $detalle;

                    $arrValor = json_decode($detalle['requisitoValor'], true);
                    //dd($arrValor);


                    if (is_array($arrValor)) {
                        foreach ($arrValor as $valorFinal) {
                            $valores[$detalle->requisitoId][$valorFinal['Alias']] = !empty($valorFinal['valor-validado']) ? $valorFinal['valor-validado'] : $valorFinal['valor'];
                        }
                    }
                    $arrayValores = array('nombreComercial', 'actividad', 'NIT', 'regimen', 'tipoPersona', 'cui', 'nombres', 'apellidos', 'razonSocial', 'correo', 'monedaCuenta', 'direccion', 'direccionFiscal', 'Banco', 'tipoCuenta', 'noCuenta',);
                }
                $nombres = $expediente->cliente->nombres ?? '';
                // Crear una instancia de Carbon utilizando la fecha de MySQL
                $date = Carbon::parse($expediente->cliente->dateCreated ?? '');

                // Asignar la zona horaria de Guatemala
                $date->setTimezone('America/Guatemala');
                $formattedDate = $date->format('Y-m-d H:i:s');


                $fecha = $formattedDate;
                $canal = $expediente->cliente->canalId ?? 0;
                $segmento = $expediente->cliente->segmento ?? 0;
                $canal = Canales::where('id', $canal)->first();
                $segmento = CanalesSegmentos::where('id', $segmento)->first();
                $resultado = array_filter($etapas, function ($item) use ($value) {
                    return $item->etapaId == $value->id;
                });
                $isBanco = TareasCanales::where('CanalId', $canal->id)->first();
                $isBanco = (!empty($isBanco)) ? true : false;
                $canalString = (!empty($canal->nombre)) ? ($canal->nombre . '' . ((!empty($segmento)) ? '- ' . $segmento->nombre : '')) : '';
                $vistoBueno = !empty($tareasRespuestas['ONB-GENERACION-CONTRATO']['vistoBueno']);
                $items[] = ["id" => "{$value->slug}-{$expediente->id}", "expedienteId" => "{$expediente->id}", "estadoSlug" => "{$value->slug}", "title" => "{$nombres}", "etapa" => "{$value->nombre}", "comments" => 0, "badge-text" => $canalString ?? '', "isBanco" => $isBanco ?? '', "badge" => ($vistoBueno) ? "info" : "danger", "due-date" => $fecha, "attachments" => count($requisitos), "cliente" => $expediente->cliente, "categoria" => $categoria->nombre ?? '', "productos" => $productosClientes ?? [], "archivos" => $requisitos, "tareas" => $resultado, "tareasRespuestas" => $tareasRespuestas ?? [], "valores" => $valores ?? [], "requisitosBase" => $requisitosBase ?? [], "headers" => $arrayValores ?? []];
            }

            return ['id' => $value->slug, 'title' => $value->nombre, 'desc' => $value->descripcion, 'orden' => $value->orden, 'item' => $items,];
        }

    }

    public function addLeadStep(Request $request) {
        try {
            $validateForm = Validator::make($request->all(), ['title' => 'nullable|string', 'desc' => 'nullable|string', 'orden' => 'required', 'id' => '', 'delete' => 'nullable|boolean',]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos');
            }
            if (empty($request->delete)) {
                if (empty($request->id)) {
                    $etapas = new Etapas();
                    $etapas->nombre = $request->title ?? '';
                    $etapas->descripcion = $request->desc ?? '';
                    $etapas->slug = Str::slug($request->nombre ?? '');
                    $etapas->orden = $request->orden ?? 1;

                    if ($etapas->save()) {
                        return $this->ResponseSuccess('Ok', $etapas);
                    }
                    else {
                        return $this->ResponseSuccess('Ok', []);
                    }
                }
                else {
                    $etapas = Etapas::where('slug', '=', $request->id)->first();
                    $etapas->nombre = $request->title ?? $etapas->nombre;
                    $etapas->descripcion = $request->desc ?? $etapas->descripcion;
                    //$etapa->slug = Str::slug($request->nombre??'');
                    $etapas->orden = $request->orden ?? $etapas->orden;

                    if ($etapas->save()) {
                        return $this->ResponseSuccess('Ok', $etapas);
                    }
                    else {
                        return $this->ResponseSuccess('Ok', []);
                    }
                }
            }
            else {
                if (!empty($request->id)) {
                    Etapas::where('id', '=', $request->id)->delete();
                    return $this->ResponseSuccess('Ok', []);
                }
            }


        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF644d80F', 'Error al generar etapa',);
        }
    }

    public function addLeadStepTask(Request $request) {
        try {
            $validateForm = Validator::make($request->all(), ['title' => 'nullable|string', 'desc' => 'nullable|string', 'id' => '', 'delete' => 'nullable|boolean',]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos');
            }
            if (empty($request->delete)) {
                if (empty($request->id)) {
                    $tareas = new Tareas();
                    $tareas->nombre = $request->title ?? '';
                    $tareas->interfaz = $request->desc ?? '';
                    $tareas->slug = Str::slug($request->nombre ?? '');

                    if ($tareas->save()) {
                        return $this->ResponseSuccess('Ok', $tareas);
                    }
                    else {
                        return $this->ResponseSuccess('Ok', []);
                    }
                }
                else {
                    $tareas = Tareas::where('slug', '=', $request->id)->first();
                    $tareas->nombre = $request->title ?? $tareas->nombre;
                    $tareas->interfaz = $request->desc ?? $tareas->interfaz;
                    $tareas->slug = $request->id ?? $tareas->slug;
                    //$tareas->orden = $request->orden??$tareas->orden;

                    if ($tareas->save()) {
                        return $this->ResponseSuccess('Ok', $tareas);
                    }
                    else {
                        return $this->ResponseSuccess('Ok', []);
                    }
                }
            }
            else {
                if (!empty($request->id)) {
                    Etapas::where('id', '=', $request->id)->delete();
                    return $this->ResponseSuccess('Ok', []);
                }
            }


        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF644d80F', 'Error al generar etapa',);
        }
    }

    public function rechazarExpediente(Request $request) {
        try {
            $validateForm = Validator::make($request->all(), ['expediente' => 'required', 'estadoActual' => 'required', 'comentarioRechazo' => '', 'motivoRechazo' => 'required', 'requisitoRechazado' => 'required',]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos');
            }
            $arrFinal = [];
            $usuario = auth('sanctum')->user();
            $expediente = Expedientes::find($request->expediente ?? 0);
            $cliente = Clientes::find($expediente->clienteId);
            $tarea = Tareas::where('slug', 'ONB-RECHAZOS')->first();
            $etapaExp = ExpedientesEtapas::where('expedienteId', $expediente->id)->first();
            $etapa = Etapas::where('id', $etapaExp->etapaId)->first();
            $timelineLink = url('/timeline?cliente=' . $cliente->token);
            $linkInicial = url('/formularios?cliente=' . $cliente->token);
            $linkRequisitos = url('/requisitos?cliente=' . $cliente->token);
            $tareaRespuesta = new ExpedientesTareasRespuestas();
            $tareaRespuesta->idTarea = $tarea->id;
            $tareaRespuesta->idExpediente = $expediente->id;
            $tareaRespuesta->respuesta = json_encode($request->all());
            $tareaRespuesta->usuario = $usuario->id;
            $tareaRespuesta->save();
            $etapaExp->etapaId = $etapa->paso_rechazado;
            $lista = $request->requisitoRechazado ?? [];
            $listaSeparada = implode(" ✔ ", $lista);


            $data = ['linkTimeline' => $timelineLink, 'nombres' => $cliente->nombres ?? '', 'linkInicial' => $linkInicial, 'linkRequisitos' => $linkRequisitos, 'appName' => LgcAppTitle, 'year' => Date('Y'), 'nombreRechazo' => $request->comentarioRechazo ?? $request->motivoRechazo ?? '', 'nombreDocumento' => $listaSeparada ?? ''];

            if ($data) {
                //Actualizo el timeline
                $cliente->ordenGeneral = 1;
                $cliente->save();
                mailer::send('mailer/rechazo-documento', $data, function ($message) use ($cliente) {
                    $message->to($cliente->correo);
                    $message->subject('Actualización en el estado de tu solicitud');
                });

                $etapaExp->save();
                $arrFinal['recargar'] = true;

                return $this->ResponseSuccess('Ok', $arrFinal);
            }
            else {
                return $this->ResponseSuccess('Ok', $arrFinal);
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF644d80F', 'Error al rechazar expediente' . $th);
        }
    }

    public function getEtapasDisponibles(Request $request) {
        try {
            // Realizar la consulta RAW
            $usuario = auth('sanctum')->user();
            $rolName = $usuario->getRoleNames()[0] ?? '';
            $results = DB::table('etapas')->leftJoin('tareas_etapas', 'etapas.id', '=', 'tareas_etapas.idEtapa')->leftJoin('tareas', 'tareas.id', '=', 'tareas_etapas.idTarea')->select('etapas.id as etapa_id', 'etapas.nombre as etapa_nombre', 'etapas.descripcion', 'tareas.id as tarea_id', 'etapas.orden as etapa_orden')->orderBy('etapas.orden', 'asc')->get()->groupBy('etapa_id')->mapWithKeys(function ($items, $etapaId) use ($rolName) {
                $tareas = $items->pluck('tarea_id')->filter(function ($tareaId) {
                    return !is_null($tareaId);
                })->values()->toArray();
                return [$etapaId => ['id' => $etapaId, 'title' => $items[0]->etapa_nombre, 'tareas' => $tareas ?? false, 'desc' => $items[0]->descripcion, 'orden' => $items[0]->etapa_orden, 'rolName' => $rolName,]];
            })->toArray();


            return $this->ResponseSuccess('Ok', $results);
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF644d80F', 'Error al rechazar expediente' . $th);
        }
    }

    public function getTareasDisponibles(Request $request) {
        try {
            // Realizar la consulta RAW
            $usuario = auth('sanctum')->user();
            $rolName = $usuario->getRoleNames()[0] ?? '';
            $results = DB::table('tareas')->select('tareas.id as id', 'tareas.nombre as nombre', 'tareas.slug as slug', 'tareas.interfaz as interfaz')->get()->mapWithKeys(function ($item) use ($rolName) {
                return [$item->id => ['title' => $item->nombre, 'id' => $item->slug, 'desc' => $item->interfaz, 'rolName' => $rolName]];
            })->toArray();


            return $this->ResponseSuccess('Ok', $results);
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF644d80F', 'Error al rechazar expediente' . $th);
        }
    }

    public function guardarTarea(Request $request) {
        try {
            $arrFinal['recargar'] = false;
            $etapaAdmin = false;
            $validateForm = Validator::make($request->all(), ['expediente' => 'required', 'valorTarea' => '',]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos');
            }
            $tarea = Tareas::where('slug', $request->tarea)->first();
            $expediente = Expedientes::where('id', $request->expediente)->first();


            if (!empty($tarea) && !empty($expediente)) {
                $usuario = auth('sanctum')->user();

                $cliente = Clientes::with('expedientes.expedienteDetail')->where('id', $expediente->clienteId)->first();
                $segmento = $cliente->segmento ?? 0;
                if ($segmento != 2) {
                    if ($request->tarea === 'ADMINISTRADOR') {
                        //ExpedientesTareasRespuestas::where('idExpediente',$request->expediente)->where('idTarea',$tarea->id)->delete();
                        $respuesta = new ExpedientesTareasRespuestas();
                        $respuesta->idTarea = $tarea->id;
                        $respuesta->idExpediente = $request->expediente;
                        $datosFormulario = $request->all();
                        $datosFormularioJson = json_encode($datosFormulario);
                        $respuesta->respuesta = $datosFormularioJson;
                        $respuesta->usuario = $usuario->id;
                        //dd($datosFormulario['etapaNueva']);
                        $expedienteEtapa = ExpedientesEtapas::where('expedienteId', '=', $request->expediente ?? 0)->first();
                        $etapaAdmin = Etapas::where('slug', '=', $datosFormulario['etapaNueva'] ?? '')->first();
                        if (!empty($etapaAdmin)) {
                            $expedienteEtapa->etapaId = $etapaAdmin->id;
                            $expedienteEtapa->save();
                        }
                        //return $this->ResponseSuccess('Ok', $arrFinal);
                    }
                    else {
                        ExpedientesTareasRespuestas::where('idExpediente', $request->expediente)->where('idTarea', $tarea->id)->delete();
                        $respuesta = new ExpedientesTareasRespuestas();
                        $respuesta->idTarea = $tarea->id;
                        $respuesta->idExpediente = $request->expediente;
                        $datosFormulario = $request->all();
                        $datosFormularioJson = json_encode($datosFormulario);
                        $respuesta->respuesta = $datosFormularioJson;
                        $respuesta->usuario = $usuario->id;
                    }


                }
                else {
                    if ($request->tarea === 'ADMINISTRADOR') {
                        //ExpedientesTareasRespuestas::where('idExpediente',$request->expediente)->where('idTarea',$tarea->id)->delete();
                        $respuesta = new ExpedientesTareasRespuestas();
                        $respuesta->idTarea = $tarea->id;
                        $respuesta->idExpediente = $request->expediente;
                        $datosFormulario = $request->all();
                        $datosFormularioJson = json_encode($datosFormulario);
                        $respuesta->respuesta = $datosFormularioJson;
                        $respuesta->usuario = $usuario->id;
                        //dd($datosFormulario['etapaNueva']);
                        $expedienteEtapa = ExpedientesEtapas::where('expedienteId', '=', $request->expediente ?? 0)->first();
                        $etapaAdmin = Etapas::where('slug', '=', $datosFormulario['etapaNueva'] ?? '')->first();
                        if (!empty($etapaAdmin)) {
                            $expedienteEtapa->etapaId = $etapaAdmin->id;
                            $expedienteEtapa->save();
                        }
                        //return $this->ResponseSuccess('Ok', $arrFinal);
                    }
                    if ($request->tarea === 'ONB-GENERACION-CONTRATO') {
                        $respuesta = ExpedientesTareasRespuestas::where('idExpediente', $request->expediente)->where('idTarea', $tarea->id)->first();
                        //$respuesta = new ExpedientesTareasRespuestas();
                        $respuesta->idTarea = $tarea->id;
                        $respuesta->idExpediente = $request->expediente;
                        $datosFormulario = $request->all();
                        $datosFormularioJson = json_encode($datosFormulario);
                        $respuesta->respuesta = $datosFormularioJson;
                        $respuesta->usuario = $usuario->id;
                        //dd($datosFormularioJson);
                    }
                    else {
                        ExpedientesTareasRespuestas::where('idExpediente', $request->expediente)->where('idTarea', $tarea->id)->delete();
                        $respuesta = new ExpedientesTareasRespuestas();
                        $respuesta->idTarea = $tarea->id;
                        $respuesta->idExpediente = $request->expediente;
                        $datosFormulario = $request->all();
                        $datosFormularioJson = json_encode($datosFormulario);
                        $respuesta->respuesta = $datosFormularioJson;
                        $respuesta->usuario = $usuario->id;
                        //dd($datosFormularioJson);
                    }
                }
                if ($respuesta->save()) {
                    $requisitos = Requisitos::select('expedientes.id', 'expedientes_detail.requisitoValor')->join('expedientes_detail', 'requisitos.id', '=', 'expedientes_detail.requisitoId')->join('expedientes', 'expedientes_detail.expedienteId', '=', 'expedientes.id')->join('clientes', 'expedientes.clienteId', '=', 'clientes.id')->join('expedientes_tareas_respuestas', 'expedientes.id', '=', 'expedientes_tareas_respuestas.idExpediente')->join('tareas', 'expedientes_tareas_respuestas.idTarea', '=', 'tareas.id')->where('tareas.slug', '=', $tarea->slug)->where('clientes.id', '=', $cliente->id ?? 0)->groupBy('expedientes.id', 'expedientes_detail.requisitoValor')->get();
                    //dd($requisitos);

                    $arrayValores = array('nombreComercial', 'actividad', 'NIT', 'regimen', 'tipoPersona', 'cui', 'nombres', 'apellidos', 'razonSocial', 'correo', 'monedaCuenta', 'direccion', 'direccionFiscal', 'Banco', 'tipoCuenta', 'noCuenta',);
                    $valores = [];
                    $arrFinal = [];
                    $tareasRespuesta = [];

                    foreach ($requisitos as $requisito) {
                        $tareasRespuesta = ExpedientesTareasRespuestas::where('idExpediente', $requisito->id)->where('idTarea', $tarea->id ?? 0)->first();
                        $arrFinal['vistoBueno'] = $tareasRespuesta->vistoBueno;
                        $tareasRespuesta = json_decode($tareasRespuesta->respuesta, true);
                        $arrValor = json_decode($requisito->requisitoValor, true);
                        if (is_array($arrValor)) {
                            foreach ($arrValor as $valorFinal) {
                                //dd($valorFinal);
                                $valores[$valorFinal['Alias']] = !empty($valorFinal['valor-validado']) ? $valorFinal['valor-validado'] : $valorFinal['valor'];;
                            }
                        }
                    }

                    $encabezados = $arrayValores;

                    if (!empty($requisitos)) {
                        foreach ($encabezados as $encabezado) {
                            $arrFinal[$encabezado] = $valores[$encabezado] ?? '';
                        }
                        foreach ($tareasRespuesta as $key => $item) {
                            $arrFinal[$key] = $item;
                        }

                        if ($tarea->finalizaEtapa) {
                            $productos = Productos::whereIn('id', function ($query) use ($expediente) {
                                $query->select('idProducto')->from('clientes_productos')->where('idCliente', $expediente->clienteId);
                            })->get();

                            $onlyFisico = false;
                            $onlyVirtual = false;
                            $onlyMixto = false;
                            //dd( $expediente->clienteId);
                            foreach ($productos as $producto) {

                                if ($producto->isVirtual) {
                                    $onlyVirtual = true;
                                }
                                else {
                                    $onlyFisico = true;
                                }
                                if ($onlyFisico && $onlyVirtual) {
                                    $onlyMixto = true;
                                    $onlyVirtual = false;
                                    $onlyFisico = false;
                                    break;
                                }
                            }
                            $nextEtapa = '';
                            $expedienteEtapa = ExpedientesEtapas::where('expedienteId', '=', $request->expediente ?? 0)->first();
                            $etapa = Etapas::where('id', '=', $expedienteEtapa->etapaId ?? '')->first();
                            //dd("{$onlyMixto},{$onlyFisico}, {$onlyVirtual}");
                            $nextEtapa = $etapa->paso_aceptado;
                            if ($onlyMixto) {
                                $nextEtapa = $etapa->paso_mixto ?? $etapa->paso_aceptado;
                            }
                            if ($onlyFisico) {
                                $nextEtapa = $etapa->paso_fisico ?? $etapa->paso_aceptado;
                            }
                            if ($onlyVirtual) {
                                $nextEtapa = $etapa->paso_virtual ?? $etapa->paso_aceptado;
                            }
                            //dd($datosFormularioJson);
                            //dd($onlyVirtual,$onlyFisico,$onlyMixto,$nextEtapa,$expedienteEtapa);
                            if (!empty($nextEtapa) && !empty($expedienteEtapa)) {
                                $expedienteEtapa->etapaId = $nextEtapa;
                                if ($expedienteEtapa->save()) {
                                    $arrFinal['recargar'] = true;
                                    return $this->ResponseSuccess('Ok', $arrFinal);
                                }
                            }

                        }
                        //dd($arrFinal);
                    }
                    if (!empty($etapaAdmin)) {
                        $arrFinal['recargar'] = true;
                        return $this->ResponseSuccess('Ok', $arrFinal);
                    }
                    return $this->ResponseSuccess('Ok', $arrFinal);
                }
            }
            else {
                return $this->ResponseError('AUTH-AF10dsF', 'No se encontró la tarea');
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF644d80F', 'Error al rechazar expediente' . $th);
        }
    }

    public function guardarResumen(Request $request) {
        try {
            $validateForm = Validator::make($request->all(), ['requisito' => 'required|integer', 'cliente' => 'required', 'formulario' => 'required'

            ]);

            if ($validateForm->fails()) {
                $errores = $validateForm->errors()->keys();
                return $this->ResponseError('FILE-AF5ds834', 'Faltan Campos ' . implode(',', $errores));
            }
            $cliente = Clientes::with('expedientes')->where('token', $request->cliente)->first();
            $expedienteId = $cliente->expedientes->id ?? 0;

            if (!empty($cliente)) {
                if (!empty($expedienteId)) {
                    $detalle = ExpedientesDetail::where('expedienteId', '=', $expedienteId)->where('requisitoId', '=', $request->requisito);
                    if (!empty($detalle->get())) {
                        foreach ($detalle->get() as $item) {
                            $item->requisitoValor = json_encode($request->formulario);
                            $item->save();
                            //Storage::disk('s3')->delete($item->requisitoS3Key);
                        }
                    }

                    return $this->ResponseSuccess('archivo actualizado con éxito', []);

                }
                else {
                    return $this->ResponseError('FILE-ASd5678', 'Error al eliminar archivos, no se encontró al expediente');
                }

            }
            else {
                return $this->ResponseSuccess('No se encontró al cliente', []);
            }

        } catch (\Throwable $th) {
            return $this->ResponseError('CLIENT-AF6994d40F1', 'Error al actualizar el cliente ' . $th);
        }
    }

    public function generarExcel($tarea, $expediente) {
        try {

            $tarea = Tareas::where('slug', $tarea ?? '')->first();
            if ($tarea->slug == 'GENERAR-EXCEL') {
                $user = auth('sanctum')->user();
                //dd($user);
                $requisitos = Requisitos::select('expedientes.id', 'expedientes_detail.requisitoValor', 'expedientes_tareas_respuestas.vistoBueno', 'expedientes_tareas_respuestas.respuesta', 'clientes.nombres', 'clientes.correo', 'clientes.contacto', 'clientes.canalId', 'clientes.usuarioId')->join('expedientes_detail', 'requisitos.id', '=', 'expedientes_detail.requisitoId')->join('expedientes', 'expedientes_detail.expedienteId', '=', 'expedientes.id')->join('clientes', 'expedientes.clienteId', '=', 'clientes.id')->join('canales', 'clientes.canalId', '=', 'canales.id')->join('users_channels', 'canales.id', '=', 'users_channels.idCanal')->join('users', 'users_channels.idUser', '=', 'users.id')->join('expedientes_tareas_respuestas', 'expedientes.id', '=', 'expedientes_tareas_respuestas.idExpediente')->join('tareas', 'expedientes_tareas_respuestas.idTarea', '=', 'tareas.id')->where('tareas.slug', '=', 'ONB-GENERACION-CONTRATO')->where('users.id', '=', $user->id ?? 0)->where('expedientes.id', '=', $expediente ?? 0)->groupBy('expedientes.id', 'expedientes_detail.requisitoValor', 'expedientes_tareas_respuestas.vistoBueno', 'expedientes_tareas_respuestas.respuesta', 'clientes.nombres', 'clientes.correo', 'clientes.contacto', 'clientes.canalId', 'clientes.usuarioId')->get();
                $arrayValores = array('Fecha Firma', 'nombreComercial', 'MCC', 'actividad', 'NIT', 'regimen', 'tipoPersona', 'cui', 'Nombres', 'Ejecutivo', 'Canal', 'razonSocial', 'correo', 'Tasa', 'ADA', 'monedaCuenta', 'Direccion Fiscal', 'Direccion Comercial', 'Telefono', 'Banco', 'tipoCuenta', 'noCuenta',);
                $valores = [];
                foreach ($requisitos as $requisito) {
                    $respuestaTarea = json_decode($requisito->respuesta, true);
                    //dd($respuestaTarea);
                    $arrValor = json_decode($requisito->requisitoValor, true);
                    if (is_array($arrValor)) {
                        foreach ($arrValor as $valorFinal) {
                            $valores[$requisito->id][$valorFinal['Alias']] = !empty($valorFinal['valor-validado']) ? $valorFinal['valor-validado'] : $valorFinal['valor'];

                            if ($valorFinal['Alias'] === 'razonSocial') {
                                $valores[$requisito->id]['Razón Social'] = !empty($valorFinal['valor-validado']) ? $valorFinal['valor-validado'] : $valorFinal['valor'];
                            }
                            if ($valorFinal['Alias'] === 'patComNombre') {
                                $valores[$requisito->id]['Nombre Comercial'] = !empty($valorFinal['valor-validado']) ? $valorFinal['valor-validado'] : $valorFinal['valor'];
                            }
                            if ($valorFinal['Alias'] === 'direccionComercio') {
                                $valores[$requisito->id]['Direccion Comercial'] = !empty($valorFinal['valor-validado']) ? $valorFinal['valor-validado'] : $valorFinal['valor'];
                            }
                            if ($valorFinal['Alias'] === 'direccion') {
                                $valores[$requisito->id]['Direccion Fiscal'] = !empty($valorFinal['valor-validado']) ? $valorFinal['valor-validado'] : $valorFinal['valor'];
                            }
                        }
                        $strCanal = Canales::where('id', $requisito->canalId)->first();
                        $strEjecutivo = \App\Models\User::where('id', $requisito->usuarioId)->first();
                        $valores[$requisito->id]['Fecha Firma'] = $requisito->vistoBueno;
                        $valores[$requisito->id]['MCC'] = $respuestaTarea['mcc'] ?? '';
                        $valores[$requisito->id]['actividad'] = $respuestaTarea['giro'] ?? '';
                        $valores[$requisito->id]['Nombres'] = $requisito->nombres ?? '';
                        $valores[$requisito->id]['Ejecutivo'] = $strEjecutivo->name ?? '';
                        $valores[$requisito->id]['Canal'] = $strCanal->nombre ?? '';
                        $valores[$requisito->id]['Telefono'] = $requisito->contacto ?? '';
                        $valores[$requisito->id]['correo'] = $requisito->correo ?? '';
                        $valores[$requisito->id]['Tasa'] = $respuestaTarea['tasa'] ?? '';
                        $valores[$requisito->id]['ADA'] = $respuestaTarea['ada'] ?? '';
                        // Convertir a mayúsculas todas las claves excepto "correo"
                        foreach ($valores[$requisito->id] as $clave => $valor) {
                            if ($clave !== 'correo') {
                                $valores[$requisito->id][$clave] = strtoupper($valor);
                            }
                            else {
                                $valores[$requisito->id][$clave] = strtolower($valor);
                            }
                        }
                    }
                }
                $encabezados = $arrayValores;
                $excel = new Spreadsheet();
                $hoja = $excel->getActiveSheet();

                // Agregar los encabezados
                $columna = 'A';
                foreach ($encabezados as $nombreColumna) {
                    $hoja->setCellValue($columna . '1', $nombreColumna);
                    $columna++;
                }

                // Agregar los valores
                $fila = 2;
                foreach ($valores as $dato) {
                    $columna = 'A';
                    foreach ($encabezados as $nombreColumna) {
                        if (isset($dato[$nombreColumna])) {
                            $hoja->setCellValue($columna . $fila, $dato[$nombreColumna]);
                        }
                        else {
                            $hoja->setCellValue($columna . $fila, '');
                        }
                        $columna++;
                    }
                    $fila++;
                }
                // Guardar el archivo Excel en un buffer de salida
                $writer = new Xlsx($excel);
                ob_start();
                $writer->save('php://output');
                $archivoExcel = ob_get_clean();

                $archivoBase64 = base64_encode($archivoExcel);


                return $this->ResponseSuccess('Excel generado correctamente', ['excel' => $archivoBase64]);

            }

        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF644d80F', 'Error al rechazar expediente' . $th);
        }
    }

    public function generarExcelGeneral(Request $request) {
        try {
            $user = auth('sanctum')->user();
            $user->rol = $user->getRoleNames()[0] ?? '';
            $query = Clientes::selectRaw('
                                clientes.nombres,
                                clientes.correo,
                                clientes.contacto,
                                clientes.canalId,
                                clientes.segmento,
                                clientes.usuarioId,
                                clientes.dateCreated,
                                clientes.id,
                                expedientes_detail.requisitoValor,
                                expedientes_tareas_respuestas.vistoBueno,
                                expedientes_tareas_respuestas.respuesta,
                                expedientes_tareas_respuestas.usuario,
                                expedientes_tareas_respuestas.fecha,
                                tareas.slug,
                                requisitos.includeReport as requisito,
                                etapas.nombre as nombreEtapa,
                                users.id as usuario
                            ')->join('canales', 'clientes.canalId', '=', 'canales.id')->join('users_channels', 'canales.id', '=', 'users_channels.idCanal')->join('users', 'users_channels.idUser', '=', 'users.id')->leftJoin('expedientes', 'clientes.id', '=', 'expedientes.clienteId')->leftJoin('expedientes_detail', 'expedientes.id', '=', 'expedientes_detail.expedienteId')->leftJoin('expedientes_etapas', 'expedientes.id', '=', 'expedientes_etapas.expedienteId')->leftJoin('etapas', 'expedientes_etapas.etapaId', '=', 'etapas.id')->leftJoin('requisitos', 'expedientes_detail.requisitoId', '=', 'requisitos.id')->leftJoin('expedientes_tareas_respuestas', 'expedientes.id', '=', 'expedientes_tareas_respuestas.idExpediente')->leftJoin('tareas', 'expedientes_tareas_respuestas.idTarea', '=', 'tareas.id')->when($user->rol === 'Operador', function ($query) use ($user) {
                $query->where('clientes.usuarioId', $user->id);
            })->when(in_array($user->rol, ['Super Administrador', 'Administrador']), function ($query) use ($user) {
                $query->where('users.id', $user->id ?? 0);
            })->when(!empty($request->dateIni) && !empty($request->dateFinal), function ($query) use ($request) {
                $query->whereBetween('clientes.dateCreated', [$request->dateIni, $request->dateFinal]);
            })->when(!empty($request->etapaId), function ($query) use ($request) {
                $query->where('expedientes_etapas.etapaId', '=', $request->etapaId);
            })->when(!empty($request->canalId), function ($query) use ($request) {
                $query->where('clientes.canalId', '=', $request->canalId);
            })->groupBy('clientes.id', 'clientes.nombres', 'clientes.correo', 'clientes.contacto', 'clientes.canalId', 'clientes.segmento', 'clientes.usuarioId', 'clientes.dateCreated', 'expedientes.id', 'expedientes_detail.requisitoValor', 'expedientes_tareas_respuestas.vistoBueno', 'expedientes_tareas_respuestas.respuesta', 'expedientes_tareas_respuestas.usuario', 'expedientes_tareas_respuestas.fecha', 'tareas.slug', 'users.id', 'requisitos.includeReport', 'etapas.nombre');


            $requisitos = $query->get();
            //dd($requisitos);

            $arrayValores = [];

            if ($user->rol === 'Operador') {
                $arrayValores = ['Fecha Ingreso', 'nombreEtapa', 'Afiliacion', 'Nombre Comercial', 'NIT', 'Ejecutivo', 'Canal', 'Segmento', 'Producto', 'Cantidad', 'Infraestructura', 'Caso / Terminal'];
            }
            elseif ($user->rol === 'Super Administrador') {
                $arrayValores = ['Fecha Ingreso', 'MCC', 'nombreEtapa', 'Nombre Comercial', 'actividad', 'NIT', 'regimen', 'tipoPersona', 'cui', 'nombres', 'Ejecutivo', 'Canal', 'Segmento', 'Razón Social', 'correo', 'Tasa', 'monedaCuenta', 'Direccion Comercial', 'Direccion Fiscal', 'Telefono', 'Banco', 'tipoCuenta', 'noCuenta', 'Producto', 'Cantidad', 'Afiliacion', 'Infraestructura', 'Caso / Terminal'];
            }
            elseif ($user->rol === 'Administrador') {
                $arrayValores = ['Fecha Ingreso', 'nombreEtapa', 'Afiliacion', 'Nombre Comercial', 'NIT', 'Ejecutivo', 'Canal', 'Segmento', 'Producto', 'Cantidad', 'Afiliacion', 'Infraestructura', 'Caso / Terminal'];
            }

            $valores = [];
            $aliasProductos = ['razonSocial' => 'Razón Social', 'patComNombre' => 'Nombre Comercial', 'direccionComercio' => 'Direccion Comercial', 'direccion' => 'Direccion Fiscal'];

            $productosNames = Productos::all();
            $productosArray = $productosNames->pluck('nombreProducto', 'id')->toArray();
            $canaleName = Canales::all();
            $canalesArray = $canaleName->pluck('nombre', 'id')->toArray();
            $strSegmento = CanalesSegmentos::all();
            $strSegmento = $strSegmento->pluck('nombre', 'id')->toArray();
            $ejecutivo = \App\Models\User::all();
            $ejecutivo = $ejecutivo->pluck('name', 'id')->toArray();

            foreach ($requisitos as $requisito) {

                $arrValor = json_decode($requisito->requisitoValor, true);
                $clientesProductos = DB::table('clientes_productos')->where('idCliente', $requisito->id)->select('sucursal', 'idProducto', 'direccion', 'nombreSucursal', 'cantidad')->groupBy('sucursal', 'idProducto', 'direccion', 'nombreSucursal', 'cantidad')->get()->groupBy('sucursal')->map(function ($group) {
                    return $group->keyBy('idProducto')->map(function ($item) {
                        return ['direccion' => $item->direccion, 'nombreSucursal' => $item->nombreSucursal, 'cantidad' => $item->cantidad, 'producto' => $item->idProducto,];
                    });
                });
                $respuestaTarea = json_decode($requisito->respuesta, true);


                if ($requisito->requisito) {

                    foreach ($clientesProductos as $sucursal => $productos) {
                        foreach ($productos as $indProducto => $detalle) {
                            if (is_array($arrValor)) {
                                foreach ($arrValor as $valorFinal) {
                                    $alias = $valorFinal['Alias'];
                                    $valor = !empty($valorFinal['valor-validado']) ? $valorFinal['valor-validado'] : $valorFinal['valor'];
                                    $valores["{$requisito->id}{$sucursal}{$indProducto}"][$alias] = $valor;
                                    if (isset($aliasProductos[$alias])) {
                                        $clave = $aliasProductos[$alias];
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"][$clave] = $valor;
                                        if (key_exists('noAfiliacion-' . $indProducto . '-' . $sucursal, $respuestaTarea ?? [])) {
                                            $noAfiliacion = $respuestaTarea['noAfiliacion-' . $indProducto . '-' . $sucursal] ?? '';
                                            $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Afiliacion'] = $noAfiliacion;
                                        }
                                        if (key_exists($indProducto . '-' . $sucursal . '-casoInfra', $respuestaTarea ?? [])) {
                                            $casoInfraestructura = $respuestaTarea[$indProducto . '-' . $sucursal . '-casoInfra'];
                                            $casoTerminal = $respuestaTarea[$indProducto . '-' . $sucursal . '-casoTerminal'];
                                            $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Infraestructura'] = $casoInfraestructura;
                                            $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Caso / Terminal'] = $casoTerminal;
                                        }
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Producto'] = $productosArray[$indProducto] ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Fecha Ingreso'] = $requisito->dateCreated;
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['MCC'] = $respuestaTarea['mcc'] ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['actividad'] = $respuestaTarea['giro'] ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Nombres'] = $requisito->nombres ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Ejecutivo'] = $ejecutivo[$requisito->usuarioId] ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Canal'] = $canalesArray[$requisito->canalId] ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Segmento'] = $strSegmento[$requisito->segmento] ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Telefono'] = $requisito->contacto ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['correo'] = $requisito->correo ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Tasa'] = $respuestaTarea['tasa'] ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['nombreEtapa'] = $requisito->nombreEtapa ?? '';
                                        $valores["{$requisito->id}{$sucursal}{$indProducto}"]['Cantidad'] = $detalle['cantidad'] ?? '';
                                    }
                                }
                            }

                        }
                    }

                }

            }

            $encabezados = $arrayValores;

            $excel = new Spreadsheet();
            $hoja1 = $excel->getActiveSheet();
            $hoja1->setTitle('Hoja 1');

            $columna = 'A';
            foreach ($encabezados as $nombreColumna) {
                $hoja1->setCellValue($columna . '1', $nombreColumna);
                $columna++;
            }

            $fila = 2;
            foreach ($valores as $dato) {
                $columna = 'A';
                foreach ($encabezados as $nombreColumna) {
                    if (isset($dato[$nombreColumna])) {
                        $hoja1->setCellValue($columna . $fila, $dato[$nombreColumna]);
                    }
                    else {
                        $hoja1->setCellValue($columna . $fila, '');
                    }
                    $columna++;
                }
                $fila++;
            }

            $writer = new Xlsx($excel);
            ob_start();
            $writer->save('php://output');
            $archivoExcel = ob_get_clean();
            $archivoBase64 = base64_encode($archivoExcel);

            return $this->ResponseSuccess('Excel generado correctamente', ['excel' => $archivoBase64]);
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF644d80F', 'Error al rechazar expediente' . $th);
        }
    }


    public function sendNotifications(Tareas $tareas) {

    }

    public function promoverExpediente(Request $request) {
        try {
            $validateForm = Validator::make($request->all(), ['expediente' => 'required', 'estadoActual' => 'required',]);

            if ($validateForm->fails()) {
                return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos');
            }
            $expedienteEtapa = ExpedientesEtapas::where('expedienteId', '=', $request->expediente ?? 0)->first();
            $expediente = Expedientes::where('id', $expedienteEtapa->expedienteId ?? 0)->first();
            $productos = Productos::whereIn('id', function ($query) use ($expediente) {
                $query->select('idProducto')->from('clientes_productos')->where('idCliente', $expediente->clienteId);
            })->get();

            $onlyFisico = false;
            $onlyVirtual = false;
            $onlyMixto = false;
            foreach ($productos as $producto) {
                if ($producto->isVirtual) {
                    $onlyVirtual = true;
                }
                else {
                    $onlyFisico = true;
                }
                if ($onlyFisico && $onlyVirtual) {
                    $onlyMixto = true;
                    $onlyVirtual = false;
                    $onlyFisico = false;
                    break;
                }
            }
            $nextEtapa = '';
            $etapa = Etapas::where('slug', '=', $request->estadoActual ?? '')->first();
            //dd("{$onlyMixto},{$onlyFisico}, {$onlyVirtual}");
            if ($onlyMixto) {
                $nextEtapa = $etapa->paso_mixto ?? $etapa->paso_aceptado;
            }
            if ($onlyFisico) {
                $nextEtapa = $etapa->paso_fisico ?? $etapa->paso_aceptado;
            }
            if ($onlyVirtual) {
                $nextEtapa = $etapa->paso_virtual ?? $etapa->paso_aceptado;
            }

            if (!empty($nextEtapa) && !empty($expedienteEtapa)) {
                $expedienteEtapa->etapaId = $nextEtapa;
                if ($expedienteEtapa->save()) {
                    return $this->ResponseSuccess('Ok', 'Correo enviado');
                }
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF644d80F', 'Error al rechazar expediente' . $th);
        }
    }

    public function subirArchivo(Request $request) {
        $validateForm = Validator::make($request->all(), ['file.*' => 'file', 'expediente' => 'required|integer', 'slug' => 'required', 'productoId' => 'required', 'requisito' => 'required', 'form' => 'required',

        ]);
        if ($validateForm->fails()) {
            $errores = $validateForm->errors()->keys();
            return $this->ResponseError('CLIENT-AF5ds834', 'Faltan Campos ' . implode(',', $errores));
        }
        $slug = $request->slug;
        $productoId = $request->productoId;
        $file = $request->file;
        $expedienteId = $request->expediente;
        $requisitoId = $request->requisito;

        // Obtener la tarea por su slug
        $user = auth('sanctum')->user();
        $tarea = Tareas::where('slug', $slug)->firstOrFail();
        $tareaUsuario = TareasUsuarios::where('tareaId', $tarea->id)->where('usuarioId', $user->id)->firstOrFail();
        if (!empty($tareaUsuario)) {
            $expediente = Expedientes::where('id', $expedienteId)->first();
            $requisito = Requisitos::where('id', $requisitoId)->first();


            // Obtener el nombre del archivo y su extensión
            $nombreArchivo = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();

            // Crear el nombre del archivo en formato hash y concatenarle la extensión original
            $hashName = md5(time() . $nombreArchivo) . '.' . $extension;

            // Crear la ruta en la que se guardará el archivo en S3
            $dir = '/' . $expediente->clienteId . md5('visanet' . $expediente->clienteId);
            $expiration = date('Y-m-d\TH:i:s\Z', strtotime('+1 week'));

            try {
                // Subir el archivo a S3
                $path = Storage::disk('s3')->putFileAs($dir, $file, $hashName, 'private');

                // Generar la URL con un tiempo de expiración de 15 días
                //traigo la url temporal
                $url = Storage::disk('s3')->temporaryUrl($path, $expiration);
                //dd($request->form);
                $expedienteDetail = new ExpedientesDetail();
                $expedienteDetail->expedienteId = $expediente->id;
                $expedienteDetail->requisitoId = $requisito->id;
                $expedienteDetail->requisitoS3Key = $path;
                $expedienteDetail->requisitoValor = $request->form;
                $expedienteDetail->save();
                // Devolver la URL
                return $this->ResponseSuccess('Ok', ['url' => $url]);

            } catch (S3Exception $e) {
                // En caso de error, devolver null
                return null;
            }

        }
    }

    public function pruebaWs(Request $request) {
        $acsel = new \ACSEL_WS($request, true);
        $data1 = ['funcion' => 'FIND_CLI', 'param5' => '<CLIENTE><CUI>16572888040101</CUI><NIT>16572888040101</NIT></CLIENTE>'];
        $data2 = ['funcion' => 'GENXML', 'param1' => 'GEKO', 'param2' => 'PRODUCTO'];
        //$dataSend = $acsel->post("cotizadores/findCatalogos",$data2, 'CLIENTE');
        $dataSend = $acsel->get("v1/findParentescos");

        dd($dataSend);
    }

    public function findCliente(Request $request) {
        $acsel = new \ACSEL_WS($request, true);
        $data['funcion'] = 'FIND_CLI';
        $data['param1'] = 'GEKO';
        $data['param5'] = '<CLIENTE><NIT>' . $request->nit . '</NIT><CUI>' . $request->cui . '</CUI></CLIENTE>';
        $data1 = ['funcion' => 'FIND_CLI', 'param1' => 'GEKO', 'param5' => ''];
        $data2 = ['funcion' => 'GENXML', 'param1' => 'GEKO', 'param2' => 'PRODUCTO'];
        $dataSend = $acsel->post("cotizadores/findCatalogos", $data, 'CLIENTE');
        //$dataSend = $acsel->get("v1/findParentescos");

        //dd($dataSend);
        return $this->ResponseSuccess('Ok', $dataSend['CLIENTE'][0]['DATO'] ?? []);
    }

    public function findPlanesByProducto(Request $request) {
        $acsel = new \ACSEL_WS($request, false);

        $dataSend = $acsel->get("v1/findPlanesByProducto?pcodprod={$request->plan}");
        return $this->ResponseSuccess('Ok', $dataSend);
        //dd($dataSend);
    }

    public function findParentescos(Request $request) {
        $acsel = new \ACSEL_WS($request, false);

        $dataSend = $acsel->get("v1/findParentescos");
        return $this->ResponseSuccess('Ok', $dataSend);
        //dd($dataSend);
    }

    public function emulateServicioWeb(Request $request) {
        ini_set('max_execution_time', 300);

        $validateForm = Validator::make($request->all(), ['authPayload' => '', 'authUrl' => '', 'authType' => 'required', 'tokenAuth' => '', 'entrada' => '', 'url' => 'required', 'header' => '', 'respuestaXML' => '', 'metodo' => '', 'identificadorWs' => '', 'campos' => '',]);
        if ($validateForm->fails()) {
            $errores = $validateForm->errors()->keys();
            return $this->ResponseError('CLIENT-AF5ds834', 'Faltan Campos ' . implode(',', $errores));
        }
        else {

            $campos = [];
            foreach ($request->campos as $campo) {
                $campos[] = ['campo' => $campo['name'], 'valor' => $campo['value'], 'id' => $campo['name'],];
            }

            $dataTest = ['authUrl' => $request->authUrl, 'authPayload' => $request->authPayload, 'authType' => $request->authType, 'bearerToken' => $request->tokenAuth, 'entrada' => $request->entrada, 'url' => $request->url, 'header' => $request->header, 'respuestaXML' => $request->respuestaXML, 'method' => $request->metodo, 'identificadorWs' => $request->identificadorWs,];

            $tareaHandler = new TareaController();
            $resultado = $tareaHandler->consumirServicio($dataTest, $campos);
            $resultado['log']['data'] = print_r($resultado['data'], true);

            //dd($resultado);

            if ($resultado['status']) {
                return $this->ResponseSuccess('Petición realizada', $resultado);
            }
            else {
                return $this->ResponseError('WS-123', $resultado['msg'], $resultado['data']);
            }
        }

    }

    public function buscarValorEnRespuesta($respuesta, $variableExterna) {
        //var_dump($respuesta);
        //var_dump($variableExterna);
        if (is_array($respuesta) && array_key_exists($variableExterna, $respuesta)) {
            return $respuesta['value'];
        }

        foreach ($respuesta as $key => $value) {
            if (is_array($value)) {
                $valorEncontrado = $this->buscarValorEnRespuesta($value, $variableExterna);
                if ($valorEncontrado !== NULL) {
                    return $valorEncontrado;
                }
            }
        }

        return NULL;
    }

    public function recursiveFind(array $haystack, $needle) {
        $iterator = new RecursiveArrayIterator($haystack);
        $recursive = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($recursive as $key => $value) {
            if ($key === $needle) {
                return $value;
            }
        }
    }

    public function extractConfigProcesoSalida($salidaConfig, $respuesta, $valores) {
        //dd($salidaConfig);
        //dd($respuesta);
        foreach ($salidaConfig as $config) {
            $nombreCampo = $config['nombreCampo'];
            $variableExterna = $config['variableExterna'];

            //var_dump($valores);
            //var_dump($variableExterna);
            $tmpArray = json_decode(json_encode((array)$respuesta), true);
            $valorVariableExterna = $this->recursiveFind($tmpArray, $variableExterna);

            //var_dump($valorVariableExterna);
            //var_dump($nombreCampo);
            //dd($valores);
            //$valorVariableExterna = $this->buscarValorEnRespuesta($tmpArray, $variableExterna);

            if (!empty($valorVariableExterna)) {
                foreach ($valores as &$valor) {
                    if ($valor['id'] === $nombreCampo) {
                        $valor['value'] = $valorVariableExterna;
                        //break;
                    }
                }
            }
        }

        return $valores;
    }

    public function cotizarProducto(Request $request) {
        ini_set('max_execution_time', 300);

        $validateForm = Validator::make($request->all(), ['nodoId' => 'required', 'methodo' => 'required', 'header' => '', 'url' => 'required', 'tipoRespuesta' => '', 'dataToSend' => '', 'productoId' => 'required', 'config' => '', 'valores' => '', 'flujoId' => '', 'salidaReemplazar' => '']);

        $dataResponse = [];
        $dataResponse['url'] = '';
        $dataResponse['metodo'] = $request->methodo;
        $dataResponse['enviadoH'] = '';
        $dataResponse['enviado'] = '';
        $dataResponse['recibido'] = '';

        if ($validateForm->fails()) {
            $errores = $validateForm->errors()->keys();
            return $this->ResponseError('CLIENT-AF5ds834', 'Faltan campos: ' . implode(', ', $errores), $errores, false);
        }
        else {
            $dataToSend = $this->generateXMLFromConfig($request->config, $request->valores, $request->dataToSend);
            $url = $this->generateXMLFromConfig($request->config, $request->valores, $request->url);
            $headers = $this->generateXMLFromConfig($request->config, $request->valores, $request->header);
            $dataSend = [];

            $dataResponse['url'] = $url;

            //dd($dataToSend);


            if ($request->methodo == 'get') {
                $isXML = $request->tipoRespuesta === 'xml';
                $acsel = new \ACSEL_WS($request, $isXML);
                $dataResponse['enviadoH'] = $headers;
                $dataResponse['enviado'] = $dataToSend;
                $dataSend = $acsel->get($url, $dataToSend, $headers, $isXML);
                $dataResponse['recibido'] = $acsel->rawResponse;

                if (!empty($dataSend)) {
                    return $this->ResponseSuccess('Ok', $dataSend ?? []);
                }
                else {
                    return $this->ResponseError('WS-023', 'El servicio no ha devuelto ninguna respuesta (en blanco o null)', $dataSend);
                }
            }
            if ($request->methodo == 'post') {
                $isXML = $request->tipoRespuesta === 'xml';
                $acsel = new \ACSEL_WS($request, $isXML);
                $dataResponse['enviadoH'] = $headers;
                $dataResponse['enviado'] = $dataToSend;
                $dataSend = $acsel->post($url, $dataToSend ?? [], $headers, $isXML);
                $dataResponse['recibido'] = $acsel->rawResponse;
                //Te gusta chingar va!
                $dataResponse['recibidoProcesado'] = $dataSend;
            }

            if (!empty($request->salidaReemplazar)) {
                $valoresReemplazados = $this->extractConfigProcesoSalida($request->salidaReemplazar, $dataSend, $request->valores);
                //VAR_DUMP($valoresReemplazados);

                if (!empty($valoresReemplazados)) {
                    $user = auth('sanctum')->user();
                    $cliente = new Clientes();

                    $data = ['unique_id' => (string)Str::uuid()];
                    $secret_key = 'prueballaveMortalMaestraPOrelmomentosetienequecambiaralennv';
                    $token = hash_hmac('sha256', json_encode($data), $secret_key);
                    $expedienteId = 0;
                    $cliente->token = $token;
                    $cliente->usuarioId = $user->id;

                    if ($cliente->save()) {
                        if (empty($expedienteId)) {
                            $expediente = new Expedientes();
                            $expediente->clienteId = $cliente->id;
                            $expediente->productoId = $request->productoId;
                            $expediente->updateToken = $request->flujoId;
                            $expediente->estado = $request->nodoId;
                            $expediente->save();
                            if (is_array($valoresReemplazados)) {
                                foreach ($valoresReemplazados as $valorActual) {
                                    if (!empty($valorActual['value'])) {
                                        $expedienteDetail = new ExpedientesDetail();
                                        $expedienteDetail->expedienteId = $expediente->id;
                                        $expedienteDetail->requisitoValor = (is_array($valorActual['value'])) ? json_encode($valorActual['value']) : $valorActual['value'];
                                        $expedienteDetail->campoName = (is_array($valorActual['id'])) ? json_encode($valorActual['id']) : $valorActual['id'];
                                        $expedienteDetail->save();
                                    }

                                }
                            }
                        }

                        return $this->ResponseSuccess('Ok', $dataResponse);
                    }
                    else {
                        return $this->ResponseSuccess('Ok', []);
                    }

                }
                else {
                    return $this->ResponseError('WS-024', 'El servicio no ha devuelto ninguna respuesta (en blanco o null)', $dataResponse);
                }
                //$dataSend = $this->extractConfigProcesoSalida($request->salidaReemplazar, $dataSend);
            }
            else {
                if (!empty($dataResponse)) {
                    return $this->ResponseSuccess('Ok', $dataResponse ?? []);
                }
                else {
                    return $this->ResponseError('WS-024', 'El servicio no ha devuelto ninguna respuesta (en blanco o null)', $dataResponse);
                }
            }
        }

    }

    public function buscarValor($id, $arrayValores) {
        foreach ($arrayValores as $item) {
            if ($item['id'] === $id) {
                return $item['value'];
            }
        }
        return null;
    }

    public function generateXMLFromConfig($config, $arrayValores, $texto) {

        //dd($texto);
        //dd($arrayValores);

        if (empty($config)) {
            $result = $texto;
            foreach ($arrayValores as $dataItem) {
                $token = "::" . $dataItem['id'] . "::";
                if (isset($dataItem['value'])) {
                    $valor = is_array($dataItem['value']) ? '' : $dataItem['value'];
                }
                else {
                    $valor = '';
                }
                $result = preg_replace("/" . preg_quote($token) . "/", $valor, $result);
            }
        }
        else {
            $result = $texto;
            foreach ($config as $field) {
                if ($field['esArray']) {
                    $dataArray = $this->buscarValor($field['nombreCampo'], $arrayValores);
                    $reemplazo = '';
                    foreach ($dataArray as $dataItem) {
                        $reemplazo .= "<{$field['variableExterna']}>";
                        $reemplazo .= $this->generateXMLFromConfig($field['subconfiguracion'], $dataItem, '');
                        $reemplazo .= "</{$field['variableExterna']}>";
                    }
                    $token = "::" . $field['nombreCampo'] . "::";
                    $result = preg_replace("/" . preg_quote($token) . "/", $reemplazo, $result);
                }
                else {
                    $token = "::" . $field['nombreCampo'] . "::";
                    if (strpos($result, $token) !== false) {
                        $valor = $this->buscarValor($field['nombreCampo'], $arrayValores);
                        $result = preg_replace("/" . preg_quote($token) . "/", $valor, $result);
                    }
                }
            }
        }
        return $result;
    }


    public function emulateEmail(Request $request) {
        ini_set('max_execution_time', 300);
        $validateForm = Validator::make($request->all(), ['destino' => 'required', 'asunto' => '', 'origen' => '', 'dominio' => '', 'apikey' => '', 'copias' => '', 'body' => 'required',]);
        if ($validateForm->fails()) {
            $errores = $validateForm->errors()->keys();
            return $this->ResponseError('CLIENT-AFdd5ds834', 'Faltan Campos ' . implode(',', $errores));
        }
        else {

            try {
                $mg = Mailgun::create($request->apikey); // For US servers
                $copiasDestinatarios = array_column($request->copias ?? [], 'destino');
                $mg->messages()->send($request->dominio, ['from' => $request->origen ?? '', 'to' => $request->destino ?? '', 'cc' => $copiasDestinatarios, 'subject' => $request->asunto ?? '', 'html' => $request->body ?? '']);
                return $this->ResponseSuccess('Correo enviado', []);
            } catch (Exception $e) {
                return $this->ResponseError('AUTH-RA94', 'Error al enviar correo, verifique el correo o la configuración del sistema');
            }


        }
    }
}

