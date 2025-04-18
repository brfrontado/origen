<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Extra\ClassCache;
use App\Models\ConfiguracionOCR;
use App\Models\Cotizacion;
use App\Models\CotizacionBitacora;
use App\Models\CotizacionComentario;
use App\Models\CotizacionDetalle;
use App\Models\CotizacionDetalleBitacora;
use App\Models\CotizacionesUserNodo;
use App\Models\CotizacionLoteOrden;
use App\Models\CotizacionLoteOperacion;
use App\Models\CotizacionLoteOperacionDetalle;
use App\Models\Flujos;
use App\Models\OrdenAsignacion;
use App\Models\PdfTemplate;
use App\Models\Productos;
use App\Models\Rol;
use App\Models\SistemaVariable;
use App\Models\User;
use App\Models\UserCanalGrupo;
use App\Models\CotizacionOCR;
use App\Models\UserGrupoRol;
use App\Models\UserGrupoUsuario;
use App\Models\UserRol;
use App\Models\ParalizarCarga;
use App\Models\CotizacionCierre;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mailgun\Exception\HttpClientException;
use Mailgun\Mailgun;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use PhpOffice\PhpWord\TemplateProcessor;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\File;
use Illuminate\Http\UploadedFile;


class TareaController extends Controller
{

    use Response;

    public function Load($rolId)
    {

        $item = Formulario::where([['id', '=', $rolId]])->with('seccion', 'seccion.campos', 'seccion.campos.archivadorDetalle', 'seccion.campos.archivadorDetalle.archivador')->first();

        if (!empty($item)) {

            $arrSecciones = $item->toArray();

            usort($arrSecciones['seccion'], function ($a, $b) {
                if ($a['orden'] > $b['orden']) {
                    return 1;
                } elseif ($a['orden'] < $b['orden']) {
                    return -1;
                }
                return 0;
            });

            return $this->ResponseSuccess('Ok', $arrSecciones);
        } else {
            return $this->ResponseError('Aplicación inválida');
        }
    }

    public function IniciarCotizacion(Request $request, $returnArray = false)
    {

        $productoToken = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();

        if (!empty($usuarioLogueado)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/start-cot'])) return $AC->NoAccess();
        }

        // traigo el producto
        $producto = Productos::where([['token', '=', $productoToken]])->first();

        if (empty($producto)) {
            return $this->ResponseError('COT-15', 'Producto inválido');
        }

        if (empty($producto->status)) {
            $variabledesistema = SistemaVariable::where('slug', 'PRODUCTO_MENSAJE')->first();
            $message = 'En este momento no es posible ingresar su solicitud, contacte con la compañia';
            if (!empty($variabledesistema)) {
                $message = $variabledesistema->contenido;
            }
            return $this->ResponseError('', $message);
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('COT-611', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('COT-610', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        // Validación si el nodo es público
        $tipoForm = false;
        foreach ($flujoConfig['nodes'] as $nodo) {
            if (empty($nodo['typeObject'])) continue;

            // si es inicio
            if ($nodo['typeObject'] === 'start' && !empty($nodo['formulario']['tipo'])) {
                $tipoForm = $nodo['typeObject'];
            }
        }

        if (!$tipoForm) {
            return $this->ResponseError('COT-615', 'Error al iniciar flujo, el formulario se encuentra desconfigurado (flujo sin inicio)');
        }

        if ($tipoForm === 'privado' && !$usuarioLogueado) {
            return $this->ResponseError('COT-616', 'Error al iniciar flujo, el formulario no posee visibilidad pública');
        }

        $item = new Cotizacion();
        $item->usuarioId = $usuarioLogueado->id ?? 0;
        $item->usuarioIdAsignado = $usuarioLogueado->id ?? 0;
        $item->token = trim(bin2hex(random_bytes(18))) . time();
        $item->estado = 'creada';
        $item->gbstatus = 'nueva';
        $item->productoId = $producto->id;

        if ($item->save()) {

            if ($returnArray) {
                return ['token' => $item->token, 'id' => $item->id];
            } else {
                return $this->ResponseSuccess('Tarea iniciada con éxito', ['token' => $item->token, 'id' => $item->id]);
            }
        } else {
            if ($returnArray) {
                return false;
            } else {
                return $this->ResponseError('COT-014', 'Error al iniciar tarea, por favor intente de nuevo');
            }
        }
    }

    public function Save(Request $request)
    {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $urlAmigable = $request->get('urlAmigable');
        $activo = $request->get('activo');

        $secciones = $request->get('campos');

        if (!empty($id)) {
            $item = Formulario::where([['id', '=', $id]])->first();
        } else {
            $item = new Formulario();
        }

        $activo = ($activo === 'true' || $activo === true) ? true : false;

        if (empty($item)) {
            return $this->ResponseError('APP-5412', 'Formulario no válido');
        }

        // valido url amigable
        $urlForm = Formulario::where([['urlAmigable', '=', $urlAmigable]])->first();
        if (!empty($urlForm) && !empty($item) && ($item->id !== $urlForm->id)) {
            return $this->ResponseError('APP-0412', 'La url amigable ya se encuentra en uso');
        }

        $item->nombre = $nombre;
        $item->urlAmigable = $urlAmigable;
        $item->activo = $activo;
        $item->save();

        // guardo secciones
        foreach ($secciones as $seccion) {
            //dd($seccion);

            if (!empty($seccion['id'])) {
                $seccionTmp = FormularioSeccion::where([['id', '=', $seccion['id']]])->first();
            } else {
                $seccionTmp = new FormularioSeccion();
            }

            if (empty($seccionTmp)) {
                return $this->ResponseError('APP-S5412', 'Sección inválida');
            }

            $seccionTmp->nombre = $seccion['nombre'] ?? 'Sin nombre de sección';
            $seccionTmp->formularioId = $item->id;
            $seccionTmp->orden = $seccion['orden'];
            $seccionTmp->save();

            // traigo todos los campos
            foreach ($seccion['campos'] as $campo) {

                if (empty($campo['id'])) {
                    $campoTmp = new FormularioDetalle();
                } else {
                    $campoTmp = FormularioDetalle::where('id', $campo['id'])->first();
                }

                $campoTmp->formularioId = $item->id;
                $campoTmp->seccionId = $seccionTmp->id;
                $campoTmp->archivadorDetalleId = $campo['archivadorDetalleId'];
                $campoTmp->nombre = $campo['nombre'];
                $campoTmp->layoutSizePc = $campo['layoutSizePc'] ?? 4;
                $campoTmp->layoutSizeMobile = $campo['layoutSizeMobile'] ?? 12;
                $campoTmp->cssClass = $campo['cssClass'] ?? '';
                $campoTmp->requerido = $campo['requerido'] ?? 0;
                $campoTmp->deshabilitado = $campo['deshabilitado'] ?? 0;
                $campoTmp->visible = $campo['visible'] ?? 1;
                $campoTmp->activo = $campo['activo'] ?? 1;

                $campoTmp->save();
            }
        }

        if (!empty($item)) {
            return $this->ResponseSuccess('Guardado con éxito', $item->id);
        } else {
            return $this->ResponseError('AUTH-RL934', 'Error al crear rol');
        }
    }

    // Cotizaciones

    public function RevivirCotizacion(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/admin/revivir-cot'])) return $AC->NoAccess();

        $cotizacionToken = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = $usuarioLogueado->id ?? 0;

        // traigo el flujo
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionToken]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('COT-R10', 'Flujo inválida');
        }

        $producto = $cotizacion->producto;

        $revivirComportamiento = '';
        if (isset($producto->extraData) && $producto->extraData !== '') {
            $tmp = json_decode($producto->extraData, true);
            $revivirComportamiento = $tmp['revC'] ?? '';
        }

        $item = new Cotizacion();
        $item->usuarioId = $cotizacion->usuarioId ?? 0;
        $item->usuarioIdAsignado = ($usuarioLogueadoId) ? $usuarioLogueadoId : ($cotizacion->usuarioIdAsignado ?? 0);
        $item->token = trim(bin2hex(random_bytes(18))) . time();
        $item->estado = 'creada';
        $item->gbstatus = 'nueva';
        $item->productoId = $cotizacion->productoId;

        // Si hay que revivir desde el último nodo
        if ($revivirComportamiento === 'u') {
            $item->nodoActual = $cotizacion->nodoActual;
        } else if ($revivirComportamiento === 'i') { // nodo inicial
            $item->nodoActual = null;
        } else if ($revivirComportamiento === 'd') { // desactivado
            return $this->ResponseError('COT-R40', 'Revivir flujo desactivado');
        } else {
            return $this->ResponseError('COT-R41', 'Configuración para revivir flujo no seleccionada');
        }

        $item->save();

        $detalleAll = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->get();
        foreach ($detalleAll as $detalle) {
            $newDetalle = $detalle->replicate();
            $newDetalle->cotizacionId = $item->id; // the new project_id
            $newDetalle->save();
        }

        if ($item->save()) {

            // Guardo la bitacora actual
            $bitacoraCoti = new CotizacionBitacora();
            $bitacoraCoti->nodoId = $item->nodoActual;
            $bitacoraCoti->tipo = 'revivir';
            $bitacoraCoti->cotizacionId = $item->id;
            $bitacoraCoti->usuarioId = $usuarioLogueado->id;
            $bitacoraCoti->log = "Flujo revivida por usuario \"{$usuarioLogueado->name}\", desde flujo No.{$cotizacion->id}";
            $bitacoraCoti->save();

            return $this->ResponseSuccess('Flujo revivida con éxito', ['token' => $item->token]);
        } else {
            return $this->ResponseError('COT-R11', 'Error al iniciar tarea, por favor intente de nuevo');
        }
    }

    public function GetCotizacion($cotizacionId)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $usuarioLogueado = $usuario = auth('sanctum')->user();

        $item = Cotizacion::where([['id', '=', $cotizacionId], ['usuarioIdAsignado', '=', $usuarioLogueado->id]])->first();

        if (empty($item)) {
            return $this->ResponseError('COT-016', 'La tarea no existe o se encuentra asignada a otro usuario');
        }

        return $this->ResponseSuccess('Tarea obtenida con éxito', $item);
    }

    // Cotizaciones

    public function GetProductos(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $filterSearch = $request->get('filterSearch');
        $productoId = $request->get('productoId');
        $estadoFilter = $request->get('estadoFilter');

        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');

        $currentPage = $request->get('currentPage') ?? 1;
        $perPage = $request->get('perPage') ?? 20;

        $fechaIni = Carbon::parse($fechaIni);
        $fechaFin = Carbon::parse($fechaFin);
        $fechaIni = $fechaIni->toDateString() . " 00:00:00";
        $fechaFin = $fechaFin->toDateString() . " 23:59:59";

        $usuarioLogueado = auth('sanctum')->user();
        $userHandler = new AuthController();
        $rolUsuarioLogueado = ($usuarioLogueado) ? $usuarioLogueado->rolAsignacion->rol : 0;

        $etapas = [];

        // los productos del usuario
        $productosTmp = DB::table('productos')->get();
        //var_dump($productosTmp);

        $configFlujoEd = [];
        $configFlujo = [];
        foreach ($productosTmp as $producto) {

            $flujo = Flujos::Where('productoId', '=', $producto->id)->where('activo', '=', 1)->first();

            if (isset($producto->extraData) && $producto->extraData !== '') {
                $configFlujoEd[$producto->id] = json_decode($producto->extraData, true);
            }

            $configFlujo[$producto->id] = @json_decode($flujo->flujo_config, true);

            // etapas
            if (empty($configFlujo[$producto->id]) || empty($configFlujo[$producto->id]['nodes'])) continue;
            foreach ($configFlujo[$producto->id]['nodes'] as $node) {
                if (empty($node['typeObject'])) {
                    return $this->ResponseError("Producto con errores ID: {$producto->id}, nodo sin tipo: {$node['id']}");
                }
                if ($node['typeObject'] === 'input' || $node['typeObject'] === 'review' || $node['typeObject'] === 'start') {
                    $etapas[$producto->id][$node['id']] = $node['nodoName'];
                }
            }
        }

        $productosTmp->map(function ($producto) use ($usuarioLogueado, $configFlujoEd, $configFlujo) {


            /*if (!empty($flujo)) {
                $producto->flujo = @json_decode($flujo->flujo_config, true);
                $producto->flujoId = $flujo->id;
            }*/
            $producto->flujo = $configFlujo[$producto->id];
            //$producto->flujoId = $configFlujo[$producto->id]->id;

            $producto->roles_assign = $configFlujoEd[$producto->id]['roles_assign'] ?? [];
            $producto->grupos_assign = $configFlujoEd[$producto->id]['grupos_assign'] ?? [];
            $producto->canales_assign = $configFlujoEd[$producto->id]['canales_assign'] ?? [];

            return $producto;
        });
        //var_dump($productosTmp);

        $authHandler = new AuthController();
        $productos = [];
        foreach ($productosTmp as $pr) {
            $access = $authHandler->CalculateVisibility($usuarioLogueado->id, $rolUsuarioLogueado->id ?? 0, false, $pr->roles_assign ?? [], $pr->grupos_assign ?? [], $pr->canales_assign ?? []);
            if (!$access) continue;
            $productos[] = [
                'id' => $pr->id,
                'token' => $pr->token,
                'nombreProducto' => $pr->nombreProducto,
            ];
        }

        return $this->ResponseSuccess('Productos obtenidos con éxito', ['p' => $productos, 'e' => $etapas]);
    }

    public function GetCotizaciones(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $filterSearch = $request->get('filterSearch');
        $productoId = $request->get('productoId');
        $estadoFilter = $request->get('estadoFilter');
        $gbestadoFilter = $request->get('gbestadoFilter');

        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');


        $currentPage = $request->get('currentPage') ?? 1;
        $perPage = $request->get('perPage') ?? 20;

        $fechaIni = Carbon::parse($fechaIni);
        $fechaFin = Carbon::parse($fechaFin);
        $fechaIni = $fechaIni->toDateString() . " 00:00:00";
        $fechaFin = $fechaFin->toDateString() . " 23:59:59";

        /*$usuarioLogueado = auth('sanctum')->user();
        $rolUsuarioLogueado = ($usuarioLogueado) ? $usuarioLogueado->rolAsignacion->rol : 0;*/
        // dd($usuarioLogueado);

        /*var_dump($productos);
        die();*/

        $userHandler = new AuthController();
        $CalculateAccess = $userHandler->CalculateAccess();
        $CalculateAccessProducts = $userHandler->AccessProducts();

        $items = Cotizacion::where([['dateCreated', '>=', $fechaIni], ['dateCreated', '<=', $fechaFin]])
            ->where(function ($query) use ($CalculateAccess, $CalculateAccessProducts) {
                $query
                    ->whereIn('usuarioIdAsignado', $CalculateAccess['all'])
                    ->orWhereIn('productoId', $CalculateAccessProducts);
            });

        $resultadosEstado = Cotizacion::select(DB::raw('LOWER(estado) as estado'), DB::raw('count(*) as total'))
            ->groupBy(DB::raw('LOWER(estado)'))
            ->where([['dateCreated', '>=', $fechaIni], ['dateCreated', '<=', $fechaFin]])
            ->where(function ($query) use ($CalculateAccess, $CalculateAccessProducts) {
                $query
                    ->whereIn('usuarioIdAsignado', $CalculateAccess['all'])
                    ->orWhereIn('productoId', $CalculateAccessProducts);
            });

        $resultadosGbEstado = Cotizacion::select(DB::raw('LOWER(gbstatus) as gbstatus'), DB::raw('count(*) as total'))
            ->groupBy(DB::raw('LOWER(gbstatus)'))
            ->where([['dateCreated', '>=', $fechaIni], ['dateCreated', '<=', $fechaFin]])
            ->where(function ($query) use ($CalculateAccess, $CalculateAccessProducts) {
                $query
                    ->whereIn('usuarioIdAsignado', $CalculateAccess['all'])
                    ->orWhereIn('productoId', $CalculateAccessProducts);
            });

        if (count($gbestadoFilter) < 6) {
            $items->whereIn('gbstatus', $gbestadoFilter);
            $resultadosEstado->whereIn('gbstatus', $gbestadoFilter);
            $resultadosGbEstado->whereIn('gbstatus', $gbestadoFilter);
        }


        if (!empty($estadoFilter) && $estadoFilter !== '__all__') {
            $items->where('estado', $estadoFilter);
            $resultadosEstado->where('estado', $estadoFilter);
            $resultadosGbEstado->where('estado', $estadoFilter);
        }

        if (!empty($productoId)) {
            $items->where('productoId', $productoId);
            $resultadosEstado->where('productoId', $productoId);
            $resultadosGbEstado->where('productoId', $productoId);
        }
        if (!empty($filterSearch)) {
            $items->where(function ($query) use ($filterSearch) {
                $query->where('id', $filterSearch)
                    ->orWhereHas('usuarioAsignado', function ($subQuery) use ($filterSearch) {
                        $subQuery->where('name', 'LIKE', "%{$filterSearch}%");
                    })
                    ->orWhereHas('usuario', function ($subQuery) use ($filterSearch) {
                        $subQuery->where('name', 'LIKE', "%{$filterSearch}%");
                    })
                    ->orWhereHas('campos', function ($subQuery) use ($filterSearch) {
                        $subQuery->where('useForSearch', 1)->where('valorLong', 'LIKE', "%{$filterSearch}%");
                    });
            });
            $resultadosEstado->where(function ($query) use ($filterSearch) {
                $query->where('id', $filterSearch)
                    ->orWhereHas('usuarioAsignado', function ($subQuery) use ($filterSearch) {
                        $subQuery->where('name', 'LIKE', "%{$filterSearch}%");
                    })
                    ->orWhereHas('usuario', function ($subQuery) use ($filterSearch) {
                        $subQuery->where('name', 'LIKE', "%{$filterSearch}%");
                    })
                    ->orWhereHas('campos', function ($subQuery) use ($filterSearch) {
                        $subQuery->where('useForSearch', 1)->where('valorLong', 'LIKE', "%{$filterSearch}%");
                    });
            });

            $resultadosGbEstado->where(function ($query) use ($filterSearch) {
                $query->where('id', $filterSearch)
                    ->orWhereHas('usuarioAsignado', function ($subQuery) use ($filterSearch) {
                        $subQuery->where('name', 'LIKE', "%{$filterSearch}%");
                    })
                    ->orWhereHas('usuario', function ($subQuery) use ($filterSearch) {
                        $subQuery->where('name', 'LIKE', "%{$filterSearch}%");
                    })
                    ->orWhereHas('campos', function ($subQuery) use ($filterSearch) {
                        $subQuery->where('useForSearch', 1)->where('valorLong', 'LIKE', "%{$filterSearch}%");
                    });
            });
        }

        $totalPages = ceil($items->count() / $perPage);
        if ($currentPage > $totalPages) $currentPage = 1;
        $startIndex = ($currentPage - 1) * $perPage;

        $resultadosEstado = $resultadosEstado->get();
        $resultadosGbEstado = $resultadosGbEstado->get();

        $conteoEstados = [];
        foreach ($resultadosEstado as $key => $resultado) {
            $conteoEstados[$resultado->estado]['n'] = ucwords($resultado->estado);
            $conteoEstados[$resultado->estado]['c'] = $resultado->total;
        }

        $conteoGbEstados = [];
        foreach ($resultadosGbEstado as $key => $resultado) {
            $conteoGbEstados[$resultado->gbstatus]['n'] = ucwords($resultado->gbstatus);
            $conteoGbEstados[$resultado->gbstatus]['c'] = $resultado->total;
        }

        $items = $items
            ->with(['usuario', 'usuarioAsignado', 'producto', 'campos'])
            ->orderBy('id', 'DESC')
            ->skip($startIndex)
            ->take($perPage)
            ->get();

        $arrCache = [];

        foreach ($items as $key => $item) {
            if (!isset($arrCache[$item->productoId])) {

                $flujoConfig = $this->getFlujoFromCotizacion($item);

                if (!$flujoConfig['status']) {
                    // return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
                    continue;
                } else {
                    $flujoConfig = $flujoConfig['data'];
                }
                $arrCache[$item->productoId] = $flujoConfig;
            }
        }

        $cotizaciones = [];


        foreach ($items as $key => $item) {

            if (isset($arrCache[$item->productoId])) {
                $camposCoti = $item->campos->where('useForSearch', 1);
                // campos
                $agenteAsignado = $item->usuarioAsignado->name ?? 'Sin usuario asignado';
                $usuario = $item->usuario->name ?? 'Usuario no disponible';
                $producto = $item->producto->nombreProducto ?? 'Flujo no especificado';

                $searchedOk = false;
                $resumen = [];

                // campos de búsqueda por defecto
                $camposDefault = [
                    ['l' => 'Id', 'v' => $item->id],
                    ['l' => 'A', 'v' => $agenteAsignado],
                    ['l' => 'U', 'v' => $usuario],
                    ['l' => 'PR', 'v' => $producto],
                ];

                foreach ($camposCoti as $tmp) {
                    $valorTmp = (!empty($tmp->valorShow) ? $tmp->valorShow : $tmp->valorLong);
                    $resumen[] = [
                        'l' => $tmp->label,
                        'v' => $valorTmp,
                    ];
                }

                $cotizaciones['c'][$key]['id'] = $item->id;
                $cotizaciones['c'][$key]['dateCreated'] = Carbon::parse($item->dateCreated)->setTimezone('America/Guatemala')->toDateTimeString();
                $cotizaciones['c'][$key]['token'] = $item->token;
                $cotizaciones['c'][$key]['estado'] = $item->estado;
                $cotizaciones['c'][$key]['productoTk'] = $item->producto->token ?? '';
                $cotizaciones['c'][$key]['productoId'] = $item->productoId ?? '0';
                $cotizaciones['c'][$key]['producto'] = $producto;
                $cotizaciones['c'][$key]['usuario'] = $usuario;
                $cotizaciones['c'][$key]['usuarioAsignado'] = $agenteAsignado;
                $cotizaciones['c'][$key]['resumen'] = $resumen;
                $cotizaciones['c'][$key]['expireAt'] = (!empty($item->dateExpire)) ? Carbon::parse($item->dateExpire)->format('d-m-Y') : 'No expira';
            }
        }

        $cotizaciones['e'] = $conteoEstados;
        $cotizaciones['gb'] = $conteoGbEstados;

        $cotizaciones['totalPages'] = $totalPages;
        $cotizaciones['currentPage'] = $currentPage;


        if (empty($items)) {
            return $this->ResponseError('COT-016', 'Tarea inválida');
        }

        return $this->ResponseSuccess('Tareas obtenidas con éxito', $cotizaciones);
    }

    public function getFlujoFromCotizacion($cotizacionObject)
    {

        $fromCache = false;
        if (empty($cotizacionObject)) {
            return $this->ResponseError('COT-4211', 'Flujo inválido', [], false, false);
        }

        $cacheH = ClassCache::getInstance();
        $producto = $cacheH->get("PR_COTI_{$cotizacionObject->id}");
        if (empty($producto)) {
            $producto = $cotizacionObject->producto;
            $cacheH->set("PR_COTI_{$cotizacionObject->id}", $producto);
        }

        if (empty($producto)) {
            return $this->ResponseError('COT-4213', 'Producto no válido', [], false, false);
        }

        $flujo = $cacheH->get("FL_PR_{$producto->id}");
        if (empty($flujo)) {
            $flujo = $producto->flujo->first();
            $cacheH->set("FL_PR_{$producto->id}", $flujo);
        }

        if (empty($flujo)) {
            return $this->ResponseError('COT-4212', 'Flujo no válido', [], false, false);
        }

        $flujoConfig = $cacheH->get("FL_CF_{$flujo->id}");
        if (empty($flujoConfig)) {
            $flujoConfig = @json_decode($flujo->flujo_config, true);
            $cacheH->set("FL_CF_{$flujo->id}", $flujoConfig);
        } else {
            $fromCache = true;
        }

        if (!is_array($flujoConfig)) {
            return $this->ResponseError('COT-610', 'Error al interpretar flujo, por favor, contacte a su administrador', [], false, false);
        }

        return $this->ResponseSuccess(($fromCache ? 'From cache' : 'Ok'), $flujoConfig, false);
    }

    public function GetCotizacionesV2(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $filterSearch = $request->get('filterSearch');
        $productoId = $request->get('productoId');
        $etapaNodoId = $request->get('etapaNodoId');
        $estadoFilter = $request->get('estadoFilter');
        $gbestadoFilter = $request->get('gbestadoFilter');
        $justUser = $request->get('justUser');
        $all = $request->get('all');

        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');

        $fechaIni = Carbon::parse($fechaIni);
        $fechaFin = Carbon::parse($fechaFin);
        $fechaIni = $fechaIni->toDateString() . " 00:00:00";
        $fechaFin = $fechaFin->toDateString() . " 23:59:59";

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = $usuarioLogueado->id;
        /* $rolUsuarioLogueado = ($usuarioLogueado) ? $usuarioLogueado->rolAsignacion->rol : 0;*/
        // dd($usuarioLogueado);

        /*var_dump($productos);
        die();*/

        $userHandler = new AuthController();
        $users = [$usuarioLogueado->id];

        if (empty($justUser)) {
            $CalculateAccess = $userHandler->CalculateAccess();
            $users = $CalculateAccess['all'];
        }

        $items = Cotizacion::whereIn('usuarioIdAsignado', $users)->whereHas('producto');
        if (empty($all)) {
            $items =  $items->where([['dateCreated', '>=', $fechaIni], ['dateCreated', '<=', $fechaFin]]);
        }

        if (count($gbestadoFilter) < 6) $items->whereIn('gbstatus', $gbestadoFilter);

        if (!empty($estadoFilter) && $estadoFilter !== '__all__') {
            $items->where('estado', $estadoFilter);
        }

        if (!empty($etapaNodoId)) {
            $items->where('nodoActual', $etapaNodoId);
        }
        if (!empty($productoId)) {
            $items->where('productoId', $productoId);
        } else if (!empty($filterSearch)) {
            $items->orWhere('id', $filterSearch);
        }

        $items = $items->with(['usuario', 'usuarioAsignado', 'producto', 'campos'])->limit(1500)->orderBy('id', 'DESC')->get();

        $arrCache = [];
        $arrCacheResumen = [];
        $arrCacheResumenSort = [];
        $arrProductos = [];
        // $arrSuspend = [];

        foreach ($items as $key => $item) {
            if (!isset($arrCache[$item->productoId])) {

                $flujoConfig = $this->getFlujoFromCotizacion($item);

                $arrCache[$item->productoId] = $flujoConfig;

                $arrCacheResumen[$item->productoId] = [
                    ['text' => 'No.', 'value' => '_id', 'sortable' => true, 'o' => 0],
                    ['text' => 'Fecha creación', 'value' => '_dateCreated', 'sortable' => true, 'o' => 0],
                    ['text' => 'Estado Gobal', 'value' => '_gbestado', 'sortable' => true, 'o' => 0],
                    ['text' => 'Estado', 'value' => '_estado', 'sortable' => true, 'o' => 0],
                    ['text' => 'Agente asignado', 'value' => '_ag_asig', 'sortable' => true, 'o' => 0],
                    ['text' => 'Creado Por', 'value' => '_creado_p', 'sortable' => true, 'o' => 0],
                ];

                if (!$flujoConfig['status']) {
                    // return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
                    continue;
                } else {
                    $flujoConfig = $flujoConfig['data'];
                }

                foreach ($flujoConfig['nodes'] as $node) {
                    foreach ($node['formulario']['secciones'] as $seccion) {
                        foreach ($seccion['campos'] as $campo) {

                            $exists = false;
                            foreach ($arrCacheResumen[$item->productoId] as $val) {

                                if ($val['value'] === $campo['id']) {
                                    $exists = true;
                                    break;
                                }
                            }

                            if (!empty($campo['showInReports']) && !$exists) {
                                $arrCacheResumen[$item->productoId][] = [
                                    'value' => $campo['id'],
                                    'text' => $campo['nombre'],
                                    'sortable' => true,
                                    'o' => 0,
                                ];
                            }
                        }
                    }
                }

                $arrProductos[$item->productoId] = $item->producto->nombreProducto;
                //$load = ParalizarCarga::where('productoId', $item->productoId)->where('userId', $usuarioLogueadoId)->first();
                // $arrSuspend[$item->productoId] = !empty($load->suspend) ? true : false;
            }
        }

        // var_dump($arrProductos);

        // die;

        $cotizaciones = [];
        // $cotizaciones['p'] = $productos;

        $conteoEstados = [];
        $countGbstatus = [
            'nueva' => ['n' => 'Nueva', 'c' => 0],
            'en proceso' => ['n' => 'En proceso', 'c' => 0],
            'vencida' => ['n' => 'Vencida', 'c' => 0],
        ];
        $resumenH = [];

        foreach ($items as $key => $item) {

            $estado = (!empty($item->estado)) ? $item->estado : 'sin estado';
            if (!isset($conteoEstados[$estado])) {
                $conteoEstados[$estado]['n'] = ucwords($estado);
                $conteoEstados[$estado]['c'] = 1;
            } else {
                $conteoEstados[$estado]['c']++;
            }

            if (isset($arrCache[$item->productoId])) {
                //$camposCoti = CotizacionDetalle::where('cotizacionId', $item->id)->where('useForSearch', 1)->get();
                $camposCoti = $item->campos->where('useForSearch', 1);

                // campos
                $agenteAsignado = $item->usuarioAsignado->name ?? 'Sin usuario asignado';
                $usuario = $item->usuario->name ?? 'Usuario no disponible';
                $producto = $item->producto->nombreProducto ?? 'Flujo no especificado';

                $searchedOk = false;
                $resumen = [
                    '_selected' => false,
                    '_id' => $item->id,
                    '_dateCreated' => $item->dateCreated,
                    '_gbestado' => $item->gbstatus,
                    '_estado' => $item->estado,
                    '_ag_asig' => $agenteAsignado,
                    '_creado_p' => $usuario,
                ];

                if (!empty($arrCacheResumen[$item->productoId])) {
                    foreach ($arrCacheResumen[$item->productoId] as $cache) {

                        if ($cache['value'] === '_id' || $cache['value'] === '_dateCreated' || $cache['value'] === '_estado' || $cache['value'] === '_gbestado' || $cache['value'] === '_ag_asig' || $cache['value'] === '_creado_p') continue;

                        $valorTmp = '';
                        foreach ($camposCoti as $tmp) {
                            if ($tmp->campo === $cache['value']) {
                                $valorTmp = (!empty($tmp->valorShow) ? $tmp->valorShow : $tmp->valorLong);
                            }
                        }
                        $resumen[$cache['value']] = $valorTmp;
                    }
                }

                if (!empty($filterSearch)) {
                    if (!$searchedOk) {
                        foreach ($resumen as $tmp) {
                            if (str_contains(strtolower($tmp), strtolower($filterSearch))) {
                                $searchedOk = true;
                            }
                        }
                    }
                    if (!$searchedOk) continue;
                }

                $cotizaciones['c'][$item->productoId][] = $resumen;

                $gbstatus = $item->gbstatus;
                if (!empty($gbstatus) && !empty($countGbstatus[$gbstatus])) $countGbstatus[$gbstatus]['c']++;
            }
        }

        // orden temporal
        $arrOrdenTmp = [];

        // sort
        foreach ($arrCacheResumen as $prodKey => $item) {
            foreach ($item as $prod) {
                $arrCacheResumenSort[$prodKey] = [];
            }

            $ordenLote = CotizacionLoteOrden::where('productoId', $prodKey)->orderBy('orden', 'asc')->get();
            foreach ($ordenLote as $ordenTmp) {
                $arrOrdenTmp[$ordenTmp->productoId][$ordenTmp->campo] = $ordenTmp->orden;                // $arrCacheResumenOrdenado[]
            }
        }

        // orden del resumen
        $arrCacheResumenOrdenado = [];
        foreach ($arrCacheResumen as $prodKey => $items) {

            foreach ($items as $campoKeyTmp => $campo) {
                if (!empty($arrOrdenTmp[$prodKey])) {

                    foreach ($arrOrdenTmp[$prodKey] as $campoKey => $campoOrder) {
                        if ($campo['value'] === $campoKey) {
                            $arrCacheResumen[$prodKey][$campoKeyTmp]['o'] = $campoOrder;
                        }
                    }
                }
            }
        }

        foreach ($arrCacheResumen as $prodKey => $items) {

            usort($arrCacheResumen[$prodKey], function ($a, $b) {
                if ($a['o'] > $b['o']) {
                    return 1;
                } elseif ($a['o'] < $b['o']) {
                    return -1;
                }
                return 0;
            });
        }

        $cotizaciones['p'] = $arrProductos;
        $cotizaciones['h'] = $arrCacheResumen;
        $cotizaciones['e'] = $conteoEstados;
        $cotizaciones['gb'] = $countGbstatus;
        $cotizaciones['s'] = $arrCacheResumenSort;
        // $cotizaciones['suspend'] = $arrSuspend;

        if (empty($items)) {
            return $this->ResponseError('COT-016', 'Tarea inválida');
        }

        return $this->ResponseSuccess('Tareas obtenidas con éxito', $cotizaciones);
    }

    public function GetCotizacionesFastCount(Request $request, $noJson = false)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $fechaIni = Carbon::now()->subDays(5);
        $fechaFin = Carbon::now();

        $fechaIni = $fechaIni->toDateString() . " 00:00:00";
        $fechaFin = $fechaFin->toDateString() . " 23:59:59";

        $usuarioLogueado = auth('sanctum')->user();

        $items = Cotizacion::where([['usuarioIdAsignado', '=', $usuarioLogueado->id], ['dateCreated', '>=', $fechaIni], ['dateCreated', '<=', $fechaFin]]);
        $items = $items->with(['usuario', 'usuarioAsignado', 'producto', 'campos'])->limit(10)->orderBy('id', 'DESC')->get();

        $cotizaciones = [];
        $conteoEstados = [];

        foreach ($items as $key => $item) {

            $estado = (!empty($item->estado)) ? $item->estado : 'sin estado';
            if (!isset($conteoEstados[$estado])) {
                $conteoEstados[$estado]['n'] = ucwords($estado);
                $conteoEstados[$estado]['c'] = 1;
            } else {
                $conteoEstados[$estado]['c']++;
            }

            $cotizaciones['c'][$key]['id'] = $item->id;
            $cotizaciones['c'][$key]['dateCreated'] = $item->dateCreated;
            $cotizaciones['c'][$key]['token'] = $item->token;
            $cotizaciones['c'][$key]['estado'] = $item->estado;
            $cotizaciones['c'][$key]['productoId'] = $item->productoId ?? '0';
            $cotizaciones['c'][$key]['productoTk'] = $item->producto->token ?? '';
            $cotizaciones['c'][$key]['producto'] = $item->producto->nombreProducto ?? 'Producto no especificado';
            $cotizaciones['c'][$key]['usuario'] = $item->usuario->name ?? '';
            $cotizaciones['c'][$key]['usuarioAsignado'] = $item->usuarioAsignado->name ?? '';
            $cotizaciones['c'][$key]['expireAt'] = (!empty($item->dateExpire)) ? Carbon::parse($item->dateExpire)->format('d-m-Y') : 'No expira';
        }

        $cotizaciones['e'] = $conteoEstados;

        // conteo por productos
        $strQueryFull = "SELECT COUNT(C.id) as c, P.nombreProducto as p, P.id as pid
                        FROM cotizaciones AS C
                        JOIN productos AS P ON C.productoId = P.id
                        WHERE 
                            C.usuarioIdAsignado = '{$usuarioLogueado->id}'
                            AND C.dateCreated >= '{$fechaIni}'
                            AND C.dateCreated <= '{$fechaFin}'
                        AND P.status = 1
                        GROUP BY P.nombreProducto, P.id";

        $cotizaciones['pc'] = DB::select(DB::raw($strQueryFull));

        $cotizaciones['l'] = SistemaVariable::where('slug', 'LINK_AYUDA')->first();

        if ($noJson) {
            return $cotizaciones;
        }

        if (empty($items)) {
            return $this->ResponseError('COT-016', 'Tarea inválida');
        }

        return $this->ResponseSuccess('Tareas obtenidas con éxito', $cotizaciones);
    }

    public function GetCountMyTask(Request $request)
    {
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $allTaks = Cotizacion::where('usuarioIdAsignado', $usuarioLogueadoId)
            ->whereNotIn('estado', ['expirada', 'finalizada', 'cancelada', 'expirado', 'finalizado', 'cancelado'])
            ->whereNotIn('gbstatus', ['finalizada', 'cancelada', 'expirada'])
            ->whereHas('producto');
        $count = $allTaks->count();
        $expire = !empty($allTaks->where('gbstatus', 'vencida')->first());

        return $this->ResponseSuccess('Actualizacion de estado exitosa', ['count' => $count, 'expire' => $expire]);
    }

    public function GetCotizacionResumen(Request $request, $returnArray = false, $camposAllBita = null)
    {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $usuarioLogueado = $usuario = auth('sanctum')->user();
        $cotizacionId = $request->get('token');

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('COT-632', 'Tarea no válida');
        }

        $producto = $cotizacion->producto;
        if (empty($producto)) {
            return $this->ResponseError('COT-600', 'Producto no válido');
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('COT-601', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('COT-601', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        $camposCoti = [];
        if (!empty($camposAllBita)) $camposCoti = $camposAllBita;
        else $camposCoti = $cotizacion->campos;
        // Recorro campos para hacer resumen
        $resumen = [];
        foreach ($flujoConfig['nodes'] as $nodo) {
            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                    $resumen[$keySeccion]['nombre'] = $seccion['nombre'];

                    foreach ($seccion['campos'] as $keyCampo => $campo) {

                        /*var_dump($nodo['id']);
                        var_dump($seccion['nombre']);
                        var_dump($campo['id']);
                        var_dump($campo['visible']);*/

                        if (empty($campo['visible'])) {
                            if (!$AC->CheckAccess(['admin/show-hidden-fields'])) {
                                continue;
                            };
                        }

                        $campoTmp = $camposCoti->where('campo', $campo['id'])->first();

                        if ($returnArray) {
                            $resumen[$keySeccion]['campos'][$campo['id']] = ['value' => $campoTmp->valorLong ?? '', 'label' => $campo['nombre'], 'id' => $campo['id'], 't' => $campo['tipoCampo'], 'vs' => $campoTmp->valorShow ?? ''];
                        } else {
                            if (!empty($campoTmp->valorLong)) {
                                $valorLong = $campoTmp->valorLong;
                                $valorShow = $campoTmp->valorShow ?? '';
                                $campoMulti = $camposCoti->filter(function ($item) use ($campo) {
                                    return (strpos($item['campo'], "{$campo['id']}_") === 0) && is_numeric(substr($item['campo'], strlen($campo['id']) + 1));
                                });
                                if ($campo['tipoCampo'] === 'currency') {
                                    $valorShow = $camposCoti->where('campo', $campo['id'] . '_FORMATEADO')->first()->valorLong ?? '';
                                }
                                if ((count($campoMulti) > 0) && (!empty($nodo['wsLogic']) && $nodo['wsLogic'] === 'a')) $valorLong = array_values(array_map(function ($camp) {
                                    return $camp->valorLong;
                                }, $campoMulti->all()));
                                $resumen[$keySeccion]['campos'][$campo['id']] = ['value' => $valorLong ?? '', 'label' => $campo['nombre'], 'id' => $campo['id'], 't' => $campo['tipoCampo'], 'vs' => $valorShow];
                            }
                        }
                    }
                }
            }
        }

        if ($returnArray) {
            return $resumen;
        } else {
            return $this->ResponseSuccess('Resumen generado con éxito', $resumen);
        }
    }


    public function CambiarUsuarioCotizacion(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/admin/usuario-asignado'])) return $AC->NoAccess();

        $usuario = $request->get('usuarioId');
        $cotizacionId = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();

        $item = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($item)) {
            return $this->ResponseError('COT-015', 'Tarea inválida');
        }

        $usuarioDetail = User::find($usuario);

        // Cambio el estado al nodo actual
        $item->usuarioIdAsignado = $usuario;
        $item->save();

        // Guardo la bitacora actual
        $bitacoraCoti = new CotizacionBitacora();
        $bitacoraCoti->nodoId = $item->nodoActual;
        $bitacoraCoti->tipo = 'cambio_user';
        $bitacoraCoti->cotizacionId = $item->id;
        $bitacoraCoti->usuarioId = $usuarioLogueado->id;
        $bitacoraCoti->log = "Editado usuario asignado por \"{$usuarioLogueado->name}\", asignado: {$usuarioDetail->name}";
        $bitacoraCoti->save();

        if ($item->save()) {
            return $this->ResponseSuccess('Usuario actualizada con éxito', ['id' => $item->id]);
        } else {
            return $this->ResponseError('COT-016', 'Error al actualizar tarea, por favor intente de nuevo');
        }
    }

    public function EditarEstadoCotizacion(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/admin/usuario-asignado'])) return $AC->NoAccess();

        $estado = $request->get('estado');
        $cotizacionId = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();

        $item = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($item)) {
            return $this->ResponseError('COT-015', 'Tarea inválida');
        }

        // Cambio el estado al nodo actual
        $item->estado = $estado;
        $item->save();

        // Guardo la bitacora actual
        $bitacoraCoti = new CotizacionBitacora();
        $bitacoraCoti->cotizacionId = $item->id;
        $bitacoraCoti->nodoId = $item->nodoActual;
        $bitacoraCoti->tipo = 'cambio_estado';
        $bitacoraCoti->usuarioId = $usuarioLogueado->id;
        $bitacoraCoti->log = "Editado estado de flujo, usuario: \"{$usuarioLogueado->name}\", asignado estado: {$estado}";
        $bitacoraCoti->save();

        if ($item->save()) {
            return $this->ResponseSuccess('Estado editado con éxito', ['id' => $item->id]);
        } else {
            return $this->ResponseError('COT-016', 'Error al actualizar flujo, por favor intente de nuevo');
        }
    }

    public function getConstantVars($cotizacion)
    {
        $campos = [];
        $tmpUser = User::where('id', $cotizacion->usuarioId)->first();
        $rolUser = UserRol::where('userId', $cotizacion->usuarioId)->first();
        $rol = null;
        if (!empty($rolUser)) $rol = Rol::where('id', $rolUser->rolId)->first();

        $campos['FECHA_SOLICITUD'] = $cotizacion->dateCreated;
        $campos['FECHA_HOY'] = Carbon::now()->toDateTimeString();
        $campos['ID_SOLICITUD'] = $cotizacion->id;
        $campos['TAREA_TOKEN'] = $cotizacion->token;
        $campos['HOY_SUM_1_YEAR'] = Carbon::now()->addYear()->toDateTimeString();
        $campos['HOY_SUM_1_YEAR_F1'] = Carbon::now()->addYear()->format('d/m/Y');
        $campos['CREADOR_NOMBRE'] = (!empty($tmpUser) ? $tmpUser->name : 'Sin nombre');
        $campos['CREADOR_CORP'] = (!empty($tmpUser) ? $tmpUser->corporativo : 'Sin corporativo');
        $campos['CREADOR_NOMBRE_USUARIO'] = (!empty($tmpUser) ? $tmpUser->nombreUsuario : 'Sin nombre');
        $campos['CREADOR_ROL'] = (!empty($rol) ? $rol->name : 'Sin rol');
        return $campos;
    }

    public function CambiarEstadoCotizacionPublic(Request $request)
    {
        return $this->CambiarEstadoCotizacion($request, false, false, false, true);
    }

    public function CambiarEstadoCotizacionAuto(Request $request)
    {

        $token = $request->get('token');
        $tokenFlujo = $request->get('flujo');
        $campos = $request->get('campos');
        $newTask = false;
        $cotizacionNueva = null;
        $item = null;

        if (empty($token)) {
            if (empty($item)) {
                $requestTmp = new \Illuminate\Http\Request();
                $requestTmp->replace(['token' => $tokenFlujo]);
                $newTask = true;
                $cotizacionNueva = $this->IniciarCotizacion($requestTmp, true);

                if (empty($cotizacionNueva['token'])) {
                    return $cotizacionNueva; // aqui viene siempre un json
                }

                if (!empty($cotizacionNueva['token'])) {
                    $token = $cotizacionNueva['token'];
                }
            }
        } else {
            $item = Cotizacion::where([['token', '=', $token]])->first();
        }

        $requestTmp = new \Illuminate\Http\Request();
        $requestTmp->replace([
            'campos' => $campos,
            'token' => $token,
            'estado' => false,
            'paso' => 'next',
            'seccionKey' => 0,
        ]);

        $tmp = $this->CambiarEstadoCotizacion($requestTmp);
        $tmp = json_decode($tmp, true);

        $calculoVariables = function ($id) {
            $id = intval($id);
            $vars = [];
            $strQueryFull = "SELECT count(nodoId) AS conteo, tipo, nodoId
                            FROM cotizacionesBitacora
                            WHERE cotizacionId = '{$id}'
                            GROUP BY nodoId, tipo";
            $tmpData = DB::select(DB::raw($strQueryFull));
            foreach ($tmpData as $item) {
                $vars[$item->nodoId] = $item->conteo;
            }

            foreach ($vars as $nodo => $value) {
                $campoKey = "SYS_OPT_{$nodo}";
                $campoTmp = CotizacionDetalle::where('cotizacionId', $id)->where('campo', $campoKey)->first();
                if (empty($campoTmp)) {
                    $campoTmp = new CotizacionDetalle();
                }
                $campoTmp->cotizacionId = $id;
                $campoTmp->seccionKey = null;
                $campoTmp->campo = $campoKey;
                $campoTmp->tipo = 'default';
                $campoTmp->valorLong = $value;
                $campoTmp->save();
            }
        };

        if ($newTask) {

            if (!empty($tmp) && !empty($tmp['data']) && !empty($tmp['data']['next']) &&  ($tmp['data']['next']['typeObject'] === 'output' && !empty($tmp['data']['next']['jsonws']))) {

                $camposAll = CotizacionDetalle::where('cotizacionId', $cotizacionNueva['id'])->get();
                $tmp['data']['next']['jsonws'] = $this->reemplazarValoresSalida($camposAll, $tmp['data']['next']['jsonws']);

                $dataTmp =  json_decode($tmp['data']['next']['jsonws'], true);
                $cotizacionNueva['params'] = $dataTmp;
            }
            $calculoVariables($cotizacionNueva['id']);
            return $this->ResponseSuccess('Tarea creada exitosamente', $cotizacionNueva);
        } else {
            if (empty($tmp['status'])) {
                return $this->ResponseError('COT-421', 'Error al actualizar tarea');
            } else {
                $dataTmpS = [];

                if (!empty($tmp) && !empty($tmp['data']) && !empty($tmp['data']['next']) &&  ($tmp['data']['next']['typeObject'] === 'output'  && !empty($tmp['data']['next']['jsonws']))) {
                    $camposAll = CotizacionDetalle::where('cotizacionId', $item->id)->get();
                    $tmp['data']['actual']['jsonws'] = $this->reemplazarValoresSalida($camposAll, $tmp['data']['next']['jsonws']);

                    $dataTmpS =  json_decode($tmp['data']['actual']['jsonws'], true);
                }
                $calculoVariables($item->id);
                return $this->ResponseSuccess('Tarea actualizada exitosamente', [
                    'params' => $dataTmpS
                ]);
            }
        }
    }

    public function CambiarEstadoCotizacion(Request $request, $recursivo = false, $desdeDecision = false, $originalStep = false, $public = false, $count = 0)
    {

        ini_set('max_execution_time', '600');

        $campos = $request->get('campos');
        $paso = $request->get('paso');
        $estado = $request->get('estado');
        $gbstatus = $request->get('gbstatus');
        $token = $request->get('token');
        $seccionKey = $request->get('seccionKey');
        $comentarioRechazo = $request->get('rG');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
        }

        // Actual
        $userHandler = new AuthController();
        $CalculateAccess = $userHandler->CalculateAccess();

        // si es supervisor
        $arrUsers = false;
        if (in_array($usuarioLogueadoId, $CalculateAccess['sup'])) {
            $arrUsers = $CalculateAccess['all'];
        } else {
            $arrUsers = $CalculateAccess['det'];
        }

        $item = Cotizacion::where([['token', '=', $token]])->first();
        if (!$recursivo && !$desdeDecision) {
            $item->dateStepChange = null;
            $item->dateExpireUserAsig = null;
        };

        if (empty($item)) {
            return $this->ResponseError('COT-015', 'Tarea inválida');
        }

        if (
            !empty($usuarioLogueadoId)
            && ($usuarioLogueado->id !== $item->usuarioIdAsignado) && !$recursivo
        ) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/modificar'])) return $AC->NoAccess();
        }

        $lastCotizacionesUserNodo = CotizacionesUserNodo::where('cotizacionId', $item->id)->orderBy('id', 'desc')->first();
        $idLastCotiUserNodo = !empty($lastCotizacionesUserNodo) ? $lastCotizacionesUserNodo->id : null;

        if (!empty($gbstatus) && !$recursivo) {
            $item->gbstatus = $gbstatus;
            $item->save();

            $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', 'ESTADO_GLOBAL_ACTUAL')->first();
            if (empty($campo)) {
                $campo = new CotizacionDetalle();
            }

            $campo->cotizacionId = $item->id;
            $campo->seccionKey = $seccionKey;
            $campo->campo = 'ESTADO_GLOBAL_ACTUAL';
            $campo->label = '';
            $campo->useForSearch = 0;
            $campo->tipo = 'default';
            $campo->valorLong = $gbstatus;
            $campo->save();

            $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
        };

        // cambio de estado a cancelada
        if (!empty($estado)) {
            $item->estado = $estado;
            $item->save();

            $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', 'ESTADO_ACTUAL')->first();
            if (empty($campo)) {
                $campo = new CotizacionDetalle();
            }
            $campo->cotizacionId = $item->id;
            $campo->seccionKey = $seccionKey;
            $campo->campo = 'ESTADO_ACTUAL';
            $campo->label = '';
            $campo->useForSearch = 0;
            $campo->tipo = 'default';
            $campo->valorLong = $estado;
            $campo->save();

            $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);

            return $this->ResponseSuccess('Estado actualizado con éxito');
        }

        // Recorro campos para tener sus datos de configuración
        $flujoConfig = $this->getFlujoFromCotizacion($item);
        $fieldsData = [];
        if (!empty($flujoConfig['data']['nodes'])) {
            foreach ($flujoConfig['data']['nodes'] as $nodo) {
                //$resumen
                if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {
                    foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                        foreach ($seccion['campos'] as $keyCampo => $campo) {
                            $fieldsData[$campo['id']] = $campo;
                        }
                    }
                }
            }
        }

        $flujo = $this->CalcularPasos($request, true, $public, true);

        if (empty($flujo['actual']['nodoId'])) {
            return $this->ResponseError('COT-010', 'Hubo un error al calcular el flujo, por favor intente de nuevo');
        }

        // Cambio el estado al nodo actual
        if (!empty($flujo['actual']['estOut']) && ($flujo['actual']['estIo'] === 's')) $item->estado = $flujo['actual']['estOut'];

        // Si se está saliendo de un rechazo
        if ($flujo['actual']['typeObject'] === 'review') {
            if ($desdeDecision) {
                return $this->ResponseSuccess('Tarea actualizada con éxito', $flujo);
            } else {
                $rechazo = false;
                $camposTmp = [];
                foreach ($campos as $campoKey => $valor) {
                    if (!empty($valor['r'])) {
                        $rechazo = true;
                        $camposTmp[$campoKey] = true;
                    }
                }

                if ($rechazo) {
                    // guardo operación del nodo
                    $userNodo = new CotizacionesUserNodo();
                    $userNodo->cotizacionId = $item->id;
                    $userNodo->usuarioId = $usuarioLogueadoId;
                    $userNodo->nodoId = $flujo['actual']['nodoId'];
                    $userNodo->comentario = 'Rechazado';
                    $userNodo->save();

                    $rechazoTmp = @json_decode($item->rechazoData, true);
                    if (is_array($rechazoTmp)) {
                        $tmp = [];
                        $tmp['f'] = $camposTmp;
                        $tmp['c'] = $comentarioRechazo;
                        $tmp['d'] = Carbon::now()->format('d-m-Y H:i');
                        $rechazoTmp[$flujo['actual']['nodoId']][] = $tmp;
                    } else {
                        $rechazoTmp = [];
                        $tmp = [];
                        $tmp['f'] = $camposTmp;
                        $tmp['c'] = $comentarioRechazo;
                        $tmp['d'] = Carbon::now()->format('d-m-Y H:i');
                        $rechazoTmp[$flujo['actual']['nodoId']][] = $tmp;
                    }

                    $item->ultRechazo = $flujo['actual']['nodoId'];
                    $item->rechazoData = json_encode($rechazoTmp);
                    $item->save();

                    // si se rechazó, lo devuelvo
                    $flujoConfig = $this->getFlujoFromCotizacion($item);
                    $flujoConfig = $flujoConfig['data'];

                    $nodoRegresar = false;
                    foreach ($flujoConfig['nodes'] as $key => $nodo) {
                        //var_dump($nodo);
                        if (empty($nodo['typeObject'])) continue;

                        foreach ($nodo['formulario']['secciones'] as $seccion) {
                            foreach ($seccion['campos'] as $campo) {

                                // si encuentra el campo, lo regresa
                                if (isset($camposTmp[$campo['id']])) {

                                    // voy a traer el usuario que operó el nodo
                                    $userNodo = CotizacionesUserNodo::where('cotizacionId', $item->id)->where('nodoId', $nodo['id'])->orderBy('createdAt', 'DESC')->first();

                                    $nodoRegresar = $nodo['id'];
                                    $item->nodoActual = $nodoRegresar;
                                    $item->nodoPrevio = $flujo['actual']['nodoId'];
                                    $item->usuarioIdAsignado = $userNodo->usuarioId ?? 0;
                                    if (!empty($nodo['estOut']) && ($nodo['estIo'] === 'e')) $item->estado = $nodo['estOut'];
                                    $item->save();

                                    // Guardo la bitacora actual
                                    if (!empty($userNodo->usuarioId)) {
                                        $bitacoraCoti = new CotizacionBitacora();
                                        $bitacoraCoti->nodoId = $item->nodoActual;
                                        $bitacoraCoti->tipo = 'user_asig';
                                        $bitacoraCoti->cotizacionId = $item->id;
                                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                        $bitacoraCoti->log = "Asignado usuario automático por rechazo de campos \"{$flujo['actual']['label']}\"";
                                        $bitacoraCoti->save();
                                    }


                                    return $this->ResponseSuccess('Tarea actualizada con éxito', $flujo);
                                }
                                if ($nodoRegresar) break;
                            }
                            if ($nodoRegresar) break;
                        }
                    }
                }
            }
            // quita los campos para que en los rechazos no se guarde nada
            $campos = false;
        }

        if (!$originalStep) {
            $originalStep = $item->nodoActual;
        }

        // se guarda el nodo actual
        if (!$recursivo && $paso === 'next') {
            if (!empty($item->nodoActual)) {
                $item->nodoPrevio = $item->nodoActual;
            }
        }

        $item->nodoActual = $flujo['actual']['nodoId'];
        $item->save();

        // guardo operación del nodo
        $userNodo = new CotizacionesUserNodo();
        $userNodo->cotizacionId = $item->id;
        $userNodo->usuarioId = $usuarioLogueadoId;
        $userNodo->nodoId = $flujo['actual']['nodoId'];
        if ($flujo['actual']['typeObject'] === 'review') $userNodo->comentario = 'Aprobado';
        $userNodo->save();
        $campoAprobacion = true;
        $hasCampoAprobacion = false;

        $idLastCotiUserNodo = $userNodo->id;
        if (!empty($campos) && !empty($flujo['actual']['nodoNameId'])) {
            $nodeUser = User::where('id', $usuarioLogueadoId)->first();
            $rolUser = UserRol::where('userId', $usuarioLogueadoId)->first();
            $rol = null;
            if (!empty($rolUser)) $rol = Rol::where('id', $rolUser->rolId)->first();

            $campos['FECHA_ACT_NODO_' . $flujo['actual']['nodoNameId']]['v'] = Carbon::parse($userNodo->createdAt)->setTimezone('America/Guatemala')->format('d/m/Y H:i');
            $campos['USUARIO_ACT_NODO_' . $flujo['actual']['nodoNameId']]['v'] = (!empty($nodeUser) ? $nodeUser->name : 'Sin nombre');
            $campos['ID_USUARIO_ACT_NODO_' . $flujo['actual']['nodoNameId']]['v'] = (!empty($nodeUser) ? $nodeUser->id : 'Sin Id');
            $campos['ROL_USUARIO_ACT_NODO_' . $flujo['actual']['nodoNameId']]['v'] = (!empty($rol) ? $rol->name : 'Sin rol');
            $campos['CORP_USUARIO_ACT_NODO_' . $flujo['actual']['nodoNameId']]['v'] = (!empty($nodeUser) ? $nodeUser->corporativo : 'Sin Corporativo');
        }
        /*var_dump('actual');
        var_dump($flujo['actual']['nodoName']);
        var_dump('next');
        var_dump($flujo['next']['nodoName']);*/
        if (empty($campos)) $campos = [];
        $campos['FECHA_MODIFICACION']['v'] = Carbon::now()->setTimezone('America/Guatemala')->toDateTimeString();
        // Guardo campos
        if (!empty($campos) && is_array($campos) && !$recursivo) {

            // Variables por defecto
            $tmpUser = User::where('id', $item->usuarioId)->first();
            $rolUser = UserRol::where('userId', $item->usuarioId)->first();
            $rol = null;
            if (!empty($rolUser)) $rol = Rol::where('id', $rolUser->rolId)->first();

            // producto
            $productoTk = $item->producto->token ?? '';

            if ($flujo['actual']['wsLogic'] === 'n') {
                $campos['FECHA_SOLICITUD']['v'] = $item->dateCreated;
                $campos['FECHA_HOY']['v'] = Carbon::now()->toDateTimeString();
                $campos['ID_SOLICITUD']['v'] = $item->id;
                $campos['TAREA_TOKEN']['v'] = $item->token;
                $campos['HOY_SUM_1_YEAR']['v'] = Carbon::now()->addYear()->toDateTimeString();
                $campos['HOY_SUM_1_YEAR_F1']['v'] = Carbon::now()->addYear()->format('d/m/Y');
                $campos['CREADOR_NOMBRE']['v'] = (!empty($tmpUser) ? $tmpUser->name : 'Sin nombre');
                $campos['CREADOR_CORP']['v'] = (!empty($tmpUser) ? $tmpUser->corporativo : 'Sin corporativo');
                $campos['CREADOR_NOMBRE_USUARIO']['v'] = (!empty($tmpUser) ? $tmpUser->nombreUsuario : 'Sin nombre');
                $campos['CREADOR_ROL']['v'] = (!empty($rol) ? $rol->name : 'Sin rol');
                $campos['LINK_FORM']['v'] = $this->getCotizacionLink($productoTk, $item->token);
                $campos['LINK_FORM_PRIVADO']['v'] = $this->getCotizacionLinkPrivado($productoTk, $item->token);
            }

            foreach ($campos as $campoKey => $valor) {
                $campoKeyNew = $campoKey;
                if (!is_array($valor)) continue;
                if ($valor['v'] === '__SKIP__FILE__') continue;

                // tipos de archivo que no se guardan
                if (!empty($valor['t']) && ($valor['t'] === 'txtlabel' || $valor['t'] === 'subtitle')) {
                    continue;
                }

                if (!empty($valor['t']) && !empty($valor['v']) && $valor['t'] === 'aprobacion') {
                    $campoAprobacion = $campoAprobacion && ($valor['v'] === "aprobar");
                    $hasCampoAprobacion = true;
                }

                $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campoKey)->first();

                if (empty($campo)) {
                    $campo = new CotizacionDetalle();
                }
                $campo->cotizacionId = $item->id;
                $campo->seccionKey = $seccionKey;
                $campo->campo = $campoKeyNew;
                $campo->label = (!empty($fieldsData[$campoKey]['nombre']) ? $fieldsData[$campoKey]['nombre'] : '');
                $campo->useForSearch = (!empty($fieldsData[$campoKey]['showInReports']) ? 1 : 0);
                $campo->tipo = $valor['t'] ?? 'default';

                if ($campo->tipo === 'signature' && !empty($valor['v'])) {
                    // solo se guarda la firma si viene
                    $marcaToken = $item->marca->token ?? false;
                    $name = md5(uniqid()) . '.png';
                    $dir = "{$marcaToken}/{$item->token}/{$name}";
                    $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $valor['v']));
                    $disk = Storage::disk('s3');
                    $path = $disk->put($dir, $image);
                    $campo->isFile = 1;
                    $campo->valorLong = $dir;
                } else {
                    if (is_array($valor['v'])) {
                        $campo->valorLong = json_encode($valor['v'], JSON_FORCE_OBJECT);
                    } else {
                        $campo->valorLong = $valor['v'];
                    }
                }
                $campo->valorShow = (!empty($valor['vs']) ? $valor['vs'] : null);

                if (!empty($campo->valorShow)) {
                    $campoTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', "{$campoKey}_DESC")->first();
                    if (empty($campoTmp)) {
                        $campoTmp = new CotizacionDetalle();
                    }
                    $campoTmp->cotizacionId = $item->id;
                    $campoTmp->seccionKey = $seccionKey;
                    $campoTmp->campo = "{$campoKeyNew}_DESC";
                    $campoTmp->label = (!empty($fieldsData[$campoKey]['nombre']) ? $fieldsData[$campoKey]['nombre'] : '');
                    $campoTmp->useForSearch = (!empty($fieldsData[$campoKey]['showInReports']) ? 1 : 0);
                    $campoTmp->tipo = $valor['t'] ?? 'default';
                    $campoTmp->valorLong = $campo->valorShow;
                    $campoTmp->save();

                    $this->saveCotizacionDetalleBitacora($campoTmp, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
                }

                $campo->save();

                $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);

                if ($flujo['actual']['wsLogic'] === 'a') {
                    for ($i = 1; $i <= 20; $i++) {
                        $tmpkey = "{$campoKey}_{$i}";
                        $campoWsLogic = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $tmpkey)->first();
                        // si no lo encontró
                        if (empty($campoWsLogic)) {
                            $campoKeyNew = $tmpkey;
                            $campoWsLogic = new CotizacionDetalle();
                            break;
                        }
                    }
                    // logica paraa guardar dataa
                    $campoWsLogic->cotizacionId = $campo->cotizacionId;
                    $campoWsLogic->seccionKey = $campo->seccionKey;
                    $campoWsLogic->campo = $campoKeyNew;
                    $campoWsLogic->label = $campo->label;
                    $campoWsLogic->useForSearch = $campo->useForSearch;
                    $campoWsLogic->tipo = $campo->tipo;
                    $campoWsLogic->valorLong = $campo->valorLong;
                    $campoWsLogic->valorShow = $campo->valorShow;
                    $campoWsLogic->save();

                    $this->saveCotizacionDetalleBitacora($campoWsLogic, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);

                    if (!empty($campo->valorShow)) {
                        $campoTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', "{$campoKeyNew}_DESC")->first();
                        if (empty($campoTmp)) {
                            $campoTmp = new CotizacionDetalle();
                        }
                        $campoTmp->cotizacionId = $item->id;
                        $campoTmp->seccionKey = $seccionKey;
                        $campoTmp->campo = "{$campoKeyNew}_DESC";
                        $campoTmp->label = (!empty($fieldsData[$campoKey]['nombre']) ? $fieldsData[$campoKey]['nombre'] : '');
                        $campoTmp->useForSearch = (!empty($fieldsData[$campoKey]['showInReports']) ? 1 : 0);
                        $campoTmp->tipo = $valor['t'] ?? 'default';
                        $campoTmp->valorLong = $campo->valorShow;
                        $campoTmp->save();

                        $this->saveCotizacionDetalleBitacora($campoTmp, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
                    }
                }
            }

            // Guardo la bitacora actual
            $bitacoraCoti = new CotizacionBitacora();
            $bitacoraCoti->nodoId = $item->nodoActual;
            $bitacoraCoti->tipo = 'cambio_paso';
            $bitacoraCoti->cotizacionId = $item->id;
            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
            $bitacoraCoti->log = "Guardados datos en paso \"{$flujo['actual']['label']}\"";
            $bitacoraCoti->save();
        }

        if ($hasCampoAprobacion && $flujo['actual']['typeObject'] !== 'review') {
            $userNodo->comentario = $campoAprobacion ? 'Aprobado' : 'Rechazado';
            $userNodo->save();
        }

        $autoSaltarASiguiente = false;
        $decisionTomada = false;

        // Cambio a siguiente paso
        if ($paso === 'next') {
            $camposExpiracion = [];
            $count += 1;
            if ($flujo['actual']['estIo'] === 's') {
                if (!empty($flujo['actual']['expiracionNodo']) && $flujo['actual']['expiracionNodo'] > 0) {
                    $fechaExpira = Carbon::now()->addDays($flujo['actual']['expiracionNodo']);
                    $item->dateExpire = $fechaExpira->format('Y-m-d');
                    $item->save();
                    $camposSetUser['FECHA_EXPIRACION']['v'] = $fechaExpira->setTimezone('America/Guatemala')->toDateTimeString();
                    $camposSetUser['TIEMPO_EXPIRACION']['v'] = $flujo['actual']['expiracionNodo'];
                }

                if (!empty($flujo['actual']['contFecha']) && $flujo['actual']['contFecha'] > 0) {
                    $fechaExpira = Carbon::now()->addDays($flujo['actual']['contFecha']);
                    $item->dateStepChange = $fechaExpira->format('Y-m-d');
                    $item->save();
                    $camposSetUser['FECHA_AUT_SIG_ETAPA']['v'] = $fechaExpira->setTimezone('America/Guatemala')->toDateTimeString();
                    $camposSetUser['TIEMPO_AUT_SIG_ETAPA']['v'] = $flujo['actual']['contFecha'];
                }

                if (!empty($flujo['actual']['atencionNodo']) && $flujo['actual']['atencionNodo'] > 0) {
                    $fechaExpira = Carbon::now()->addHours($flujo['actual']['atencionNodo']);
                    $item->dateExpireUserAsig = $fechaExpira;
                    $item->save();
                    $camposSetUser['FECHA_PROMESA']['v'] = $fechaExpira->setTimezone('America/Guatemala')->toDateTimeString();
                    $camposSetUser['TIEMPO_PROMESA']['v'] = $flujo['actual']['atencionNodo'];
                }
            }

            foreach ($camposExpiracion as $campoKey => $valor) {
                $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campoKey)->first();
                if (empty($campo)) {
                    $campo = new CotizacionDetalle();
                }
                $campo->cotizacionId = $item->id;
                $campo->seccionKey = $seccionKey;
                $campo->campo = $campoKey;
                $campo->label = '';
                $campo->useForSearch = 0;
                $campo->tipo = 'default';
                $campo->valorLong = $valor['v'];
                $campo->save();

                $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
            }

            // si es condicion, hay que volver a evaluarla
            if ($flujo['actual']['typeObject'] === 'condition') {
                $flujo['next'] = $flujo['actual'];
            }

            // Si viene el resultado desde decisión
            if (isset($desdeDecision['result'])) {
                // dd($flujo);

                if ($desdeDecision['result']) {
                    $nodoSiguiente = $flujo['actual']['nodosSalidaDecision']['si'];
                } else {
                    $nodoSiguiente = $flujo['actual']['nodosSalidaDecision']['no'];
                }
                if (empty($nodoSiguiente)) {
                    return $this->ResponseError('COT-010', 'Hubo un error al continuar flujo, decisión mal configurada (sin una salida)');
                }

                $item->nodoActual = $nodoSiguiente;
                $item->save();

                $flujo = $this->CalcularPasos($request, true, false, true);

                /*if ($flujo['actual']['typeObject'] === 'setuser') {
                    dd($flujo);
                }*/

                // Si el nodo actual es de estos, lo tengo que ejecutar, entonces lo pongo como next
                if ($flujo['actual']['typeObject'] === 'process' || $flujo['actual']['typeObject'] === 'condition' || $flujo['actual']['typeObject'] === 'setuser'  || $flujo['actual']['typeObject'] === 'finish' || $flujo['actual']['typeObject'] === 'output') {
                    $flujo['next'] = $flujo['actual'];
                } else if ($flujo['actual']['typeObject'] === 'input' || $flujo['actual']['typeObject'] === 'review') {
                    //return $flujo;
                    if (!empty($flujo['actual']['estOut']) && ($flujo['actual']['estIo'] === 'e')) {
                        $item->estado = $flujo['actual']['estOut'];
                        $item->save();
                    }
                    return $this->ResponseSuccess('Tarea actualizada con éxito', $flujo);
                }
            } else {
                if ($desdeDecision) {
                    if ($flujo['actual']['typeObject'] === 'input' || $flujo['actual']['typeObject'] === 'output' || $flujo['actual']['typeObject'] === 'review' || $flujo['actual']['typeObject'] === 'finish') {
                        //return $flujo;
                        return $this->ResponseSuccess('Tarea actualizada con éxito', $flujo);
                    }
                }
            }
            $restrictAutoJump = false;
            // Si no existe un next es porque es el último paso
            if (empty($flujo['next']['typeObject'])) {

                // Cambio el flujo al nodo next
                /*if (empty($estado)) {
                    $item->estado = 'ultimo_paso';
                    $item->save();
                }*/

                // Guardo la bitácora
                /*$bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Tarea en último paso \"{$flujo['actual']['nodoName']}\"";
                $bitacoraCoti->save();*/

                // si no tengo ninguno siguiente, pues es actual para ejecutar los procesos necesarios
                $flujo['next'] = $flujo['actual'];
                $restrictAutoJump = true;
            }

            // Verifico si es de procesos, acá siempre solo es uno
            if ($flujo['next']['typeObject'] === 'process') {

                $resultado = $this->consumirServicio($flujo['next']['procesos'][0], $item->campos);
                //dd($resultado);

                $dataLog = "<h5>Data enviada</h5> <br> " . htmlentities($resultado['log']['enviado'] ?? '') . " <br><br> <h5>Headers enviados</h5> <br> " . ($resultado['log']['enviadoH'] ?? '') . " <br><br> <h5>Data recibida</h5> <br> " . htmlentities($resultado['log']['recibido'] ?? '') . " <br><br> <h5>Data procesada</h5> <br> " . htmlentities(print_r($resultado['data'] ?? '', true));

                if (empty($resultado['status'])) {
                    $bitacoraCoti = new CotizacionBitacora();
                    $bitacoraCoti->cotizacionId = $item->id;
                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                    $bitacoraCoti->onlyPruebas = 1;
                    $bitacoraCoti->dataInfo = $dataLog;
                    $bitacoraCoti->log = "Error ejecutando proceso. Saliendo de \"{$flujo['actual']['nodoName']}\", URL: {$flujo['next']['procesos'][0]['url']}";
                    $bitacoraCoti->save();

                    if ($originalStep) {
                        $item->nodoActual = $originalStep;
                        $item->save();
                    }

                    //dd($resultado);

                    return $this->ResponseError('COTW-001', "Ha ocurrido realizando el proceso de envío de datos. {$resultado['msg']}");
                } else {

                    // Si tiene identificador de WS, se guardan los campos de una
                    if (!empty($flujo['next']['procesos'][0]['identificadorWs'])) {

                        foreach ($resultado['data'] as $campoKey => $campoValue) {
                            $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campoKey)->first();
                            if (empty($campo)) {
                                $campo = new CotizacionDetalle();
                            }
                            $campo->cotizacionId = $item->id;
                            $campo->campo = $campoKey;
                            if (is_array($campoValue)) {
                                $campo->valorLong = json_encode($campoValue, JSON_FORCE_OBJECT);
                            } else {
                                $campo->valorLong = $campoValue;
                            }
                            $campo->save();

                            $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
                        }

                        $allCampAdd = [];
                        foreach ($flujo['next']['processCampos'] as $campProcess) {
                            $expressionCampos = CotizacionDetalle::where('cotizacionId', $item->id)->get();
                            $expresion = $this->reemplazarValoresSalida($expressionCampos, $campProcess['valorCalculado']);

                            try {
                                $valorLong = strval(@eval("try{ return ({$expresion});} catch(Throwable \$e){ return 'error';};"));
                            } catch (Throwable $e) {
                                $valorLong = $expresion;
                            }

                            if (!empty($expresion)) {
                                $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campProcess['id'])->first();
                                if (empty($campo)) {
                                    $campo = new CotizacionDetalle();
                                }
                                $campo->cotizacionId = $item->id;
                                $campo->campo = $campProcess['id'];
                                $campo->valorLong  = $valorLong;
                                $campo->public = $public;
                                $campo->save();

                                $allCampAdd[$campProcess['id']] = ['calculado' => $expresion, 'resultado' => $valorLong];
                            }
                        }
                    }
                    $allCampAdd = json_encode($allCampAdd);
                    $bitacoraCoti = new CotizacionBitacora();
                    $bitacoraCoti->nodoId = $item->nodoActual;
                    $bitacoraCoti->tipo = 'proceso';
                    $bitacoraCoti->cotizacionId = $item->id;
                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                    $bitacoraCoti->onlyPruebas = 1;
                    $bitacoraCoti->dataInfo = "<h5>URL:</h5> {$flujo['next']['procesos'][0]['url']} <br/><br/>" . $dataLog . "<br/><br/>" . "{$allCampAdd}";
                    $bitacoraCoti->log = "Ejecutado proceso saliendo de \"{$flujo['actual']['nodoName']}\"";
                    $bitacoraCoti->save();
                }

                $autoSaltarASiguiente = true;
            } else if ($flujo['next']['typeObject'] === 'condition') {

                $decisionCumple = true;
                $valuacionValores = '';

                if (!empty($flujo['next']['decisiones'])) {

                    $camposTmp = $item->campos;

                    foreach ($flujo['next']['decisiones'] as $decision) {

                        // Si el campo existe
                        $cumplio = false;
                        $variableDinamica = (!empty($decision['vDin']) ? str_replace("{{", '', str_replace("}}", '', $decision['vDin'])) : false);
                        if ($variableDinamica) {
                            $decision['campoId'] = $variableDinamica;
                        }

                        if ($campoTmp = $camposTmp->where('campo', $decision['campoId'])->first()) {

                            $valorJsonDecode = @json_decode($campoTmp->valorLong, true);

                            if (!is_array($valorJsonDecode)) {

                                $isInt = (is_integer($decision['value']));
                                $campoTmp->valorLong = ($isInt) ? intval($campoTmp->valorLong) : (string)$campoTmp->valorLong;
                                $decision['value'] = ($isInt) ? $decision['value'] : (string)$decision['value'];

                                if ($decision['campoIs'] === '=') {
                                    if ($campoTmp->valorLong == $decision['value']) $cumplio = true;
                                } else if ($decision['campoIs'] === '<') {
                                    if ($campoTmp->valorLong < $decision['value']) $cumplio = true;
                                } else if ($decision['campoIs'] === '<=') {
                                    if ($campoTmp->valorLong <= $decision['value']) $cumplio = true;
                                } else if ($decision['campoIs'] === '>') {
                                    if ($campoTmp->valorLong > $decision['value']) $cumplio = true;
                                } else if ($decision['campoIs'] === '>=') {
                                    if ($campoTmp->valorLong >= $decision['value']) $cumplio = true;
                                } else if ($decision['campoIs'] === '<>') {
                                    if ($campoTmp->valorLong != $decision['value']) $cumplio = true;
                                } else if ($decision['campoIs'] === 'like') {
                                    $decision['value'] = (string)$decision['value'];
                                    $campoTmp->valorLong = (string)$campoTmp->valorLong;
                                    if (str_contains($campoTmp->valorLong, $decision['value'])) $cumplio = true;
                                }
                            } else {

                                foreach ($valorJsonDecode as $valorTmp) {

                                    $valorTmp = (is_integer($campoTmp->valorLong) ? $campoTmp->valorLong : (string)$campoTmp->valorLong);
                                    $decision['value'] = (is_integer($decision['value']) ? $decision['value'] : (string)$decision['value']);

                                    if ($decision['campoIs'] === '=') {
                                        if ($valorTmp == $decision['value']) $cumplio = true;
                                        break;
                                    } else if ($decision['campoIs'] === '<') {
                                        if ($valorTmp < $decision['value']) $cumplio = true;
                                        break;
                                    } else if ($decision['campoIs'] === '<=') {
                                        if ($valorTmp <= $decision['value']) $cumplio = true;
                                        break;
                                    } else if ($decision['campoIs'] === '>') {
                                        if ($valorTmp > $decision['value']) $cumplio = true;
                                        break;
                                    } else if ($decision['campoIs'] === '>=') {
                                        if ($valorTmp >= $decision['value']) $cumplio = true;
                                        break;
                                    } else if ($decision['campoIs'] === 'like') {
                                        if (str_contains($valorTmp, $decision['value'])) $cumplio = true;
                                        break;
                                    }
                                }
                            }

                            $valuacionValores .= " {$decision['glue']} {$campoTmp->valorLong} {$decision['campoIs']} {$decision['value']}";

                            if ($decision['glue'] === 'AND') {
                                $decisionCumple = ($decisionCumple && $cumplio);
                            } else if ($decision['glue'] === 'OR') {
                                $decisionCumple = ($decisionCumple || $cumplio);
                            }
                        }
                    }
                }

                $valuacionValores .= ' ====> ' . ($decisionCumple ? 'true' : 'false');
                $decisionTomada = ['result' => $decisionCumple];

                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->nodoId = $item->nodoActual;
                $bitacoraCoti->tipo = 'condicional';
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Evaluado condicional saliendo de \"{$flujo['actual']['nodoName']}\"";
                $bitacoraCoti->onlyPruebas = 1;
                $bitacoraCoti->dataInfo = $valuacionValores;
                $bitacoraCoti->save();

                // si es condición siempre salta al siguiente
                $autoSaltarASiguiente = true;
            } else if ($flujo['next']['typeObject'] === 'setuser') {

                //$item->gbstatus = 'nueva';
                $camposSetUser = [];
                //$camposSetUser['ESTADO_GLOBAL_ACTUAL']['v'] = 'nueva';

                if (!empty($flujo['next']['userAssign']['user'])) {

                    if ($flujo['next']['userAssign']['user'] === '_PREV_') {
                        $user = User::where('id', $item->usuarioIdAsignadoPrevio)->first();
                    } else if ($flujo['next']['userAssign']['user'] === '_ORI_') {
                        $user = User::where('id', $item->usuarioId)->first();
                    } else {
                        $user = User::where('id', $flujo['next']['userAssign']['user'])->orderBy('id', 'desc')->first();
                    }

                    if (!empty($user)) {
                        $item->usuarioIdAsignadoPrevio = $item->usuarioIdAsignado;
                        $item->usuarioIdAsignado = $user->id;
                        $item->nodoActual = $flujo['next']['nodoId'];
                        $item->save();
                    } else {
                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->log = "Error de asignación a usuario, el usuario no se encuentra o es inválido";
                        $bitacoraCoti->save();
                    }
                }
                /*  else if (!empty($flujo['next']['userAssign']['node'])){
                      $user = CotizacionesUserNodo::where('cotizacionId', $item->id)->where('nodoId', $flujo['next']['userAssign']['node'])->orderBy('createdAt', 'DESC')->first();

                      if (!empty($user)) {
                          $item->usuarioIdAsignadoPrevio = $item->usuarioIdAsignado;
                          $item->usuarioIdAsignado = $user->usuarioId;
                          $item->nodoActual = $flujo['next']['nodoId'];
                          $item->save();
                      }
                      else {
                          // Guardo la bitácora
                          $bitacoraCoti = new CotizacionBitacora();
                          $bitacoraCoti->cotizacionId = $item->id;
                          $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                          $bitacoraCoti->log = "Error de asignación a usuario, el usuario no se encuentra o es inválido";
                          $bitacoraCoti->save();
                      }
                  } */ else if (!empty($flujo['next']['userAssign']['variable'])) {
                    $variable = str_replace("{{", '', str_replace("}}", '', $flujo['next']['userAssign']['variable']));
                    $valorDetalle = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $variable)->first();
                    $user = User::where('id', $valorDetalle->valorLong)->first();

                    if (!empty($user)) {
                        $item->usuarioIdAsignadoPrevio = $item->usuarioIdAsignado;
                        $item->usuarioIdAsignado = $user->id;
                        $item->nodoActual = $flujo['next']['nodoId'];
                        $item->save();
                    } else {
                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->log = "Error de asignación a usuario, el usuario no se encuentra o es inválido";
                        $bitacoraCoti->save();
                    }
                } else {
                    if (!empty($flujo['next']['userAssign']['role']) || !empty($flujo['next']['userAssign']['group'])) {
                        /* $carga = ParalizarCarga::where('productoId', $item->productoId)->get();
                        $usersSuspend = [];
                        foreach($carga as $c){
                            $usersSuspend[] = $c->userId;
                        } */

                        $fueraDeOficina = [];
                        $strQueryFull = "SELECT U.id FROM users AS U WHERE U.fueraOficina = 1";
                        $usuariosTmp = DB::select(DB::raw($strQueryFull));
                        foreach ($usuariosTmp as $tmp) {
                            $fueraDeOficina[] = $tmp->id;
                        }

                        $userIdAsignar = 0;
                        $usersToAssign = [];
                        $roles = '';
                        $verifyIsForGroup = false;

                        // roles por grupo
                        if (!empty($flujo['next']['userAssign']['group'])) {

                            $rolId = [];
                            $strQueryFull = "SELECT GU.*
                                                FROM usersGroupRoles AS GU
                                                WHERE GU.userGroupId = '{$flujo['next']['userAssign']['group']}'";
                            $usuariosTmp = DB::select(DB::raw($strQueryFull));

                            foreach ($usuariosTmp as $tmp) {
                                $rolId[] = $tmp->rolId;
                            }

                            $roles = implode(', ', $rolId);


                            $strQueryFull = "SELECT GU.*
                                                FROM usersGroupUsuarios AS GU
                                                JOIN users AS U ON GU.userId = U.id
                                                WHERE GU.userGroupId = '{$flujo['next']['userAssign']['group']}'
                                                AND U.fueraOficina = 0";
                            $usuariosTmp = DB::select(DB::raw($strQueryFull));
                            foreach ($usuariosTmp as $tmp) {
                                // if(in_array($tmp->userId, $usersSuspend)) continue;
                                $usersToAssign[] = $tmp->userId;
                            }


                            $verifyIsForGroup = true;
                        }

                        // rol individual
                        if (!empty($flujo['next']['userAssign']['role'])) {
                            $roles = ($roles === '' ? $flujo['next']['userAssign']['role'] : ($roles . ", {$flujo['next']['userAssign']['role']}"));
                        }

                        if (!empty($roles)) {
                            $strQueryFull = "SELECT U.id
                                            FROM users AS U
                                            JOIN user_rol AS UR ON U.id = UR.userId
                                            WHERE UR.rolId IN ({$roles})
                                            AND U.fueraOficina = 0";

                            $usuariosTmp = DB::select(DB::raw($strQueryFull));
                            foreach ($usuariosTmp as $tmp) {
                                //if(in_array($tmp->id, $usersSuspend)) continue;
                                if (!in_array($tmp->id, $fueraDeOficina)) {
                                    $usersToAssign[] = $tmp->id;
                                }
                            }
                        }

                        $usersToFind = implode(', ', $usersToAssign);

                        // búsqueda de datos para usuario
                        $strQueryFull = "SELECT C.id, C.usuarioIdAsignado
                                        FROM cotizaciones AS C
                                        WHERE usuarioIdAsignado IN ({$usersToFind})
                                        AND LOWER(C.estado) <> 'finalizada'
                                        AND LOWER(C.estado) <> 'cancelada'
                                        AND LOWER(C.estado) <> 'finalizado'
                                        AND LOWER(C.estado) <> 'cancelado'";

                        $cotizacionesConteo = [];
                        $conteo = DB::select(DB::raw($strQueryFull));
                        foreach ($conteo as $tmp) {
                            if (!isset($cotizacionesConteo[$tmp->usuarioIdAsignado])) {
                                $cotizacionesConteo[$tmp->usuarioIdAsignado] = [
                                    'conteo' => 0,
                                    'detalle' => [],
                                ];
                            }
                            $cotizacionesConteo[$tmp->usuarioIdAsignado]['conteo']++;
                            $cotizacionesConteo[$tmp->usuarioIdAsignado]['detalle'][] = $tmp->id;
                        }

                        if ($flujo['next']['userAssign']['setuser_method'] === 'load') {

                            // coloco los que no tienen asignado nada
                            foreach ($usersToAssign as $keyAssig) {
                                if (!isset($cotizacionesConteo[$keyAssig])) {
                                    $cotizacionesConteo[$keyAssig]['conteo'] = 0;
                                    $cotizacionesConteo[$keyAssig]['detalle'] = [];
                                }
                            }
                            // calculo la menor carga
                            if (count($cotizacionesConteo) > 1) {
                                $conteos = min(array_column($cotizacionesConteo, 'conteo'));
                                foreach ($cotizacionesConteo as $user => $tmp) {
                                    if ($tmp['conteo'] === $conteos) {
                                        $userIdAsignar = $user;
                                        break;
                                    }
                                }
                            } else {
                                $userIdAsignar = $usersToAssign[0] ?? 0;
                            }
                        } else if ($flujo['next']['userAssign']['setuser_method'] === 'random') {
                            if (count($cotizacionesConteo) === 0) {
                                $cotizacionesConteo[] = $usersToAssign[0] ?? 0;
                            }
                            $userIdAsignar = array_rand($cotizacionesConteo);
                        } else if ($flujo['next']['userAssign']['setuser_method'] === 'order') {
                            if ($verifyIsForGroup) {
                                $bitacoraCoti = new CotizacionBitacora();
                                $bitacoraCoti->cotizacionId = $item->id;
                                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                $bitacoraCoti->log = "Error al asignar usuario. La asignación por orden es incompatible con grupos; solo debe usarse con roles.";
                                $bitacoraCoti->save();
                            }

                            $lastUserAsig = 0;
                            $UserAsig = 0;
                            $lastUser = OrdenAsignacion::where('productoId', $item->productoId)->where('rolId', $roles)->first();
                            if (!empty($lastUser)) $lastUserAsig = $lastUser->userId;

                            $userDetected = false;
                            foreach ($usersToAssign as $userTmp) {
                                if (empty($lastUserAsig) || $userDetected) {
                                    $UserAsig = $userTmp;
                                    break;
                                } else {
                                    if ($userTmp === $lastUserAsig) {
                                        $userDetected = true;
                                    }
                                }
                            }

                            // si ya pasó la vuelta
                            if (empty($UserAsig)) {
                                $UserAsig = $usersToAssign[0] ?? 0;
                            }

                            if (empty($lastUser)) {
                                $lastUser = new OrdenAsignacion();
                            }

                            $userIdAsignar = $UserAsig;

                            $lastUser->productoId = $item->productoId;
                            $lastUser->userId = $UserAsig;
                            $lastUser->rolId = $roles ?? null;
                            $lastUser->save();
                        }

                        if (!empty($userIdAsignar)) {
                            $item->usuarioIdAsignadoPrevio = $item->usuarioIdAsignado;
                            $item->usuarioIdAsignado = $userIdAsignar;
                            $item->nodoActual = $flujo['next']['nodoId'];
                            $item->save();
                        } else {
                            $bitacoraCoti = new CotizacionBitacora();
                            $bitacoraCoti->cotizacionId = $item->id;
                            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                            $bitacoraCoti->log = "Error al asignar usuario, no existe ningún usuario que cumpla la asignación";
                            $bitacoraCoti->save();
                        }
                    }
                }

                $user = User::where('id', $item->usuarioIdAsignado)->first();
                if (!empty($user)) {
                    $rol = $user->rolAsignacion->rol;

                    $camposSetUser['FECHA_USUARIO_ASIGNADO']['v'] = Carbon::now()->setTimezone('America/Guatemala')->toDateTimeString();
                    $camposSetUser['USUARIO_ASIGNADO']['v'] = (!empty($user) ? $user->nombreUsuario : 'Sin usuario');
                    $camposSetUser['CORREO_USUARIO_ASIGNADO']['v'] = (!empty($user) ? $user->email : 'Sin email');
                    $camposSetUser['NOMBRE_USUARIO_ASIGNADO']['v'] = (!empty($user) ? $user->name : 'Sin nombre');
                    $camposSetUser['ID_USUARIO_ASIGNADO']['v'] = (!empty($user) ? $user->id : 'Sin Id');
                    $camposSetUser['ROL_USUARIO_ASIGNADO']['v'] = (!empty($rol) ? $rol->name : 'Sin rol');
                    $camposSetUser['CORP_USUARIO_ASIGNADO']['v'] = (!empty($user) ? $user->corporativo : 'Sin Corporativo');

                    foreach ($camposSetUser as $campoKey => $valor) {
                        $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campoKey)->first();
                        if (empty($campo)) {
                            $campo = new CotizacionDetalle();
                        }
                        $campo->cotizacionId = $item->id;
                        $campo->seccionKey = $seccionKey;
                        $campo->campo = $campoKey;
                        $campo->label = '';
                        $campo->useForSearch = 0;
                        $campo->tipo = 'default';
                        $campo->valorLong = $valor['v'];
                        $campo->save();

                        $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
                    }
                }
                // dd($user);

                // Guardo la bitácora
                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->nodoId = $item->nodoActual;
                $bitacoraCoti->tipo = 'user_asig';
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Asignación de usuario \"" . ($user->name ?? 'Sin nombre') . "\"";
                $bitacoraCoti->save();

                // se recalcula el flujo
                $autoSaltarASiguiente = true;
                $decisionTomada = true;
            } else if ($flujo['next']['typeObject'] === 'output') {

                // Guardo la bitácora
                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->nodoId = $item->nodoActual;
                $bitacoraCoti->tipo = 'salida';
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Salida de datos \"{$flujo['actual']['nodoName']}\" -> \"{$flujo['next']['nodoName']}\"";
                $bitacoraCoti->save();

                $docsPlusToken = $flujo['next']['salidaPDFDp'] ?? false;

                // Si es pdf
                if (!empty($flujo['next']['salidaIsPDF'])) {

                    if (!empty($flujo['next']['pdfTpl']) || !empty($docsPlusToken)) {

                        $itemTemplate = PdfTemplate::where('id', intval($flujo['next']['pdfTpl']))->first();
                        if (!empty($itemTemplate) || !empty($docsPlusToken)) {

                            $flujoConfig = $this->getFlujoFromCotizacion($item);
                            //dd($flujoConfig);

                            if (!$flujoConfig['status']) {
                                return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
                            } else {
                                $flujoConfig = $flujoConfig['data'];
                            }

                            // Recorro campos para hacer resumen

                            $campoConfig = $flujo['next']['salidaPDFconf'] ?? false;
                            $dir = '';
                            if (!empty($campoConfig['path'])) {
                                $dir = $campoConfig['path'];
                            }

                            $campoKeyTmp = (!empty($flujo['next']['salidaPDFId'])) ? $flujo['next']['salidaPDFId'] : 'SALIDA_' . ($flujo['next']['nodoId']);
                            $campoSalida = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campoKeyTmp)->first();

                            $ch = curl_init();

                            $arrArchivo = [];
                            $expedientesNew = $campoConfig['expNewConf'] ?? [];
                            $urlExp = env('EXPEDIENTES_URL') . '/?api=true&opt=upload';

                            // Si usará nueva estructura de expedientes
                            if (!empty($expedientesNew['label'])) {

                                $urlExp = env('EXPEDIENTES_NEW_URL') . '/?api=true&opt=upload';

                                $arrArchivo['folderPath'] = trim(trim($dir), '/');
                                $arrArchivo['ramo'] = $expedientesNew['ramo'] ?? '';
                                $arrArchivo['label'] = $expedientesNew['label'] ?? '';
                                $arrArchivo['filetype'] = $expedientesNew['tipo'] ?? '';
                                $arrArchivo['sourceaplication'] = 'Workflow';
                                $arrArchivo['bucket'] = 'EXPEDIENTES';
                                $arrArchivo['overwrite'] = (!empty($expedientesNew['sobreescribir']) && $expedientesNew['sobreescribir'] === 'S') ? 'Y' : 'N';

                                if (!empty($campoSalida->expToken)) {
                                    $arrArchivo['token'] = $campoSalida->expToken;
                                }

                                foreach ($expedientesNew['attr'] as $attr) {
                                    $arrArchivo[$attr['attr']] = $attr['value'];
                                }
                            } else {
                                // Se mandan indexados de la forma viejita

                                $arrArchivo['folderPath'] = trim(trim($dir), '/');
                                $arrArchivo['ramo'] = $campoConfig['fileRamo'] ?? '';
                                $arrArchivo['producto'] = $campoConfig['fileProducto'] ?? '';
                                $arrArchivo['fechaCaducidad'] = $campoConfig['fileFechaExp'] ?? '';
                                $arrArchivo['reclamo'] = $campoConfig['fileReclamo'] ?? '';
                                $arrArchivo['poliza'] = $campoConfig['filePoliza'] ?? '';
                                $arrArchivo['estadoPoliza'] = $campoConfig['fileEstadoPoliza'] ?? '';
                                $arrArchivo['nit'] = $campoConfig['fileNit'] ?? '';
                                $arrArchivo['dpi'] = $campoConfig['fileDPI'] ?? '';
                                $arrArchivo['cif'] = $campoConfig['fileCIF'] ?? '';
                                $arrArchivo['label'] = $campoConfig['fileLabel'] ?? '';
                                $arrArchivo['filetype'] = $campoConfig['fileTipo'] ?? '';
                                $arrArchivo['filetypeSecondary'] = $campoConfig['fileTipo2'] ?? '';
                                $arrArchivo['source'] = 'Workflow';
                            }

                            $data = $item->campos;
                            $arrSend = [];
                            foreach ($arrArchivo as $key => $itemTmp) {
                                $arrSend[$key] = $this->reemplazarValoresSalida($data, $itemTmp, false, $key === 'folderPath'); // En realidad es salida pero lo guardan como entrada
                            }

                            // Guardo la bitácora
                            /*$bitacoraCoti = new CotizacionBitacora();
                            $bitacoraCoti->cotizacionId = $itemTemplate->id;
                            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                            $bitacoraCoti->log = "Error al crear PDF, plantilla inválida";
                            $bitacoraCoti->save();*/


                            $finalFilePath = '';
                            $errorPdfLog = '';
                            if (empty($docsPlusToken)) {
                                $fileNameHash = md5(uniqid());
                                $tmpPath = storage_path("tmp/");
                                $tmpFile = storage_path("tmp/" . md5(uniqid()) . ".docx");
                                $outputTmp = storage_path("tmp/" . $fileNameHash . ".docx");
                                $outputTmpPdf = $fileNameHash . ".pdf";

                                $s3_file = Storage::disk('s3')->get($itemTemplate->urlTemplate);
                                file_put_contents($tmpFile, $s3_file);

                                // reemplazo valores
                                $templateProcessor = new TemplateProcessor($tmpFile);
                                //dd($item->campos);
                                foreach ($item->campos as $campoTmp) {
                                    if (is_array($campoTmp)) continue;
                                    if (is_array($campoTmp->valorLong ?? '')) {
                                        $campoTmp->valorLong = implode(', ', $campoTmp->valorLong ?? '');
                                    }
                                    $templateProcessor->setValue($campoTmp->campo, htmlspecialchars($campoTmp->valorLong ?? ''));
                                }
                                // dd($templateProcessor->getVariables());
                                foreach ($templateProcessor->getVariables() as $variable) {
                                    $templateProcessor->setValue($variable, '');
                                }
                                $templateProcessor->saveAs($outputTmp);

                                // lowriter, pdf conversion
                                putenv('PATH=/usr/local/bin:/bin:/usr/bin:/usr/local/sbin:/usr/sbin:/sbin');
                                putenv('HOME=' . $tmpPath);
                                exec("/usr/bin/lowriter --convert-to pdf {$outputTmp} --outdir '{$tmpPath}'", $outputInfo);
                                if (file_exists($tmpPath)) {
                                    $errorPdfLog = json_encode($outputInfo);
                                } else {
                                    $errorPdfLog = 'No se pudo cargar el template';
                                }

                                $finalFilePath = "{$tmpPath}{$outputTmpPdf}";
                            } else {
                                $headers = array(
                                    'Content-Type: application/json',
                                    'Authorization: Bearer ' . env('ANY_SUBSCRIPTIONS_TOKEN')
                                );

                                $dataSend = [];
                                $dataSend['token'] = $docsPlusToken;
                                $dataSend['operation'] = 'generate';
                                $dataSend['response'] = 'url';
                                $dataSend['data'] = [];
                                foreach ($item->campos as $campo) {

                                    if (empty($campo->tipo)) {
                                        continue;
                                    }

                                    if (
                                        $campo->tipo === 'text' ||
                                        $campo->tipo === 'option' ||
                                        $campo->tipo === 'select' ||
                                        $campo->tipo === 'textArea' ||
                                        $campo->tipo === 'default' ||
                                        $campo->tipo === 'number' ||
                                        $campo->tipo === 'date'
                                    ) {
                                        $dataSend['data'][$campo->campo] = $campo->valorLong;
                                    }

                                    if ($campo->tipo === 'signature') {
                                        if (empty($campo->valorLong)) continue;
                                        $dataSend['data'][$campo->campo] = Storage::disk('s3')->temporaryUrl($campo->valorLong, now()->addMinutes(80));
                                    }

                                    if ($campo->tipo === 'file' && !empty($campo->valorLong)) {
                                        if (empty($dataSend['data'][$campo->campo])) {
                                            $dataSend['data'][$campo->campo] = [];
                                        }
                                        $dataSend['data'][$campo->campo][] = Storage::disk('s3')->temporaryUrl($campo->valorLong, now()->addMinutes(80));
                                    }

                                    if (empty($campo->tipo)) {
                                        // print_r($campo->campo);
                                        $dataSend['data'][$campo->campo] = $campo->valorLong;
                                    }

                                    if ($campo->tipo === 'checkbox' || $campo->tipo === 'multiselect') {
                                        if (empty($campo->valorLong)) continue;
                                        $dataSend['data'][$campo->campo] =  (is_array($campo->valorLong) ? implode(", ", $campo->valorLong) : $campo->valorLong ?? '');
                                    }
                                }

                                $ch = curl_init(env('ANY_SUBSCRIPTIONS_URL', '') . '/formularios/docs-plus/generate');
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                $data = curl_exec($ch);
                                $info = curl_getinfo($ch);
                                curl_close($ch);

                                $dataResponse = @json_decode($data, true);

                                if (empty($dataResponse['status'])) {
                                    // Guardo la bitácora
                                    $bitacoraCoti = new CotizacionBitacora();
                                    $bitacoraCoti->cotizacionId = $item->id;
                                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                    $bitacoraCoti->log = "Error al crear PDF, verifique sus credenciales de acceso o el token de plantilla";
                                    $bitacoraCoti->save();
                                } else {
                                    if (!empty($dataResponse['data']['url'])) {

                                        $finalFilePath = storage_path("tmp/" . md5(uniqid()) . ".pdf");
                                        file_put_contents($finalFilePath, file_get_contents($dataResponse['data']['url']));

                                        // Guardo la bitácora
                                        $bitacoraCoti = new CotizacionBitacora();
                                        $bitacoraCoti->cotizacionId = $item->id;
                                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                        $bitacoraCoti->log = "Archivo PDF generado con éxito, token Docs+: {$docsPlusToken}";
                                        $bitacoraCoti->save();
                                    }
                                }
                            }

                            $path = '';
                            $token = null;
                            if (file_exists($finalFilePath)) {

                                // $disk = Storage::disk('s3');
                                //$path = $disk->putFileAs("/".md5($itemTemplate->id)."/files", $finalFilePath, md5(uniqid()).".pdf");

                                if (empty($arrSend['folderPath'])) {
                                    return $this->ResponseError('T-223', 'Uno o más campos son requeridos previo a la subida de este archivo');
                                }

                                $arrSend['file'] = new \CurlFile($finalFilePath, 'application/pdf');
                                $arrSend['file']->setPostFilename($arrSend['folderPath'] . '/' . $arrSend['label'] . '.pdf');

                                $headers = [
                                    'Authorization: Bearer 1TnwxbcvSesYkiqzl2nsmPgULTlYZFgSrcb3hSb383Tkv0ZzyaBz0sjD7LM2ymh',
                                ];
                                //dd($arrArchivo);

                                curl_setopt($ch, CURLOPT_URL, $urlExp);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $arrSend);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                $server_output = curl_exec($ch);
                                $server_output = @json_decode($server_output, true);
                                curl_close($ch);

                                //dd($server_output);

                                if (!empty($server_output['status'])) {
                                    /*
                                    $campo = CotizacionDetalle::where('campo', 'SYSTEM_TEMPLATE')->where('cotizacionId', $item->id)->first();

                                    if (empty($campo)) {
                                        $campo = new CotizacionDetalle();
                                    }
                                    $campo->cotizacionId = $item->id;
                                    $campo->seccionKey = 0;
                                    $campo->campo = 'SYSTEM_TEMPLATE';
                                    $campo->valorLong = $server_output['data']['exp-url'];
                                    $campo->isFile = 1;
                                    $campo->fromSalida = 1;
                                    $campo->save(); */

                                    $path = $server_output['data']['exp-url'] ?? null;
                                    $token = $server_output['data']['token'] ?? null;

                                    /*return $this->ResponseSuccess('Archivo subido con éxito', [
                                        'key' => $server_output['data']['s3-url-tmp']
                                    ]);*/
                                } else {
                                    return $this->ResponseError('T-222', 'Error al cargar archivo, por favor intente de nuevo');
                                }
                            } else {
                                $bitacoraCoti = new CotizacionBitacora();
                                $bitacoraCoti->cotizacionId = $item->id;
                                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                $bitacoraCoti->log = "Error al generar PDF, la plantilla parece corrupta. \"{$flujo['actual']['nodoName']}\" -> \"{$flujo['next']['nodoName']}\"";
                                $bitacoraCoti->dataInfo = $errorPdfLog;
                                $bitacoraCoti->save();
                            }

                            if (!empty($finalFilePath) && file_exists($finalFilePath)) unlink($finalFilePath);
                            if (!empty($tmpFile) && file_exists($tmpFile)) unlink($tmpFile);
                            if (!empty($outputTmp) && file_exists($outputTmp)) unlink($outputTmp);
                            if (!empty($tmpPath) && !empty($outputTmpPdf) && file_exists("{$tmpPath}{$outputTmpPdf}")) unlink("{$tmpPath}{$outputTmpPdf}");

                            if (empty($campoSalida)) {
                                $campoSalida = new CotizacionDetalle();
                            }
                            $campoSalida->cotizacionId = $item->id;
                            $campoSalida->seccionKey = 0;
                            $campoSalida->campo = $campoKeyTmp;
                            $campoSalida->label = ($arrArchivo['label'] ?? $flujo['next']['salidaPDFconf']['fileLabel']) ?? 'Archivo sin nombre';
                            $campoSalida->valorLong = $path;
                            $campoSalida->expToken = $token;
                            $campoSalida->isFile = true;
                            $campoSalida->fromSalida = true;
                            $campoSalida->save();

                            $this->saveCotizacionDetalleBitacora($campoSalida, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
                        }
                    }
                }

                $item->refresh();

                if (!empty($flujo['next']['salidaIsWhatsapp']) && empty($flujo['next']['procesoWhatsapp']['autoSend'])) {
                    $whatsappToken = $flujo['next']['procesoWhatsapp']['token'] ?? '';
                    $whatsappUrl = $flujo['next']['procesoWhatsapp']['url'] ?? '';
                    $whatsappAttachments = $flujo['next']['procesoWhatsapp']['attachments'] ?? '';

                    $whatsappData = (!empty($flujo['next']['procesoWhatsapp']['data'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['next']['procesoWhatsapp']['data']) : false;

                    // chapus para yalo
                    $tmpData = json_decode($whatsappData, true);
                    if (isset($tmpData['users'][0]['params']['document']['link'])) {
                        $tmpData['users'][0]['params']['document']['link'] = $this->getWhatsappUrl($tmpData['users'][0]['params']['document']['link']);
                        $whatsappData = json_encode($tmpData, JSON_UNESCAPED_SLASHES);
                    }

                    $headers = [
                        'Authorization: Bearer ' . $whatsappToken ?? '',
                        'Content-Type: application/json',
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $whatsappUrl ?? '');
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $whatsappData);  //Post Fields
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    $server_output = curl_exec($ch);
                    $yaloTmp = $server_output;
                    $server_output = @json_decode($server_output, true);
                    // dd($server_output);
                    curl_close($ch);

                    $bitacoraCoti = new CotizacionBitacora();
                    $bitacoraCoti->cotizacionId = $item->id;
                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                    $bitacoraCoti->onlyPruebas = 1;
                    $bitacoraCoti->dataInfo = "<b>Enviado:</b> {$whatsappData}, <b>Recibido:</b> {$yaloTmp}";
                    $bitacoraCoti->log = "Enviado Whatsapp";
                    $bitacoraCoti->save();

                    if (empty($server_output['success'])) {
                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->onlyPruebas = 1;
                        $bitacoraCoti->log = "Error al enviar WhatsApp: {$whatsappData}";
                        $bitacoraCoti->save();
                    } else {
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->log = "Enviado WhatsApp con éxito";
                        $bitacoraCoti->save();
                    }
                }

                if (!empty($flujo['next']['salidaIsEmail']) && empty($flujo['next']['procesoEmail']['autoSend'])) {

                    // dd($flujo['next']);
                    $copia = (!empty($flujo['next']['procesoEmail']['copia']) && is_array($flujo['next']['procesoEmail']['copia']))
                    ? array_map(function($itemCopia) use ($item) {
                        // Accedemos al valor de 'destino' dentro de cada objeto en el array 'copia'
                        return isset($itemCopia['destino'])
                            ? $this->reemplazarValoresSalida($item->campos, $itemCopia['destino'])
                            : false;
                    }, $flujo['next']['procesoEmail']['copia'])
                    : false;

                    $destino = (!empty($flujo['next']['procesoEmail']['destino'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['next']['procesoEmail']['destino']) : false;
                    $asunto = (!empty($flujo['next']['procesoEmail']['asunto'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['next']['procesoEmail']['asunto']) : false;
                    $config = $flujo['next']['procesoEmail']['mailgun'] ?? [];

                    // reemplazo plantilla
                    $contenido = $flujo['next']['procesoEmail']['salidasEmail'];
                    $contenido = $this->reemplazarValoresSalida($item->campos, $contenido);

                    $attachments = $flujo['next']['procesoEmail']['attachments'] ?? false;

                    $attachmentsSend = [];
                    if ($attachments) {
                        $attachments = explode(',', $attachments);

                        foreach ($attachments as $attach) {
                            $campoTmp = CotizacionDetalle::where('cotizacionId', $item->id)
                                ->where('campo', $attach)
                                ->first();

                            if (!empty($campoTmp) && !empty($campoTmp['valorLong'])) {
                                try {
                                    // Obtener el contenido del archivo
                                    $s3_file = file_get_contents($campoTmp['valorLong']);

                                    // Escribir el archivo en un directorio temporal
                                    $tempFile = tempnam(sys_get_temp_dir(), 'adjunto_');
                                    file_put_contents($tempFile, $s3_file);

                                    // Obtener el tipo MIME del archivo
                                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                    $mimeType = finfo_file($finfo, $tempFile);
                                    finfo_close($finfo);

                                    // Determinar la extensión según el MIME type
                                    $extensions = [
                                        'video/3gpp2' => '3g2',
                                        'video/3gp' => '3gp',
                                        'video/3gpp' => '3gp',
                                        'application/x-compressed' => '7zip',
                                        'audio/x-acc' => 'aac',
                                        'audio/ac3' => 'ac3',
                                        'application/postscript' => 'ai',
                                        'audio/x-aiff' => 'aif',
                                        'audio/aiff' => 'aif',
                                        'audio/x-au' => 'au',
                                        'video/x-msvideo' => 'avi',
                                        'video/msvideo' => 'avi',
                                        'video/avi' => 'avi',
                                        'application/x-troff-msvideo' => 'avi',
                                        'application/macbinary' => 'bin',
                                        'application/mac-binary' => 'bin',
                                        'application/x-binary' => 'bin',
                                        'application/x-macbinary' => 'bin',
                                        'image/bmp' => 'bmp',
                                        'image/x-bmp' => 'bmp',
                                        'image/x-bitmap' => 'bmp',
                                        'image/x-xbitmap' => 'bmp',
                                        'image/x-win-bitmap' => 'bmp',
                                        'image/x-windows-bmp' => 'bmp',
                                        'image/ms-bmp' => 'bmp',
                                        'image/x-ms-bmp' => 'bmp',
                                        'application/bmp' => 'bmp',
                                        'application/x-bmp' => 'bmp',
                                        'application/x-win-bitmap' => 'bmp',
                                        'application/cdr' => 'cdr',
                                        'application/coreldraw' => 'cdr',
                                        'application/x-cdr' => 'cdr',
                                        'application/x-coreldraw' => 'cdr',
                                        'image/cdr' => 'cdr',
                                        'image/x-cdr' => 'cdr',
                                        'zz-application/zz-winassoc-cdr' => 'cdr',
                                        'application/mac-compactpro' => 'cpt',
                                        'application/pkix-crl' => 'crl',
                                        'application/pkcs-crl' => 'crl',
                                        'application/x-x509-ca-cert' => 'crt',
                                        'application/pkix-cert' => 'crt',
                                        'text/css' => 'css',
                                        'text/x-comma-separated-values' => 'csv',
                                        'text/comma-separated-values' => 'csv',
                                        'application/vnd.msexcel' => 'csv',
                                        'application/x-director' => 'dcr',
                                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                                        'application/x-dvi' => 'dvi',
                                        'message/rfc822' => 'eml',
                                        'application/x-msdownload' => 'exe',
                                        'video/x-f4v' => 'f4v',
                                        'audio/x-flac' => 'flac',
                                        'video/x-flv' => 'flv',
                                        'image/gif' => 'gif',
                                        'application/gpg-keys' => 'gpg',
                                        'application/x-gtar' => 'gtar',
                                        'application/x-gzip' => 'gzip',
                                        'application/mac-binhex40' => 'hqx',
                                        'application/mac-binhex' => 'hqx',
                                        'application/x-binhex40' => 'hqx',
                                        'application/x-mac-binhex40' => 'hqx',
                                        'text/html' => 'html',
                                        'image/x-icon' => 'ico',
                                        'image/x-ico' => 'ico',
                                        'image/vnd.microsoft.icon' => 'ico',
                                        'text/calendar' => 'ics',
                                        'application/java-archive' => 'jar',
                                        'application/x-java-application' => 'jar',
                                        'application/x-jar' => 'jar',
                                        'image/jp2' => 'jp2',
                                        'video/mj2' => 'jp2',
                                        'image/jpx' => 'jp2',
                                        'image/jpm' => 'jp2',
                                        'image/jpeg' => 'jpeg',
                                        'image/pjpeg' => 'jpeg',
                                        'application/x-javascript' => 'js',
                                        'application/json' => 'json',
                                        'text/json' => 'json',
                                        'application/vnd.google-earth.kml+xml' => 'kml',
                                        'application/vnd.google-earth.kmz' => 'kmz',
                                        'text/x-log' => 'log',
                                        'audio/x-m4a' => 'm4a',
                                        'audio/mp4' => 'm4a',
                                        'application/vnd.mpegurl' => 'm4u',
                                        'audio/midi' => 'mid',
                                        'application/vnd.mif' => 'mif',
                                        'video/quicktime' => 'mov',
                                        'video/x-sgi-movie' => 'movie',
                                        'audio/mpeg' => 'mp3',
                                        'audio/mpg' => 'mp3',
                                        'audio/mpeg3' => 'mp3',
                                        'audio/mp3' => 'mp3',
                                        'video/mp4' => 'mp4',
                                        'video/mpeg' => 'mpeg',
                                        'application/oda' => 'oda',
                                        'audio/ogg' => 'ogg',
                                        'video/ogg' => 'ogg',
                                        'application/ogg' => 'ogg',
                                        'font/otf' => 'otf',
                                        'application/x-pkcs10' => 'p10',
                                        'application/pkcs10' => 'p10',
                                        'application/x-pkcs12' => 'p12',
                                        'application/x-pkcs7-signature' => 'p7a',
                                        'application/pkcs7-mime' => 'p7c',
                                        'application/x-pkcs7-mime' => 'p7c',
                                        'application/x-pkcs7-certreqresp' => 'p7r',
                                        'application/pkcs7-signature' => 'p7s',
                                        'application/pdf' => 'pdf',
                                        'application/octet-stream' => 'pdf',
                                        'application/x-x509-user-cert' => 'pem',
                                        'application/x-pem-file' => 'pem',
                                        'application/pgp' => 'pgp',
                                        'application/x-httpd-php' => 'php',
                                        'application/php' => 'php',
                                        'application/x-php' => 'php',
                                        'text/php' => 'php',
                                        'text/x-php' => 'php',
                                        'application/x-httpd-php-source' => 'php',
                                        'image/png' => 'png',
                                        'image/x-png' => 'png',
                                        'application/powerpoint' => 'ppt',
                                        'application/vnd.ms-powerpoint' => 'ppt',
                                        'application/vnd.ms-office' => 'ppt',
                                        'application/msword' => 'doc',
                                        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                                        'application/x-photoshop' => 'psd',
                                        'image/vnd.adobe.photoshop' => 'psd',
                                        'audio/x-realaudio' => 'ra',
                                        'audio/x-pn-realaudio' => 'ram',
                                        'application/x-rar' => 'rar',
                                        'application/rar' => 'rar',
                                        'application/x-rar-compressed' => 'rar',
                                        'audio/x-pn-realaudio-plugin' => 'rpm',
                                        'application/x-pkcs7' => 'rsa',
                                        'text/rtf' => 'rtf',
                                        'text/richtext' => 'rtx',
                                        'video/vnd.rn-realvideo' => 'rv',
                                        'application/x-stuffit' => 'sit',
                                        'application/smil' => 'smil',
                                        'text/srt' => 'srt',
                                        'image/svg+xml' => 'svg',
                                        'application/x-shockwave-flash' => 'swf',
                                        'application/x-tar' => 'tar',
                                        'application/x-gzip-compressed' => 'tgz',
                                        'image/tiff' => 'tiff',
                                        'font/ttf' => 'ttf',
                                        'text/plain' => 'txt',
                                        'text/x-vcard' => 'vcf',
                                        'application/videolan' => 'vlc',
                                        'text/vtt' => 'vtt',
                                        'audio/x-wav' => 'wav',
                                        'audio/wave' => 'wav',
                                        'audio/wav' => 'wav',
                                        'application/wbxml' => 'wbxml',
                                        'video/webm' => 'webm',
                                        'image/webp' => 'webp',
                                        'audio/x-ms-wma' => 'wma',
                                        'application/wmlc' => 'wmlc',
                                        'video/x-ms-wmv' => 'wmv',
                                        'video/x-ms-asf' => 'wmv',
                                        'font/woff' => 'woff',
                                        'font/woff2' => 'woff2',
                                        'application/xhtml+xml' => 'xhtml',
                                        'application/excel' => 'xl',
                                        'application/msexcel' => 'xls',
                                        'application/x-msexcel' => 'xls',
                                        'application/x-ms-excel' => 'xls',
                                        'application/x-excel' => 'xls',
                                        'application/x-dos_ms_excel' => 'xls',
                                        'application/xls' => 'xls',
                                        'application/x-xls' => 'xls',
                                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                                        'application/xml' => 'xml',
                                        'text/xml' => 'xml',
                                        'text/xsl' => 'xsl',
                                        'application/xspf+xml' => 'xspf',
                                        'application/x-compress' => 'z',
                                        'application/x-zip' => 'zip',
                                        'application/zip' => 'zip',
                                        'application/x-zip-compressed' => 'zip',
                                        'application/s-compressed' => 'zip',
                                        'multipart/x-zip' => 'zip',
                                        'text/x-scriptzsh' => 'zsh'
                                    ];

                                    $ext = $extensions[$mimeType] ?? 'bin'; // 'bin' si no se reconoce el tipo

                                    // ext = 'pdf';
                                    // $ext = pathinfo($campoTmp['valorLong'] ?? '', PATHINFO_EXTENSION);
                                    // $s3_file = Storage::disk('s3')->get($campoTmp['valorLong']);

                                    // Agregar el archivo al array de adjuntos
                                    $attachmentsSend[] = [
                                        'fileContent' => $s3_file,
                                        'filename' => ($campoTmp['label'] ?? 'Sin nombre') . '.' . $ext
                                    ];

                                    // Eliminar el archivo temporal
                                    unlink($tempFile);
                                } catch (\Exception $e) {
                                    // Manejo de errores si algo falla
                                    $bitacoraCoti = new CotizacionBitacora();
                                    $bitacoraCoti->cotizacionId = $item->id;
                                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                    $bitacoraCoti->log = "Error al procesar el adjunto \"{$attach}\" en el correo: " . $e->getMessage();
                                    $bitacoraCoti->save();
                                }
                            } else {
                                $bitacoraCoti = new CotizacionBitacora();
                                $bitacoraCoti->cotizacionId = $item->id;
                                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                $bitacoraCoti->log = "Error al enviar adjunto \"{$attach}\" en el correo";
                                $bitacoraCoti->save();
                            }
                        }
                    }

                    // reemplazo
                    $config['domain'] = $this->reemplazarValoresSalida($item->campos, $config['domain']);
                    $config['from'] = $this->reemplazarValoresSalida($item->campos, $config['from']);
                    $config['apiKey'] = $this->reemplazarValoresSalida($item->campos, $config['apiKey']);

                    $config['domain'] = $config['domain'] ?? 'N/D';

                    try {
                        $mg = Mailgun::create($config['apiKey'] ?? ''); // For US servers
                        $email = $mg->messages()->send($config['domain'] ?? '', [
                            'from' => $config['from'] ?? '',
                            'to' => $destino ?? '',
                            'cc' => $copia ?? '',
                            'subject' => $asunto ?? '',
                            'html' => $contenido,
                            'attachment' => $attachmentsSend
                        ]);

                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->log = "Enviado correo electrónico \"{$destino}\" desde \"{$config['from']}\"";
                        $bitacoraCoti->save();
                        // return $this->ResponseSuccess( 'Si tu cuenta existe, llegará un enlace de recuperación');
                    } catch (HttpClientException $e) {
                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->log = "Error al enviar correo electrónico \"{$destino}\" desde \"{$config['from']}\", dominio de salida: {$config['domain']}";
                        $bitacoraCoti->save();
                        // return $this->ResponseError('AUTH-RA94', 'Error al enviar notificación, verifique el correo o la configuración del sistema');
                    }
                }

                // salto automático para outputs
                if (!empty($flujo['next']['saltoAutomatico']) && empty($flujo['next']['salidaIsHTML'])) {
                    $autoSaltarASiguiente = true;
                }
            } else if ($flujo['next']['typeObject'] === 'finish') {

                $item->gbstatus = 'finalizada';
                $item->estado = 'finalizada';
                $item->save();

                $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', 'ESTADO_GLOBAL_ACTUAL')->first();
                if (empty($campo)) {
                    $campo = new CotizacionDetalle();
                }

                $campo->cotizacionId = $item->id;
                $campo->seccionKey = $seccionKey;
                $campo->campo = 'ESTADO_GLOBAL_ACTUAL';
                $campo->label = '';
                $campo->useForSearch = 0;
                $campo->tipo = 'default';
                $campo->valorLong = 'finalizada';
                $campo->save();

                $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);

                $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', 'ESTADO_ACTUAL')->first();
                if (empty($campo)) {
                    $campo = new CotizacionDetalle();
                }
                $campo->cotizacionId = $item->id;
                $campo->seccionKey = $seccionKey;
                $campo->campo = 'ESTADO_ACTUAL';
                $campo->label = '';
                $campo->useForSearch = 0;
                $campo->tipo = 'default';
                $campo->valorLong = 'finalizada';
                $campo->save();

                $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
                //$autoSaltarASiguiente = true;

            } else if ($flujo['next']['typeObject'] === 'ocr') {


                /*var_dump($flujo['next']['ocrType']);
                var_dump($flujo['next']['ocrTpl']);
                var_dump($flujo['next']['ocrField']);*/
                $tpl = ConfiguracionOCR::where('id', $flujo['next']['ocrTpl'] ?? 0)->first();
                if (empty($tpl)) {
                    return $this->ResponseError('OCR01', 'Plantilla OCR no configurada correctamente');
                }

                $fileTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('isFile', 0)->get();

                $tplConfig = @json_decode($tpl->configuracion);

                $template = false;
                foreach ($tplConfig as $config) {
                    if (!empty($config->condition)) {
                        $condicion = $this->reemplazarValoresSalida($fileTmp, $config->condition);
                        $smpl = new \Le\SMPLang\SMPLang();
                        $result = @$smpl->evaluate($condicion);
                        if ($result) {
                            $template = $config->slug;
                            break;
                        }
                    } else {
                        $template = $config->slug;
                    }
                }

                if (empty($template)) {
                    return $this->ResponseError('OCR02', 'No cumple con ninguna validacion de OCR');
                }

                /*var_dump($template);
                die;*/


                // traigo campo archivo
                $arrCamposId = [];
                $camposOcr = explode(',', $flujo['next']['ocrField']);

                foreach ($camposOcr as $tmpField) {
                    $arrCamposId[] = trim($tmpField);
                    for ($i = 0; $i <= 50; $i++) {
                        $tmp = trim($tmpField);
                        $arrCamposId[] = "{$tmp}_{$i}";
                    }
                }

                /*var_dump($arrCamposId);
                die;*/

                // revisa procesos zip
                $fileTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('isFile', 1)->whereIn('campo', $arrCamposId)->get();

                /*var_dump($fileTmp);
                die;*/

                foreach ($fileTmp as $file) {

                    if ($flujo['next']['ocrType'] === 'especifico') {

                        // procesa ocr
                        $headers = array(
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . env('ANY_SUBSCRIPTIONS_TOKEN')
                        );

                        $dataSend = [
                            "removePages" => 1,
                            "htmlEndlines" => 0,
                            "noReturnEndlines" => 1,
                            "includeText" => 1,
                            "detectQRBar" => 0,
                            "encodingFrom" => 0,
                            "encodingTo" => 0,
                            "detectTables" => 1
                        ];

                        $dataSend['templateToken'] =  $template;

                        // temporal para mandar
                        $dataSend['fileLink'] = $this->getExpedientesTmpUrl($file->valorLong);

                        // $dataSend['fileLink'] = $file->valorLong;

                        $urlOcr = env('ANY_SUBSCRIPTIONS_URL', '').'/formularios/docs-plus/ocr-process/gen3';
                        $dataOcr = json_encode($dataSend);

                        //var_dump($dataSend);

                        $ch = curl_init($urlOcr);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataOcr);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        $data = curl_exec($ch);
                        $info = curl_getinfo($ch);
                        curl_close($ch);
                        $resultado = @json_decode($data, true);

                        // Guardo la bitácora
                        $responseRaw = print_r($data, true);
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->nodoId = $item->nodoActual;
                        $bitacoraCoti->tipo = 'salida';
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->log = "OCR - Enviado a Docs+, URL:  \"{$urlOcr}\", ENVIADO: \"{$dataOcr}\", RESPUESTA: \"{$responseRaw}\"";
                        $bitacoraCoti->save();

                        /*var_dump(json_encode($dataSend));
                        var_dump($resultado);
                        die;*/

                        if (!empty($resultado['status'])) {

                            CotizacionOCR::where('cotizacionId', $item->id)->where('nodoId', $flujo['next']['nodoId'])->where('cotizacionDetalleId', $file->id)->delete();

                            $result = [];
                            if (is_array($dataSend)) {
                                $ritit = new RecursiveIteratorIterator(new RecursiveArrayIterator($resultado['data']['tokens']));

                                foreach ($ritit as $leafValue) {
                                    $keys = array();
                                    foreach (range(0, $ritit->getDepth()) as $depth) {
                                        $keys[] = $ritit->getSubIterator($depth)->key();
                                    }
                                    $result[join('.', $keys)] = $leafValue;
                                }
                            }

                            /*$tmpEnviado = json_encode($dataSend);
                            $tmpParseado = print_r($result, true);
                            $tmpParseadoR = print_r($resultado, true);
                            $bitacoraCoti = new CotizacionBitacora();
                            $bitacoraCoti->cotizacionId = $item->id;
                            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                            $bitacoraCoti->onlyPruebas = 1;
                            $bitacoraCoti->dataInfo = "<b>Enviado:</b> {$tmpEnviado}, <b>Recibido:</b> {$tmpParseadoR}: <b>Parseado</b>: {$tmpParseado}";
                            $bitacoraCoti->log = "OCR Procesado";
                            $bitacoraCoti->save();*/

                            $resultFull = [];
                            $count = 1;
                            foreach ($result as $key => $value) {
                                $resultFull["{$file->campo}.{$key}"] = $value;
                            }

                            foreach ($resultFull as $key => $value) {
                                $fileTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $key)->first();
                                if (empty($fileTmp)) {
                                    $fileTmp = new CotizacionDetalle();
                                }
                                $fileTmp->cotizacionId = $item->id;
                                $fileTmp->campo = $key;
                                $fileTmp->valorLong = $value;
                                $fileTmp->save();
                            }

                            // guarda campos
                            if (!empty($resultado['data']['tokens'])) {
                                foreach ($resultado['data']['tokens'] as $key => $value) {
                                    $ocrRow = new CotizacionOCR();
                                    $ocrRow->cotizacionId = $item->id;
                                    $ocrRow->cotizacionDetalleId = $file->id;
                                    $ocrRow->configuracionOcrId = $tpl->id;
                                    $ocrRow->nodoId = $flujo['next']['nodoId'];
                                    $ocrRow->tipo = 'token';
                                    $ocrRow->field = $key;
                                    $ocrRow->value = $value;
                                    $ocrRow->save();
                                }
                            }
                            if (!empty($resultado['data']['tables'])) {
                                foreach ($resultado['data']['tables'] as $key => $value) {
                                    $ocrRow = new CotizacionOCR();
                                    $ocrRow->cotizacionId = $item->id;
                                    $ocrRow->cotizacionDetalleId = $file->id;
                                    $ocrRow->configuracionOcrId = $tpl->id;
                                    $ocrRow->nodoId = $flujo['next']['nodoId'];
                                    $ocrRow->tipo = 'table';
                                    $ocrRow->field = $key;
                                    $ocrRow->value = @json_encode($value);
                                    $ocrRow->save();
                                }
                            }

                            /*var_dump($resultado['data']['tables']);
                            die;*/

                            // proceso de variables de tablas

                            $headers = [];
                            $tabla = [];
                            foreach ($resultado['data']['tables'] as $tblKey => $dataTable) {
                                foreach ($dataTable as $rowKey => $row) {
                                    if ($rowKey === 0) {
                                        $headers = $row;
                                    } else {
                                        $newRow = [];
                                        foreach ($row as $keyB => $valueB) {
                                            if (isset($headers[$keyB])) {
                                                $newRow[$headers[$keyB]] = $valueB;
                                            }
                                        }
                                        $tabla[$tblKey][] = $newRow;
                                    }
                                }
                            }

                            // campo padre
                            $fileTmpField = CotizacionDetalle::where('id', $file->id)->first();

                            // guarda variables
                            $result = array();
                            if (is_array($tabla)) {
                                $ritit = new RecursiveIteratorIterator(new RecursiveArrayIterator($tabla));

                                foreach ($ritit as $leafValue) {
                                    $keys = array();
                                    foreach (range(0, $ritit->getDepth()) as $depth) {
                                        $keys[] = $ritit->getSubIterator($depth)->key();
                                    }
                                    $result[join('.', $keys)] = $leafValue;
                                }
                            }

                            foreach ($result as $key2 => $value2) {
                                $string = str_replace(' ', '-', $key2); // Replaces all spaces with hyphens.
                                $string = preg_replace('/[^A-Za-z0-9.\.\_\-]/', '', $string); // Removes special chars.

                                $campo = $fileTmpField->campo . '.' . $string;
                                $fileTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campo)->first();
                                if (empty($fileTmp)) {
                                    $fileTmp = new CotizacionDetalle();
                                }
                                $fileTmp->cotizacionId = $item->id;
                                $fileTmp->campo = $campo;
                                $fileTmp->valorLong = $value2;
                                $fileTmp->save();
                            }

                            sleep(1); // Para no saturar el servidor
                        }
                    } else {
                        return $this->ResponseSuccess('Únicamente OCR específico se encuentra activo', $flujo);
                    }
                }
            }

            // Cambio el flujo al nodo next si existe
            if (!empty($flujo['next']['nodoId'])) {
                $item->nodoActual = $flujo['next']['nodoId'];
                if (!empty($flujo['next']['estOut']) && ($flujo['next']['estIo'] === 'e')) $item->estado = $flujo['next']['estOut'];
                $item->save(); // Cambio el flujo al nodo next
            } else {
                $autoSaltarASiguiente = false;
            }

            if ($restrictAutoJump) $autoSaltarASiguiente = false;
        } else if ($paso === 'prev') {
            $idNodoActual = null;
            if (!empty($item->nodoPrevio)) {
                if ((!empty($flujo['prev']['procesos'][0]) && !empty($flujo['prev']['procesos'][0]['url']) || ($flujo['prev']['typeObject'] === 'condition'))) {
                    $autoSaltarASiguiente = true;
                } else if ($flujo['prev']['typeObject'] === 'setuser' || $flujo['prev']['typeObject'] === 'finish') {
                    $autoSaltarASiguiente = true;
                }

                // Cambio el flujo al nodo next
                $item->nodoActual = $flujo['prev']['nodoId'];
                $idNodoActual = $flujo['prev']['nodoId'];
            } else {
                $item->nodoActual = $item->nodoPrevio;
                $idNodoActual = $item->nodoPrevio;
            }

            foreach ($flujoConfig['data']['nodes'] as $nodo) {
                if ($nodo['id'] === $idNodoActual) {
                    if (!empty($nodo['estOut']) && ($nodo['estIo'] === 'e')) $item->estado = $nodo['estOut'];
                } else if (empty($idNodoActual)) {
                    if ($nodo['typeObject'] === 'start' && !empty($nodo['formulario']['tipo'])) {
                        if (!empty($nodo['estOut']) && ($nodo['estIo'] === 'e')) $item->estado = $nodo['estOut'];
                    }
                }
            }

            $item->save();

            // Guardo la bitácora
            $bitacoraCoti = new CotizacionBitacora();
            $bitacoraCoti->cotizacionId = $item->id;
            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
            $bitacoraCoti->log = "Regreso de paso \"{$flujo['actual']['nodoName']}\" -> \"{$flujo['prev']['nodoName']}\"";
            $bitacoraCoti->save();
        } else if ($paso === 'start') {
            $nodoEstOut = null;
            $nodoEstIo = 's';
            foreach ($flujoConfig['data']['nodes'] as $nodo) {
                if ($nodo['typeObject'] === 'start' && !empty($nodo['formulario']['tipo'])) {
                    $nodoStart = $nodo['id'];
                    $nodoEstOut = $nodo['estOut'];
                    $nodoEstIo = $nodo['estIo'];
                }
            }
            if (!empty($nodoStart)) {
                $item->nodoActual = $nodoStart;
                if (!empty($nodoEstOut) && ($nodoEstIo === 'e')) $item->estado = $nodoEstOut;
                $item->save();

                // Guardo la bitácora
                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $nodoPrev = $flujo['prev'] ? $flujo['prev']['nodoName'] : $flujo['actual']['nodoName'];
                $bitacoraCoti->log = "Regreso al inicio de \"{$flujo['actual']['nodoName']}\" -> \"{$nodoPrev}\"";
                $bitacoraCoti->save();
            } else {
                return $this->ResponseError('COT-011', 'Error al actualizar tarea, no se encontro no de inicio');
            }
        }

        $camposSetUser = [];

        if (!empty($flujo['next']) && !empty($flujo['next']['estIo'])  && $flujo['next']['estIo'] === 'e') {
            if (!empty($flujo['next']['expiracionNodo']) && $flujo['next']['expiracionNodo'] > 0) {
                $fechaExpira = Carbon::now()->addDays($flujo['next']['expiracionNodo']);
                $item->dateExpire = $fechaExpira->format('Y-m-d');
                $item->save();
                $camposSetUser['FECHA_EXPIRACION']['v'] = $fechaExpira->setTimezone('America/Guatemala')->toDateTimeString();
                $camposSetUser['TIEMPO_EXPIRACION']['v'] = $flujo['next']['expiracionNodo'];
            }

            if (!empty($flujo['next']['contFecha']) && $flujo['next']['contFecha'] > 0) {
                $fechaExpira = Carbon::now()->addDays($flujo['next']['contFecha']);
                $item->dateStepChange = $fechaExpira->format('Y-m-d');
                $item->save();
                $camposSetUser['FECHA_AUT_SIG_ETAPA']['v'] = $fechaExpira->setTimezone('America/Guatemala')->toDateTimeString();
                $camposSetUser['TIEMPO_AUT_SIG_ETAPA']['v'] = $flujo['next']['contFecha'];
            }

            if (!empty($flujo['next']['atencionNodo']) && $flujo['next']['atencionNodo'] > 0) {
                $fechaExpira = Carbon::now()->addHours($flujo['next']['atencionNodo']);
                $item->dateExpireUserAsig = $fechaExpira;
                $item->save();
                $camposSetUser['FECHA_PROMESA']['v'] = $fechaExpira->setTimezone('America/Guatemala')->toDateTimeString();
                $camposSetUser['TIEMPO_PROMESA']['v'] = $flujo['next']['atencionNodo'];
            }
        }
        $camposSetUser['NODO_ACTUAL']['v'] = $flujo['next']['nodoNameId'] ?? '';

        foreach ($camposSetUser as $campoKey => $valor) {
            $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campoKey)->first();
            if (empty($campo)) {
                $campo = new CotizacionDetalle();
            }
            $campo->cotizacionId = $item->id;
            $campo->seccionKey = $seccionKey;
            $campo->campo = $campoKey;
            $campo->label = '';
            $campo->useForSearch = 0;
            $campo->tipo = 'default';
            $campo->valorLong = $valor['v'];
            $campo->save();

            $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
        }


        // Si no está visible el next, valido la lógica de asignación
        /*if (!empty($flujo['next']['visible'])) {
            if (!empty($flujo['next']['userAssign']['logicaAsig']) && $flujo['next']['userAssign']['logicaAsig'] === 'saltar') {
                $autoSaltarASiguiente = true;
            }
        }
        if (!empty($flujo['prev']['visible'])) {
            if (!empty($flujo['prev']['userAssign']['logicaAsig']) && $flujo['prev']['userAssign']['logicaAsig'] === 'saltar') {
                $autoSaltarASiguiente = true;
            }
        }*/

        /*if ($flujo['actual']['nodoName'] === 'USERTEST') {
            dd($flujo);
        }*/
        if ($count > 15) {
            return $this->ResponseError('COT-017', 'Excedio el número de vueltas');
        }

        if ($autoSaltarASiguiente) {
            return $this->CambiarEstadoCotizacion($request, true, $decisionTomada, $originalStep, $public, $count);
        }

        if ($item->save()) {
            return $this->ResponseSuccess('Tarea actualizada con éxito', $flujo);
        } else {
            return $this->ResponseError('COT-016', 'Error al actualizar tarea, por favor intente de nuevo');
        }
    }

    public function CalcularPasos(Request $request, $onlyArray = false, $public = false, $toggle = false, $nodoActualBita = null, $camposAllBita = null)
    {

        ini_set('max_execution_time', '600');

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        $cotizacionId = $request->get('token');

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('COT-632', 'Tarea no válida');
        }

        $modifyData = true;
        if (
            !empty($usuarioLogueadoId) && !$AC->CheckAccess(['tareas/admin/modificar'])
            && ($usuarioLogueado->id !== $cotizacion->usuarioIdAsignado)
        ) $modifyData = false;

        $producto = $cotizacion->producto;
        $flujoConfig = $this->getFlujoFromCotizacion($cotizacion);

        if (!$flujoConfig['status']) {
            return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
        } else {
            $flujoConfig = $flujoConfig['data'];
        }

        // Estados
        $estados = [];
        if (!$public && isset($producto->extraData) && $producto->extraData !== '') {
            $estados = json_decode($producto->extraData, true);
            $estados = $estados['e'] ?? [];
        }
        // estados default
        $estados[] = 'expirada';

        // El flujo se va a orientar en orden según un array
        $allFields = [];
        $flujoOrientado = [];
        $flujoPrev = [];
        $flujoActual = [];
        $flujoNext = [];

        $reviewNodes = [];
        $reviewFields = [];

        // dd($flujoConfig['nodes']);
        // usuario asignado, variables
        $userAsigTmp = User::where('id', $cotizacion->usuarioIdAsignado)->first();
        $userAsigTmpVars = (!empty($userAsigTmp->userVars) ? @json_decode($userAsigTmp->userVars, true) : false);

        $camposAll = [];
        if (empty($camposAllBita)) $camposAll = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->get();
        else $camposAll = $camposAllBita;

        $tmpUser = User::where('id', $cotizacion->usuarioId)->first();
        $grupoNombre = '';
        $tmpUserGrupo = UserGrupoUsuario::where('userId', $tmpUser->id ?? 0)->first();
        if (!empty($tmpUserGrupo)) {
            $grupoNombre = $tmpUserGrupo->grupo->nombre ?? '';
        }

        // variables de sistema
        if (!$public) {
            $tmpUserGrupo = SistemaVariable::all();
            foreach ($tmpUserGrupo as $varTmp) {
                $allFields[$varTmp->slug] = ['id' => $varTmp->slug, 'nombre' => '', 'valor' => $varTmp->contenido];
            }
        }

        // Variables defecto
        $allFields['FECHA_SOLICITUD'] = ['id' => 'FECHA_SOLICITUD', 'nombre' => '', 'valor' => Carbon::parse($cotizacion->dateCreated)->toDateTimeString()];
        $allFields['FECHA_HOY'] = ['id' => 'FECHA_HOY', 'nombre' => '', 'valor' => Carbon::now()->toDateTimeString()];

        // variables de usuario
        if (!$public) {
            $rolUser = UserRol::where('userId', $cotizacion->usuarioId)->first();
            $rol = null;
            if (!empty($rolUser)) $rol = Rol::where('id', $rolUser->rolId)->first();

            $allFields['CREADOR_NOMBRE'] = ['id' => 'CREADOR_NOMBRE', 'nombre' => '', 'valor' => (!empty($tmpUser) ? $tmpUser->name : 'Sin nombre')];
            $allFields['CREADOR_CORP'] = ['id' => 'CREADOR_CORP', 'nombre' => '', 'valor' => (!empty($tmpUser) ? $tmpUser->corporativo : 'Sin corporativo')];
            $allFields['CREADOR_GRUPO'] = ['id' => 'CREADOR_GRUPO', 'nombre' => '', 'valor' => $grupoNombre];
            $allFields['CREADOR_NOMBRE_USUARIO'] = ['id' => 'CREADOR_NOMBRE_USUARIO', 'nombre' => '', 'valor' => (!empty($tmpUser) ? $tmpUser->nombreUsuario : 'Sin nombre')];
            $allFields['CREADOR_ROL'] = ['id' => 'CREADOR_ROL', 'nombre' => '', 'valor' => (!empty($rol) ? $rol->name : 'Sin rol')];

            if (is_array($userAsigTmpVars)) {
                foreach ($userAsigTmpVars as $varTmp) {
                    $allFields[$varTmp['nombre']] = ['id' => $varTmp['nombre'], 'nombre' => '', 'valor' => $varTmp['valor']];
                }
            }
        }

        foreach ($flujoConfig['nodes'] as $nodo) {
            if (empty($nodo['typeObject'])) continue;
            if ($nodo['typeObject'] === 'review') {
                $reviewNodes[$nodo['id']]['c'] = $nodo['review'] ?? [];
                $reviewNodes[$nodo['id']]['f'] = [];
            }
        }
        //var_dump($reviewNodes);
        // Recorro las lineas primero
        foreach ($flujoConfig['nodes'] as $key => $nodo) {

            if (empty($nodo['typeObject'])) continue;

            $privacidad = $nodo['priv'] ?? 'n';

            // todos los campos
            foreach ($nodo['formulario']['secciones'] as $seccion) {
                //$allFields[$keySeccion]['nombre'] = $seccion['nombre'];
                foreach ($seccion['campos'] as $campo) {

                    if (empty($campo['id'])) continue;

                    $campoTmp = $camposAll->where('campo', $campo['id'])->first();
                    $valorTmp = $campo['valor'] ?? '';

                    if (!empty($campoTmp) && !empty($campoTmp->valorLong)) {
                        $valorTmp = $campoTmp->valorLong;
                        $jsonTmp = @json_decode($campoTmp->valorLong, true);
                        if ($jsonTmp) {
                            $valorTmp = $jsonTmp;
                        }
                    }

                    $allFields[$campo['id']] = [
                        'id' => $campo['id'],
                        'nombre' => $campo['id'],
                        'valor' => $valorTmp,
                    ];

                    if (!empty($campoTmp->valorShow)) {
                        $allFields["{$campo['id']}_DESC"] = [
                            'id' => "{$campo['id']}_DESC",
                            'nombre' => "{$campo['id']}_DESC",
                            'valor' => $campoTmp->valorShow,
                        ];
                    }

                    // agregar campos a revisión
                    foreach ($reviewNodes as $nodoId => $reviewFieldsTmp) {
                        if (in_array($campo['id'], $reviewFieldsTmp['c']) && !isset($reviewFields[$nodoId][$campo['id']])) {
                            $campo['valor'] = $valorTmp;
                            $reviewNodes[$nodoId]['f'][] = $campo;
                            $reviewFields[$nodoId][$campo['id']] = 1;
                        }
                    }
                }
            }

            $allFieldsSecure = $allFields;

            // se agreagan todas las variables guardadas del flujo, esto sirve también para los WS
            foreach ($camposAll as $campoTmp) {
                if (!isset($camposAll[$campoTmp->campo])) {
                    $allFields[$campoTmp->campo] = [
                        'id' => $campoTmp->campo,
                        'nombre' => $campoTmp->campo,
                        'valor' => $campoTmp->valorLong,
                    ];
                }
            }

            $lineasTemporalEntrada = [];
            $lineasTemporalSalida = [];
            $lineasTemporalSalidaDecision = ['si' => [], 'no' => [],];
            foreach ($flujoConfig['edges'] as $linea) {
                if ($linea['source'] === $nodo['id']) {
                    $lineasTemporalSalida[] = $linea['target'];

                    if ($linea['sourceHandle'] === 'salidaTrue') {
                        $lineasTemporalSalidaDecision['si'] = $linea['target'];
                    } else if ($linea['sourceHandle'] === 'salidaFalse') {
                        $lineasTemporalSalidaDecision['no'] = $linea['target'];
                    }
                }
                if ($linea['target'] === $nodo['id']) {
                    $lineasTemporalEntrada[] = $linea['source'];
                }
            }

            $flujoOrientado[$nodo['id']] = [
                'nodoId' => $nodo['id'],
                'typeObject' => $nodo['typeObject'],
                'wsLogic' => $nodo['wsLogic'] ?? 'n',
                'estOut' => $nodo['estOut'] ?? null, // Estado out
                'estIo' => $nodo['estIo'] ?? 's',
                'cmT' => $nodo['cmT'] ?? '', // Comentarios Tipo
                'expiracionNodo' => $nodo['expiracionNodo'] ?? false,
                'atencionNodo' => $nodo['atencionNodo'] ?? false,
                'contFecha' => $nodo['contFecha'] ?? false,
                'nodoName' => $nodo['nodoName'],
                'nodoNameId' => $nodo['nodoId'] ?? '',
                'type' => $nodo['type'],
                'label' => $nodo['label'] ?? '',
                'formulario' => $nodo['formulario'] ?? [],
                'ocrType' => $nodo['ocrType'] ?? '',
                'ocrTpl' => $nodo['ocrTpl'] ?? '',
                'ocrField' => $nodo['ocrField'] ?? '',
                'ocrFieldT' => $nodo['ocrFieldT'] ?? '',
                'btnText' => [
                    'prev' => $nodo['btnTextPrev'] ?? '',
                    'next' => $nodo['btnTextNext'] ?? '',
                    'finish' => $nodo['btnTextFinish'] ?? '',
                    'cancel' => $nodo['btnTextCancel'] ?? '',
                ],
                'processCampos' => $nodo['processCampos'] ?? [],
            ];

            $flujoOrientado[$nodo['id']]['nodosEntrada'] = $lineasTemporalEntrada;
            $flujoOrientado[$nodo['id']]['nodosSalida'] = $lineasTemporalSalida;
            $flujoOrientado[$nodo['id']]['nodosSalidaDecision'] = $lineasTemporalSalidaDecision;

            $flujoOrientado[$nodo['id']]['userAssign'] = [
                'user' => $nodo['setuser_user'] ?? '',
                'role' => $nodo['setuser_roles'] ?? [],
                'group' => $nodo['setuser_group'] ?? [],
                'canal' => $nodo['canales_assign'] ?? [],
                'node' => $nodo['setuser_node'] ?? '',
                'variable' => $nodo['setuser_variable'] ?? '',
                'setuser_method' => $nodo['setuser_method'] ?? [],
            ];
            $flujoOrientado[$nodo['id']]['expiracionNodo'] = $nodo['expiracionNodo'] ?? false;
            $flujoOrientado[$nodo['id']]['atencionNodo'] = $nodo['atencionNodo'] ?? false;
            $flujoOrientado[$nodo['id']]['contFecha'] = $nodo['contFecha'] ?? false;
            $flujoOrientado[$nodo['id']]['procesos'] = $nodo['procesos'];
            $flujoOrientado[$nodo['id']]['decisiones'] = $nodo['decisiones'];
            $flujoOrientado[$nodo['id']]['salidas'] = $nodo['salidas'];
            $flujoOrientado[$nodo['id']]['salidaIsPDF'] = $nodo['salidaIsPDF'];
            $flujoOrientado[$nodo['id']]['salidaPDFconf'] = $nodo['salidaPDFconf'] ?? [];
            $flujoOrientado[$nodo['id']]['salidaIsHTML'] = $nodo['salidaIsHTML'];
            $flujoOrientado[$nodo['id']]['salidaIsEmail'] = $nodo['salidaIsEmail'];
            $flujoOrientado[$nodo['id']]['salidaIsWhatsapp'] = $nodo['salidaIsWhatsapp'];
            $flujoOrientado[$nodo['id']]['procesoWhatsapp'] = $nodo['procesoWhatsapp'];
            $flujoOrientado[$nodo['id']]['procesoEmail'] = $nodo['procesoEmail'];
            $flujoOrientado[$nodo['id']]['roles_assign'] = $nodo['roles_assign'];
            $flujoOrientado[$nodo['id']]['tareas_programadas'] = $nodo['tareas_programadas'];
            $flujoOrientado[$nodo['id']]['pdfTpl'] = $nodo['pdfTpl'] ?? [];
            $flujoOrientado[$nodo['id']]['salidaPDFId'] = $nodo['salidaPDFId'] ?? '';
            $flujoOrientado[$nodo['id']]['salidaPDFLabel'] = $nodo['salidaPDFLabel'] ?? '';
            $flujoOrientado[$nodo['id']]['salidaPDFDp'] = $nodo['salidaPDFDp'] ?? '';
            $flujoOrientado[$nodo['id']]['saltoAutomatico'] = $nodo['saltoAutomatico'] ?? '';
            $flujoOrientado[$nodo['id']]['enableJsonws'] = $nodo['enableJsonws'] ?? '';
            $flujoOrientado[$nodo['id']]['jsonws'] = $nodo['jsonws'] ?? '';
        }

        // Si el nodo actual está vacío, debe ser que está iniciando
        if (empty($cotizacion->nodoActual)) {

            // Validación de nodo de entrada
            $entradaDetectada = false;
            foreach ($flujoOrientado as $nodo) {
                // Si es de entrada
                if ($nodo['type'] === 'input') {

                    // valido si existen dos entradas
                    if (!$entradaDetectada) {
                        $flujoActual = $nodo;
                        $entradaDetectada = true;
                    } else {
                        return $this->ResponseError('COT-048', 'El flujo se encuentra mal configurado, existen dos nodos de entrada');
                    }
                }
            }
        } else {
            foreach ($flujoOrientado as $nodo) {
                $nodoSelect = !empty($nodoActualBita) ? $nodoActualBita : $cotizacion->nodoActual;
                if ($nodo['nodoId'] === $nodoSelect) {
                    $flujoActual = $nodo;
                }
            }
        }

        if (empty($flujoActual)) {
            return $this->ResponseError('COT-058', 'Este flujo no puede visualizarse, ha cambiado o se han eliminado etapas');
        }

        // Traigo los nodos de entrada
        if (!empty($flujoActual['nodosEntrada'])) {
            foreach ($flujoActual['nodosEntrada'] as $id) {
                if (isset($flujoOrientado[$id])) {
                    $flujoPrev = $flujoOrientado[$id];
                }
            }
        }

        // dd($flujoActual);

        // Traigo los nodos de salida
        if (!empty($flujoActual['nodosSalida'])) {
            foreach ($flujoActual['nodosSalida'] as $id) {
                if (isset($flujoOrientado[$id])) {
                    $flujoNext = $flujoOrientado[$id];
                }
            }
        }

        //var_dump($reviewNode);

        // agrega campos a revisión
        if (count($reviewNodes) > 0) {
            if (isset($reviewNodes[$flujoActual['nodoId']])) {
                $flujoActual['formulario']['secciones'][0]['nombre'] = 'Revisión';
                $flujoActual['formulario']['secciones'][0]['campos'] = $reviewNodes[$flujoActual['nodoId']]['f'];
                $flujoActual['formulario']['secciones'][0]['condiciones'] = [];
            }
        }

        //var_dump($allFields);
        // var_dump($flujoActual['formulario']['secciones']);

        // Se calculan los valores que se traen
        if (!empty($flujoActual['formulario']['secciones'])) {
            foreach ($flujoActual['formulario']['secciones'] as $keySeccion => $seccion) {

                $keySeccion = (string)$keySeccion;

                $flujoActual['formulario']['secciones'][$keySeccion]['seccionId'] = $keySeccion;

                foreach ($seccion['campos'] as $keyCampo => $campo) {

                    $campoTmp = $camposAll->where('campo', $campo['id'])->first();
                    //$campoTmp = $allFields[$campo['id']] ?? false;

                    // defaults
                    if (empty($flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMax'])) $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMax'] = 20;

                    // Reemplazo de parámetros de campo
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['ph'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['ph'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['ttp'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['ttp'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['desc'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['desc'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['nombre'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['nombre'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMax'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMax'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMin'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMin'] ?? '');

                    // procesa los por defecto
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['valor'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['valor'] ?? '');

                    if (!empty($campoTmp) && !empty($campoTmp->valorLong)) {
                        $tmpJson = @json_decode($campoTmp->valorLong, true);

                        //var_dump($campoTmp->valorLong);

                        $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['valor'] = ((!empty($tmpJson) && (is_array($tmpJson) /*|| (!is_infinite($tmpJson) && !is_nan($tmpJson))*/)) ? $tmpJson : $campoTmp->valorLong);

                        // si es array, reviso los valores ya seleccionados
                        /*if ($tmpJson) {
                            if (!empty($flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['catalogoId']['items']) ) {

                                foreach ($flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['catalogoId']['items'] as $keyItem => $itemTmp) {

                                    //dd($itemTmp);
                                    if (!empty($itemTmp[$flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['catalogoValue']])){
                                        if (is_array($tmpJson) && in_array($itemTmp[$flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['catalogoValue']], $tmpJson)) {
                                            $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['catalogoId']['items'][$keyItem]['selected'] = true;
                                        }
                                    }
                                }
                            }
                        }*/
                        if ($campoTmp->tipo === 'currency') {
                            if ($campoTmp->valorLong === null || $campoTmp->valorLong === '.00' || $campoTmp->valorLong === '') {
                                $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['valor'] = 0;
                            }
                        }
                    }
                }
            }
        }

        // dd($flujoActual);

        // Si es una salida, hay que procesar la salida con la data ya guardada
        if ($flujoActual['typeObject'] === 'output') {
            $dataToSend = $this->reemplazarValoresSalida($camposAll, $flujoActual['salidas']);
            $flujoActual['salidaReplaced'] = $dataToSend;
            //$flujoActual['jsonwsReplaced'] = $this->reemplazarValoresSalida($camposAll, $flujoActual['jsonws']);

            if (!empty($flujoActual['saltoAutomatico']) && !empty($flujoActual['salidaIsHTML']) && !$toggle) {
                $request->merge(['paso' => 'next']);
                $producto = $this->CambiarEstadoCotizacion($request, false, false, false, false);
            }
        }

        $flujoTmp = Flujos::where('productoId', $cotizacion->productoId)->where('activo', 1)->first('modoPruebas');
        if (empty($flujoTmp)) {
            return $this->ResponseError('COT-254', 'No existe ningún flujo activo para este producto');
        }

        $bitacoraView = [];
        if ($usuarioLogueadoId) {
            $bitacora = CotizacionBitacora::where('cotizacionId', $cotizacion->id)->with('usuario')->orderBy('id', 'DESC')->get();

            foreach ($bitacora as $bit) {
                if (!$flujoTmp['modoPruebas']) {
                    $bit->makeHidden(['dataInfo']);
                }

                $bit->usuarioNombre = $bit->usuario->name ?? 'Sin usuario';
                $bit->usuarioCorporativo = $bit->usuario->corporativo ?? 'Sin usuario';
                $bit->makeHidden(['usuario']);

                $bitacoraView[] = $bit;
            }
        }

        $bitacoraViewRecapitulation = [];
        $typesNode = [
            "start" => "Inicio",
            "input" => "Entradas",
            "condition" => "Condición",
            "process" => "Proceso",
            "setuser" => "Usuario",
            "review" => "Revisión",
            "output" => "Salida",
            "finish" => "Finalizar"
        ];

        if ($usuarioLogueadoId) {
            $bitacoraReca = CotizacionesUserNodo::where('cotizacionId', $cotizacion->id)
                ->orderBy('cotizacionesUserNodo.id', 'ASC')
                ->get();

            $fechaInicioBit = Carbon::parse($cotizacion->dateCreated)->setTimezone('America/Guatemala')->format('d/m/Y H:i');

            foreach ($bitacoraReca as $bitReca) {
                $dateCreteadBita = $bitReca->createdAt;
                $bitReca->nodoName = $flujoOrientado[$bitReca->nodoId]['nodoName'] ?? 'Nodo sin Nombre';
                $bitReca->nodoNameId = $flujoOrientado[$bitReca->nodoId]['nodoNameId'] ?? 'Nodo sin Identificador';
                $bitReca->typeObject = $typesNode[$flujoOrientado[$bitReca->nodoId]['typeObject'] ?? 'default'] ?? 'Nodo sin tipo';
                $bitReca->usuarioNombre = $bitReca->usuario->name ?? 'Sin usuario';
                $bitReca->usuarioCorporativo = $bitReca->usuario->corporativo ?? 'Sin Corporativo';
                $bitReca->comentario = $bitReca->comentario;
                $bitReca->createdAt = $fechaInicioBit;
                $bitReca->endAt = Carbon::parse($dateCreteadBita)->setTimezone('America/Guatemala')->format('d/m/Y H:i');
                $fechaInicioBit = Carbon::parse($dateCreteadBita)->setTimezone('America/Guatemala')->format('d/m/Y H:i');
                array_unshift($bitacoraViewRecapitulation, $bitReca);
            }
        }

        // Salto el nodo ya que no corresponde a mi usuario
        $rolUsuarioLogueado = ($usuarioLogueado) ? $usuarioLogueado->rolAsignacion->rol : 0;
        /*
        $calcularVisibilidad = function ($flujo) use ($usuarioLogueadoId, $rolUsuarioLogueado, $public) {

            $hasConfigUsers = false;
            $usersDetalle = [];

            if (($public && $flujo['formulario']['tipo'] === 'publico') || ($public && $flujo['formulario']['tipo'] === 'mixto')) {
                // var_dump('asdfasdfsda');
                return true;
            };

            // evalua canales
            if (!empty($flujo['userAssign']['canal']) && is_array($flujo['userAssign']['canal']) && count($flujo['userAssign']['canal']) > 0) {

                $hasConfigUsers = true;

                $canales = UserCanalGrupo::whereIn('userCanalId', $flujo['userAssign']['canal'])->get();
                $flujo['userAssign']['group'] = [];
                foreach ($canales as $canal) {

                    $gruposUsuarios = $canal->canal->grupos;

                    foreach ($gruposUsuarios as $grupoU) {
                        if ($grupo = $grupoU->grupo) {
                            $users = $grupo->users;

                            // por usuario del grupo
                            foreach ($users as $userAsig) {
                                $usersDetalle[$userAsig->userId] = $userAsig->userId;
                            }
                            // por rol
                            if ($rol = $grupo->roles) {
                                foreach ($rol as $r) {
                                    if ($gruposRol = $r->rol) {
                                        $roles = $gruposRol->usersAsig;
                                        foreach ($roles as $userAsig) {
                                            $usersDetalle[$userAsig->userId] = $userAsig->userId;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // usuarios específicos del grupo
            if (!empty($flujo['userAssign']['group']) && is_array($flujo['userAssign']['group']) && count($flujo['userAssign']['group']) > 0) {
                $hasConfigUsers = true;

                // verifico usuarios específicos
                $usersGroup = UserGrupoUsuario::whereIn('userGroupId', $flujo['userAssign']['group'])->get();
                foreach ($usersGroup as $grupoUser) {
                    $gruposUsuarios = $grupoUser->grupo->users;
                    foreach ($gruposUsuarios as $userAsig) {
                        $usersDetalle[$userAsig->userId] = $userAsig->userId;
                    }
                }

                // por rol
                $usersGroupR = UserGrupoRol::whereIn('userGroupId', $flujo['userAssign']['group'])->get();

                foreach ($usersGroupR as $gruposRol) {
                    $userA = $gruposRol->rol->usersAsig;
                    foreach ($userA as $userAsig) {
                        $usersDetalle[$userAsig->userId] = $userAsig->userId;
                    }
                }
            }

            // verifico roles específicos
            if (!empty($flujo['roles_assign']) && is_array($flujo['roles_assign']) && count($flujo['roles_assign']) > 0) {
                $hasConfigUsers = true;
                if (in_array($rolUsuarioLogueado->id ?? 0, $flujo['roles_assign'])) {
                    $usersDetalle[] = $usuarioLogueadoId;
                }
            }

            return (in_array($usuarioLogueadoId, $usersDetalle));
        };
        */

        $expiraDate = '';
        $expiro = false;

        if (!empty($cotizacion->dateExpire) && !in_array($cotizacion->estado, ['finalizada', 'cancelada', 'finalizado', 'cancelado'])) {
            $fechaHoy = Carbon::now();
            $fechaExpira = Carbon::parse($cotizacion->dateExpire);
            if ($fechaHoy->gt($fechaExpira)) {
                $expiro = true;
            }
            $expiraDate = $fechaExpira->format('d-m-Y');

            if ($AC->CheckAccess(['tareas/admin/operar-expirado']) && $expiro) {
                $cotizacion->estado = 'expirada_opt';
                $expiro = false;
                $expiraDate = '';
            }
        }

        // Extra data
        $cotizacionData = [
            'acc' => true,
            'ed' => '',
            'ex' => $expiro,
            'exd' => $expiraDate,
            'no' => $cotizacion->id,
            'rve' => $flujoActual['procesoEmail']['reenvio'] ?? false,
            'rvw' => $flujoActual['procesoWhatsapp']['reenvio'] ?? false,
        ];

        // Actual
        $userHandler = new AuthController();
        $CalculateAccess = $userHandler->CalculateAccess();
        $usuarioAsigID = (!empty($cotizacion->usuarioAsignado) ? $cotizacion->usuarioAsignado->id : 0);

        // si es supervisor
        $visibilidad = in_array($usuarioAsigID, $CalculateAccess['all']);

        // acceso
        $cotizacionData['acc'] = $visibilidad;


        // si no es público
        if (!$public) {
            $usuarioAsig = $cotizacion->usuarioAsignado;
            $CalculateAccessProducts = $userHandler->AccessProducts();
            $cotizacionData['acc'] =  $visibilidad || in_array($cotizacion->producto->id, $CalculateAccessProducts);
            if (!empty($usuarioAsig)) {
                $rolAsignado = $usuarioAsig->rolAsignacion->rol->name ?? 'N/D';
                $usuarioDesc = "";

                if ($AC->CheckAccess(['users/listar'])) {
                    $usuarioDesc = ", usuario: {$usuarioAsig->nombreUsuario} ({$usuarioAsig->name})";
                }
                $cotizacionData['ed'] = "Formulario asociado al rol: {$rolAsignado}{$usuarioDesc}";
            }

            if (!$cotizacionData['acc']) {
                $cotizacionData['ed'] .= ', no posees acceso a este formulario.';
            }
        } else {
            $usuarioAsig = $cotizacion->usuarioAsignado;
            $cotizacionData['acc'] = $flujoActual['formulario']['tipo'] === 'publico' || $flujoActual['formulario']['tipo'] === 'mixto';
            if (!empty($usuarioAsig)) {
                $rolAsignado = $usuarioAsig->rolAsignacion->rol->name ?? 'N/D';
                $usuarioDesc = "";

                if ($AC->CheckAccess(['users/listar'])) {
                    $usuarioDesc = ", usuario: {$usuarioAsig->nombreUsuario} ({$usuarioAsig->name})";
                }
                $cotizacionData['ed'] = "Formulario asociado al rol: {$rolAsignado}{$usuarioDesc}";
            }

            if (!$cotizacionData['acc']) {
                $cotizacionData['ed'] .= ', no posees acceso a este formulario.';
            } else {
                $cotizacionData['ed'] = "Formulario publico";
            }
        }

        // Valido el usuario asignado
        /*if ($cotizacion->usuarioIdAsignado !== $usuarioLogueadoId) {

            $usuarioAsig = $cotizacion->usuarioAsignado;
            $cotizacionData['acc'] = false; // Acceso

            if (!$public) {
                if (!$usuarioAsig) {
                    $cotizacionData['ed'] = "El formulario no posee un usuario asignado";
                    $cotizacionData['acc'] = true; // Acceso
                }
                else {
                    $cotizacionData['ed'] = "Formulario no disponible, se encuentra asociada al usuario: {$usuarioAsig->nombreUsuario} ({$usuarioAsig->name})";
                }
            }
        }*/

        if (!$AC->CheckAccess(['admin/show-assi-usr'])) {
            $cotizacionData['ed'] = '';
        }

        //var_dump($cotizacion->rechazoData);
        $rechazoComments = [];
        $rechazoDataTmp = @json_decode($cotizacion->rechazoData, true);
        if (is_array($rechazoDataTmp)) {

            $camposActual = [];
            foreach ($flujoActual['formulario']['secciones'] as $seccion) {
                foreach ($seccion['campos'] as $campo) {
                    $camposActual[$campo['id']] = true;
                }
            }

            foreach ($rechazoDataTmp as $rechazoNodo) {
                foreach ($rechazoNodo as $rechazo) {
                    $hasField = false;
                    foreach ($rechazo['f'] as $campoKey => $campoVal) {
                        // si el rechazo tiene algún campo de mi nodo
                        if (isset($camposActual[$campoKey])) {
                            $hasField = true;
                            break;
                        }
                    }
                    if ($hasField) {
                        $rechazoComments[] = $rechazo;
                    }
                }
            }
        }
        //var_dump($flujoActual);

        //var_dump($flujoNext);

        // valido si es nodo de salida
        if ($onlyArray) {
            return ['actual' => $flujoActual, 'next' => $flujoNext, 'prev' => $flujoPrev, 'bit' => $bitacoraView, 'd' => $allFields, 'c' => $cotizacionData];
        } else {

            if ($public && $cotizacionData['acc']) {
                unset($flujoActual['nodosEntrada']);
                unset($flujoActual['userAssign']);
                unset($flujoActual['nodosEntrada']);
                unset($flujoActual['nodosSalida']);
                unset($flujoActual['nodosSalidaDecision']);
                unset($flujoActual['expiracionNodo']);
                unset($flujoActual['atencionNodo']);
                unset($flujoActual['contFecha']);
                unset($flujoActual['salidas']);
                unset($flujoActual['salidaIsPDF']);
                unset($flujoActual['salidaIsHTML']);
                unset($flujoActual['salidaIsEmail']);
                unset($flujoActual['salidaIsWhatsapp']);
                unset($flujoActual['procesoWhatsapp']);
                unset($flujoActual['procesoEmail']);
                unset($flujoActual['roles_assign']);
                unset($flujoActual['tareas_programadas']);
                unset($flujoActual['pdfTpl']);
                unset($flujoActual['salidaPDFId']);
                unset($flujoActual['salidaPDFLabel']);
                unset($flujoActual['decisiones']);
                unset($flujoActual['procesos']);
                unset($flujoActual['saltoAutomatico']);
                unset($flujoActual['enableJsonws']);
                unset($flujoActual['jsonws']);
                unset($flujoActual['ocrTpl']);
                unset($flujoActual['ocrType']);
                unset($flujoActual['ocrFieldT']);
                unset($flujoActual['ocrField']);
            }

            if (!$cotizacionData['acc']) {
                $flujoActual = false;
            }

            if (!$AC->CheckAccess(['admin/show-bitacora-process'])) {
                $bitacoraViewRecapitulation = [];
            }

            return $this->ResponseSuccess('Flujo calculado con éxito', ['estado' => $cotizacion->estado, 'actual' => $flujoActual, 'next' => (count($flujoNext) > 0), 'prev' => (count($flujoPrev) > 0), 'bit' => $bitacoraView, 'bitReca' => $bitacoraViewRecapitulation, 'd' => $allFieldsSecure, 'c' => $cotizacionData, 'e' => $estados, 'cG' => $rechazoComments, 'modifyData' => $modifyData]);
        }
    }

    public function CalcularPasosOcr(Request $request)
    {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        $cotizacionId = $request->get('token');

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('COT-632', 'Tarea no válida');
        }

        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
        }

        // Actual
        $userHandler = new AuthController();
        $CalculateAccess = $userHandler->CalculateAccess();

        // si es supervisor
        $arrUsers = false;
        if (in_array($usuarioLogueadoId, $CalculateAccess['sup'])) {
            $arrUsers = $CalculateAccess['all'];
        } else {
            $arrUsers = $CalculateAccess['det'];
        }

        /*if (!empty($usuarioLogueadoId)
            && ($usuarioLogueado->id !== $cotizacion->usuarioIdAsignado) && !$recursivo) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/modificar'])) return $AC->NoAccess();
        }*/

        // Recorro campos para tener sus datos de configuración
        $flujoConfig = $this->getFlujoFromCotizacion($cotizacion);
        $fieldsData = [];
        if (!empty($flujoConfig['data']['nodes'])) {
            foreach ($flujoConfig['data']['nodes'] as $nodo) {
                //$resumen
                if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {
                    foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                        foreach ($seccion['campos'] as $keyCampo => $campo) {
                            $fieldsData[$campo['id']] = $campo;
                        }
                    }
                }
            }
        }

        $flujo = $this->CalcularPasos($request, true, false, true);


        $ocrData = [];
        if (!empty($flujo['actual']['nodoId'])) {

            // archivos
            $camposList = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('isFile', 1)->get();

            foreach ($camposList as $key => $dbValor) {
                $tmpPath = '';
                if (!empty($dbValor->valorLong)) {
                    //dd($dbValor['valorLong']);

                    $temporarySignedUrl = $dbValor->valorLong;

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $temporarySignedUrl);
                    curl_setopt($ch, CURLOPT_HEADER, TRUE);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                    $a = curl_exec($ch);
                    if (preg_match('#Location: (.*)#', $a, $r)) {
                        $tmpPath = trim($r[1]);
                        $tmpPath = parse_url($tmpPath);
                    }

                    $dataPDF = false;

                    $type = '';
                    $ext = pathinfo($tmpPath['path'] ?? '', PATHINFO_EXTENSION);
                    //dd($ext);
                    /*var_dump($ext);
                    die();*/

                    if ($ext == 'jpg' || $ext == 'jpeg' || $ext == 'png' || $ext == 'tiff' || $ext == 'gif') {
                        $type = 'image';
                        $dataPDF = $temporarySignedUrl;
                    } else if ($ext == 'pdf') {

                        $arrContextOptions = array(
                            "ssl" => array(
                                "verify_peer" => false,
                                "verify_peer_name" => false,
                            ),
                        );
                        $response = file_get_contents($temporarySignedUrl, false, stream_context_create($arrContextOptions));
                        $type = 'pdf';
                        $dataPDF = 'data:application/pdf;base64,' . base64_encode($response);
                    }

                    $ocrData[$dbValor->id] = [
                        'name' => $dbValor->campo,
                        'no' => $key + 1,
                        't' => $type,
                        'dbsa' => $dataPDF,
                    ];

                    $ocr = CotizacionOCR::where('cotizacionId', '=', $cotizacion->id)->where('cotizacionDetalleId', '=', $dbValor->id)->get();

                    foreach ($ocr as $item) {

                        // configuraciones ocr
                        $ocrTpl = ConfiguracionOCR::where('id', $item->configuracionOcrId)->first();
                        $tpl = @json_decode($ocrTpl->configuracion, true);
                        $tplConfig = @json_decode($tpl[0]['config'], true);
                        $decoded = @json_decode($item->value, true);
                        //$decoded = $item->value;

                        if ($item->tipo === 'token') {
                            $ocrData[$dbValor->id]['d'][$item->tipo][] = [
                                'id' => $item->id,
                                'type' => $item->tipo,
                                'field' => $item->field,
                                'lang' => $tplConfig['langs'][$item->field] ?? $item->field,
                                'data' => $item->value,
                                'value' => $item->valueLast ?? (trim($item->value) ?? ''),
                                'value2' => trim($item->value) ?? '',
                                'edit' => $item->editado,
                            ];
                        } else {
                            $headersTmp = $decoded[0] ?? [];
                            //if (!empty($decoded[0])) unset($decoded[0]);
                            $headers = [];
                            $bodyTmp = $decoded;
                            $body = [];
                            $tmpH = [];

                            foreach ($headersTmp as $keyHeader => $tmp) {
                                //$tmpH[] = str_replace(' ', '_', $tmp);
                                $headers[] = [
                                    'text' => trim($keyHeader),
                                    'value' => trim($keyHeader),
                                ];
                            }

                            $headers[] = [
                                'text' => '',
                                'value' => 'operacion',
                                'fixed' => true,
                                'width' => 35,
                            ];

                            /*var_dump($bodyTmp);
                            die();*/

                            foreach ($bodyTmp as $keyRow => $tmp) {
                                $row = [];
                                $row['_id_'] = $keyRow;
                                foreach ($tmp as $keyR => $val) {
                                    $keyR = trim($keyR);
                                    $row[$keyR] = $val;
                                }
                                $row['operation'] = '';
                                $body[] = $row;
                            }


                            $ocrData[$dbValor->id]['d'][$item->tipo][$item->field] = [
                                'id' => $item->id,
                                'type' => $item->tipo,
                                'field' => $item->field,
                                'lang' => $tplConfig['langs'][$item->field] ?? $item->field,
                                'headers' => $headers,
                                'body' => $body,
                                'value' => $item->valueLast,
                                'value2' => $item->valueLast,
                                'edit' => $item->editado,
                            ];
                        }
                    }
                }
            }


            //dd($ocrData);
        }

        return $this->ResponseSuccess('Validación OCR', $ocrData);
    }


    public function reemplazarValoresSalida($arrayValores, $texto, $convertirMayuscula = false, $verifyIsReplace = false)
    {

        // Verificación si hay algo que reemplazar
        if (!preg_match('/{{.*?}}/', $texto)) {
            return $texto;
        }

        // var_dump($texto);

        $cacheH = ClassCache::getInstance();
        $tmpUserGrupo = $cacheH->get("REPLACE_VALORES_SYSVAR_ALL");
        if (empty($tmpUserGrupo)) {
            $tmpUserGrupo = SistemaVariable::all();
            $cacheH->set("REPLACE_VALORES_SYSVAR_ALL", $tmpUserGrupo);
        }

        foreach ($tmpUserGrupo as $varTmp) {
            $varTmp->slug = trim($varTmp->slug);
            $arrayValores[$varTmp->slug] = ['id' => $varTmp->slug, 'nombre' => '', 'valorLong' => $varTmp->contenido];
        }

        /*var_dump($arrayValores);

        die();*/
        $result = $texto;
        foreach ($arrayValores as $dataItem) {

            if (empty($dataItem['id'])) continue;

            if (!isset($dataItem['valorLong'])) {
                $dataItem['valorLong'] = $dataItem['valor'] ?? '';
            }

            if (!empty($dataItem['isFile']) && !empty($dataItem['valorLong'])) {
                $dataItem['valorLong'] = $dataItem['valorLong'];
                // $dataItem['valorLong'] = Storage::disk('s3')->temporaryUrl($dataItem['valorLong'], now()->addMinutes(10));
            }

            $stringData = $dataItem['valorLong'];

            if ($dataItem['valorLong'] === '{}') $stringData = '';
            $jsonTmp = is_array($dataItem['valorLong']) ? $dataItem['valorLong'] : @json_decode($dataItem['valorLong'], true);

            if ($jsonTmp && is_array($jsonTmp)) {
                if (count($jsonTmp) > 0) {
                    $stringData = implode(', ', $jsonTmp);
                } else {
                    $stringData = '';
                }
            }

            /*if (!empty($dataItem['maskOutput'])) {
                $stringDataTmp = @Carbon::parse($stringData)->format($dataItem['maskOutput']);

                if ($stringDataTmp) {
                    $stringData = $stringDataTmp;
                }
            }*/


            /*if ( $convertirMayuscula ) {
                $dataItem['campo'] = strtoupper($dataItem['campo']);
            }*/
            $idField = (!empty($dataItem['campo']) ? $dataItem['campo'] : $dataItem['id']);
            $idField = trim($idField);
            $token = "{{" . $idField . "}}";

            if ($verifyIsReplace && strpos($result, $token) && empty($stringData)) return false;
            $result = preg_replace("/" . preg_quote($token) . "/", $stringData, $result);
        }

        // remueve todas las variables que no existan
        //$result = preg_replace('/\{\{...*}}/s', '', $result);

        //$result = strtr($result, array('{{' => '', '}}' => ''));
        if ($verifyIsReplace && preg_match('/{{.*?}}/', $result)) return false;
        $result = preg_replace('#\s*\{\{.+}}\s*#U', '', $result);
        //dd($result);

        return $result;
    }

    public function getCotizacionLink($tokenPr, $tokenCot)
    {
        return env('APP_URL') . '#/f/' . $tokenPr . '/' . $tokenCot;
    }

    public function getCotizacionLinkPrivado($tokenPr, $tokenCot)
    {
        return env('APP_URL') . '#/solicitar/producto/' . $tokenPr . '/' . $tokenCot;
    }

    public function consumirServicio($proceso = [], $data = [])
    {
        ini_set('max_execution_time', 400);

        $isRoble = ($proceso['authType'] === 'elroble');

        //dd($proceso);
        $arrResponse = [];
        $arrResponse['status'] = false;
        $arrResponse['msg'] = 'El servicio no ha respondido adecuadamente o ha devuelto un error';
        $arrResponse['log'] = [];
        $arrResponse['data'] = [];

        // Log de proceso
        $dataResponse = [];
        $dataResponse['enviado'] = [];
        $dataResponse['enviadoH'] = [];
        $dataResponse['recibidoProcesado'] = [];
        $dataResponse['recibido'] = [];

        if (empty($proceso['authType'])) {
            $arrResponse['msg'] = 'Error, la configuración del servicio no tiene tipo de autenticación definida';
            return $arrResponse;
        }

        if (is_object($data)) {
            $data = $data->toArray();
        }

        // ahora se reemplazan los pre formatos
        if (!empty($proceso['pf'])) {
            //dd($data);
            foreach ($proceso['pf'] as $pf) {

                $condicion = $this->reemplazarValoresSalida($data, $pf['con']);
                $valores = $this->reemplazarValoresSalida($data, $pf['c']);

                $smpl = new \Le\SMPLang\SMPLang();
                $result = @$smpl->evaluate($condicion);

                $data[] = [
                    'id' => $pf['va'],
                    'campo' => $pf['va'],
                    'valorLong' => ((!empty($result)) ? $valores : ''),
                ];
            }
        }

        $dataToSend = $this->reemplazarValoresSalida($data, $proceso['entrada'], $isRoble); // En realidad es salida pero lo guardan como entrada
        $dataToSend = trim($dataToSend);
        $url = $this->reemplazarValoresSalida($data, $proceso['url']);
        $headers = $this->reemplazarValoresSalida($data, $proceso['header']);
        $hadersSend = [];
        // dd($proceso['header']);

        // Reemplazo bien los headers
        if (!empty($headers)) {
            $hadersSend = @json_decode($headers, true);

            if (!is_array($hadersSend)) {
                $arrResponse['msg'] = 'Error, las cabeceras no se encuentran bien configuradas';
                return $arrResponse;
            }
        }

        $respuestaXml = (!empty($proceso['respuestaXML']));

        $dataSend = false;

        if (empty($proceso['method'])) {
            $arrResponse['msg'] = 'Debe configurar el tipo de servicio (POST, GET, etc)';
            return $arrResponse;
        }

        if ($proceso['authType'] === 'elroble') {

            $urlAuth = $proceso['authUrl'] ?? '';
            $authPayload = $proceso['authPayload'] ?? '';

            if (empty($urlAuth)) {
                $arrResponse['msg'] = 'Debe configurar la url de autenticación del servicio';
                return $arrResponse;
            }

            if (empty($authPayload)) {
                $arrResponse['msg'] = 'Debe configurar los datos de autenticación del servicio';
                return $arrResponse;
            }

            $acsel = new \ACSEL_WS(false, true); // Siempre el servicio de gestiones de momento
            $acsel->setAuthData($urlAuth, $authPayload);


            $dataResponse['enviado'] = $dataToSend;

            if ($proceso['method'] == 'get') {
                $dataSend = $acsel->get($url, false);
            } else if ($proceso['method'] == 'post') {
                $dataSend = $acsel->post($url, $dataToSend ?? [], false, $respuestaXml);
            }

            if (!empty($dataSend)) {
                $arrResponse['status'] = true;
                $arrResponse['msg'] = 'Petición realizada con éxito';
            } else {
                $arrResponse['msg'] = 'Error al consumir servicio, el servicio no ha devuelto respuesta';
            }

            $dataResponse['enviadoH'] = (!empty($acsel->rawHeaders) ? $acsel->rawHeaders : $headers);
            $dataResponse['recibidoProcesado'] = $dataSend;
            $dataResponse['recibido'] = $acsel->rawResponse;
        } else {

            // Autenticación cualquiera
            if ($proceso['authType'] === 'bearer') {
                $hadersSend['Authorization'] = "Bearer {$proceso['bearerToken']}";
            }

            $headers = [];
            foreach ($hadersSend as $key => $value) {
                $headers[] = "{$key}:{$value}";
            }

            $dataResponse['enviadoH'] = print_r($headers, true);
            $dataResponse['enviado'] = $dataToSend;

            // PHP cURL  for https connection with auth
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            //curl_setopt($ch, CURLOPT_USERPWD, $soapUser.":".$soapPassword); // username and password - declared at the top of the doc
            //curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            if ($proceso['method'] == 'get') {
                curl_setopt($ch, CURLOPT_POST, false);
            } else if ($proceso['method'] == 'post') {
                curl_setopt($ch, CURLOPT_POST, true);
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $dataToSend); // the SOAP request

            //dd($hadersSend);

            // converting
            $dataSend = curl_exec($ch);
            /*if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
            }*/
            /*var_dump($dataSend);
            die();*/
            $dataResponse['recibido'] = print_r($dataSend, true);

            curl_close($ch);

            if ($respuestaXml) {

                $dataSend = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $dataSend);
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($dataSend);

                if (!$xml) {
                    libxml_clear_errors();
                    $arrResponse['msg'] = 'Error al parsear XML de respuesta';
                    return $arrResponse;
                } else {
                    $dataSend = json_decode(json_encode((array)simplexml_load_string($dataSend)), true);
                }
            } else {
                $dataSend = @json_decode($dataSend, true);
            }

            $dataResponse['recibidoProcesado'] = print_r($dataSend, true);
        }

        $result = array();
        if (is_array($dataSend)) {
            $ritit = new RecursiveIteratorIterator(new RecursiveArrayIterator($dataSend));

            foreach ($ritit as $leafValue) {
                $keys = array();
                foreach (range(0, $ritit->getDepth()) as $depth) {
                    $keys[] = $ritit->getSubIterator($depth)->key();
                }
                $result[join('.', $keys)] = $leafValue;
            }
        }

        $resultFull = [];
        foreach ($result as $key => $value) {
            $resultFull[$proceso['identificadorWs'] . '.' . $key] = $value;
        }

        $arrResponse['data'] = $resultFull;
        $arrResponse['log'] = $dataResponse;

        if (!empty($dataSend)) {
            $arrResponse['status'] = true;
            $arrResponse['msg'] = 'Petición realizada con éxito';
        }

        return $arrResponse;
    }

    public function CalcularPasosPublic(Request $request)
    {
        return $this->CalcularPasos($request, false, true);
    }

    public function CalcularPasosBitacora(Request $request)
    {
        $nodoId = $request->get('nodoId');
        $cotizacionesUserNodoId = intval($request->get('cotizacionesUserNodoId'));
        $cotizacionId = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('COT-632', 'Tarea no válida');
        }

        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            // if (!$AC->CheckAccess(['tareas/admin/calcular-paso-bitacora'])) return $AC->NoAccess();
        }

        $camposAll = CotizacionDetalleBitacora::where('cotizacionId', $cotizacion->id)
            ->where(function ($query) use ($cotizacionesUserNodoId) {
                $query->where('cotUserNodId', '<', $cotizacionesUserNodoId)
                    ->orWhereNull('cotUserNodId');
            })
            ->whereIn('id', function ($query) use ($cotizacion, $cotizacionesUserNodoId) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('cotizacionesDetalleBitacora')
                    ->where('cotizacionId', $cotizacion->id)
                    ->where(function ($query) use ($cotizacionesUserNodoId) {
                        $query->where('cotUserNodId', '<', $cotizacionesUserNodoId)
                            ->orWhereNull('cotUserNodId');
                    })
                    ->groupBy('campo');
            })
            ->orderBy('id', 'DESC')
            ->get();
        return $this->CalcularPasos($request, false, false, true, $nodoId, $camposAll);
    }

    public function GetCotizacionResumenBitacora(Request $request)
    {
        $nodoId = $request->get('nodoId');
        $cotizacionesUserNodoId = $request->get('cotizacionesUserNodoId');
        $cotizacionId = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('COT-632', 'Tarea no válida');
        }

        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            // if (!$AC->CheckAccess(['tareas/admin/calcular-paso-bitacora'])) return $AC->NoAccess();
        }

        $camposAll = CotizacionDetalleBitacora::where('cotizacionId', $cotizacion->id)
            ->where(function ($query) use ($cotizacionesUserNodoId) {
                $query->where('cotUserNodId', '<', $cotizacionesUserNodoId)
                    ->orWhereNull('cotUserNodId');
            })
            ->whereIn('id', function ($query) use ($cotizacion, $cotizacionesUserNodoId) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('cotizacionesDetalleBitacora')
                    ->where('cotizacionId', $cotizacion->id)
                    ->where(function ($query) use ($cotizacionesUserNodoId) {
                        $query->where('cotUserNodId', '<', $cotizacionesUserNodoId)
                            ->orWhereNull('cotUserNodId');
                    })
                    ->groupBy('campo');
            })
            ->orderBy('id', 'DESC')
            ->get();
        return $this->GetCotizacionResumen($request, false, $camposAll);
    }

    public function GetFieldsByNodoBitacora(Request $request)
    {
        $nodoId = $request->get('nodoId');
        $cotizacionesUserNodoId = intval($request->get('cotizacionesUserNodoId'));
        $cotizacionId = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();
        $lastCotizacionesUserNodo = CotizacionesUserNodo::where('cotizacionId', $cotizacion->id)
            ->where('id', '<', $cotizacionesUserNodoId)
            ->orderBy('id', 'desc')
            ->first();
        $idLastCotizacionesUserNodo = $lastCotizacionesUserNodo->id ?? null;
        if (empty($cotizacion)) {
            return $this->ResponseError('COT-632', 'Tarea no válida');
        }

        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
        }

        $camposAll = CotizacionDetalleBitacora::where('cotizacionId', $cotizacion->id)
            // ->whereIn('cotUserNodId', [$idLastCotizacionesUserNodo, $cotizacionesUserNodoId])
            // ->whereIn('nodoId', [null, $nodoId])
            ->where(function ($query) use ($idLastCotizacionesUserNodo, $cotizacionesUserNodoId) {
                $query->whereIn('cotUserNodId', [$idLastCotizacionesUserNodo, $cotizacionesUserNodoId]);
                if (empty($idLastCotizacionesUserNodo)) {
                    $query->orWhereNull('cotUserNodId');
                }
            })
            ->where(function ($query) use ($nodoId) {
                $query->where('nodoid', $nodoId)
                    ->orWhereNull('nodoid');
            })
            ->whereNot('tipo', 'default')
            ->orderBy('campo')
            ->orderBy('id', 'DESC')
            ->get();

        foreach ($camposAll as $campo) {
            $usuario = $campo->usuario ?? null;
            $campo->usuarioNombre = $usuario->name ?? 'Sin Usuario';
            $campo->usuarioCorporativo = $usuario->corporativo ?? 'Sin corporativo';
            $campo->createdAt = Carbon::parse($campo->createdAt)->setTimezone('America/Guatemala')->format('d/m/Y H:i');
        }

        return $this->ResponseSuccess('Resumen con exito', $camposAll);
    }


    public function CalcularCatalogo(Request $request)
    {

        $depends = $request->get('depends');
        $valor = $request->get('value');
        $cotizacionId = $request->get('token');
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();
        $producto = $cotizacion->producto;
        //$campos = $cotizacion->campos;

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('COT-608', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('COT-610', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        $arrNodosCatalogo = [];
        foreach ($flujoConfig['nodes'] as $nodo) {

            if (empty($nodo['typeObject'])) continue;

            // todos los campos
            foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                //$allFields[$keySeccion]['nombre'] = $seccion['nombre'];
                foreach ($seccion['campos'] as $campo) {
                    if (empty($campo['id']) || empty($campo['catalogoId'])) continue;
                    $arrNodosCatalogo[$campo['id']] = $campo;
                }
            }
        }

        if (count($arrNodosCatalogo) === 0) {
            return $this->ResponseSuccess('Catalogos obtenidos con éxito', []);
        }

        $tmpData = [];
        if (isset($producto->extraData) && $producto->extraData !== '') {
            $tmpData = json_decode($producto->extraData, true);
            $tmpData = $tmpData['planes'] ?? [];
        }

        //dd($tmpData);

        $arrResponse = [];

        foreach ($arrNodosCatalogo as $campo) {
            if (is_string($campo['catalogoId'])) {

                if (isset($tmpData[$campo['catalogoId']])) {

                    $itemsCatalog = [];

                    if (!empty($campo['catFId'])) {
                        if (!empty($depends)) {
                            foreach ($tmpData[$campo['catalogoId']]['items'] as $item) {
                                if (isset($item[$campo['catFValue']]) && $item[$campo['catFValue']] === $valor) {
                                    $itemsCatalog[] = $item;
                                }
                            }
                            $arrResponse[$campo['id']] = $itemsCatalog;
                        }
                    } else {
                        //var_dump($campo['catFValue']);
                        //dd($campo['catalogoId']);
                        if (empty($depends)) {
                            $itemsCatalog = $tmpData[$campo['catalogoId']]['items'];
                            $arrResponse[$campo['id']] = $itemsCatalog;
                        }
                    }
                }
            }
        }
        //dd($arrResponse);
        return $this->ResponseSuccess('Catalogos obtenidos con éxito', $arrResponse);
    }

    public function uploadFileAttachPublic(Request $request)
    {
        return $this->uploadFileAttach($request, true);
    }

    public function uploadFileAttach(Request $request, $public = false)
    {

        $archivo = $request->file('file');
        $fileBase64 = $request->get('fileBase64');
        $fileLink = $request->get('fileLink');
        $typeUpload = $request->get('typeUpload');
        $cotizacionId = $request->get('token');
        $seccionKey = $request->get('seccionKey');
        $campoId = $request->get('campoId');
        $execNodoOCR = $request->get('execOCR');

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        /*return $this->ResponseSuccess('Archivo subido con éxito', [
            'key' => 'test'
        ]);
        die();*/

        if (!empty($usuarioLogueado) && !$public) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/uploadfiles'])) return $AC->NoAccess();
        }

        if (empty($cotizacion)) {
            return $this->ResponseError('COT-632', 'La tarea no existe o está asociada a otro usuario');
        }
        $lastCotizacionesUserNodo = CotizacionesUserNodo::where('cotizacionId', $cotizacion->id)->orderBy('id', 'desc')->first();
        $idLastCotiUserNodo = !empty($lastCotizacionesUserNodo) ? $lastCotizacionesUserNodo->id : null;

        $flujoConfig = $this->getFlujoFromCotizacion($cotizacion);
        //dd($flujoConfig);

        if (!$flujoConfig['status']) {
            return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
        } else {
            $flujoConfig = $flujoConfig['data'];
        }

        // Recorro campos para hacer resumen
        $expedientesNew = [];
        $campos = [];
        foreach ($flujoConfig['nodes'] as $nodo) {
            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {
                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                    foreach ($seccion['campos'] as $keyCampo => $campo) {
                        $campos[$campo['id']] = $campo;
                    }
                }
            }
        }

        $expedientesNew = $campos[$campoId]['expNewConf'] ?? [];
        /*var_dump($expedientesNew);
        die();*/

        if ($expedientesNew['sobreescribir'] === 'N') {
            $archivoTmp = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('campo', $campoId)->first();
            if (!empty($archivoTmp->valorLong)) {
                return $this->ResponseError('UPLF-O2', 'El archivo no permite la sobreescritura');
            }
        }

        $dir = '';
        $tipoArchivo = '';
        $arrMimeTypes = [];
        $arrMimeTypesTmp = [];

        if (!empty($campos[$campoId]['filePath'])) {
            $dir = $campos[$campoId]['filePath'];
            $tipoArchivo = $campos[$campoId]['tipoCampo'];
        }
        $dir = trim($dir, '/');

        // Variables por defecto si no existen
        $data = $cotizacion->campos;

        if (empty($data->where('campo', 'ID_SOLICITUD')->first())) {

            $camposTmp = [];
            $tmpUser = User::where('id', $cotizacion->usuarioId)->first();
            $rolUser = UserRol::where('userId', $cotizacion->usuarioId)->first();
            $rol = null;
            if (!empty($rolUser)) $rol = Rol::where('id', $rolUser->rolId)->first();
            $camposTmp['FECHA_SOLICITUD']['v'] = $cotizacion->dateCreated;
            $camposTmp['FECHA_HOY']['v'] = Carbon::now()->toDateTimeString();
            $camposTmp['ID_SOLICITUD']['v'] = $cotizacion->id;
            $camposTmp['TAREA_TOKEN']['v'] = $cotizacion->token;
            $camposTmp['HOY_SUM_1_YEAR']['v'] = Carbon::now()->addYear()->toDateTimeString();
            $camposTmp['HOY_SUM_1_YEAR_F1']['v'] = Carbon::now()->addYear()->format('d/m/Y');
            $camposTmp['CREADOR_NOMBRE']['v'] = (!empty($tmpUser) ? $tmpUser->name : 'Sin nombre');
            $camposTmp['CREADOR_CORP']['v'] = (!empty($tmpUser) ? $tmpUser->corporativo : 'Sin corporativo');
            $camposTmp['CREADOR_NOMBRE_USUARIO']['v'] = (!empty($tmpUser) ? $tmpUser->nombreUsuario : 'Sin nombre');
            $camposTmp['CREADOR_ROL']['v'] = (!empty($rol) ? $rol->name : 'Sin rol');

            // producto
            $productoTk = $cotizacion->producto->token ?? '';
            $camposTmp['LINK_FORM']['v'] = $this->getCotizacionLink($productoTk, $cotizacion->token);
            $camposTmp['LINK_FORM_PRIVADO']['v'] = $this->getCotizacionLinkPrivado($productoTk, $cotizacion->token);



            foreach ($camposTmp as $campoKey => $valor) {
                $campoTmp = new CotizacionDetalle();
                $campoTmp->cotizacionId = $cotizacion->id;
                $campoTmp->seccionKey = 0;
                $campoTmp->campo = $campoKey;
                $campoTmp->label = '';
                $campoTmp->useForSearch = 0;
                $campoTmp->tipo = 'default';
                $campoTmp->valorLong = $valor['v'];
                $campoTmp->save();

                $this->saveCotizacionDetalleBitacora($campoTmp, $idLastCotiUserNodo, $cotizacion->nodoActual, $usuarioLogueadoId);
            }

            // se vuelve a traer la data
            $cotizacion = $cotizacion->refresh();
            $data = $cotizacion->campos;
        }

        if (!empty($campos[$campoId]['mime'])) {
            $campos[$campoId]['mime'] = $this->reemplazarValoresSalida($data, $campos[$campoId]['mime']);
            $arrMimeTypesTmp = explode(',', $campos[$campoId]['mime']);
        }
        //wdd($campos[$campoId]);

        $resultMimes = array_map('trim', $arrMimeTypesTmp);

        foreach ($resultMimes as $mime) {
            $peso = explode('|', $mime);
            if (!empty($peso[0])) {
                $arrMimeTypes[$peso[0]] = $peso[1] ?? 0;
            }
        }

        /*var_dump($typeUpload);
        die;*/
        $nombreOriginal = '';

        //dd($arrMimeTypes);
        if (!empty($fileBase64)) {
            $fileData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $fileBase64));
            $tmpFilePath = storage_path("tmp/" . uniqid());
            file_put_contents($tmpFilePath, $fileData);
            $archivo = new File($tmpFilePath);
            $extension = $this->verifyFileExtension($archivo->getMimeType());

            /*$archivoTmp = new UploadedFile(
                $archivo->getPathname(),
                $archivo->getFilename(),
                $archivo->getMimeType(),
                0,
                true // Mark it as test, since the file isn't from real HTTP POST.
            );*/
        } else if (!empty($fileLink)) {
            $fileData = fopen($fileLink, 'r');
            $tmpFilePath = storage_path("tmp/" . uniqid());
            file_put_contents($tmpFilePath, $fileData);
            $archivo = new File($tmpFilePath);
            $extension = $this->verifyFileExtension($archivo->getMimeType());

            /*$archivoTmp = new UploadedFile(
                $archivo->getPathname(),
                $archivo->getFilename(),
                $archivo->getMimeType(),
                0,
                true // Mark it as test, since the file isn't from real HTTP POST.
            );*/
        } else {
            $nombreOriginal = $archivo->getClientOriginalName();
            $extension = $archivo->extension();
        }

        // Valido los mime
        $fileType = $archivo->getMimeType();
        $fileType = trim($fileType);
        $fileSize = $archivo->getSize();
        $fileSize = $fileSize / 1000000;
        if (!isset($arrMimeTypes[$fileType])) {
            return $this->ResponseError('T-12', 'Tipo de archivo no permitido para subida', ['mime' => $fileType, 'size' => $fileSize]);
        }

        // valido peso
        if (floatval($arrMimeTypes[$fileType]) < floatval($fileSize)) {
            return $this->ResponseError('T-13', "Peso de archivo excedido, máximo " . number_format($arrMimeTypes[$fileType], 2) . " mb");
        }

        $hashName = md5($nombreOriginal); // Obtiene el nombre generado por Laravel
        $filenameWithExtension = $hashName . '.' . $extension; // Concatena el nombre generado por Laravel con la extensión

        if ($tipoArchivo === 'file') {
            try {
                $disk = Storage::disk('s3');
                $path = $disk->putFileAs($dir, $archivo, $filenameWithExtension);
                $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(10));

                $campo = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('campo', $campoId)->first();

                if (empty($campo)) {
                    $campo = new CotizacionDetalle();
                }
                $campo->cotizacionId = $cotizacion->id;
                $campo->seccionKey = $seccionKey;
                $campo->campo = $campoId;
                $campo->valorLong = $path;
                $campo->isFile = 1;
                $campo->save();

                $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $cotizacion->nodoActual, $usuarioLogueadoId);

                return $this->ResponseSuccess('Archivo subido con éxito', [
                    'key' => $temporarySignedUrl
                ]);
            } catch (\Exception $e) {
                //dd($e->getMessage());
                //$response['msg'] = 'Error en subida, por favor intente de nuevo '.$e;
                return $this->ResponseError('T-121', 'Error al cargar archivo ');
            }
        } else {

            if ($tipoArchivo === 'fileERMulti' && $extension === 'zip') {

                $folderTmp = md5(uniqid());
                $tmpPath = storage_path("tmp/");
                if (!file_exists("{$tmpPath}/{$folderTmp}")) {
                    mkdir("{$tmpPath}/{$folderTmp}");
                    $tmpPath = "{$tmpPath}/{$folderTmp}";
                }

                $zipArchive = new \ZipArchive();
                $result = $zipArchive->open($archivo->getRealPath());
                if ($result === TRUE) {
                    $zipArchive->extractTo($tmpPath);
                    $zipArchive->close();
                } else {
                    return $this->ResponseError('T-225', 'Error al descomprimir .zip, el archivo parece corrupto');
                }

                $filesToUpload = [];

                if ($handle = opendir($tmpPath)) {
                    while (false !== ($entry = readdir($handle))) {
                        if ($entry != "." && $entry != ".." && $entry != "__MACOSX" && $entry != ".DS_Store") {
                            $mimeTmp = mime_content_type("{$tmpPath}/{$entry}");
                            if (!isset($arrMimeTypes[$mimeTmp])) {
                                continue;
                            }
                            $extension = pathinfo("{$tmpPath}/{$entry}", PATHINFO_EXTENSION);
                            $hashName = md5(uniqid()); // Obtiene el nombre generado por Laravel
                            $filenameWithExtension = $hashName . '.' . $extension; // Concatena el nombre generado por Laravel con la extensión
                            $filesToUpload[] = [
                                'path' => "{$tmpPath}/{$entry}",
                                'mime' => $mimeTmp,
                                'name' => $filenameWithExtension,
                            ];
                        }
                    }
                    closedir($handle);
                }

                if (!empty($campos[$campoId]['filePath'])) {
                    $dir = $campos[$campoId]['filePath'];
                    $tipoArchivo = $campos[$campoId]['tipoCampo'];
                }

                $uniqueId = uniqid();

                $arrErrors = [];
                $countFile = 1;
                foreach ($filesToUpload as $numberFile => $file) {

                    $campoIdNew = "{$campoId}_{$countFile}";
                    $campo = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('campo', $campoIdNew)->first();

                    $ch = curl_init();

                    // Se mandan indexados
                    $arrArchivo = [];
                    $urlExp = env('EXPEDIENTES_URL') . '/?api=true&opt=upload';

                    // Si usará nueva estructura de expedientes
                    if (!empty($expedientesNew['label'])) {

                        $urlExp = env('EXPEDIENTES_NEW_URL') . '/?api=true&opt=upload';

                        $label = $expedientesNew['label'] ?? "Sin-nombre-{$uniqueId}";
                        $arrArchivo['folderPath'] = trim(trim($dir), '/');
                        $arrArchivo['ramo'] = $expedientesNew['ramo'] ?? '';
                        $arrArchivo['label'] = $label . "_" . $numberFile;
                        $arrArchivo['filetype'] = $expedientesNew['tipo'] ?? '';
                        $arrArchivo['sourceaplication'] = 'Workflow';
                        $arrArchivo['bucket'] = 'EXPEDIENTES';
                        $arrArchivo['overwrite'] = $expedientesNew['sobreescribir'] ?? 'N';

                        if (!empty($campo->expToken)) {
                            $arrArchivo['token'] = $campo->expToken;
                        }

                        foreach ($expedientesNew['attr'] as $attr) {
                            $arrArchivo[$attr['attr']] = $attr['value'];
                        }
                    } else {
                        return $this->ResponseError('T-226', 'Campos múltiples no soportan estructura de expedientes antigua');
                    }

                    $arrSend = [];
                    foreach ($arrArchivo as $key => $item) {
                        $arrSend[$key] = $this->reemplazarValoresSalida($data, $item, false, $key === 'folderPath'); // En realidad es salida pero lo guardan como entrada
                    }

                    if (empty($arrSend['folderPath'])) {
                        return $this->ResponseError('T-223', 'Uno o más campos son requeridos previo a la subida de este archivo');
                    }

                    $arrSend['file'] = new \CurlFile($file['path'], $file['mime'], $file['name']);

                    $headers = [
                        'Authorization: Bearer 1TnwxbcvSesYkiqzl2nsmPgULTlYZFgSrcb3hSb383Tkv0ZzyaBz0sjD7LM2ymh',
                    ];
                    //dd($arrArchivo);

                    curl_setopt($ch, CURLOPT_URL, $urlExp);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $arrSend);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    $server_output = curl_exec($ch);
                    $server_output = @json_decode($server_output, true);
                    curl_close($ch);

                    // var_dump($server_output);

                    //dd($server_output);

                    // elimina el archivo
                    if (file_exists($file['path'])) unlink($file['path']);

                    if (!empty($server_output['status'])) {

                        if (empty($campo)) {
                            $campo = new CotizacionDetalle();
                        }
                        $campo->cotizacionId = $cotizacion->id;
                        $campo->seccionKey = $seccionKey;
                        $campo->campo = $campoIdNew;
                        $campo->valorLong = $server_output['data']['exp-url'];
                        $campo->expToken = $server_output['data']['token'] ?? null;
                        $campo->isFile = 1;
                        $campo->save();

                        $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $cotizacion->nodoActual, $usuarioLogueadoId);
                    } else {
                        $arrErrors[] = $server_output['msg'];
                        //return $this->ResponseError('T-222', $server_output['msg'] ?? 'Error al cargar archivo, por favor intente de nuevo');
                    }
                    $countFile++;
                }

                if (count($arrErrors) > 0) {
                    return $this->ResponseError('T-228', implode(', ', $arrErrors));
                } else {
                    return $this->ResponseSuccess('Archivos subidos con éxito');
                }
                return $this->ResponseError('T-225', 'DIE');
            } else {
                if (!empty($campos[$campoId]['filePath'])) {
                    $dir = $campos[$campoId]['filePath'];
                    $tipoArchivo = $campos[$campoId]['tipoCampo'];
                }

                $ch = curl_init();

                // Se mandan indexados
                $arrArchivo = [];
                $urlExp = env('EXPEDIENTES_URL') . '/?api=true&opt=upload';

                $campo = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('campo', $campoId)->first();

                // Si usará nueva estructura de expedientes
                if (!empty($expedientesNew['label'])) {

                    $urlExp = env('EXPEDIENTES_NEW_URL') . '/?api=true&opt=upload';

                    $arrArchivo['folderPath'] = trim(trim($dir), '/');
                    $arrArchivo['ramo'] = $expedientesNew['ramo'] ?? '';
                    $arrArchivo['label'] = $expedientesNew['label'] ?? '';
                    $arrArchivo['filetype'] = $expedientesNew['tipo'] ?? '';
                    $arrArchivo['sourceaplication'] = 'Workflow';
                    $arrArchivo['bucket'] = 'EXPEDIENTES';
                    $arrArchivo['overwrite'] = (!empty($expedientesNew['sobreescribir']) && $expedientesNew['sobreescribir'] === 'S') ? 'Y' : 'N';

                    if (!empty($campo->expToken)) {
                        $arrArchivo['token'] = $campo->expToken;
                    }

                    foreach ($expedientesNew['attr'] as $attr) {
                        $arrArchivo[$attr['attr']] = $attr['value'];
                    }
                } else {
                    $arrArchivo['folderPath'] = trim(trim($dir), '/');
                    $arrArchivo['ramo'] = $campos[$campoId]['fileRamo'] ?? '';
                    $arrArchivo['producto'] = $campos[$campoId]['fileProducto'] ?? '';
                    $arrArchivo['fechaCaducidad'] = $campos[$campoId]['fileFechaExp'] ?? '';
                    $arrArchivo['reclamo'] = $campos[$campoId]['fileReclamo'] ?? '';
                    $arrArchivo['poliza'] = $campos[$campoId]['filePoliza'] ?? '';
                    $arrArchivo['estadoPoliza'] = $campos[$campoId]['fileEstadoPoliza'] ?? '';
                    $arrArchivo['nit'] = $campos[$campoId]['fileNit'] ?? '';
                    $arrArchivo['dpi'] = $campos[$campoId]['fileDPI'] ?? '';
                    $arrArchivo['cif'] = $campos[$campoId]['fileCIF'] ?? '';
                    $arrArchivo['label'] = $campos[$campoId]['fileLabel'] ?? '';
                    $arrArchivo['filetype'] = $campos[$campoId]['fileTipo'] ?? '';
                    $arrArchivo['filetypeSecondary'] = $campos[$campoId]['fileTipo2'] ?? '';
                    $arrArchivo['source'] = 'Workflow';
                    $arrArchivo['identificador'] = $cotizacion->id;
                }
                /*var_dump($arrArchivo);
                die();*/

                $arrSend = [];
                foreach ($arrArchivo as $key => $item) {
                    $arrSend[$key] = $this->reemplazarValoresSalida($data, $item, false, $key === 'folderPath'); // En realidad es salida pero lo guardan como entrada
                }

                if (empty($arrSend['folderPath'])) {
                    return $this->ResponseError('T-223', 'Uno o más campos son requeridos previo a la subida de este archivo');
                }

                $arrSend['file'] = new \CurlFile($archivo->getRealPath(), $fileType, $filenameWithExtension);

                $headers = [
                    'Authorization: Bearer 1TnwxbcvSesYkiqzl2nsmPgULTlYZFgSrcb3hSb383Tkv0ZzyaBz0sjD7LM2ymh',
                ];
                //dd($arrArchivo);

                curl_setopt($ch, CURLOPT_URL, $urlExp);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $arrSend);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $server_output = curl_exec($ch);
                $server_output = @json_decode($server_output, true);
                curl_close($ch);

                // var_dump($server_output);

                //dd($server_output);

                if (!empty($server_output['status'])) {

                    if (empty($campo)) {
                        $campo = new CotizacionDetalle();
                    }
                    $campo->cotizacionId = $cotizacion->id;
                    $campo->seccionKey = $seccionKey;
                    $campo->label = $arrArchivo['label'] ?? null;
                    $campo->campo = $campoId;
                    $campo->valorLong = $server_output['data']['exp-url'];
                    $campo->expToken = $server_output['data']['token'] ?? null;
                    $campo->isFile = 1;
                    $campo->save();


                    if (!empty($execNodoOCR)) {

                        $tpl = ConfiguracionOCR::where('id', $execNodoOCR)->first();
                        if (empty($tpl)) {
                            return $this->ResponseError('OCR01', 'Plantilla OCR no configurada correctamente');
                        }

                        $fileTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('isFile', 0)->get();
                        $tplConfig = @json_decode($tpl->configuracion);
                        //$subConfig = @json_decode($tplConfig->$template);

                        // procesa ocr
                        $headers = array(
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . env('ANY_SUBSCRIPTIONS_TOKEN')
                        );

                        $dataSend = [
                            "process" => "auto",
                            "removePages" => 1,
                            "htmlEndlines" => 0,
                            "noReturnEndlines" => 1,
                            "includeText" => 1,
                            "detectQRBar" => 0,
                            "encodingFrom" => 0,
                            "encodingTo" => 0
                        ];

                        $dataSend['templateToken'] = $tplConfig->slug;
                        //$dataSend['filepath'] = $file->valorLong;

                        //var_dump($dataSend);

                        $ch = curl_init(env('ANY_SUBSCRIPTIONS_URL', '').'/formularios/docs-plus/ocr-process/gen3');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        $data = curl_exec($ch);
                        $info = curl_getinfo($ch);
                        curl_close($ch);
                        $resultado = @json_decode($data, true);

                        if (!empty($resultado['status'])) {

                            /*CotizacionOCR::where('cotizacionId', $item->id)->where('nodoId', $execNodoOCR)->delete();

                            $result = [];
                            if (is_array($dataSend)) {
                                $ritit = new RecursiveIteratorIterator(new RecursiveArrayIterator($resultado['data']));

                                foreach ($ritit as $leafValue) {
                                    $keys = array();
                                    foreach (range(0, $ritit->getDepth()) as $depth) {
                                        $keys[] = $ritit->getSubIterator($depth)->key();
                                    }
                                    $result[join('.', $keys)] = $leafValue;
                                }
                            }

                            $resultFull = [];
                            $count = 1;
                            foreach ($result as $key => $value) {
                                $resultFull["{$execNodoOCR}_{$key}"] = $value;
                            }

                            foreach ($resultFull as $key => $value) {
                                $fileTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $key)->first();
                                if (empty($fileTmp)) {
                                    $fileTmp = new CotizacionDetalle();
                                }
                                $fileTmp->cotizacionId = $item->id;
                                $fileTmp->campo = $key;
                                $fileTmp->valorLong = $value;
                                $fileTmp->save();
                            }*/

                            // guarda campos
                            /*if (!empty($resultado['data']['tokens']['pages']['0'])) {
                                foreach ($resultado['data']['tokens']['pages']['0'] as $key => $value) {
                                    $ocrRow = new CotizacionOCR();
                                    $ocrRow->cotizacionId = $item->id;
                                    $ocrRow->cotizacionDetalleId = $file->id;
                                    $ocrRow->configuracionOcrId = $tpl->id;
                                    $ocrRow->nodoId = $flujo['next']['nodoId'];
                                    $ocrRow->tipo = 'token';
                                    $ocrRow->field = $key;
                                    $ocrRow->value = @json_encode($value);
                                    $ocrRow->save();
                                }
                            }
                            if (!empty($resultado['data']['tokens']['tables'])) {
                                foreach ($resultado['data']['tokens']['tables'] as $key => $value) {
                                    $ocrRow = new CotizacionOCR();
                                    $ocrRow->cotizacionId = $item->id;
                                    $ocrRow->cotizacionDetalleId = $file->id;
                                    $ocrRow->configuracionOcrId = $tpl->id;
                                    $ocrRow->nodoId = $flujo['next']['nodoId'];
                                    $ocrRow->tipo = 'table';
                                    $ocrRow->field = $key;
                                    $ocrRow->value = @json_encode($value['data']);
                                    $ocrRow->save();
                                }
                            }*/
                        }
                    }

                    $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $cotizacion->nodoActual, $usuarioLogueadoId);

                    return $this->ResponseSuccess('Archivo subido con éxito', [
                        'key' => $server_output['data']['s3-url-tmp']
                    ]);
                } else {
                    return $this->ResponseError('T-222', $server_output['msg'] ?? 'Error al cargar archivo, por favor intente de nuevo');
                }
            }
        }
    }

    public function GetFilePreview(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $token = $request->get('token');
        $seccionKey = $request->get('seccionKey');

        $usuarioLogueado = $usuario = auth('sanctum')->user();
        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseSuccess('Flujo sin adjuntos');
        }

        $producto = $cotizacion->producto;
        if (empty($producto)) {
            return $this->ResponseError('COT-700', 'Producto no válido');
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('COT-701', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('COT-701', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        $camposList = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('isFile', 1)->get();

        // Recorro campos para hacer resumen
        $campos = [];
        foreach ($flujoConfig['nodes'] as $nodo) {
            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                    foreach ($seccion['campos'] as $keyCampo => $campo) {

                        // campos tipo archivo
                        if ($campo['tipoCampo'] !== 'file' && $campo['tipoCampo'] !== 'fileER' && $campo['tipoCampo'] !== 'fileERMulti' && $campo['tipoCampo'] !== 'signature') continue;

                        if (!empty($campo['grupos_assign']) || !empty($campo['roles_assign'])) {
                            $isInGroup = $this->validateUserInGroup($usuarioLogueado, $campo['grupos_assign'] ?? [], $campo['roles_assign'] ?? []);
                            if (!$isInGroup) continue;
                        }

                        $tmp = trim($campo['id']);
                        $arrCamposId = [
                            $tmp
                        ];
                        if ($campo['tipoCampo'] === 'fileERMulti') {
                            for ($i = 0; $i <= 20; $i++) {
                                $arrCamposId[] = "{$tmp}_{$i}";
                            }
                        }

                        // var_dump($arrCamposId);
                        $camposTmp = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->whereIn('campo', $arrCamposId)->get();

                        foreach ($camposTmp as $dbValor) {
                            $tmpPath = '';
                            if (!empty($dbValor['valorLong'])) {
                                //dd($dbValor['valorLong']);
                                if ($campo['tipoCampo'] === 'fileER' || $campo['tipoCampo'] === 'fileERMulti') {
                                    $temporarySignedUrl = $dbValor['valorLong'];

                                    $ch = curl_init();
                                    curl_setopt($ch, CURLOPT_URL, $temporarySignedUrl);
                                    curl_setopt($ch, CURLOPT_HEADER, TRUE);
                                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE);
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                                    $a = curl_exec($ch);
                                    if (preg_match('#Location: (.*)#', $a, $r)) {
                                        $tmpPath = trim($r[1]);
                                        $tmpPath = parse_url($tmpPath);
                                    }
                                } else {
                                    $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($dbValor['valorLong'], now()->addMinutes(10));
                                    $tmpPath = parse_url($temporarySignedUrl);
                                }

                                $dataPDF = '';

                                $type = '';
                                $ext = pathinfo($tmpPath['path'] ?? '', PATHINFO_EXTENSION);
                                //dd($ext);

                                if ($dbValor->tipo === 'signature') {
                                    $type = 'signature';
                                } else {
                                    if ($ext == 'jpg' || $ext == 'jpeg' || $ext == 'png' || $ext == 'tiff' || $ext == 'gif') {
                                        $type = 'image';
                                    } else if ($ext == 'pdf') {

                                        $arrContextOptions = array(
                                            "ssl" => array(
                                                "verify_peer" => false,
                                                "verify_peer_name" => false,
                                            ),
                                        );
                                        $response = file_get_contents($temporarySignedUrl, false, stream_context_create($arrContextOptions));
                                        $type = 'pdf';
                                        $dataPDF = 'data:application/pdf;base64,' . base64_encode($response);
                                    } else if ($ext == 'docx' || $ext == 'doc') {
                                        $type = 'doc';
                                    } else if ($ext == 'xlsx' || $ext == 'xls') {
                                        $type = 'xls';
                                    }
                                }
                                $campos[$dbValor->campo] = [
                                    'label' => $campo['label'] ?? 'Sin etiqueta',
                                    'name' => $campo['nombre'] ?? 'Sin nombre',
                                    'valor' => $dbValor['valorLong'],
                                    'url' => $temporarySignedUrl,
                                    'type' => $type,
                                    'salida' => false,
                                    'basePDF' => $dataPDF,
                                ];
                            }
                        }
                    }
                }
            }
        }

        // Salidas
        foreach ($camposList as $campo) {
            if ($campo->fromSalida) {

                // dd($campo);

                if (!empty($campo['valorLong'])) {

                    //$temporarySignedUrl = Storage::disk('s3')->temporaryUrl($campo['valorLong'], now()->addMinutes(10));
                    $temporarySignedUrl = $campo['valorLong'];

                    //$ext = pathinfo($campo['valorLong'], PATHINFO_EXTENSION);
                    $ext = 'pdf';

                    $arrContextOptions = array(
                        "ssl" => array(
                            "verify_peer" => false,
                            "verify_peer_name" => false,
                        ),
                    );
                    $response = file_get_contents($temporarySignedUrl, false, stream_context_create($arrContextOptions));
                    $dataPDF = 'data:application/pdf;base64,' . base64_encode($response);

                    $campos[$campo['id']] = [
                        'label' => $campo['label'],
                        'name' => $campo['nombre'],
                        'valor' => $campo['valorLong'],
                        'url' => $temporarySignedUrl,
                        'type' => $ext,
                        'salida' => $campo['fromSalida'],
                        'basePDF' => $dataPDF,
                    ];
                }
            }
        }

        return $this->ResponseSuccess('Adjuntos actualizados con éxito', $campos);
    }

    // Subida de archivos

    public function validateUserInGroup($user, $userGroups = [], $roles = [])
    {

        $rolesGroupArr = [];

        $userRol = $user->rolAsignacion->first();
        $userRol = $userRol->rolId ?? 0;

        if (count($userGroups) > 0) {
            $rolesGroup = UserGrupoRol::whereIn('userGroupId', $userGroups)->get();

            foreach ($rolesGroup as $rolG) {
                $rolesGroupArr[] = $rolG->rolId;
            }

            if (!in_array($userRol, $rolesGroupArr)) {
                return false;
            }
        }

        if (count($roles) > 0) {
            if (!in_array($userRol, $roles)) {
                return false;
            }
        }

        return true;
    }

    public function GetProgression(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $usuarioLogueado = $usuario = auth('sanctum')->user();
        $cotizacionId = $request->get('token');

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('COT-632', 'Flujo no válido');
        }

        $flujoConfig = $this->getFlujoFromCotizacion($cotizacion);

        if (!$flujoConfig['status']) {
            return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
        } else {
            $flujoConfig = $flujoConfig['data'];
        }

        $camposCoti = $cotizacion->campos;

        $arrResponse = [
            'percent' => 0,
            'total' => 0,
            'llenos' => 0,
            'nodos' => [],
        ];

        $totalCampos = 0;
        $totalLlenos = 0;

        // Recorro campos para hacer resumen
        foreach ($flujoConfig['nodes'] as $nodo) {

            $totalCamposN = 0;
            $totalLlenosN = 0;

            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                    $totalCamposS = 0;
                    $totalLlenosS = 0;

                    foreach ($seccion['campos'] as $keyCampo => $campo) {
                        $totalCamposN++;
                        $totalCamposS++;
                        $totalCampos++;

                        $campoTmp = $camposCoti->where('campo', $campo['id'])->first();

                        if (!empty($campoTmp->valorLong)) {
                            $totalLlenosN++;
                            $totalLlenosS++;
                            $totalLlenos++;
                        }
                    }

                    if ($totalCamposS > 0) {
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['nombre'] = $seccion['nombre'];
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['percent'] = number_format(($totalLlenosS * 100) / $totalCamposS, 2);
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['total'] = $totalCamposS;
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['llenos'] = $totalLlenosS;
                    }
                }
            }

            if ($totalCamposN) {
                $arrResponse['nodos'][$nodo['id']]['info']['nombre'] = $nodo['label'];
                $arrResponse['nodos'][$nodo['id']]['info']['percent'] = number_format(($totalLlenosN * 100) / $totalCamposN, 2);
                $arrResponse['nodos'][$nodo['id']]['info']['total'] = $totalCamposN;
                $arrResponse['nodos'][$nodo['id']]['info']['llenos'] = $totalLlenosN;
            }
        }

        if ($totalCampos) {
            $arrResponse['total'] = $totalCampos;
            $arrResponse['percent'] = number_format(($totalLlenos * 100) / $totalCampos, 2);
        }

        return $this->ResponseSuccess('Preview configurada con éxito', $arrResponse);
    }

    public function CalcularCampos(Request $request)
    {

        $campos = $request->get('campos');

        // dd($campos);

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('COT-601', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('COT-601', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        $camposCoti = $cotizacion->campos;

        $arrResponse = [
            'percent' => 0,
            'total' => 0,
            'llenos' => 0,
            'nodos' => [],
        ];

        $totalCampos = 0;
        $totalLlenos = 0;

        // Recorro campos para hacer resumen
        foreach ($flujoConfig['nodes'] as $nodo) {

            $totalCamposN = 0;
            $totalLlenosN = 0;

            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                    $totalCamposS = 0;
                    $totalLlenosS = 0;

                    foreach ($seccion['campos'] as $keyCampo => $campo) {
                        $totalCamposN++;
                        $totalCamposS++;
                        $totalCampos++;

                        $campoTmp = $camposCoti->where('campo', $campo['id'])->first();

                        if (!empty($campoTmp->valorLong)) {
                            $totalLlenosN++;
                            $totalLlenosS++;
                            $totalLlenos++;
                        }
                    }

                    if ($totalCamposS > 0) {
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['nombre'] = $seccion['nombre'];
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['percent'] = number_format(($totalLlenosS * 100) / $totalCamposS, 2);
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['total'] = $totalCamposS;
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['llenos'] = $totalLlenosS;
                    }
                }
            }

            if ($totalCamposN) {
                $arrResponse['nodos'][$nodo['id']]['info']['nombre'] = $nodo['label'];
                $arrResponse['nodos'][$nodo['id']]['info']['percent'] = number_format(($totalLlenosN * 100) / $totalCamposN, 2);
                $arrResponse['nodos'][$nodo['id']]['info']['total'] = $totalCamposN;
                $arrResponse['nodos'][$nodo['id']]['info']['llenos'] = $totalLlenosN;
            }
        }

        if ($totalCampos) {
            $arrResponse['total'] = $totalCampos;
            $arrResponse['percent'] = number_format(($totalLlenos * 100) / $totalCampos, 2);
        }

        return $this->ResponseSuccess('Preview configurada con éxito', $arrResponse);
    }

    public function GetCatalogo(Request $request)
    {

        $campos = $request->get('campos');

        // dd($campos);

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('COT-601', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('COT-601', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        $camposCoti = $cotizacion->campos;

        $arrResponse = [
            'percent' => 0,
            'total' => 0,
            'llenos' => 0,
            'nodos' => [],
        ];

        $totalCampos = 0;
        $totalLlenos = 0;

        // Recorro campos para hacer resumen
        foreach ($flujoConfig['nodes'] as $nodo) {

            $totalCamposN = 0;
            $totalLlenosN = 0;

            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                    $totalCamposS = 0;
                    $totalLlenosS = 0;

                    foreach ($seccion['campos'] as $keyCampo => $campo) {
                        $totalCamposN++;
                        $totalCamposS++;
                        $totalCampos++;

                        $campoTmp = $camposCoti->where('campo', $campo['id'])->first();

                        if (!empty($campoTmp->valorLong)) {
                            $totalLlenosN++;
                            $totalLlenosS++;
                            $totalLlenos++;
                        }
                    }

                    if ($totalCamposS > 0) {
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['nombre'] = $seccion['nombre'];
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['percent'] = number_format(($totalLlenosS * 100) / $totalCamposS, 2);
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['total'] = $totalCamposS;
                        $arrResponse['nodos'][$nodo['id']]['secciones'][$keySeccion]['llenos'] = $totalLlenosS;
                    }
                }
            }

            if ($totalCamposN) {
                $arrResponse['nodos'][$nodo['id']]['info']['nombre'] = $nodo['label'];
                $arrResponse['nodos'][$nodo['id']]['info']['percent'] = number_format(($totalLlenosN * 100) / $totalCamposN, 2);
                $arrResponse['nodos'][$nodo['id']]['info']['total'] = $totalCamposN;
                $arrResponse['nodos'][$nodo['id']]['info']['llenos'] = $totalLlenosN;
            }
        }

        if ($totalCampos) {
            $arrResponse['total'] = $totalCampos;
            $arrResponse['percent'] = number_format(($totalLlenos * 100) / $totalCampos, 2);
        }

        return $this->ResponseSuccess('Preview configurada con éxito', $arrResponse);
    }

    public function uploadPdfTemplate(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/plantillas-pdf'])) return $AC->NoAccess();
        $archivo = $request->file('file');
        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $activo = $request->get('activo');

        $item = PdfTemplate::where('id', $id)->first();
        $fileNameHash = md5(uniqid());

        if (empty($item)) {
            $item = new PdfTemplate();
        } else {
            $pattern = '/tpl_([a-f\d]+)\.docx/i';
            if (preg_match($pattern, $item->urlTemplate, $matches) && !ctype_digit($matches[1])) {
                $fileNameHash = $matches[1];
            }
        }
        $item->id = $id;
        $item->nombre = $nombre;
        $item->activo = intval($activo);
        $item->save();

        if (!empty($archivo)) {
            $disk = Storage::disk('s3');
            $path = $disk->putFileAs("/system-templates", $archivo, "tpl_{$fileNameHash}.docx");

            if (empty($path)) {
                return $this->ResponseError('TPL-6254', 'Error al subir plantilla');
            }

            $item->urlTemplate = $path;
            $item->save();
        }

        return $this->ResponseSuccess('Plantilla guardada con éxito', ['id' => $item->id]);
    }


    // plantillas pdf

    public function getPdfTemplateList(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/plantillas-pdf'])) return $AC->NoAccess();

        $item = PdfTemplate::all();

        return $this->ResponseSuccess('Plantillas obtenidas con éxito', $item);
    }

    public function getPdfTemplate(Request $request, $id)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/plantillas-pdf'])) return $AC->NoAccess();
        $item = PdfTemplate::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('TPL-145', 'Error al obtener plantilla');
        }

        $item->urlShow = (!empty($item->urlTemplate)) ? Storage::disk('s3')->temporaryUrl($item->urlTemplate, now()->addMinutes(30)) : false;

        return $this->ResponseSuccess('Plantilla obtenida con éxito', $item);
    }

    public function deletePdfTemplate(Request $request)
    {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/plantillas-pdf'])) return $AC->NoAccess();

        $id = $request->get('id');
        $item = PdfTemplate::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('TPL-145', 'Plantilla inválida');
        }

        $item->delete();

        return $this->ResponseSuccess('Plantilla eliminada con éxito', $item);
    }

    public function Delete(Request $request)
    {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $item = Formulario::find($id);

            if (!empty($item)) {
                $item->delete();
                return $this->ResponseSuccess('Eliminado con éxito', $item->id);
            } else {
                return $this->ResponseError('AUTH-R5321', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            var_dump($th->getMessage());
            return $this->ResponseError('AUTH-R5302', 'Error al eliminar');
        }
    }

    // Comentarios

    public function CrearComentarioPublic(Request $request)
    {
        return $this->CrearComentario($request);
    }

    public function CrearComentario(Request $request)
    {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $token = $request->get('token');
        $comment = $request->get('comment');
        $comentarioAcceso = $request->get('comentarioAcceso');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = (!empty($usuarioLogueado) ? $usuarioLogueado->id : 0);
        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('CM-002', 'Flujo inválida');
        }

        if (!empty($comment)) {
            $commentario = new CotizacionComentario();
            $commentario->cotizacionId = $cotizacion->id;
            $commentario->userId = $usuarioLogueadoId;
            $commentario->comentario = strip_tags($comment);
            $commentario->acceso = $comentarioAcceso;
            $commentario->deleted = null;
            $commentario->save();

            return $this->ResponseSuccess('Comentario enviado con éxito');
        } else {
            return $this->ResponseError('CM-003', 'El comentario no puede estar vacío');
        }
    }

    public function GetComentariosPublic(Request $request)
    {
        return $this->GetComentarios($request);
    }

    public function GetComentarios(Request $request)
    {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $token = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = (!empty($usuarioLogueado) ? $usuarioLogueado->id : 0);

        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('CM-001', 'Flujo inválida');
        }

        $arrResult = [];

        $comentariosTmp = CotizacionComentario::where([['cotizacionId', '=', $cotizacion->id], ['deleted', '=', null]]);

        if (!$usuarioLogueadoId) {
            $comentariosTmp->where('acceso', 'publico');
        }

        $comentarios = $comentariosTmp->get();

        foreach ($comentarios as $comment) {
            $arrResult[$comment->id]['date'] = Carbon::parse($comment->createdAt)->format('d/m/Y H:i');
            $usuario = User::find($comment->userId);
            $userName = $usuario ? $usuario->name : 'Usuario sin nombre';
            $arrResult[$comment->id]['usuario'] = $arrResult[$comment->id]['date'] . ' - ' . ($usuarioLogueadoId ? $userName : 'Cliente');
            $arrResult[$comment->id]['comentario'] = $comment->comentario;
            $arrResult[$comment->id]['a'] = $comment->acceso;
        }

        return $this->ResponseSuccess('Comentarios obtenidos con éxito', $arrResult);
    }

    // reenvío
    public function reenviarSalida(Request $request)
    {

        $token = $request->get('token');
        $tipo = $request->get('tipo');
        $cotizacionId = $request->get('token');
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();
        $newEmailReenvio = $request->get('newEmailReenvio');
        $newWspReenvio = $request->get('newWspReenvio');

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
        }

        $item = Cotizacion::where([['token', '=', $token]])->first();

        $flujo = $this->CalcularPasos($request, true, false, false);

        // Si es pdf
        /*if (!empty($flujo['actual']['salidaIsPDF'])) {

            if (!empty($flujo['actual']['pdfTpl'])) {

                $itemTemplate = PdfTemplate::where('id', intval($flujo['actual']['pdfTpl']))->first();
                if (!empty($itemTemplate)) {
                    // Guardo la bitácora
                    $bitacoraCoti = new CotizacionBitacora();
                    $bitacoraCoti->cotizacionId = $itemTemplate->id;
                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                    $bitacoraCoti->log = "Error al crear PDF, plantilla inválida";
                    $bitacoraCoti->save();

                    $fileNameHash = md5(uniqid());
                    $tmpPath = storage_path("tmp/");
                    $tmpFile = storage_path("tmp/".md5(uniqid()).".docx");
                    $outputTmp = storage_path("tmp/".$fileNameHash.".docx");
                    $outputTmpPdf = $fileNameHash.".pdf";

                    $s3_file = Storage::disk('s3')->get($itemTemplate->urlTemplate);
                    file_put_contents($tmpFile, $s3_file);

                    // reemplazo valores
                    $templateProcessor = new TemplateProcessor($tmpFile);
                    //dd($item->campos);
                    foreach ($item->campos as $campoTmp) {
                        if (is_array($campoTmp->valorLong)){
                            $campoTmp->valorLong = implode(', ', $campoTmp->valorLong);
                        }
                        $templateProcessor->setValue($campoTmp->campo, $campoTmp->valorLong);
                    }
                    // dd($templateProcessor->getVariables());
                    foreach($templateProcessor->getVariables() as $variable){
                        $templateProcessor->setValue($variable, '');
                    }
                    $templateProcessor->saveAs($outputTmp);

                    // lowriter, pdf conversion
                    putenv('PATH=/usr/local/bin:/bin:/usr/bin:/usr/local/sbin:/usr/sbin:/sbin');
                    putenv('HOME=' . $tmpPath);
                    exec("/usr/bin/lowriter --convert-to pdf {$outputTmp} --outdir '{$tmpPath}'");

                    $path = '';
                    if (file_exists("{$tmpPath}{$outputTmpPdf}")) {

                        $disk = Storage::disk('s3');
                        $path = $disk->putFileAs("/".md5($itemTemplate->id)."/files", "{$tmpPath}{$outputTmpPdf}", md5(uniqid()).".pdf");
                    }
                    else {
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->log = "Error al generar PDF, la plantilla parece corrupta. \"{$flujo['actual']['nodoName']}\" -> \"{$flujo['actual']['nodoName']}\"";
                        $bitacoraCoti->save();
                    }

                    if (file_exists($tmpFile)) unlink($tmpFile);
                    if (file_exists($outputTmp)) unlink($outputTmp);
                    if (file_exists("{$tmpPath}{$outputTmpPdf}")) unlink("{$tmpPath}{$outputTmpPdf}");

                    $campoKeyTmp = (!empty($flujo['actual']['salidaPDFId'])) ? $flujo['actual']['salidaPDFId'] : 'SALIDA_'.($flujo['actual']['nodoId']);
                    $campoSalida = CotizacionDetalle::where('campo', $campoKeyTmp)->where('cotizacionId', $item->id)->first();
                    if (empty($campoSalida)) {
                        $campoSalida = new CotizacionDetalle();
                    }
                    $campoSalida->cotizacionId = $item->id;
                    $campoSalida->seccionKey = 0;
                    $campoSalida->campo = $campoKeyTmp;
                    $campoSalida->label = $flujo['actual']['salidaPDFLabel'] ?? 'Archivo sin nombre';
                    $campoSalida->valorLong = $path;
                    $campoSalida->isFile = true;
                    $campoSalida->fromSalida = true;
                    $campoSalida->save();
                }
            }
        }

        $item->refresh();*/

        if (!empty($flujo['actual']['salidaIsWhatsapp']) && $tipo === 'whatsapp') {
            $whatsappToken = $flujo['actual']['procesoWhatsapp']['token'] ?? '';
            $whatsappUrl = $flujo['actual']['procesoWhatsapp']['url'] ?? '';
            $whatsappAttachments = $flujo['actual']['procesoWhatsapp']['attachments'] ?? '';

            $whatsappData = (!empty($flujo['actual']['procesoWhatsapp']['data'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['actual']['procesoWhatsapp']['data']) : false;

            // chapus para yalo
            $tmpData = json_decode($whatsappData, true);
            if (isset($tmpData['users'][0]['params']['document']['link'])) {
                $tmpData['users'][0]['params']['document']['link'] = $this->getWhatsappUrl($tmpData['users'][0]['params']['document']['link']);
                $whatsappData = json_encode($tmpData, JSON_UNESCAPED_SLASHES);
            }

            $headers = [
                'Authorization: Bearer ' . $whatsappToken ?? '',
                'Content-Type: application/json',
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $whatsappUrl ?? '');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $whatsappData);  //Post Fields
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $server_output = curl_exec($ch);
            $yaloTmp = $server_output;
            $server_output = @json_decode($server_output, true);
            // dd($server_output);
            curl_close($ch);

            $bitacoraCoti = new CotizacionBitacora();
            $bitacoraCoti->cotizacionId = $item->id;
            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
            $bitacoraCoti->onlyPruebas = 1;
            $bitacoraCoti->dataInfo = "<b>Enviado:</b> {$whatsappData}, <b>Recibido:</b> {$yaloTmp}";
            $bitacoraCoti->log = "Enviado Whatsapp";
            $bitacoraCoti->save();

            if (empty($server_output['success'])) {
                // Guardo la bitácora
                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->onlyPruebas = 1;
                $bitacoraCoti->log = "Error al enviar WhatsApp";
                $bitacoraCoti->save();
            } else {
                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Enviado WhatsApp con éxito";
                $bitacoraCoti->save();
            }
        }

        if (!empty($flujo['actual']['salidaIsEmail']) && $tipo === 'email') {

            // dd($flujo['actual']);
            $copia = (!empty($flujo['next']['procesoEmail']['copia']))
                ? array_map(
                    fn($item) => $this->reemplazarValoresSalida($item->campos, $item['destino']),
                    array_filter($flujo['next']['procesoEmail']['copia'], fn($item) => !empty($item['destino']))
                )
                : false;

            $destino = (!empty($flujo['actual']['procesoEmail']['destino'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['actual']['procesoEmail']['destino']) : false;
            $asunto = (!empty($flujo['actual']['procesoEmail']['asunto'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['actual']['procesoEmail']['asunto']) : false;
            $config = $flujo['actual']['procesoEmail']['mailgun'] ?? [];

            // reemplazo plantilla
            $contenido = $flujo['actual']['procesoEmail']['salidasEmail'];
            $contenido = $this->reemplazarValoresSalida($item->campos, $contenido);

            $attachments = $flujo['actual']['procesoEmail']['attachments'] ?? false;

            $attachmentsSend = [];
            if ($attachments) {
                $attachments = explode(',', $attachments);

                foreach ($attachments as $attach) {
                    $campoTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $attach)->first();

                    if (!empty($campoTmp)) {
                        $ext = pathinfo($campoTmp['valorLong'] ?? '', PATHINFO_EXTENSION);
                        $s3_file = Storage::disk('s3')->get($campoTmp['valorLong']);
                        $attachmentsSend[] = ['fileContent' => $s3_file, 'filename' => ($campoTmp['label'] ?? 'Sin nombre') . '.' . $ext];
                    }
                }
            }

            $config['domain'] = $config['domain'] ?? 'N/D';
            if (!empty($newEmailReenvio)) $destino = $newEmailReenvio;
            try {
                $mg = Mailgun::create($config['apiKey'] ?? ''); // For US servers
                $email = $mg->messages()->send($config['domain'] ?? '', [
                    'from' => $config['from'] ?? '',
                    'to' => $destino ?? '',
                    'cc' => $copia ?? '',
                    'subject' => $asunto ?? '',
                    'html' => $contenido,
                    'attachment' => $attachmentsSend
                ]);

                // Guardo la bitácora
                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Enviado correo electrónico \"{$destino}\" desde \"{$config['from']}\"";
                $bitacoraCoti->save();
                // return $this->ResponseSuccess( 'Si tu cuenta existe, llegará un enlace de recuperación');
            } catch (HttpClientException $e) {
                // Guardo la bitácora
                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Error al enviar correo electrónico \"{$destino}\" desde \"{$config['from']}\", dominio de salida: {$config['domain']}";
                $bitacoraCoti->save();
                // return $this->ResponseError('AUTH-RA94', 'Error al enviar notificación, verifique el correo o la configuración del sistema');
            }
        }

        return $this->ResponseSuccess('Reenvío solicitado con éxito');
    }

    public function saveFieldOnBlur(Request $request)
    {
        $valor = $request->get('campo'); // solo seria un campo
        $token = $request->get('token');
        $seccionKey = $request->get('seccionKey');
        $campoKey = $request->get('campoKey');
        $showInReports = $request->get('showInReports');
        $nombre = $request->get('nombre');

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        $item = Cotizacion::where([['token', '=', $token]])->first();
        if (empty($item)) {
            return $this->ResponseError('COT-015', 'Tarea inválida');
        }

        $AC = new AuthController();
        if (!empty($usuarioLogueadoId)) {
            if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
            if (($usuarioLogueado->id !== $item->usuarioIdAsignado)
                && !$AC->CheckAccess(['tareas/admin/modificar'])
            ) return $AC->NoAccess();
        }

        $lastCotizacionesUserNodo = CotizacionesUserNodo::where('cotizacionId', $item->id)->orderBy('id', 'desc')->first();
        $idLastCotiUserNodo = !empty($lastCotizacionesUserNodo) ? $lastCotizacionesUserNodo->id : null;

        if ($valor['v'] === '__SKIP__FILE__') return $this->ResponseError('COT-016', 'No se guarda');

        // tipos de archivo que no se guardan
        if (!empty($valor['t']) && ($valor['t'] === 'txtlabel' || $valor['t'] === 'subtitle' || $valor['t'] === 'title')) {
            return $this->ResponseError('COT-016', 'No se guarda');
        }

        $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campoKey)->first();
        if (empty($campo)) {
            $campo = new CotizacionDetalle();
        }
        $campo->cotizacionId = $item->id;
        $campo->seccionKey = $seccionKey;
        $campo->campo = $campoKey;
        $campo->useForSearch = $showInReports ? 1 : 0;
        $campo->label = $nombre;

        $campo->tipo = $valor['t'] ?? 'default';

        if ($campo->tipo === 'signature') {
            // solo se guarda la firma si viene en base 64, quiere decir que cambió
            if (str_contains($valor['v'], 'data:image/')) {
                $marcaToken = $item->marca->token ?? false;
                $name = md5(uniqid()) . '.png';
                $dir = "{$marcaToken}/{$item->token}/{$name}";
                $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $valor['v']));
                //$disk = Storage::disk('s3');
                //$path = $disk->put($dir, $image);
                $campo->isFile = 1;
                // $campo->valorLong = (string) $valor['v'];

                // subida a expedientes
                $flujoConfig = $this->getFlujoFromCotizacion($item);
                //dd($flujoConfig);

                if (!$flujoConfig['status']) {
                    return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
                } else {
                    $flujoConfig = $flujoConfig['data'];
                }

                $campos = [];
                foreach ($flujoConfig['nodes'] as $nodo) {
                    //$resumen
                    if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {
                        foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                            foreach ($seccion['campos'] as $keyCampo => $campoTmp) {
                                $campos[$campoTmp['id']] = $campoTmp;
                            }
                        }
                    }
                }

                $expedientesNew = $campos[$campoKey]['expNewConf'] ?? [];


                if (!empty($campos[$campoKey]['filePath'])) {
                    $dir = $campos[$campoKey]['filePath'];
                }

                $ch = curl_init();

                // Se mandan indexados
                $arrArchivo = [];
                $urlExp = env('EXPEDIENTES_NEW_URL') . '/?api=true&opt=upload';
                $arrArchivo['folderPath'] = trim(trim($dir), '/');
                $arrArchivo['ramo'] = $expedientesNew['ramo'] ?? '';
                $arrArchivo['label'] = $expedientesNew['label'] ?? '';
                $arrArchivo['filetype'] = $expedientesNew['tipo'] ?? '';
                $arrArchivo['sourceaplication'] = 'Workflow';
                $arrArchivo['bucket'] = 'EXPEDIENTES';
                $arrArchivo['overwrite'] = (!empty($expedientesNew['sobreescribir']) && $expedientesNew['sobreescribir'] === 'S') ? 'Y' : 'N';

                if (!empty($campo->expToken)) {
                    $arrArchivo['token'] = $campo->expToken;
                }

                foreach ($expedientesNew['attr'] as $attr) {
                    $arrArchivo[$attr['attr']] = $attr['value'];
                }
                /*var_dump($arrArchivo);
                die();*/
                $data = $item->campos;

                $arrSend = [];
                foreach ($arrArchivo as $key => $itemTmp) {
                    $arrSend[$key] = $this->reemplazarValoresSalida($data, $itemTmp, false, $key === 'folderPath'); // En realidad es salida pero lo guardan como entrada
                }

                if (empty($arrSend['folderPath'])) {
                    return $this->ResponseError('T-223', 'Uno o más campos son requeridos previo a la subida de este archivo');
                }

                $arrSend['file_base64'] = $valor['v'];
                $arrSend['file'] = new \CURLStringFile($image, 'testfirma.png', 'image/png');

                $headers = [
                    'Authorization: Bearer 1TnwxbcvSesYkiqzl2nsmPgULTlYZFgSrcb3hSb383Tkv0ZzyaBz0sjD7LM2ymh',
                ];

                // var_dump($arrSend);

                curl_setopt($ch, CURLOPT_URL, $urlExp);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $arrSend);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $server_output = curl_exec($ch);
                // var_dump($server_output);
                $server_output = @json_decode($server_output, true);
                // var_dump($server_output);
                curl_close($ch);

                // $campo->valorLong = (string) $dir;
            }
        } else {
            if (is_array($valor['v'])) {
                $campo->valorLong = json_encode($valor['v'], JSON_FORCE_OBJECT);
            } else {
                $campo->valorLong = (string) $valor['v'];
            }
        }
        $campo->valorShow = (!empty($valor['vs']) ? $valor['vs'] : null);
        $campo->save();

        $this->saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);

        return $this->ResponseSuccess('Cambios ejecutados con exito', $campoKey);
    }

    public function saveOCR(Request $request)
    {
        $token = $request->get('cToken'); // solo seria un campo
        $row = $request->get('r');
        $table = $request->get('t');

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        $item = Cotizacion::where([['token', '=', $token]])->first();
        if (empty($item)) {
            return $this->ResponseError('COT-015', 'Tarea inválida');
        }


        if (!empty($table)) {
            $ocrTable = CotizacionOCR::where('id', $table['id'])->where('cotizacionId', $item->id)->first();

            $tbl = json_decode($ocrTable->value);

            foreach ($tbl as $key => $value) {
                if ($key === 0) continue;

                $tmpTableNew = $value;
                $keyTbl = $key - 1;

                if (isset($table['body'][$keyTbl])) {

                    $newBody = $table['body'][$keyTbl];
                    unset($newBody['key']);
                    unset($newBody['_id_']);
                    unset($newBody['operation']);

                    $countK = 0;
                    foreach ($newBody as $valueB) {

                        $tmpTableNew[$countK] = $valueB;
                        $countK++;
                    }
                }
                $tbl[$key] = $tmpTableNew;
            }

            $ocrTable->valueLast = json_encode($tbl);
            // $ocrTable->editado = $row['edit'] ?? 0;
            $ocrTable->save();

            // campo padre
            $fileTmpField = CotizacionDetalle::where('id', $ocrTable->cotizacionDetalleId)->first();

            // guarda variables
            $result = array();
            if (is_array($table['body'])) {
                $ritit = new RecursiveIteratorIterator(new RecursiveArrayIterator($table['body']));

                foreach ($ritit as $leafValue) {
                    $keys = array();
                    foreach (range(0, $ritit->getDepth()) as $depth) {
                        $keys[] = $ritit->getSubIterator($depth)->key();
                    }
                    $result[join('.', $keys)] = $leafValue;
                }
            }

            foreach ($result as $key => $value) {
                $string = str_replace(' ', '-', $key); // Replaces all spaces with hyphens.
                $string = preg_replace('/[^A-Za-z0-9.\.\_\-]/', '', $string); // Removes special chars.

                $campo = $fileTmpField->campo . '.' . $ocrTable->field . '.' . $string;
                $fileTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campo)->first();
                if (empty($fileTmp)) {
                    $fileTmp = new CotizacionDetalle();
                }
                $fileTmp->cotizacionId = $item->id;
                $fileTmp->campo = $campo;
                $fileTmp->valorLong = $value;
                $fileTmp->save();
            }
        } else {
            // busca la fila
            $ocrRow = CotizacionOCR::where('id', $row['id'])->where('cotizacionId', $item->id)->first();
            $ocrRow->valueLast = $row['value'];
            $ocrRow->editado = $row['edit'] ?? 0;
            $ocrRow->save();

            $tmpValores = json_decode($ocrRow->value, true);

            $valorFinal = (!empty($ocrRow->valueLast)) ? $ocrRow->valueLast : ($tmpValores[0] ?? '');

            $campoOrig = CotizacionDetalle::where('cotizacionId', $item->id)->where('id', $ocrRow->cotizacionDetalleId)->first();
            $variableNew = "{$campoOrig->campo}.{$ocrRow->field}.0"; // Siempre el primero

            $fileTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $variableNew)->first();
            if (empty($fileTmp)) {
                $fileTmp = new CotizacionDetalle();
            }
            $fileTmp->cotizacionId = $item->id;
            $fileTmp->campo = $variableNew;
            $fileTmp->valorLong = $valorFinal;
            $fileTmp->save();
        }


        return $this->ResponseSuccess('Cambios guardados con exito');
    }

    public function changeStateExpired()
    {
        $todayDate = Carbon::now()->toDateString();
        $updatedRows = Cotizacion::whereRaw("DATE(dateExpire) < ?", [$todayDate])
            ->whereNotIn('estado', ['expirada', 'finalizada', 'cancelada', 'expirado', 'finalizado', 'cancelado'])
            ->update(['estado' => 'expirada'], ['gbstatus' => 'expirada']);
        return $this->ResponseSuccess('Actualizacion de estado exitosa', $updatedRows);
    }

    public function changeGbStateExpired()
    {
        $todayDate = Carbon::now()->format('Y-m-d H:i:s');

        $allRows = Cotizacion::whereRaw("dateExpireUserAsig < ?", [$todayDate])
            ->whereNotIn('gbstatus', ['expirada', 'finalizada', 'cancelada', 'vencida']);
        $updatedRowsGet = $allRows->get();
        $updatedRows = $allRows->update(['gbstatus' => 'vencida']);

        foreach ($updatedRowsGet as $item) {
            $usuarioAsignado = $item->usuarioAsignado;
            $producto = $item->producto;
            if (empty($producto->notificationData)) continue;
            $notificationData = json_decode($producto->notificationData, true);

            $destino = $usuarioAsignado->email ?? false;
            $asunto = $notificationData['asunto'] ?? '';
            $copia = $notificationData['copia'] ?? '';
            $config = $notificationData['mailgun'] ?? [];
            $contenido = $notificationData['salidasEmail'] ?? '';
            $attachments = $notificationData['attachments'] ?? false;

            $data = [
                'destino' => $usuarioAsignado->email,
                'asunto' => $asunto,
                'copia' => $copia,
                'config' => $config,
                'attachments' => $attachments,
                'contenido' => $contenido,
                'usuarioLogueadoId' => 0,
            ];

            if (
                !empty($destino)
                && !empty($config['apiKey'])
                && !empty($config['domain'])
                && !empty($config['from'])
            ) $email = $this->sendEmail($data, $item);
        };

        return $this->ResponseSuccess('Actualizacion de estado exitosa', $updatedRows);
    }

    public function changeDateStepChange()
    {
        $todayDate = Carbon::now()->toDateString();
        $updatedRows = Cotizacion::whereRaw("DATE(dateStepChange) < ?", [$todayDate])
            ->whereNotIn('estado', ['expirada', 'finalizada', 'cancelada', 'expirado', 'finalizado', 'cancelado'])
            ->whereNotIn('gbstatus', ['expirada', 'finalizada', 'cancelada'])
            ->get()->all();
        foreach ($updatedRows as $coti) {
            $requestTmp = new \Illuminate\Http\Request();
            $requestTmp->replace(['token' => $coti->token, 'paso' => 'next', 'campos' => []]);
            $data = $this->CambiarEstadoCotizacion($requestTmp);
        }
        return $this->ResponseSuccess('Actualizacion de estado exitosa', count($updatedRows));
    }

    public function sendEmail($data, $item)
    {
        //data
        $copia = (!empty($flujo['next']['procesoEmail']['copia']))
            ? array_map(
                fn($item) => $this->reemplazarValoresSalida($item->campos, $item['destino']),
                array_filter($flujo['next']['procesoEmail']['copia'], fn($item) => !empty($item['destino']))
            )
            : false;

        $destino = (!empty($data['destino'])) ? $this->reemplazarValoresSalida($item->campos, $data['destino']) : false;
        $asunto = (!empty($data['asunto'])) ? $this->reemplazarValoresSalida($item->campos, $data['asunto']) : false;
        $config = $data['config'] ?? [];
        $attachments = $data['attachments'] ?? false;
        $contenido = $data['contenido'] ?? '';
        $usuarioLogueadoId = $data['usuarioLogueadoId'] ?? '';

        // reemplazo plantilla
        $contenido = $this->reemplazarValoresSalida($item->campos, $contenido);

        $attachmentsSend = [];
        if ($attachments) {
            $attachments = explode(',', $attachments);

            foreach ($attachments as $attach) {
                $campoTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $attach)->first();

                if (!empty($campoTmp) && !empty($campoTmp['valorLong'])) {
                    $ext = 'pdf';
                    $s3_file = file_get_contents($campoTmp['valorLong']);
                    $attachmentsSend[] = ['fileContent' => $s3_file, 'filename' => ($campoTmp['label'] ?? 'Sin nombre') . '.' . $ext];
                } else {
                    $bitacoraCoti = new CotizacionBitacora();
                    $bitacoraCoti->cotizacionId = $item->id;
                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                    $bitacoraCoti->log = "Error al enviar adjunto  \"{$attach}\" en el correo";
                    $bitacoraCoti->save();
                }
            }
        }

        // reemplazo

        $config['domain'] = $this->reemplazarValoresSalida($item->campos, $config['domain']);
        $config['from'] = $this->reemplazarValoresSalida($item->campos, $config['from']);
        $config['apiKey'] = $this->reemplazarValoresSalida($item->campos, $config['apiKey']);
        $config['domain'] = $config['domain'] ?? 'N/D';

        try {
            $mg = Mailgun::create($config['apiKey'] ?? ''); // For US servers
            $email = $mg->messages()->send($config['domain'] ?? '', [
                'from' => $config['from'] ?? '',
                'to' => $destino ?? '',
                'cc' => $copia ?? '',
                'subject' => $asunto ?? '',
                'html' => $contenido,
                'attachment' => $attachmentsSend
            ]);

            // Guardo la bitácora
            $bitacoraCoti = new CotizacionBitacora();
            $bitacoraCoti->cotizacionId = $item->id;
            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
            $bitacoraCoti->log = "Enviado correo electrónico \"{$destino}\" desde \"{$config['from']}\"";
            $bitacoraCoti->save();
            // return $this->ResponseSuccess( 'Si tu cuenta existe, llegará un enlace de recuperación');
        } catch (HttpClientException $e) {
            // Guardo la bitácora
            $bitacoraCoti = new CotizacionBitacora();
            $bitacoraCoti->cotizacionId = $item->id;
            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
            $bitacoraCoti->log = "Error al enviar correo electrónico \"{$destino}\" desde \"{$config['from']}\", dominio de salida: {$config['domain']}";
            $bitacoraCoti->save();
            return $this->ResponseError('AUTH-RA94', 'Error al enviar notificación, verifique el correo o la configuración del sistema');
        }
    }

    public function calcularEnLote(Request $request)
    {

        $tareasTmp = $request->get('tareas');

        $tareas = Cotizacion::whereIn('id', $tareasTmp)->get();

        $allTokens = [];
        $allNodos = [];
        foreach ($tareas as $tarea) {
            $allNodos[$tarea->nodoActual][] = $tarea->id;
            $allTokens[$tarea->id] = $tarea->token;
        }

        if (count($allNodos) > 1) {
            return $this->ResponseError('TAR-171', 'Existen tareas que se encuentran en etapas distintas, solo puede editar tareas en la misma etapa', $allNodos);
        }

        // calcula la primer tarea
        $producto = $tareas[0]->producto->token;
        return $this->ResponseSuccess('Cálculo realizado con éxito', ['p' => $producto, 't' => $tareas[0]->token, 'o' => $allTokens]);
    }

    public function crearOperacionEnLote(Request $request)
    {

        $tareasTmp = $request->get('tareas');
        $usuarioLogueado = auth('sanctum')->user();

        $productoId = 0;
        $allTokens = [];
        $allNodos = [];
        $tareas = Cotizacion::whereIn('id', $tareasTmp)->get();
        foreach ($tareas as $tarea) {
            if (!$productoId) {
                $productoId = $tarea->productoId;
                break;
            }
        }

        $lote = new CotizacionLoteOperacion();
        $lote->usuarioId = $usuarioLogueado->id;
        $lote->productoId = $productoId;
        $lote->save();

        foreach ($tareasTmp as $tarea) {
            $loteD = new CotizacionLoteOperacionDetalle();
            $loteD->cotizacionLoteId = $lote->id;
            $loteD->cotizacionId = $tarea;
            $loteD->save();
        }


        return $this->ResponseSuccess('Lotes obtenidos con éxito', $tareas);
    }

    public function getOperacionEnLote(Request $request)
    {

        $usuarioLogueado = auth('sanctum')->user();

        $tareas = CotizacionLoteOperacion::where('usuarioId', $usuarioLogueado->id)->orderBy('createdAt', 'DESC')->with('detalle')->get();
        foreach ($tareas as $key => $items) {
            $orden = CotizacionLoteOrden::where('productoId', $items->productoId)->where('useForSearch', 1)->orderBy('orden', 'ASC')->get();
            $allCamposForSearch = [];
            foreach ($orden as $or) {
                $allCamposForSearch[] = $or->campo;
            };
            $headers = array_map(function ($or) use ($allCamposForSearch) {
                return ['text' => $or, 'value' => $or];
            }, $allCamposForSearch);
            array_unshift($headers, ['text' => 'cotizacionId', 'value' => 'cotizacionId']);
            $tareas[$key]['header'] = $headers;

            $resumen = [];
            foreach ($items->detalle as $key2 => $item) {
                $allCamposCotizacionDetalle = CotizacionDetalle::where('cotizacionId', $item->cotizacionId)
                    ->whereIn('campo', $allCamposForSearch)->get();
                $formatCamposDetalleCotizacion = [];
                $formatCamposDetalleCotizacion['cotizacionId'] =  $item->cotizacionId;
                foreach ($allCamposCotizacionDetalle as $campoKey => $camp) {
                    $formatCamposDetalleCotizacion[$camp->campo] = $camp->valorLong;
                };
                $resumen[$key2] = $formatCamposDetalleCotizacion;
            };
            $tareas[$key]['body'] = $resumen;
        }
        return $this->ResponseSuccess('Lotes obtenidos con éxito', $tareas);
    }

    public function CalcularPasosMasivo(Request $request)
    {
        try {
            $AC = new AuthController();
            $usuarioLogueado = auth('sanctum')->user();
            $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
            $tokens =  $request->get('tokens');
            if (!empty($usuarioLogueadoId)) {
                $AC = new AuthController();
                if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
            }
            foreach ($tokens as $token) {
                $request->merge(['token' => $token]);
                $flujo = $this->CalcularPasos($request, true, false, false);
            }
            return $this->ResponseSuccess('Calcular pasos con éxito', $tokens);
        } catch (HttpClientException $e) {
            return $this->ResponseError('Calcular pasos sin éxito');
        }
    }

    public function CalcularPasosNuevos(Request $request)
    {
        $fechaIni = date('Y-m-d', strtotime('2024-03-19'));

        $strQueryFull = "SELECT C.id as cid, COUNT(CD.id) as total_filas
            FROM cotizaciones AS C
            JOIN cotizacionesDetalle AS CD ON CD.cotizacionId = C.id
            WHERE C.nodoActual IS NOT NULL
            AND C.dateCreated <= '{$fechaIni}'
            AND C.count < 1
            AND ((CD.valorLong NOT LIKE '%https%'
            AND CD.valorLong LIKE '%&%') 
            OR C.productoId = 78)
            GROUP BY C.id
            LIMIT 1;";

        $cotizacionesConteo = [];
        $databring = DB::select(DB::raw($strQueryFull));
        if (empty($databring[0])) return $this->ResponseError('COT-300', "No hay mas cotizaciones por procesar");
        $cotizacionId =  $databring[0]->cid;
        $cotizacion = Cotizacion::where([['id', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('COT-500', "Cotizacion {$cotizacionId} : Tarea no válido");
        }

        if (empty($cotizacion->nodoActual)) {
            $cotizacion->count = 1;
            $cotizacion->save();
            return $this->ResponseSuccess("Sin Plantillas por el momento", $cotizacionId);
        }

        $cotizacion->count = 2;
        $cotizacion->save();
        $producto = $cotizacion->producto;

        if (empty($producto)) {
            return $this->ResponseError('COT-600', "Cotizacion {$cotizacionId} : Producto no válido");
        }

        $flujoConfig = $this->getFlujoFromCotizacion($cotizacion);

        if (!$flujoConfig['status']) {
            return $this->ResponseError($flujoConfig['error-code'], $cotizacionId . ':' . $flujoConfig['msg']);
        } else {
            $flujoConfig = $flujoConfig['data'];
        }

        // El flujo se va a orientar en orden según un array
        $allFields = [];
        $flujoOrientado = [];
        $flujoPrev = [];
        $flujoActual = [];
        $flujoNext = [];

        $reviewNodes = [];
        $reviewFields = [];
        $outputNodes = [];

        foreach ($flujoConfig['nodes'] as $key => $nodo) {

            if (empty($nodo['typeObject'])) continue;

            $lineasTemporalEntrada = [];
            $lineasTemporalSalida = [];
            $lineasTemporalSalidaDecision = ['si' => [], 'no' => [],];
            foreach ($flujoConfig['edges'] as $linea) {
                if ($linea['source'] === $nodo['id']) {
                    $lineasTemporalSalida[] = $linea['target'];

                    if ($linea['sourceHandle'] === 'salidaTrue') {
                        $lineasTemporalSalidaDecision['si'] = $linea['target'];
                    } else if ($linea['sourceHandle'] === 'salidaFalse') {
                        $lineasTemporalSalidaDecision['no'] = $linea['target'];
                    }
                }
                if ($linea['target'] === $nodo['id']) {
                    $lineasTemporalEntrada[] = $linea['source'];
                }
            }

            $flujoOrientado[$nodo['id']] = [
                'nodoId' => $nodo['id'],
                'typeObject' => $nodo['typeObject'],
                'wsLogic' => $nodo['wsLogic'] ?? 'n',
                'estOut' => $nodo['estOut'] ?? null, // Estado out
                'estIo' => $nodo['estIo'] ?? 's',
                'cmT' => $nodo['cmT'] ?? '', // Comentarios Tipo
                'expiracionNodo' => $nodo['expiracionNodo'] ?? false,
                'atencionNodo' => $nodo['atencionNodo'] ?? false,
                'contFecha' => $nodo['contFecha'] ?? false,
                'nodoName' => $nodo['nodoName'],
                'nodoNameId' => $nodo['nodoId'] ?? '',
                'type' => $nodo['type'],
                'label' => $nodo['label'] ?? '',
                'formulario' => $nodo['formulario'] ?? [],
                'btnText' => [
                    'prev' => $nodo['btnTextPrev'] ?? '',
                    'next' => $nodo['btnTextNext'] ?? '',
                    'finish' => $nodo['btnTextFinish'] ?? '',
                    'cancel' => $nodo['btnTextCancel'] ?? '',
                ],
            ];

            $flujoOrientado[$nodo['id']]['nodosEntrada'] = $lineasTemporalEntrada;
            $flujoOrientado[$nodo['id']]['nodosSalida'] = $lineasTemporalSalida;
            $flujoOrientado[$nodo['id']]['nodosSalidaDecision'] = $lineasTemporalSalidaDecision;

            $flujoOrientado[$nodo['id']]['userAssign'] = [
                'user' => $nodo['setuser_user'] ?? '',
                'role' => $nodo['setuser_roles'] ?? [],
                'group' => $nodo['setuser_group'] ?? [],
                'canal' => $nodo['canales_assign'] ?? [],
                'node' => $nodo['setuser_node'] ?? '',
                'variable' => $nodo['setuser_variable'] ?? '',
                'setuser_method' => $nodo['setuser_method'] ?? [],
            ];
            $flujoOrientado[$nodo['id']]['expiracionNodo'] = $nodo['expiracionNodo'] ?? false;
            $flujoOrientado[$nodo['id']]['atencionNodo'] = $nodo['atencionNodo'] ?? false;
            $flujoOrientado[$nodo['id']]['contFecha'] = $nodo['contFecha'] ?? false;
            $flujoOrientado[$nodo['id']]['procesos'] = $nodo['procesos'];
            $flujoOrientado[$nodo['id']]['decisiones'] = $nodo['decisiones'];
            $flujoOrientado[$nodo['id']]['salidas'] = $nodo['salidas'];
            $flujoOrientado[$nodo['id']]['salidaIsPDF'] = $nodo['salidaIsPDF'];
            $flujoOrientado[$nodo['id']]['salidaPDFconf'] = $nodo['salidaPDFconf'] ?? [];
            $flujoOrientado[$nodo['id']]['salidaIsHTML'] = $nodo['salidaIsHTML'];
            $flujoOrientado[$nodo['id']]['salidaIsEmail'] = $nodo['salidaIsEmail'];
            $flujoOrientado[$nodo['id']]['salidaIsWhatsapp'] = $nodo['salidaIsWhatsapp'];
            $flujoOrientado[$nodo['id']]['procesoWhatsapp'] = $nodo['procesoWhatsapp'];
            $flujoOrientado[$nodo['id']]['procesoEmail'] = $nodo['procesoEmail'];
            $flujoOrientado[$nodo['id']]['roles_assign'] = $nodo['roles_assign'];
            $flujoOrientado[$nodo['id']]['tareas_programadas'] = $nodo['tareas_programadas'];
            $flujoOrientado[$nodo['id']]['pdfTpl'] = $nodo['pdfTpl'] ?? [];
            $flujoOrientado[$nodo['id']]['salidaPDFId'] = $nodo['salidaPDFId'] ?? '';
            $flujoOrientado[$nodo['id']]['salidaPDFDp'] = $nodo['salidaPDFDp'] ?? '';
            $flujoOrientado[$nodo['id']]['salidaPDFLabel'] = $nodo['salidaPDFLabel'] ?? '';
            $flujoOrientado[$nodo['id']]['saltoAutomatico'] = $nodo['saltoAutomatico'] ?? '';
            $flujoOrientado[$nodo['id']]['enableJsonws'] = $nodo['enableJsonws'] ?? '';
            $flujoOrientado[$nodo['id']]['jsonws'] = $nodo['jsonws'] ?? '';
        }

        $bitacoraReca = CotizacionesUserNodo::where('cotizacionId', $cotizacion->id)
            ->orderBy('id', 'asc')
            ->get();

        foreach ($bitacoraReca as $bitReca) {
            $nodoCurrent = $flujoOrientado[$bitReca->nodoId];
            if (!empty($nodoCurrent) && !in_array($nodoCurrent, $outputNodes) && ($nodoCurrent['typeObject'] === 'output') && !empty($nodoCurrent['salidaIsPDF'])) {
                $outputNodes[] = $bitReca->nodoId;
            }
        };

        $nodoCurrent = $flujoOrientado[$cotizacion->nodoActual];
        if (empty($nodoCurrent) &&  !in_array($nodoCurrent, $outputNodes) && ($nodoCurrent['typeObject'] === 'output') && !empty($nodoCurrent['salidaIsPDF'])) {
            $outputNodes[] = $bitReca->nodoId;
        }

        foreach ($outputNodes as $nodo) {
            $flujo['next'] = $flujoOrientado[$nodo];
            if ($flujo['next']['typeObject'] === 'output') {
                if (!empty($flujo['next']['salidaIsPDF'])) {
                    if (!empty($flujo['next']['pdfTpl'])) {
                        $itemTemplate = PdfTemplate::where('id', intval($flujo['next']['pdfTpl']))->first();
                        if (!empty($itemTemplate)) {

                            $docsPlusToken = $flujo['next']['salidaPDFDp'] ?? false;
                            $campoConfig = $flujo['next']['salidaPDFconf'] ?? false;
                            $dir = '';
                            if (!empty($campoConfig['path'])) {
                                $dir = $campoConfig['path'];
                            }

                            $ch = curl_init();
                            // Se mandan indexados
                            $arrArchivo = [];
                            $arrArchivo['folderPath'] = trim(trim($dir), '/');
                            $arrArchivo['ramo'] = $campoConfig['fileRamo'] ?? '';
                            $arrArchivo['producto'] = $campoConfig['fileProducto'] ?? '';
                            $arrArchivo['fechaCaducidad'] = $campoConfig['fileFechaExp'] ?? '';
                            $arrArchivo['reclamo'] = $campoConfig['fileReclamo'] ?? '';
                            $arrArchivo['poliza'] = $campoConfig['filePoliza'] ?? '';
                            $arrArchivo['estadoPoliza'] = $campoConfig['fileEstadoPoliza'] ?? '';
                            $arrArchivo['nit'] = $campoConfig['fileNit'] ?? '';
                            $arrArchivo['dpi'] = $campoConfig['fileDPI'] ?? '';
                            $arrArchivo['cif'] = $campoConfig['fileCIF'] ?? '';
                            $arrArchivo['label'] = $campoConfig['fileLabel'] ?? '';
                            $arrArchivo['filetype'] = $campoConfig['fileTipo'] ?? '';
                            $arrArchivo['filetypeSecondary'] = $campoConfig['fileTipo2'] ?? '';
                            $arrArchivo['source'] = 'Workflow';

                            $data = $cotizacion->campos;
                            $arrSend = [];
                            foreach ($arrArchivo as $key => $itemTmp) {
                                $arrSend[$key] = $this->reemplazarValoresSalida($data, $itemTmp, false, $key === 'folderPath'); // En realidad es salida pero lo guardan como entrada
                            }

                            $finalFilePath = '';
                            $errorPdfLog = '';
                            if (empty($docsPlusToken)) {

                                $fileNameHash = md5(uniqid());
                                $tmpPath = storage_path("tmp/");
                                $tmpFile = storage_path("tmp/" . md5(uniqid()) . ".docx");
                                $outputTmp = storage_path("tmp/" . $fileNameHash . ".docx");
                                $outputTmpPdf = $fileNameHash . ".pdf";

                                $s3_file = Storage::disk('s3')->get($itemTemplate->urlTemplate);
                                file_put_contents($tmpFile, $s3_file);

                                // reemplazo valores
                                $templateProcessor = new TemplateProcessor($tmpFile);
                                //dd($item->campos);
                                foreach ($cotizacion->campos as $campoTmp) {
                                    if (is_array($campoTmp)) continue;
                                    if (is_array($campoTmp->valorLong ?? '')) {
                                        $campoTmp->valorLong = implode(', ', $campoTmp->valorLong ?? '');
                                    }
                                    $templateProcessor->setValue($campoTmp->campo, htmlspecialchars($campoTmp->valorLong ?? ''));
                                }
                                // dd($templateProcessor->getVariables());
                                foreach ($templateProcessor->getVariables() as $variable) {
                                    $templateProcessor->setValue($variable, '');
                                }
                                $templateProcessor->saveAs($outputTmp);

                                // lowriter, pdf conversion
                                putenv('PATH=/usr/local/bin:/bin:/usr/bin:/usr/local/sbin:/usr/sbin:/sbin');
                                putenv('HOME=' . $tmpPath);
                                exec("/usr/bin/lowriter --convert-to pdf {$outputTmp} --outdir '{$tmpPath}'", $outputInfo);
                                if (file_exists($tmpPath)) {
                                    $errorPdfLog = json_encode($outputInfo);
                                } else {
                                    $errorPdfLog = 'No se pudo cargar el template';
                                }

                                $finalFilePath = "{$tmpPath}{$outputTmpPdf}";
                            }

                            $path = '';

                            if (file_exists($finalFilePath)) {

                                if (empty($arrSend['folderPath'])) {
                                    return $this->ResponseError('T-223', "Cotizacion {$cotizacionId} : Uno o más campos son requeridos previo a la subida de este archivo");
                                }

                                $arrSend['file'] = new \CurlFile($finalFilePath, 'application/pdf');
                                $arrSend['file']->setPostFilename($arrSend['folderPath'] . '/' . $arrSend['label'] . '.pdf');

                                $expedientesToken = env('EXPEDIENTES_TOKEN');
                                $headers = [
                                    "Authorization: Bearer {$expedientesToken}",
                                ];

                                curl_setopt($ch, CURLOPT_URL, env('EXPEDIENTES_URL') . '/?api=true&opt=upload');
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $arrSend);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                $server_output = curl_exec($ch);
                                $server_output = @json_decode($server_output, true);
                                curl_close($ch);

                                //dd($server_output);

                                if (!empty($server_output['status'])) {

                                    $path = $server_output['data']['exp-url'];
                                } else {
                                    return $this->ResponseError('T-222', "Cotizacion {$cotizacionId} : Error al cargar archivo, por favor intente de nuevo");
                                }
                            } else {
                                return $this->ResponseError('COT-777', "Cotizacion {$cotizacionId} : Error al generar PDF, {$errorPdfLog}");
                            }

                            if (file_exists($tmpFile)) unlink($tmpFile);
                            if (file_exists($outputTmp)) unlink($outputTmp);
                            if (file_exists("{$tmpPath}{$outputTmpPdf}")) unlink("{$tmpPath}{$outputTmpPdf}");

                            $campoKeyTmp = (!empty($flujo['next']['salidaPDFId'])) ? $flujo['next']['salidaPDFId'] : 'SALIDA_' . ($flujo['next']['nodoId']);
                            $campoSalida = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('campo', $campoKeyTmp)->first();
                            if (empty($campoSalida)) {
                                $campoSalida = new CotizacionDetalle();
                            }

                            $campoSalida->cotizacionId = $cotizacion->id;
                            $campoSalida->seccionKey = 0;
                            $campoSalida->campo = $campoKeyTmp;
                            $campoSalida->label = $flujo['next']['salidaPDFconf']['fileLabel'] ?? 'Archivo sin nombre';
                            $campoSalida->valorLong = $path;
                            $campoSalida->isFile = true;
                            $campoSalida->fromSalida = true;
                            $campoSalida->save();

                            $this->saveCotizacionDetalleBitacora($campoSalida, $idLastCotiUserNodo, $item->nodoActual, $usuarioLogueadoId);
                        }
                    }
                }
            }
        }

        $cotizacion->count = 1;
        $cotizacion->save();

        return $this->ResponseSuccess("Generacion de plantilla con exito", $cotizacionId);
    }

    public function saveCotizacionDetalleBitacora($campo, $idLastCotiUserNodo, $nodoActual, $usuarioLogueadoId)
    {
        $campoBitacora = new CotizacionDetalleBitacora();
        $campoBitacora->cotizacionId = $campo->cotizacionId;
        $campoBitacora->cotUserNodId = $idLastCotiUserNodo;
        $campoBitacora->nodoId = $nodoActual;
        $campoBitacora->campoId = $campo->id;
        $campoBitacora->usuarioId = $usuarioLogueadoId;
        $campoBitacora->seccionKey = $campo->seccionKey;
        $campoBitacora->campo = $campo->campo;
        $campoBitacora->label = $campo->label;
        $campoBitacora->useForSearch = $campo->useForSearch;
        $campoBitacora->tipo = $campo->tipo;
        $campoBitacora->valorLong = $campo->valorLong;
        $campoBitacora->valorShow = $campo->valorShow;
        $campoBitacora->save();
    }

    public function SaveStopLoadWork(Request $request)
    {
        $productoId = $request->get('productoId');
        $suspend = $request->get('suspend');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        if (!empty($usuarioLogueado)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/mis-flujos/carga'])) return $AC->NoAccess();
        }

        $load = ParalizarCarga::where('userId', $usuarioLogueadoId)
            ->where('productoId', $productoId)
            ->first();

        if (empty($load)) {
            $load = new ParalizarCarga();
        }

        $load->productoId = $productoId;
        $load->userId = $usuarioLogueadoId;
        $load->suspend = $suspend;
        $load->save();

        return $this->ResponseSuccess("Cambio realizado con exito");
    }

    public function CotizacionCierreCrear(Request $request)
    {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $respuesta = $request->get('respuesta');
        $identificador = $request->get('identificador');
        // Nuevo parametro de dia INN
        $dia = $request->get('dia');
        $mes = $request->get('mes');
        $anio = $request->get('anio');
        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');
        $unico = $request->get('unico');
        $tipo = $request->get('tipo');


        // Crear / actualizar
        if ($tipo === 'C' || $tipo === 'U') {

            $flujoCierre = CotizacionCierre::where('identificador', $identificador)->first();

            if ($tipo === 'C') {
                if (!empty($flujoCierre->unico)) {
                    return $this->ResponseError('CM-CR001', 'Identificador ya existe y es único');
                }
                $flujoCierre = new CotizacionCierre();
            } else {
                if (empty($flujoCierre)) {
                    return $this->ResponseError('CM-CR001', 'Identificador no existe');
                }
            }
            $flujoCierre->respuesta = $respuesta;
            $flujoCierre->identificador = $identificador;
            // registro de nuev parametro dia -->INN
            $flujoCierre->dia = $dia;
            $flujoCierre->mes = $mes;
            $flujoCierre->anio = $anio;
            $flujoCierre->fechaIni = $fechaIni;
            $flujoCierre->fechaFin = $fechaFin;
            $flujoCierre->unico = intval($unico);
            $flujoCierre->save();

            return $this->ResponseSuccess('Creación realizada con éxito', [
                'id' => $flujoCierre->id
            ]);
        } else if ($tipo === 'D') {
            $flujoCierre = CotizacionCierre::where('identificador', $identificador)->first();
            if (empty($flujoCierre)) {
                return $this->ResponseError('CM-CR001', 'Identificador no existe');
            }
            $flujoCierre->delete();

            return $this->ResponseSuccess('Eliminación realizada con éxito');
        }
    }

    public function CotizacionCierreConsultar(Request $request)
    {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $identificador = $request->get('identificador');
        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');
        // Nuevo parametro dia -->INN
        $dia = $request->get('dia');

        if (empty($fechaIni) || empty($fechaFin)) {
            return $this->ResponseError('CM-CR004', 'Debe enviar fechaIni y fechaFin');
        }

        // Modificacion de consulta -->INN
        //$flujoCierre = CotizacionCierre::where('identificador', $identificador)->where([['fechaIni', '>=', $fechaIni], ['fechaFin', '<=', $fechaFin]])->orderBy('id', 'DESC')->get();
        $flujoCierre = CotizacionCierre::where('identificador', $identificador)->where([['fechaIni', '>=', $fechaIni], ['fechaFin', '<=', $fechaFin], ['dia', '<=', $dia]])->orderBy('id', 'DESC')->get();
        $flujoCierre = $flujoCierre->toArray();

        if (count($flujoCierre) === 1) {
            $flujoCierre = $flujoCierre[0];
        }

        if (empty($flujoCierre)) {
            return $this->ResponseError('CM-CR003', 'Identificador no existe');
        }

        return $this->ResponseSuccess('Consulta realizada con éxito', $flujoCierre);
    }

    // whatsapp chapus archivo
    public function getWhatsappUrl($urlFile)
    {
        // guarda el archivo
        $time = date('Ymd');
        $urlInfo = parse_url($urlFile);
        $fileName = basename($urlInfo['path'] ?? '');
        $tmpFilePath = storage_path("tmp/" . $fileName);
        file_put_contents($tmpFilePath, fopen($urlFile, 'r'));
        $diskTmp = Storage::disk('s3Temporal');
        $pathTmp = $diskTmp->putFileAs("{$time}", $tmpFilePath, $fileName);
        $tmpUrl = $diskTmp->url($pathTmp);
        if (file_exists($tmpFilePath)) unlink($tmpFilePath);
        return $tmpUrl;
    }

    public function verifyFileExtension($mimeType)
    {
        // Determinar la extensión según el MIME type
        $extensions = [
            'video/3gpp2' => '3g2',
            'video/3gp' => '3gp',
            'video/3gpp' => '3gp',
            'application/x-compressed' => '7zip',
            'audio/x-acc' => 'aac',
            'audio/ac3' => 'ac3',
            'application/postscript' => 'ai',
            'audio/x-aiff' => 'aif',
            'audio/aiff' => 'aif',
            'audio/x-au' => 'au',
            'video/x-msvideo' => 'avi',
            'video/msvideo' => 'avi',
            'video/avi' => 'avi',
            'application/x-troff-msvideo' => 'avi',
            'application/macbinary' => 'bin',
            'application/mac-binary' => 'bin',
            'application/x-binary' => 'bin',
            'application/x-macbinary' => 'bin',
            'image/bmp' => 'bmp',
            'image/x-bmp' => 'bmp',
            'image/x-bitmap' => 'bmp',
            'image/x-xbitmap' => 'bmp',
            'image/x-win-bitmap' => 'bmp',
            'image/x-windows-bmp' => 'bmp',
            'image/ms-bmp' => 'bmp',
            'image/x-ms-bmp' => 'bmp',
            'application/bmp' => 'bmp',
            'application/x-bmp' => 'bmp',
            'application/x-win-bitmap' => 'bmp',
            'application/cdr' => 'cdr',
            'application/coreldraw' => 'cdr',
            'application/x-cdr' => 'cdr',
            'application/x-coreldraw' => 'cdr',
            'image/cdr' => 'cdr',
            'image/x-cdr' => 'cdr',
            'zz-application/zz-winassoc-cdr' => 'cdr',
            'application/mac-compactpro' => 'cpt',
            'application/pkix-crl' => 'crl',
            'application/pkcs-crl' => 'crl',
            'application/x-x509-ca-cert' => 'crt',
            'application/pkix-cert' => 'crt',
            'text/css' => 'css',
            'text/x-comma-separated-values' => 'csv',
            'text/comma-separated-values' => 'csv',
            'application/vnd.msexcel' => 'csv',
            'application/x-director' => 'dcr',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/x-dvi' => 'dvi',
            'message/rfc822' => 'eml',
            'application/x-msdownload' => 'exe',
            'video/x-f4v' => 'f4v',
            'audio/x-flac' => 'flac',
            'video/x-flv' => 'flv',
            'image/gif' => 'gif',
            'application/gpg-keys' => 'gpg',
            'application/x-gtar' => 'gtar',
            'application/x-gzip' => 'gzip',
            'application/mac-binhex40' => 'hqx',
            'application/mac-binhex' => 'hqx',
            'application/x-binhex40' => 'hqx',
            'application/x-mac-binhex40' => 'hqx',
            'text/html' => 'html',
            'image/x-icon' => 'ico',
            'image/x-ico' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
            'text/calendar' => 'ics',
            'application/java-archive' => 'jar',
            'application/x-java-application' => 'jar',
            'application/x-jar' => 'jar',
            'image/jp2' => 'jp2',
            'video/mj2' => 'jp2',
            'image/jpx' => 'jp2',
            'image/jpm' => 'jp2',
            'image/jpeg' => 'jpeg',
            'image/pjpeg' => 'jpeg',
            'application/x-javascript' => 'js',
            'application/json' => 'json',
            'text/json' => 'json',
            'application/vnd.google-earth.kml+xml' => 'kml',
            'application/vnd.google-earth.kmz' => 'kmz',
            'text/x-log' => 'log',
            'audio/x-m4a' => 'm4a',
            'audio/mp4' => 'm4a',
            'application/vnd.mpegurl' => 'm4u',
            'audio/midi' => 'mid',
            'application/vnd.mif' => 'mif',
            'video/quicktime' => 'mov',
            'video/x-sgi-movie' => 'movie',
            'audio/mpeg' => 'mp3',
            'audio/mpg' => 'mp3',
            'audio/mpeg3' => 'mp3',
            'audio/mp3' => 'mp3',
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mpeg',
            'application/oda' => 'oda',
            'audio/ogg' => 'ogg',
            'video/ogg' => 'ogg',
            'application/ogg' => 'ogg',
            'font/otf' => 'otf',
            'application/x-pkcs10' => 'p10',
            'application/pkcs10' => 'p10',
            'application/x-pkcs12' => 'p12',
            'application/x-pkcs7-signature' => 'p7a',
            'application/pkcs7-mime' => 'p7c',
            'application/x-pkcs7-mime' => 'p7c',
            'application/x-pkcs7-certreqresp' => 'p7r',
            'application/pkcs7-signature' => 'p7s',
            'application/pdf' => 'pdf',
            'application/octet-stream' => 'pdf',
            'application/x-x509-user-cert' => 'pem',
            'application/x-pem-file' => 'pem',
            'application/pgp' => 'pgp',
            'application/x-httpd-php' => 'php',
            'application/php' => 'php',
            'application/x-php' => 'php',
            'text/php' => 'php',
            'text/x-php' => 'php',
            'application/x-httpd-php-source' => 'php',
            'image/png' => 'png',
            'image/x-png' => 'png',
            'application/powerpoint' => 'ppt',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.ms-office' => 'ppt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/x-photoshop' => 'psd',
            'image/vnd.adobe.photoshop' => 'psd',
            'audio/x-realaudio' => 'ra',
            'audio/x-pn-realaudio' => 'ram',
            'application/x-rar' => 'rar',
            'application/rar' => 'rar',
            'application/x-rar-compressed' => 'rar',
            'audio/x-pn-realaudio-plugin' => 'rpm',
            'application/x-pkcs7' => 'rsa',
            'text/rtf' => 'rtf',
            'text/richtext' => 'rtx',
            'video/vnd.rn-realvideo' => 'rv',
            'application/x-stuffit' => 'sit',
            'application/smil' => 'smil',
            'text/srt' => 'srt',
            'image/svg+xml' => 'svg',
            'application/x-shockwave-flash' => 'swf',
            'application/x-tar' => 'tar',
            'application/x-gzip-compressed' => 'tgz',
            'image/tiff' => 'tiff',
            'font/ttf' => 'ttf',
            'text/plain' => 'txt',
            'text/x-vcard' => 'vcf',
            'application/videolan' => 'vlc',
            'text/vtt' => 'vtt',
            'audio/x-wav' => 'wav',
            'audio/wave' => 'wav',
            'audio/wav' => 'wav',
            'application/wbxml' => 'wbxml',
            'video/webm' => 'webm',
            'image/webp' => 'webp',
            'audio/x-ms-wma' => 'wma',
            'application/wmlc' => 'wmlc',
            'video/x-ms-wmv' => 'wmv',
            'video/x-ms-asf' => 'wmv',
            'font/woff' => 'woff',
            'font/woff2' => 'woff2',
            'application/xhtml+xml' => 'xhtml',
            'application/excel' => 'xl',
            'application/msexcel' => 'xls',
            'application/x-msexcel' => 'xls',
            'application/x-ms-excel' => 'xls',
            'application/x-excel' => 'xls',
            'application/x-dos_ms_excel' => 'xls',
            'application/xls' => 'xls',
            'application/x-xls' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/xml' => 'xml',
            'text/xml' => 'xml',
            'text/xsl' => 'xsl',
            'application/xspf+xml' => 'xspf',
            'application/x-compress' => 'z',
            'application/x-zip' => 'zip',
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
            'application/s-compressed' => 'zip',
            'multipart/x-zip' => 'zip',
            'text/x-scriptzsh' => 'zsh'
        ];

        return $extensions[$mimeType] ?? 'bin'; // 'bin' si no se reconoce el tipo
    }

    public function getExpedientesTmpUrl($urlFile)
    {


        // Obtener el contenido del archivo
        $arrContextOptions = array(
            "ssl" => array(
                "verify_peer" => false,
                "verify_peer_name" => false,
            ),
        );
        $s3_file = file_get_contents($urlFile, false, stream_context_create($arrContextOptions));

        // Escribir el archivo en un directorio temporal
        $fileTmp = uniqid();
        $tmpFilePath = storage_path("tmp/" . $fileTmp);
        file_put_contents($tmpFilePath, $s3_file);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpFilePath);
        finfo_close($finfo);
        $ext = $this->verifyFileExtension($mimeType); // 'bin' si no se reconoce el tipo
        $fileTmpName = "{$fileTmp}.{$ext}";

        // crea link temporal
        $time = time();
        $diskTmp = Storage::disk('s3Temporal');
        $pathTmp = $diskTmp->putFileAs("{$time}", $tmpFilePath, $fileTmpName);
        $tmpUrl = $diskTmp->url($pathTmp);
        if (file_exists($tmpFilePath)) unlink($tmpFilePath);
        return $tmpUrl;
    }

    public function VarTest(Request $request)
    {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $token = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = (!empty($usuarioLogueado) ? $usuarioLogueado->id : 0);

        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();
        $detalle = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->orderBy('campo')->get();

        $vars = [];
        foreach ($detalle as $item) {
            $vars[$item->campo] = $item->valorLong;
        }

        return $this->ResponseSuccess('Variables obtenidas con éxito', $vars);
    }
}
