<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Extra\Tools;
use App\Models\Alerta;
use App\Models\Cotizacion;
use App\Models\CotizacionComentario;
use App\Models\CotizacionDetalle;
use App\Models\CotizacionDetalleCatalogo;
use App\Models\CotizacionesOcrTokens;
use App\Models\CotizacionBitacora;
use App\Models\FlujoConexion;
use App\Models\PdfTemplate;
use App\Models\Flujos;
use App\Models\Productos;
use App\Models\CotizacionTranscribe;
use App\Models\FirmaElectronica;
use App\Models\Rol;
use App\Models\RolAccess;
use App\Models\RolApp;
use App\Models\SistemaVariable;
use App\Models\User;
use App\Models\UserCanal;
use App\Models\UserCanalGrupo;
use App\Models\UserGrupoRol;
use App\Models\UserGrupoUsuario;
use App\Models\UserRol;
use App\Models\Archivador;
use App\Models\ArchivadorDetalle;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Dflydev\DotAccessData\Data;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mailgun\Exception\HttpClientException;
use Mailgun\Mailgun;
use Matrix\Exception;
use PhpOffice\PhpWord\TemplateProcessor;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use App\Extra\ClassCache;
use Intervention\Image\ImageManager;
use Aws\TranscribeService\TranscribeServiceClient;


class TareaController extends Controller {

    use Response;

    public function nodoLabel($var) {
        return strip_tags($var);
    }

    public function Load($rolId) {

        $item = Formulario::where([['id', '=', $rolId]])->with('seccion', 'seccion.campos', 'seccion.campos.archivadorDetalle', 'seccion.campos.archivadorDetalle.archivador')->first();

        if (!empty($item)) {

            $arrSecciones = $item->toArray();

            usort($arrSecciones['seccion'], function ($a, $b) {
                if ($a['orden'] > $b['orden']) {
                    return 1;
                }
                elseif ($a['orden'] < $b['orden']) {
                    return -1;
                }
                return 0;
            });

            return $this->ResponseSuccess('Ok', $arrSecciones);
        }
        else {
            return $this->ResponseError('Aplicación inválida');
        }
    }

    public function Save(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $urlAmigable = $request->get('urlAmigable');
        $activo = $request->get('activo');

        $secciones = $request->get('campos');

        if (!empty($id)) {
            $item = Formulario::where([['id', '=', $id]])->first();
        }
        else {
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
            }
            else {
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
                }
                else {
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
        }
        else {
            return $this->ResponseError('AUTH-RL934', 'Error al crear rol');
        }
    }

    public function Delete(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $id = $request->get('id');
        try {
            $item = Formulario::find($id);

            if (!empty($item)) {
                $item->delete();
                return $this->ResponseSuccess('Eliminado con éxito', $item->id);
            }
            else {
                return $this->ResponseError('AUTH-R5321', 'Error al eliminar');
            }
        } catch (\Throwable $th) {
            var_dump($th->getMessage());
            return $this->ResponseError('AUTH-R5302', 'Error al eliminar');
        }
    }

    // Cotizaciones
    public function IniciarCotizacion(Request $request, $returnArray = false) {

        $productoToken = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();

        if (!empty($usuarioLogueado)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/start-cot'])) return $AC->NoAccess();
        }

        // traigo el producto
        $producto = Productos::where([['token', '=', $productoToken]])->first();

        if (empty($producto)) {
            return $this->ResponseError('TASK-15', 'Producto inválido');
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-611', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-610', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        // Validación si el nodo es público
        $tipoForm = false;
        $notifyNoAtenTipo = '';
        $notifyNoAtenTiempo = 0;
        $expiracionTipo = '';
        $expiracionTiempo = 0;
        foreach ($flujoConfig['nodes'] as $nodo) {
            if (empty($nodo['typeObject'])) continue;

            // si es inicio
            if ($nodo['typeObject'] === 'start' && !empty($nodo['formulario']['tipo'])) {
                $tipoForm = $nodo['typeObject'];
                $expiracionTiempo = intval($nodo['expiracionNodo'] ?? 0);
                $expiracionTipo = $nodo['expiracionType'] ?? '';
                $notifyNoAtenTipo = $nodo['noAttNType'] ?? '';
                $notifyNoAtenTiempo = intval($nodo['noAttN'] ?? 0);
            }
        }

        if (!$tipoForm) {
            return $this->ResponseError('TASK-615', 'Error al iniciar cotización, el formulario se encuentra desconfigurado (flujo sin inicio)');
        }

        if ($tipoForm === 'privado' && !$usuarioLogueado) {
            return $this->ResponseError('TASK-616', 'Error al iniciar cotización, el formulario no posee visibilidad pública');
        }

        // fecha de expiración de arranque
        $fechaHoy = Carbon::now();
        $fechaNueva = null;
        if (!empty($expiracionTipo) && !empty($expiracionTiempo)) {

            if ($expiracionTipo === 'D') {
                $fechaNueva = $fechaHoy->addDays($expiracionTiempo)->format('Y-m-d H:i:s');
            }
            else if ($expiracionTipo === 'H') {
                $fechaNueva = $fechaHoy->addHours($expiracionTiempo)->format('Y-m-d H:i:s');
            }
            else if ($expiracionTipo === 'M') {
                $fechaNueva = $fechaHoy->addMinutes($expiracionTiempo)->format('Y-m-d H:i:s');
            }
        }

        // fecha de atención de arranque
        /*$fechaHoy = Carbon::now();
        $fechaNuevaAtencion = null;
        if (!empty($notifyNoAtenTipo) && !empty($notifyNoAtenTiempo)) {
            if ($notifyNoAtenTipo === 'D') {
                $fechaNuevaAtencion = $fechaHoy->addDays($notifyNoAtenTiempo)->format('Y-m-d H:i:s');
            }
            else if ($notifyNoAtenTipo === 'H') {
                $fechaNuevaAtencion = $fechaHoy->addHours($notifyNoAtenTiempo)->format('Y-m-d H:i:s');
            }
            else if ($notifyNoAtenTipo === 'M') {
                $fechaNuevaAtencion = $fechaHoy->addMinutes($notifyNoAtenTiempo)->format('Y-m-d H:i:s');
            }
        }*/

        $item = new Cotizacion();
        $item->usuarioId = $usuarioLogueado->id ?? 0;
        $item->marcaId = $producto->marcaId ?? 0;
        $item->usuarioIdAsignado = $usuarioLogueado->id ?? 0;
        $item->dateExpire = $fechaNueva;
        //$item->dateNotifyNoAtention = $fechaNuevaAtencion;
        $item->token = trim(bin2hex(random_bytes(6))) . time(); // disminuido tamaño de token
        $item->estado = 'creada';
        $item->productoId = $producto->id;

        if ($item->save()) {
            if ($returnArray) {
                return ['token' => $item->token, 'id' => $item->id];
            }
            else {
                return $this->ResponseSuccess('Tarea iniciada con éxito', ['token' => $item->token]);
            }
        }
        else {
            if ($returnArray) {
                return false;
            }
            else {
                return $this->ResponseError('TASK-014', 'Error al iniciar tarea, por favor intente de nuevo');
            }
        }
    }

    public function RevivirCotizacion(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/admin/revivir-cot'])) return $AC->NoAccess();

        $cotizacionToken = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = $usuarioLogueado->id ?? 0;

        // traigo la cotización
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionToken]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-R10', 'Cotización inválida');
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
        $item->productoId = $cotizacion->productoId;

        // Si hay que revivir desde el último nodo
        if ($revivirComportamiento === 'u') {
            $item->nodoActual = $cotizacion->nodoActual;
        }
        else if ($revivirComportamiento === 'i') { // nodo inicial
            $item->nodoActual = null;
        }
        else if ($revivirComportamiento === 'd') { // desactivado
            return $this->ResponseError('TASK-R40', 'Revivir cotización desactivado');
        }
        else {
            return $this->ResponseError('TASK-R41', 'Configuración para revivir cotización no seleccionada');
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
            $bitacoraCoti->cotizacionId = $item->id;
            $bitacoraCoti->usuarioId = $usuarioLogueado->id;
            $bitacoraCoti->log = "Cotización revivida por usuario \"{$usuarioLogueado->name}\", desde cotización No.{$cotizacion->id}";
            $bitacoraCoti->nodoName = null;
            $bitacoraCoti->logType = 'revive';
            $bitacoraCoti->save();

            return $this->ResponseSuccess('Cotización revivida con éxito', ['token' => $item->token]);
        }
        else {
            return $this->ResponseError('TASK-R11', 'Error al iniciar tarea, por favor intente de nuevo');
        }
    }

    // Cotizaciones
    public function GetCotizacion($cotizacionId) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $usuarioLogueado = $usuario = auth('sanctum')->user();

        $item = Cotizacion::where([['id', '=', $cotizacionId], ['usuarioIdAsignado', '=', $usuarioLogueado->id]])->first();

        if (empty($item)) {
            return $this->ResponseError('TASK-016', 'La tarea no existe o se encuentra asignada a otro usuario');
        }

        return $this->ResponseSuccess('Tarea obtenida con éxito', $item);
    }

    public function GetProductsFilter($includeImage = false, $includeData = false) {

        $authHandler = new AuthController();
        $cache = ClassCache::getInstance();

        $productos = Productos::where('status', 1)->where('marcaId', SSO_BRAND_ID)->get();

        $arrProds = [];
        foreach ($productos as $producto) {

            $confProd = @json_decode($producto->extraData, true);

            $access = $authHandler->CalculateVisibility(SSO_USER_ID, SSO_USER_ROL_ID, false, $confProd['roles_assign'] ?? [], $confProd['grupos_assign'] ?? [], $confProd['canales_assign'] ?? []);
            //$cache->setMemcached($cacheKey, $access, 20);

            if ($access) {

                /*var_dump(SSO_USER_ID);
                var_dump($confProd['roles_assign']);
                var_dump($confProd['grupos_assign']);
                var_dump($confProd['canales_assign']);*/

                $arrProds[$producto->id] = [
                    'id' => $producto->id,
                    'n' => $producto->nombreProducto,
                    'l' => "/flow/{$producto->token}/view",
                    't' => $producto->token,
                    // 'v' => $producto->version,
                ];

                if ($includeImage) {
                    $arrProds[$producto->id]['i'] = $producto->imagenData;
                }
                if ($includeData) {
                    $arrProds[$producto->id]['d'] = $confProd;
                }
            }
        }

        return $arrProds;
    }

    public function GetProductosForFilter(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $productsFilter = $this->GetProductsFilter();

        return $this->ResponseSuccess('Productos obtenidos con éxito', $productsFilter);
    }

    public function GetCotizaciones(Request $request) {

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $cache = ClassCache::getInstance();

        $pagina = $request->get('page');
        $filterSearchId = $request->get('filterSearchId');
        $filterSearch = $request->get('filterSearch');
        $productoId = $request->get('productoId');
        $estadoFilter = $request->get('estadoFilter');

        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');

        $fechaIni = Tools::dateConvertToDB($fechaIni, true);
        $fechaFin = Tools::dateConvertToDB($fechaFin, false, true);

        $userHandler = new AuthController();
        $CalculateAccess = $userHandler->CalculateAccess();

        // calculo de páginas
        $perPage = 50;
        $from = 0;
        $to = 0;

        //dd($CalculateAccess);

        if ($AC->CheckAccess(['tareas/view/flujos-no-asig'])) {
            $CalculateAccess['all'][] = 0;
        }

        $usuariosAsignados = implode(', ', $CalculateAccess['all']);

        $idFilterSql = '';
        $dateFilter = '';
        $estadoFilterSql = '';
        $productoIdFilterSql = '';
        $filterSearchSql = '';
        $cotizacionesUserAsigCreador = "CM.usuarioIdAsignado IN ($usuariosAsignados)";

        if (empty($filterSearchId)) {

            if (!empty($estadoFilter) && $estadoFilter !== '__all__') {
                $estadoFilter = DB::connection()->getPdo()->quote($filterSearch);
                $estadoFilterSql = "AND CM.estado = {$estadoFilter}";
            }

            if (!empty($productoId)) {
                $productoId = intval($productoId);
                $productoIdFilterSql = "AND CM.productoId = {$productoId}";
            }

            $dateFilter = "AND CM.dateCreated >= '" . $fechaIni . "' AND CM.dateCreated <= '" . $fechaFin . "'";

            if (!empty($filterSearch)) {
                $filterSearch = DB::connection()->getPdo()->quote($filterSearch);
                $filterSearchSql = "JOIN (
                                        SELECT cotizacionId
                                        FROM cotizacionesDetalle AS TFCD
                                        WHERE
                                            TFCD.useForSearch = 1
                                            AND TFCD.searchField = {$filterSearch}
                                    ) AS FT ON FT.cotizacionId = CM.id";
            }
        }
        else {
            $filterSearchId = intval($filterSearchId);
            $idFilterSql = "AND CM.id = {$filterSearchId}";
        }

        // desactivado filtro de fecha si busca por un valor
        if ($filterSearchSql !== '') {
            $dateFilter = '';
        }

        if ($AC->CheckAccess(['tareas/view/flujos-creator'])) {
            $cotizacionesUserAsigCreador = "(CM.usuarioIdAsignado IN ($usuariosAsignados) OR  CM.usuarioId = {$usuarioLogueadoId})";
        }

        // conteo para páginas
        $strQueryFull = "SELECT count(CM.id) as conteo
                        FROM cotizaciones AS CM
                        {$filterSearchSql}
                        WHERE
                            CM.marcaId = '".SSO_BRAND_ID."'
                            AND CM.deletedTask = 0
                            {$productoIdFilterSql}
                            {$estadoFilterSql}
                            AND {$cotizacionesUserAsigCreador}                           
                            {$idFilterSql}
                            {$dateFilter}                            
                        ";
        //var_dump($strQueryFull);
        $conteoPaginas = DB::select(DB::raw($strQueryFull));
        $conteoPaginas = $conteoPaginas[0]->conteo ?? 0;

        $from = (empty($pagina) ? 0 : ($pagina * $perPage));
        $to = $perPage;
        $pageNumber = ($conteoPaginas > 0 ? ceil($conteoPaginas / $perPage) : 1);

        /*var_dump($from);
        var_dump($to);*/
        //die('asdfsd');

        // Traigo el detalle
        $strQueryFull = "SELECT 
                            C.token, 
                            C.nodoActual,
                            C.estado, 
                            C.productoId, 
                            C.usuarioIdAsignado, 
                            C.dateCreated, 
                            C.dateExpire, 
                            P.nombreProducto, 
                            P.token AS productoTk, 
                            UA.name as usuarioAsignado, 
                            UC.name as usuarioCreador, 
                            C.id AS cotMasterId,
                            CD.*
                        FROM cotizaciones AS C
                        JOIN (
                            SELECT CM.id
                            FROM cotizaciones AS CM
                            {$filterSearchSql}
                            WHERE
                                CM.marcaId = '".SSO_BRAND_ID."'
                                AND CM.deletedTask = 0
                                AND {$cotizacionesUserAsigCreador}                         
                                {$idFilterSql}
                                {$dateFilter}
                                {$productoIdFilterSql}                            
                                {$estadoFilterSql}
                            ORDER BY CM.id DESC                            
                            LIMIT {$from}, {$to}
                        ) AS CMaster ON CMaster.id = C.id
                        JOIN productos AS P ON P.id = C.productoId
                        LEFT JOIN cotizacionesDetalle AS CD ON C.id = CD.cotizacionId                                               
                        LEFT JOIN users AS UA ON UA.id = C.usuarioIdAsignado
                        LEFT JOIN users AS UC ON UC.id = C.usuarioId
                        ORDER BY C.id DESC";

        /*var_dump($strQueryFull);
        die();*/

        $filasCotizacion = DB::select(DB::raw($strQueryFull));

        $cotizaciones = [];
        /*$conteoEstados = [];
        $conteoEstados['Total']['n'] = 'Conteo Total';
        $conteoEstados['Total']['c'] = 0;*/

        $arrCotizaciones = [];
        $arrTimeline = [];
        $arrCache = [];
        $arrNodes = [];

        // inicio el cache de productos
        foreach ($filasCotizacion as $key => $item) {
            if (!isset($arrCache[$item->productoId])) {

                $cacheKey = "flujo_from_coti_{$item->cotMasterId}";
                //$flujoConfig = $cache->getMemcached($cacheKey);
                $cotizacion = Cotizacion::find($item->cotMasterId);
                $flujoConfig = $this->getFlujoFromCotizacion($cotizacion);

                if (!$flujoConfig['status']) {
                    continue; // para que devuelva los demás y solo salte este
                }
                else {
                    $flujoConfig = $flujoConfig['data'];
                }

                foreach ($flujoConfig['nodes'] as $nodo) {
                    $nodoName = $item->productoId.'_'.$nodo['id'];
                    $arrNodes[$nodoName] = $nodo['nodoName'];

                    $arrTimeline[$item->productoId] = [
                        'p' => $item->nombreProducto,
                        't' => [],
                    ];
                }

                $arrCache[$item->productoId] = $flujoConfig;
            }

            $tools = new Tools();

            // Con keys cortas del array ahorramos esos bytes de transfer por cada tarea
            $arrCotizaciones[$item->cotMasterId]['id'] = $item->cotMasterId;
            $arrCotizaciones[$item->cotMasterId]['dC'] = Carbon::parse($item->dateCreated)->setTimezone('America/Guatemala')->format('d-m-Y H:i');
            $arrCotizaciones[$item->cotMasterId]['dE'] = (!empty($item->dateExpire) ? $item->dateExpire : 'Sin expiración');
            $arrCotizaciones[$item->cotMasterId]['pTk'] = $item->productoTk;
            $arrCotizaciones[$item->cotMasterId]['nP'] = $item->nombreProducto;
            $arrCotizaciones[$item->cotMasterId]['usrAs'] = (!empty($item->usuarioAsignado) ? $item->usuarioAsignado : 'No disponible');
            $arrCotizaciones[$item->cotMasterId]['usrAsI'] = $tools->makeInitialsFromWords($arrCotizaciones[$item->cotMasterId]['usrAs']);
            $arrCotizaciones[$item->cotMasterId]['u'] = (!empty($item->usuarioCreador) ? $item->usuarioCreador : 'No disponible');
            $arrCotizaciones[$item->cotMasterId]['uI'] = $tools->makeInitialsFromWords($arrCotizaciones[$item->cotMasterId]['u']);
            $arrCotizaciones[$item->cotMasterId]['pId'] = $item->nombreProducto;
            $arrCotizaciones[$item->cotMasterId]['token'] = $item->token;
            $arrCotizaciones[$item->cotMasterId]['estado'] = ucwords($item->estado);

            // busca el nodo
            /*if (in_array($arrCache[$item->productoId]['nodes'])) {


            }*/

            // inicia el resumen
            if (!isset($arrCotizaciones[$item->cotMasterId]['resumen'])) {
                $arrCotizaciones[$item->cotMasterId]['resumen'] = [];
            }

            // detalle resumen
            if (!empty($item->useForSearch)) {
                $tmpLabel = ucwords($item->label ?? str_replace('_', ' ', $item->campo));
                $valorTmp = (!empty($item->valorShow) ? $item->valorShow : $item->valorLong);
                $arrCotizaciones[$item->cotMasterId]['resumen'][$tmpLabel] = $valorTmp;
            }

            // línea del tiempo

            // busca el nodo
            //var_dump($item);
            $nodoName = $item->productoId.'_'.$item->nodoActual;
            if (isset($arrNodes[$nodoName])) {
                //var_dump($arrNodes[$item->nodoActual]);
                //var_dump($arrNodes[$item->nodoActual]);
                $arrTimeline[$item->productoId]['t'][$item->nodoActual]['nn'] = $arrNodes[$nodoName];
                $arrTimeline[$item->productoId]['t'][$item->nodoActual]['t'][$item->cotMasterId] = $item->cotMasterId;
            }
            else {
                $arrTimeline[$item->productoId]['t']['_nostep']['nn'] = 'Sin paso';
                $arrTimeline[$item->productoId]['t']['_nostep']['t'][$item->cotMasterId] = $item->cotMasterId;
            }
            /*foreach ($arrCotizaciones as $coti) {

            }
            $arrTimeline[$item->productoId][] = [

            ];*/
        }

        // conteo de timeline
        foreach ($arrTimeline as $idProd => $items) {

            $arrTimeline[$idProd]['c']['cn'] = count($items['t']);
            $arrTimeline[$idProd]['c']['cg'] = 0;

            foreach ($items['t'] as $nodo => $itemNodo) {
                $arrTimeline[$idProd]['c']['cg'] += count($itemNodo['t']);

                if (!isset($arrTimeline[$idProd]['t'][$nodo]['c'])) $arrTimeline[$idProd]['t'][$nodo]['c'] = 0;
                $arrTimeline[$idProd]['t'][$nodo]['c'] += count($itemNodo['t']);

                if (!isset($arrTimeline[$idProd]['c']['cbn'][$nodo])) $arrTimeline[$idProd]['c']['cbn'][$nodo] = 0;
                $arrTimeline[$idProd]['c']['cbn'][$nodo] += count($itemNodo['t']);
            }
        }

        /*var_dump($arrNodes);
        die();*/

        // orden de tareas
        $key = 0;
        foreach ($arrCotizaciones as $item) {
            $cotizaciones['c'][$key] = $item;
            $key++;
        }

        $cotizaciones['p'] = $pageNumber;
        $cotizaciones['pmx'] = $conteoPaginas;

        // gráfico
        // conteo para páginas
        $strQueryFull = "SELECT count(CM.id) as conteo, CM.productoId, P.nombreProducto
                        FROM cotizaciones AS CM
                        JOIN productos AS P ON CM.productoId = P.id
                        {$filterSearchSql}
                        WHERE
                            CM.marcaId = '".SSO_BRAND_ID."'
                            AND CM.deletedTask = 0
                            {$productoIdFilterSql}
                            {$estadoFilterSql}
                            AND {$cotizacionesUserAsigCreador}                         
                            {$idFilterSql}
                            {$dateFilter}                            
                        GROUP BY CM.productoId, P.nombreProducto";

        $graficoBusqueda = DB::select(DB::raw($strQueryFull));
        $cotizaciones['g'] = [];

        foreach ($graficoBusqueda as $grafico) {
            $cotizaciones['g'][] = [
                'c' => $grafico->conteo,
                'p' => $grafico->nombreProducto,
            ];
        }

        // línea del tiempo por producto

        // numeración de páginas
        $pagesNum = [];
        for($i = ($pagina - 6); $i < $pagina + 6; $i++) {
            if ($i >= 0 && $i <= $conteoPaginas && $i < $pageNumber) {
                $pagesNum[] = $i;
            }
        }
        $cotizaciones['pn'] = $pagesNum;
        $cotizaciones['tim'] = $arrTimeline;

        // var_dump($cotizacionesGraph);

        return $this->ResponseSuccess('Tareas obtenidas con éxito', $cotizaciones);
    }

    public function GetCotizacionesFastCount(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $fechaIni = Carbon::now()->subDays(5);
        $fechaFin = Carbon::now();

        $fechaIni = $fechaIni->toDateString() . " 00:00:00";
        $fechaFin = $fechaFin->toDateString() . " 23:59:59";

        $fechaIni = Tools::dateConvertToDB($fechaIni, true);
        $fechaFin = Tools::dateConvertToDB($fechaFin, false, true);

        $usuarioLogueado = auth('sanctum')->user();

        $items = Cotizacion::where([['usuarioIdAsignado', '=', $usuarioLogueado->id], ['dateCreated', '>=', $fechaIni], ['dateCreated', '<=', $fechaFin], ['marcaId', '=', SSO_BRAND_ID], ['deletedTask', '=', 0]]);
        $items = $items->with(['usuario', 'usuarioAsignado', 'producto', 'campos'])->limit(10)->orderBy('id', 'DESC')->get();

        $cotizaciones = [];
        $conteoEstados = [];

        foreach ($items as $key => $item) {

            $estado = (!empty($item->estado)) ? $item->estado : 'sin estado';
            if (!isset($conteoEstados[$estado])) {
                $conteoEstados[$estado]['n'] = ucwords($estado);
                $conteoEstados[$estado]['c'] = 1;
            }
            else {
                $conteoEstados[$estado]['c']++;
            }

            $cotizaciones['c'][$key]['id'] = $item->id;
            $cotizaciones['c'][$key]['dateCreated'] = Carbon::parse($item->dateCreated)->setTimezone('America/Guatemala')->toDateTimeString();;
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

        if (empty($items)) {
            return $this->ResponseError('TASK-016', 'Tarea inválida');
        }

        return $this->ResponseSuccess('Tareas obtenidas con éxito', $cotizaciones);
    }

    public function GetCotizacionResumen(Request $request, $returnArray = false) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $usuarioLogueado = $usuario = auth('sanctum')->user();
        $cotizacionId = $request->get('token');

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId], ['marcaId', '=', SSO_BRAND_ID]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-632', 'Tarea no válida');
        }

        $producto = $cotizacion->producto;
        if (empty($producto)) {
            return $this->ResponseError('TASK-600', 'Producto no válido');
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-601', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-601', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        $camposCoti = [];
        foreach ($cotizacion->campos as $tmp) {
            $camposCoti[$tmp->campo] = $tmp;
        }
        //$camposCoti = $cotizacion->campos;

        // Recorro campos para hacer resumen
        $resumen = [];
        foreach ($flujoConfig['nodes'] as $nodo) {
            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                    $resumen[$keySeccion]['nombre'] = $seccion['nombre'];

                    foreach ($seccion['campos'] as $keyCampo => $campo) {
                        //$campoTmp = $camposCoti->where('campo', $campo['id'])->first();
                        $campoTmp = $camposCoti[$campo['id']] ?? false;

                        if ($campoTmp) {
                            if ($campoTmp->tipo === 'tags') {
                                $campoTmp->valorLong = @json_decode($campoTmp->valorLong ?? '', true);
                            }

                            if ($returnArray) {
                                $resumen[$keySeccion]['campos'][$campo['id']] = ['value' => $campoTmp->valorLong ?? '', 'label' => $campo['nombre'], 'id' => $campo['id'], 't' => $campo['tipoCampo'],];
                            }
                            else {
                                if (!empty($campoTmp->valorLong)) {
                                    $resumen[$keySeccion]['campos'][$campo['id']] = ['value' => $campoTmp->valorLong ?? '', 'label' => $campo['nombre'], 'id' => $campo['id'], 't' => $campo['tipoCampo'],];
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($returnArray) {
            return $resumen;
        }
        else {
            return $this->ResponseSuccess('Resumen generado con éxito', $resumen);
        }
    }

    public function CambiarUsuarioCotizacion(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/admin/usuario-asignado'])) return $AC->NoAccess();

        $usuario = $request->get('usuarioId');
        $cotizacionId = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();

        $item = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($item)) {
            return $this->ResponseError('TASK-015', 'Tarea inválida');
        }

        $usuarioDetail = User::find($usuario);

        // Cambio el estado al nodo actual
        $item->usuarioIdAsignado = $usuario;
        $item->save();

        // Guardo la bitacora actual
        $bitacoraCoti = new CotizacionBitacora();
        $bitacoraCoti->cotizacionId = $item->id;
        $bitacoraCoti->usuarioId = $usuarioLogueado->id;
        $bitacoraCoti->log = "Editado usuario asignado por \"{$usuarioLogueado->name}\", asignado: {$usuarioDetail->name}";
        $bitacoraCoti->nodoName = null;
        $bitacoraCoti->logType = 'user';
        $bitacoraCoti->save();

        if ($item->save()) {
            return $this->ResponseSuccess('Usuario actualizada con éxito', ['id' => $item->id]);
        }
        else {
            return $this->ResponseError('TASK-016', 'Error al actualizar tarea, por favor intente de nuevo');
        }
    }

    public function EditarEstadoCotizacion(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/admin/usuario-asignado'])) return $AC->NoAccess();

        $estado = $request->get('estado');
        $cotizacionId = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();

        $item = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($item)) {
            return $this->ResponseError('TASK-015', 'Tarea inválida');
        }

        // Cambio el estado al nodo actual
        $item->estado = $estado;
        $item->save();

        // Guardo la bitacora actual
        $bitacoraCoti = new CotizacionBitacora();
        $bitacoraCoti->cotizacionId = $item->id;
        $bitacoraCoti->usuarioId = $usuarioLogueado->id;
        $bitacoraCoti->log = "Editado estado de cotización, usuario: \"{$usuarioLogueado->name}\", asignado estado: {$estado}";
        $bitacoraCoti->nodoName = null;
        $bitacoraCoti->logType = 'status';
        $bitacoraCoti->save();

        if ($item->save()) {
            return $this->ResponseSuccess('Estado editado con éxito', ['id' => $item->id]);
        }
        else {
            return $this->ResponseError('TASK-016', 'Error al actualizar cotización, por favor intente de nuevo');
        }
    }

    public function getCotizacionLink($tokenPr, $tokenCot) {
        return env('APP_URL') . '#/f/' . $tokenPr . '/' . $tokenCot;
    }

    public function getConstantVars($cotizacion) {
        $campos = [];
        $tmpUser = User::where('id', $cotizacion->usuarioId)->first();
        $campos['FECHA_FORMULARIO'] = Carbon::parse($cotizacion->dateCreated)->setTimezone('America/Guatemala')->toDateTimeString();
        $campos['FECHA_HOY'] = Carbon::now()->toDateTimeString();
        $campos['ID_FORMULARIO'] = $cotizacion->id;
        $campos['HOY_SUM_1_YEAR'] = Carbon::now()->addYear()->toDateTimeString();
        $campos['HOY_SUM_1_YEAR_F1'] = Carbon::now()->addYear()->format('d/m/Y');
        $campos['CREADOR_NOMBRE'] = (!empty($tmpUser) ? $tmpUser->name : 'Sin nombre');
        $campos['CREADOR_CORP'] = (!empty($tmpUser) ? $tmpUser->corporativo : 'Sin corporativo');
        $campos['FECHA_MODIFICACION'] = Carbon::parse($cotizacion->dateUpdated)->setTimezone('America/Guatemala')->toDateTimeString();
        return $campos;
    }

    public function CambiarEstadoCotizacionPublic(Request $request) {
        return $this->CambiarEstadoCotizacion($request, false, false, false, true);
    }

    public function CambiarEstadoCotizacion(Request $request, $recursivo = false, $desdeDecision = false, $originalStep = false, $public = false, $artificio = true, $watchDog = 0) {
        $campos = $request->get('campos');
        $paso = $request->get('paso');
        //$estado = $request->get('estado');
        $token = $request->get('token');
        $seccionKey = $request->get('seccionKey');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;

        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
        }

        // Actual
        $userHandler = new AuthController();

        // Si es recursivo debe seguir el proceso aunque se haya asignado otro user
        $item = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($item)) {
            return $this->ResponseError('TASK-015', 'Tarea inválida');
        }

        $item->touch();
        $item->save();

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
            return $this->ResponseError('TASK-010', 'Hubo un error al calcular el flujo, por favor intente de nuevo');
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

        // Cambio el estado al nodo actual
        if (!empty($flujo['next']['estOut'])) $item->estado = $flujo['next']['estOut'];
        $item->nodoActual = $flujo['actual']['nodoId'];
        $item->save();

        // dd($flujo['actual']);

        // Guardo campos
        if (!empty($campos) && is_array($campos) && !$recursivo) {

            // Variables por defecto
            $tmpUser = User::where('id', $item->usuarioId)->first();
            $campos['FECHA_FORMULARIO']['v'] = Carbon::parse($item->dateCreated)->setTimezone('America/Guatemala')->toDateTimeString();
            $campos['FECHA_HOY']['v'] = Carbon::now('America/Guatemala')->toDateTimeString();
            $campos['ID_FORMULARIO']['v'] = $item->id;
            $campos['HOY_SUM_1_YEAR']['v'] = Carbon::now()->addYear()->toDateTimeString();
            $campos['HOY_SUM_1_YEAR_F1']['v'] = Carbon::now()->addYear()->format('d/m/Y');
            $campos['CREADOR_NOMBRE']['v'] = (!empty($tmpUser) ? $tmpUser->name : 'Sin nombre');
            $campos['CREADOR_CORP']['v'] = (!empty($tmpUser) ? $tmpUser->corporativo : 'Sin corporativo');
            $campos['FECHA_MODIFICACION']['v'] = Carbon::parse($item->dateUpdated)->setTimezone('America/Guatemala')->toDateTimeString();
            $campos['FECHA_MODIFICACION_MINI']['v'] = Carbon::parse($item->dateUpdated)->setTimezone('America/Guatemala')->format('d-m-Y');
            $campos['HORA_MODIFICACION_24']['v'] = Carbon::parse($item->dateUpdated)->setTimezone('America/Guatemala')->format('H:i');

            //dd($campos);

            // producto
            $productoTk = $item->producto->token ?? '';
            $campos['LINK_FORM']['v'] = $this->getCotizacionLink($item->token, $productoTk);

            foreach ($campos as $campoKey => $valor) {

                if (!is_array($valor) && !empty($fieldsData[$campoKey])) {
                    $valor = [
                        'v' => $valor,
                        't' => $fieldsData[$campoKey]['tipoCampo'],
                    ];
                }

                if ($valor['v'] === '__SKIP__FILE__') continue;

                // tipos de archivo que no se guardan
                if (!empty($valor['t']) && ($valor['t'] === 'txtlabel' || $valor['t'] === 'subtitle' || $valor['t'] === 'title')) {
                    continue;
                }

                $campo = CotizacionDetalle::where('campo', $campoKey)->where('cotizacionId', $item->id)->first();
                if (empty($campo)) {
                    $campo = new CotizacionDetalle();
                }
                $campo->cotizacionId = $item->id;
                $campo->seccionKey = $seccionKey;
                $campo->campo = $campoKey;
                $campo->label = (!empty($fieldsData[$campoKey]['nombre']) ? $fieldsData[$campoKey]['nombre'] : '');
                $campo->useForSearch = (!empty($fieldsData[$campoKey]['showInReports']) ? 1 : 0);

                $campo->tipo = $valor['t'] ?? 'default';

                if ($campo->tipo === 'signature') {

                    // solo se guarda la firma si viene en base 64, quiere decir que cambió
                    if (str_contains($valor['v'], 'data:image/')) {
                        $marcaToken = $item->marca->token ?? false;
                        $name = md5(uniqid()) . '.png';
                        $dir = "{$marcaToken}/{$item->token}/{$name}";
                        $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $valor['v']));
                        $disk = Storage::disk('s3');
                        $path = $disk->put($dir, $image);
                        $campo->isFile = 1;
                        $campo->valorLong = $dir;
                    }
                }
                else {

                    if (is_array($valor['v'])) {
                        $campo->valorLong = json_encode($valor['v'], JSON_FORCE_OBJECT);
                    }
                    else {
                        $campo->valorLong = $valor['v'];
                        if (!empty($campo->useForSearch)) {
                            $campo->searchField = trim(substr($campo->valorLong, 0, 145));
                        }
                    }
                }
                $campo->valorShow = (!empty($valor['vs']) ? $valor['vs'] : null);

                $campo->save();

                //_DESC 've'
                if (!empty($valor['ve']) && is_array($valor['ve'])) {
                    foreach($valor['ve'] as $valorKey => $valorLong){
                        $slugValue = explode('.', $valorKey);
                        $slugValue = end($slugValue);

                        $campoTmp = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', "{$campoKey}_{$slugValue}")->first();
                        if (empty($campoTmp)) {
                            $campoTmp = new CotizacionDetalle();
                        }
                        $campoTmp->cotizacionId = $item->id;
                        $campoTmp->seccionKey = $seccionKey;
                        $campoTmp->campo = "{$campoKey}_{$slugValue}";
                        $campoTmp->label = $campo->label;
                        $campoTmp->useForSearch = 0;
                        $campoTmp->tipo = $valor['t'] ?? 'default';
                        $campoTmp->valorLong = $valorLong;
                        $campoTmp->save();
                    }
                }
            }

            // la coloco como llena
            if (empty($item->isFilled)) {
                $item->isFilled = 1;
                $item->save();
            }

            // Guardo la bitacora actual
            $bitacoraCoti = new CotizacionBitacora();
            $bitacoraCoti->cotizacionId = $item->id;
            $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['label']);
            $bitacoraCoti->logType = 'next';
            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
            $bitacoraCoti->log = "Guardados datos en paso \"{$flujo['actual']['label']}\"";
            $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
            $bitacoraCoti->logType = 'save';
            $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
            $bitacoraCoti->save();
        }

        $autoSaltarASiguiente = false;
        $decisionTomada = false;

        // Cambio a siguiente paso
        if ($paso === 'next') {

            /*if (!empty($flujo['actual']['expiracionNodo']) && $flujo['actual']['expiracionNodo'] > 0) {
                $fechaExpira = Carbon::now()->addDays($flujo['actual']['expiracionNodo']);
                $item->dateExpire = $fechaExpira->format('Y-m-d');
                $item->save();
            }*/

            /*if (!empty($flujo['actual']['noAttN']) && $flujo['actual']['noAttN'] > 0) {
                $fechaNotificacion = Carbon::now()->addDays($flujo['actual']['noAttN']);
                $item->dateNotifyNoAtention = $fechaNotificacion->format('Y-m-d h:i:s');
                $item->save();
            }*/

            // si es condicion, hay que volver a evaluarla
            if ($flujo['actual']['typeObject'] === 'condition') {
                $flujo['next'] = $flujo['actual'];
            }

            // Si viene el resultado desde decisión
            if (isset($desdeDecision['result'])) {
                // dd($flujo);

                if ($desdeDecision['result']) {
                    $nodoSiguiente = $flujo['actual']['nodosSalidaDecision']['si'];
                }
                else {
                    $nodoSiguiente = $flujo['actual']['nodosSalidaDecision']['no'];
                }
                if (empty($nodoSiguiente)) {
                    return $this->ResponseError('TASK-010', 'Hubo un error al continuar flujo, decisión mal configurada (sin una salida)');
                }

                $item->nodoActual = $nodoSiguiente;
                $item->save();

                $flujo = $this->CalcularPasos($request, true, $public, true);

                // Si el nodo actual es de estos, lo tengo que ejecutar, entonces lo pongo como next
                if ($flujo['actual']['typeObject'] === 'process' || $flujo['actual']['typeObject'] === 'condition' || $flujo['actual']['typeObject'] === 'setuser' || $flujo['actual']['typeObject'] === 'output' || $flujo['actual']['typeObject'] === 'ocr') {
                    $flujo['next'] = $flujo['actual'];
                }
                else if ($flujo['actual']['typeObject'] === 'input') {
                    //return $flujo;
                    return $this->ResponseSuccess('Tarea actualizada con éxito', $flujo);
                }
            }
            else {
                if ($desdeDecision) {
                    if ($flujo['actual']['typeObject'] === 'input' || $flujo['actual']['typeObject'] === 'output' || $flujo['actual']['typeObject'] === 'ocr') {
                        //return $flujo;
                        return $this->ResponseSuccess('Tarea actualizada con éxito', $flujo);
                    }
                }
            }

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
            }

            // Verifico si es de procesos, acá siempre solo es uno
            if ($flujo['next']['typeObject'] === 'process') {

                /*var_dump($flujo['next']['procesos'][0]);
                die();*/
                $dataWs = [];
                $urlSend = '';

                if (!empty($flujo['next']['procesos'][0]['prOri']) && $flujo['next']['procesos'][0]['prOri'] === "CONN") {

                    // valida la conexión
                    if (empty($flujo['next']['procesos'][0]['prConn'])) {
                        return $this->ResponseError('COTW-004', "Nodo de proceso mal configurado, debe seleccionar una conexión a ejecutar.");
                    }

                    $conexion = FlujoConexion::where('marcaId', $item->marcaId)->where('id', $flujo['next']['procesos'][0]['prConn'])->first();

                    if (empty($conexion)) {
                        return $this->ResponseError('CONN-F49', 'Conexión inválida');
                    }

                    $prefix = $flujo['next']['procesos'][0]['identificadorWs'] ?? 'WS';
                    $resultado = $this->executeConnection($conexion, $item, 'ws', true, $prefix);
                    $dataLog = "<h5>Data enviada</h5> <br> " . htmlentities($resultado['log']['enviado'] ?? '') . " <br><br> <h5>Headers enviados</h5> <br> " . ($resultado['log']['enviadoH'] ?? '') . " <br><br> <h5>Data recibida</h5> <br> " . htmlentities($resultado['log']['recibido'] ?? '') . " <br><br> <h5>Data procesada</h5> <br> " . htmlentities(print_r($resultado['parsed'] ?? '', true));
                    $dataWs = $resultado['parsed'];
                    $urlSend = $resultado['log']['url'] ?? '';
                }
                else {
                    $resultado = $this->consumirServicio($flujo['next']['procesos'][0], $item->campos);
                    $dataLog = "<h5>Data enviada</h5> <br> " . htmlentities($resultado['log']['enviado'] ?? '') . " <br><br> <h5>Headers enviados</h5> <br> " . ($resultado['log']['enviadoH'] ?? '') . " <br><br> <h5>Data recibida</h5> <br> " . htmlentities($resultado['log']['recibido'] ?? '') . " <br><br> <h5>Data procesada</h5> <br> " . htmlentities(print_r($resultado['data'] ?? '', true));
                    $dataWs = $resultado['data'];
                    $urlSend = $flujo['next']['procesos'][0]['url'] ?? '';
                }

                if (empty($resultado['status'])) {
                    $bitacoraCoti = new CotizacionBitacora();
                    $bitacoraCoti->cotizacionId = $item->id;
                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                    $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                    $bitacoraCoti->logType = 'error';
                    $bitacoraCoti->onlyPruebas = 1;
                    $bitacoraCoti->dataInfo = $dataLog;
                    $bitacoraCoti->log = "Error ejecutando proceso. Saliendo de \"{$flujo['actual']['nodoName']}\", URL: {$flujo['next']['procesos'][0]['url']}";
                    $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
                    $bitacoraCoti->save();

                    if ($originalStep) {
                        $item->nodoActual = $originalStep;
                        $item->save();
                    }

                    //dd($resultado);

                    return $this->ResponseError('COTW-001', "Ha ocurrido realizando el proceso de envío de datos. {$resultado['msg']}");
                }
                else {

                    // Si tiene identificador de WS, se guardan los campos de una
                    if (!empty($flujo['next']['procesos'][0]['identificadorWs'])) {

                        if (is_array($dataWs)) {
                            foreach ($dataWs as $campoKey => $campoValue) {
                                $campo = CotizacionDetalle::where('cotizacionId', $item->id)->where('campo', $campoKey)->first();
                                if (empty($campo)) {
                                    $campo = new CotizacionDetalle();
                                }
                                $campo->cotizacionId = $item->id;
                                $campo->campo = $campoKey;
                                if (is_array($campoValue)) {
                                    $campo->valorLong = json_encode($campoValue, JSON_FORCE_OBJECT);
                                }
                                else {
                                    $campo->valorLong = $campoValue;
                                }
                                $campo->save();
                            }
                        }
                    }

                    $bitacoraCoti = new CotizacionBitacora();
                    $bitacoraCoti->cotizacionId = $item->id;
                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                    $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                    $bitacoraCoti->logType = 'process';
                    $bitacoraCoti->onlyPruebas = 1;
                    $bitacoraCoti->dataInfo = "<h5>URL:</h5> {$urlSend} <br/><br/>" . $dataLog;
                    $bitacoraCoti->log = "Ejecutado proceso saliendo de \"{$flujo['actual']['nodoName']}\"";
                    $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                    $bitacoraCoti->save();
                }

                $autoSaltarASiguiente = true;
            }
            else if ($flujo['next']['typeObject'] === 'condition') {

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
                                $campoTmp->valorLong = ($isInt) ? intval($campoTmp->valorLong) : (string) $campoTmp->valorLong;
                                if ($decision['value'] === '""') {
                                    $decision['value'] = "";
                                }
                                else {
                                    $decision['value'] = ($isInt) ? $decision['value'] : (string) $decision['value'];
                                }

                                if ($decision['campoIs'] === '=') {
                                    if ($campoTmp->valorLong == $decision['value']) $cumplio = true;
                                }
                                else if ($decision['campoIs'] === '<') {
                                    if ($campoTmp->valorLong < $decision['value']) $cumplio = true;
                                }
                                else if ($decision['campoIs'] === '<=') {
                                    if ($campoTmp->valorLong <= $decision['value']) $cumplio = true;
                                }
                                else if ($decision['campoIs'] === '>') {
                                    if ($campoTmp->valorLong > $decision['value']) $cumplio = true;
                                }
                                else if ($decision['campoIs'] === '>=') {
                                    if ($campoTmp->valorLong >= $decision['value']) $cumplio = true;
                                }
                                else if ($decision['campoIs'] === '<>') {
                                    if ($campoTmp->valorLong != $decision['value']) $cumplio = true;
                                }
                                else if ($decision['campoIs'] === 'like') {
                                    $decision['value'] = (string) $decision['value'];
                                    $campoTmp->valorLong = (string) $campoTmp->valorLong;
                                    if (str_contains($campoTmp->valorLong, $decision['value'])) $cumplio = true;
                                }
                            }
                            else {

                                foreach ($valorJsonDecode as $valorTmp) {

                                    $valorTmp = (is_integer($campoTmp->valorLong) ? $campoTmp->valorLong : (string) $campoTmp->valorLong);
                                    $decision['value'] = (is_integer($decision['value']) ? $decision['value'] : (string) $decision['value']);

                                    if ($decision['campoIs'] === '=') {
                                        if ($valorTmp == $decision['value']) $cumplio = true;
                                        break;
                                    }
                                    else if ($decision['campoIs'] === '<') {
                                        if ($valorTmp < $decision['value']) $cumplio = true;
                                        break;
                                    }
                                    else if ($decision['campoIs'] === '<=') {
                                        if ($valorTmp <= $decision['value']) $cumplio = true;
                                        break;
                                    }
                                    else if ($decision['campoIs'] === '>') {
                                        if ($valorTmp > $decision['value']) $cumplio = true;
                                        break;
                                    }
                                    else if ($decision['campoIs'] === '>=') {
                                        if ($valorTmp >= $decision['value']) $cumplio = true;
                                        break;
                                    }
                                    else if ($decision['campoIs'] === 'like') {
                                        if (str_contains($valorTmp, $decision['value'])) $cumplio = true;
                                        break;
                                    }
                                }
                            }

                            $valuacionValores .= " {$decision['glue']} {$campoTmp->valorLong} {$decision['campoIs']} {$decision['value']}";

                            if ($decision['glue'] === 'AND') {
                                $decisionCumple = ($decisionCumple && $cumplio);
                            }
                            else if ($decision['glue'] === 'OR') {
                                $decisionCumple = ($decisionCumple || $cumplio);
                            }
                        }
                    }
                }

                $valuacionValores .= ' ====> ' . ($decisionCumple ? 'true' : 'false');
                $decisionTomada = ['result' => $decisionCumple];

                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Evaluado condicional saliendo de \"{$flujo['actual']['nodoName']}\"";
                $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
                $bitacoraCoti->logType = 'condition';
                $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
                $bitacoraCoti->onlyPruebas = 1;
                $bitacoraCoti->dataInfo = $valuacionValores;
                $bitacoraCoti->save();

                // si es condición siempre salta al siguiente
                $autoSaltarASiguiente = true;
            }
            else if ($flujo['next']['typeObject'] === 'setuser') {

                if (!empty($flujo['next']['userAssign']['user'])) {

                    if ($flujo['next']['userAssign']['user'] === '_PREV_') {
                        $user = User::where('marcaId', $item->marcaId)->where('id', $item->usuarioIdAsignadoPrevio)->first();
                    }
                    else if ($flujo['next']['userAssign']['user'] === '_ORI_') {
                        $user = User::where('marcaId', $item->marcaId)->where('id', $item->usuarioId)->first();
                    }
                    else {
                        $user = User::where('marcaId', $item->marcaId)->where('id', $flujo['next']['userAssign']['user'])->first();
                    }

                    if (!empty($user)) {
                        $item->usuarioIdAsignadoPrevio = $item->usuarioIdAsignado;
                        $item->usuarioIdAsignado = $user->id;
                        $item->nodoActual = $flujo['next']['nodoId'];
                        $item->save();
                    }
                    else {
                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
                        $bitacoraCoti->logType = 'userAsig';
                        $bitacoraCoti->log = "Error de asignación a usuario, el usuario no se encuentra o es inválido";
                        $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
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
                  } */
                else if (!empty($flujo['next']['userAssign']['variable'])){
                    $variable = str_replace("{{", '', str_replace("}}", '', $flujo['next']['userAssign']['variable']));
                    $valorDetalle = CotizacionDetalle::where('campo', $variable)->where('cotizacionId', $item->id)->first();
                    $user = User::where('id', $valorDetalle->valorLong)->first();

                    if (!empty($user)) {
                        $item->usuarioIdAsignadoPrevio = $item->usuarioIdAsignado;
                        $item->usuarioIdAsignado = $user->id;
                        $item->nodoActual = $flujo['next']['nodoId'];
                        $item->save();
                    }
                    else {
                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
                        $bitacoraCoti->logType = 'userAsig';
                        $bitacoraCoti->log = "Error de asignación a usuario, el usuario no se encuentra o es inválido";
                        $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
                        $bitacoraCoti->save();
                    }
                }
                else {
                    if (!empty($flujo['next']['userAssign']['role']) || !empty($flujo['next']['userAssign']['group'])) {

                        $userIdAsignar = 0;
                        $usersToAssign = [];
                        $roles = '';

                        // roles por grupo
                        if (!empty($flujo['next']['userAssign']['group'])) {

                            $rolId = [];
                            $strQueryFull = "SELECT GU.*
                                                FROM usersGroupRoles AS GU
                                                GU.marcaId = '{$item->marcaId}'
                                                WHERE GU.userGroupId = '{$flujo['next']['userAssign']['group']}'";
                            $usuariosTmp = DB::select(DB::raw($strQueryFull));

                            foreach ($usuariosTmp as $tmp) {
                                $rolId[] = $tmp->rolId;
                            }

                            $roles = implode(', ', $rolId);
                        }

                        // rol individual
                        if (!empty($flujo['next']['userAssign']['role'])) {
                            $roles = ($roles === '' ? $flujo['next']['userAssign']['role'] :( $roles . ", {$flujo['next']['userAssign']['role']}"));
                        }


                        if (!empty($roles)) {
                            $strQueryFull = "SELECT U.id
                                            FROM users AS U
                                            JOIN user_rol AS UR ON U.id = UR.userId
                                            WHERE
                                                U.marcaId = '{$item->marcaId}'
                                                AND UR.rolId IN ({$roles})
                                            AND U.fueraOficina = 0";

                            $usuariosTmp = DB::select(DB::raw($strQueryFull));
                            foreach ($usuariosTmp as $tmp) {
                                $usersToAssign[] = $tmp->id;
                            }
                        }
                        $usersToFind = implode(', ', $usersToAssign);

                        // búsqueda de datos para usuario
                        $strQueryFull = "SELECT C.id, C.usuarioIdAsignado
                                        FROM cotizaciones AS C
                                        WHERE 
                                            C.marcaId = '{$item->marcaId}'
                                        AND C.usuarioIdAsignado IN ({$usersToFind})
                                        AND C.dateExpire IS NULL
                                        AND C.estado <> 'cancelado'
                                        AND C.estado <> 'finalizado'";

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
                            }
                            else {
                                $userIdAsignar = $usersToAssign[0] ?? 0;
                            }

                        }
                        else if ($flujo['next']['userAssign']['setuser_method'] === 'random') {
                            if (count($cotizacionesConteo) === 0) {
                                $cotizacionesConteo[] = $usersToAssign[0] ?? 0;
                            }
                            $userIdAsignar = array_rand($cotizacionesConteo);
                        }
                        else if ($flujo['next']['userAssign']['setuser_method'] === 'order') {

                            $lastUserAsig = 0;
                            $UserAsig = 0;
                            $lastUser = OrdenAsignacion::where('productoId', $item->productoId)->first();
                            if (!empty($lastUser)) $lastUserAsig = $lastUser->userId;


                            $userDetected = false;
                            foreach ($usersToAssign as $userTmp) {
                                if (empty($lastUserAsig) || $userDetected) {
                                    $UserAsig = $userTmp;
                                    break;
                                }
                                else {
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
                            $lastUser->save();
                        }

                        if (!empty($userIdAsignar)) {
                            $item->usuarioIdAsignadoPrevio = $item->usuarioIdAsignado;
                            $item->usuarioIdAsignado = $userIdAsignar;
                            $item->nodoActual = $flujo['next']['nodoId'];
                            $item->save();
                        }
                        else {
                            $bitacoraCoti = new CotizacionBitacora();
                            $bitacoraCoti->cotizacionId = $item->id;
                            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                            $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
                            $bitacoraCoti->logType = 'userAsig';
                            $bitacoraCoti->log = "Error al asignar usuario, no existe ningún usuario que cumpla la asignación";
                            $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
                            $bitacoraCoti->save();
                        }
                    }
                }

                $user = User::where('id', $item->usuarioIdAsignado)->first();
                // dd($user);

                // Guardo la bitácora
                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Asignación de usuario \"".($user->name ?? 'Sin nombre')."\"";
                $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
                $bitacoraCoti->logType = 'userAsig';
                $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
                $bitacoraCoti->save();

                // se recalcula el flujo
                $autoSaltarASiguiente = true;
                $decisionTomada = true;
            }
            else if ($flujo['next']['typeObject'] === 'output') {

                // Guardo la bitácora
                $bitacoraCoti = new CotizacionBitacora();
                $bitacoraCoti->cotizacionId = $item->id;
                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                $bitacoraCoti->log = "Salida de datos \"{$flujo['actual']['nodoName']}\" -> \"{$flujo['next']['nodoName']}\"";
                $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
                $bitacoraCoti->logType = 'output';
                $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
                $bitacoraCoti->save();
                // Si es pdf
                if (!empty($flujo['next']['salidaIsPDF'])) {
                    if (!empty($flujo['next']['salidaPDFDocsTk'])) {
                        $payGatewayAPIKEY = SistemaVariable::where('slug', 'API_PAYGATEWAY')->where('marcaId', $item->marcaId)->first();
                        if (!empty($payGatewayAPIKEY)) {
                            $headers = array(
                                'Content-Type: application/json',
                                'Authorization: Bearer '.$payGatewayAPIKEY->contenido
                            );

                            $dataSend = [];
                            $dataSend['token'] = $flujo['next']['salidaPDFDocsTk'];
                            $dataSend['operation'] = 'generate';
                            $dataSend['response'] = 'url';
                            $dataSend['data'] = [];
                            foreach ($item->campos as $campo) {
                                if ($campo->tipo === 'text' ||
                                    $campo->tipo === 'option' ||
                                    $campo->tipo === 'select' ||
                                    $campo->tipo === 'textArea' ||
                                    $campo->tipo === 'default'||
                                    $campo->tipo === 'number' ||
                                    $campo->tipo === 'date'
                                ) {
                                    $dataSend['data'][$campo->campo] = $campo->valorLong;
                                }

                                if($campo->tipo === 'signature'){
                                    if(empty($campo->valorLong)) continue;
                                    $dataSend['data'][$campo->campo] = Storage::disk('s3')->temporaryUrl($campo->valorLong, now()->addMinutes(80));
                                }

                                if($campo->tipo === 'file' && !empty($campo->valorLong)){
                                    if(empty($dataSend['data'][$campo->campo])){
                                        $dataSend['data'][$campo->campo] = [];
                                    }
                                    $dataSend['data'][$campo->campo][]= Storage::disk('s3')->temporaryUrl($campo->valorLong, now()->addMinutes(80));
                                }

                                if($campo->tipo === 'audio' && !empty($campo->extraData)){
                                    $dataSend['data'][$campo->campo]= $campo->extraData ?? '';
                                }

                                if(empty($campo->tipo)){
                                    $dataSend['data'][$campo->campo] = $campo->valorLong;
                                }

                                if($campo->tipo === 'checkbox' || $campo->tipo === 'multiselect' || $campo->tipo === 'tags'){
                                    if (empty($campo->valorLong)) continue;
                                    if(!is_array($campo->valorLong)) $campo->valorLong = json_decode($campo->valorLong, true);
                                    if(!is_array($campo->valorLong)) continue;

                                    $tmpList = '';
                                    if (is_array($campo->valorLong)) {
                                    $tmpList = '<ul>';
                                        foreach ($campo->valorLong as $key => $value) {
                                            $tmpList .= "<li>$value</li>";
                                        }
                                        $tmpList .= '</ul>';
                                    }
                                    $dataSend['data']["{$campo->campo}_LIST"] = $tmpList;
                                    $dataSend['data'][$campo->campo] = implode(", ", $campo->valorLong);
                                }
                            }
                            /*var_dump($dataSend);
                            die();*/

                            $ch = curl_init(env('PAYGATEWAY_API_URL', '').'/formularios/docs-plus/generate');
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $data = curl_exec($ch);
                            $info = curl_getinfo($ch);
                            curl_close($ch);
                            $dataResponse = @json_decode($data, true);
                            if (empty($dataResponse['status'])){
                                // Guardo la bitácora
                                $bitacoraCoti = new CotizacionBitacora();
                                $bitacoraCoti->cotizacionId = $item->id;
                                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
                                $bitacoraCoti->logType = 'error';
                                $bitacoraCoti->log = "Error al crear PDF, verifique sus credenciales de acceso o el token de plantilla";
                                $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
                                $bitacoraCoti->save();
                            }
                            else {
                                if (!empty($dataResponse['data']['url'])) {

                                    $tmpFile = storage_path("tmp/" . md5(uniqid()) . ".pdf");
                                    file_put_contents($tmpFile, file_get_contents($dataResponse['data']['url']));

                                    $dir = '';
                                    $marcaToken = $item->marca->token ?? false;
                                    if (empty($marcaToken)) {
                                        return $this->ResponseError('T-15', 'Error al subir archivo, marca inválida');
                                    }
                                    $dir = "{$marcaToken}/{$item->token}";

                                    $disk = Storage::disk('s3');
                                    $path = $disk->putFileAs($dir, $tmpFile, "{$flujo['next']['salidaPDFId']}.pdf");
                                    if (file_exists($tmpFile)) unlink($tmpFile);
                                    //$temporarySignedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(10));

                                    // Guardo la bitácora
                                    $bitacoraCoti = new CotizacionBitacora();
                                    $bitacoraCoti->cotizacionId = $item->id;
                                    $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                    $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                                    $bitacoraCoti->logType = 'pdf';
                                    $bitacoraCoti->log = "Archivo PDF generado con éxito, token DocsPlus: {$flujo['next']['salidaPDFDocsTk']}";
                                    $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                                    $bitacoraCoti->save();

                                    $campoSalida = CotizacionDetalle::where('campo', $flujo['next']['salidaPDFId'])->where('cotizacionId', $item->id)->first();
                                    if (empty($campoSalida)) {
                                        $campoSalida = new CotizacionDetalle();
                                    }
                                    $campoSalida->cotizacionId = $item->id;
                                    $campoSalida->seccionKey = 0;
                                    $campoSalida->campo = $flujo['next']['salidaPDFId'];
                                    $campoSalida->label = $flujo['next']['salidaPDFLabel'] ?? 'Archivo sin nombre';
                                    $campoSalida->valorLong = $path;
                                    $campoSalida->isFile = true;
                                    $campoSalida->fromSalida = true;
                                    $campoSalida->save();
                                }
                            }
                        }
                    }
                }

                $item->refresh();

                if (!empty($flujo['next']['salidaIsEmail']) && $artificio) {

                    // dd($flujo['next']);

                    $destino = (!empty($flujo['next']['procesoEmail']['destino'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['next']['procesoEmail']['destino']) : false;
                    $asunto = (!empty($flujo['next']['procesoEmail']['asunto'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['next']['procesoEmail']['asunto']) : false;

                    $arrCopias = [];
                    $arrCopiasTMP = (!empty($flujo['next']['procesoEmail']['copia'])) ? $flujo['next']['procesoEmail']['copia'] : [];
                    foreach ($arrCopiasTMP as $key => $copia) {
                        $copia['destino'] = trim($copia['destino']);
                        if (!empty($copia['destino'])) {
                            $arrCopias[] = $this->reemplazarValoresSalida($item->campos, $copia['destino']);
                        }
                    }

                    // reemplazo plantilla
                    $contenido = $flujo['next']['procesoEmail']['salidasEmail'] ?? '';
                    $contenido = $this->reemplazarValoresSalida($item->campos, $contenido);

                    $attachments = $flujo['next']['procesoEmail']['attachments'] ?? false;

                    $attachmentsSend = [];
                    if ($attachments) {
                        $attachments = explode(',', $attachments);

                        foreach ($attachments as $attach) {
                            $campoTmp = CotizacionDetalle::where('campo', trim($attach))->where('cotizacionId', $item->id)->first();

                            if (!empty($campoTmp) && !empty($campoTmp['valorLong'])) {
                                $ext = pathinfo($campoTmp['valorLong'] ?? '', PATHINFO_EXTENSION);
                                $s3_file = Storage::disk('s3')->get($campoTmp['valorLong']);
                                $attachmentsSend[] = ['fileContent' => $s3_file, 'filename' => ($campoTmp['label'] ?? 'Sin nombre') . '.' . $ext];
                            }
                        }
                    }

                    $mailgun = $this->GetBrandConfig($item->marcaId, 'mailgun');

                    $domainSalida = $mailgun['MAILGUN_DEFAULT_DOMAIN'] ?? env('MAILGUN_DEFAULT_DOMAIN');
                    $from = $mailgun['MAILGUN_DEFAULT_SENDER'] ?? env('MAILGUN_DEFAULT_SENDER');

                    try {

                        $mg = Mailgun::create($mailgun['MAILGUN_SEND_API_KEY'] ?? env('MAILGUN_SEND_API_KEY')); // For US servers
                        $arrConfig = [
                            'from' => $from,
                            'to' => $destino ?? '',
                            'subject' => $asunto ?? '',
                            'html' => (!empty($contenido) ? $contenido : '<br/>'),
                            'attachment' => $attachmentsSend
                        ];

                        if (count($arrCopias) > 0) {
                            $arrConfig['cc'] = $arrCopias;
                        }
                        //dd($arrConfig);
                        $email = $mg->messages()->send($domainSalida, $arrConfig);
                        $tmpCopias = implode(',', $arrCopias);

                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                        $bitacoraCoti->logType = 'email';
                        $bitacoraCoti->log = "Enviado correo electrónico \"{$destino}\", copias: {$tmpCopias}";
                        $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                        $bitacoraCoti->save();
                        // return $this->ResponseSuccess( 'Si tu cuenta existe, llegará un enlace de recuperación');
                    }
                    catch (HttpClientException $e) {
                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                        $bitacoraCoti->logType = 'error';
                        $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                        $bitacoraCoti->log = "Error al enviar correo electrónico \"{$destino}\" desde \"{$from}\", dominio de salida: {$domainSalida}";
                        $bitacoraCoti->save();
                        // return $this->ResponseError('AUTH-RA94', 'Error al enviar notificación, verifique el correo o la configuración del sistema');
                    }
                }

                if (!empty($flujo['next']['salidaIsSMS'])) {

                    $payGatewayAPIKEY = SistemaVariable::where('slug', 'API_PAYGATEWAY')->where('marcaId', $item->marcaId)->first();
                    if (!empty($payGatewayAPIKEY)) {
                        $autoShortLink = (!empty($flujo['next']['salidaIsSMS']) ? 1 : 0);
                        $headers = array(
                            'Content-Type: application/json',
                            'Authorization: Bearer '.$payGatewayAPIKEY->contenido
                        );

                        $numbersSend = (!empty($flujo['next']['salidaSmsNum'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['next']['salidaSmsNum']) : '';
                        $numerosSalida = explode(',', $numbersSend);


                        $messageSend = (!empty($flujo['next']['salidaSmsMsg'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['next']['salidaSmsMsg']) : '';

                        foreach ($numerosSalida as $numero) {

                            $dataSend = [];
                            $dataSend['to'] = trim($numero);
                            $dataSend['message'] = $messageSend;
                            $dataSend['autoShortUrl'] = $autoShortLink;
                            $dataSend['api'] = 'cmv';

                            $ch = curl_init(env('PAYGATEWAY_API_URL', '').'/communications/send/single-sms');
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $data = curl_exec($ch);
                            $info = curl_getinfo($ch);
                            curl_close($ch);
                            $dataResponse = @json_decode($data, true);

                            if (empty($dataResponse['status'])){
                                // Guardo la bitácora
                                $bitacoraCoti = new CotizacionBitacora();
                                $bitacoraCoti->cotizacionId = $item->id;
                                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                $bitacoraCoti->log = "Error al enviar SMS, verifique sus credenciales de acceso o integración SMS";
                                $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                                $bitacoraCoti->logType = 'error';
                                $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                                $bitacoraCoti->save();
                            }
                            else {
                                $bitacoraCoti = new CotizacionBitacora();
                                $bitacoraCoti->cotizacionId = $item->id;
                                $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                                $bitacoraCoti->log = "Enviado SMS a número: {$dataSend['to']}, mensaje: {$messageSend}";
                                $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                                $bitacoraCoti->logType = 'sms';
                                $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                                $bitacoraCoti->save();
                            }
                        }
                    }
                }

                if (!empty($flujo['next']['salMAlerts'])) {

                    $whatsappKey = SistemaVariable::where('slug', 'ISOFT_KEY')->where('marcaId', $item->marcaId)->first();
                    $whatsappNumber = SistemaVariable::where('slug', 'ISOFT_NUMBER')->where('marcaId', $item->marcaId)->first();

                    if (!empty($whatsappKey->contenido)) {

                        $headers = array(
                            'Content-Type: application/json',
                            'Authorization: '.$whatsappKey->contenido
                        );

                        $smsAlert = Alerta::where('marcaId', $item->marcaId)->where('id', $flujo['next']['salMAlertsId'])->first();
                        $smsAlertData = @json_decode($smsAlert->configData, true);
                        //dd($smsAlertData);

                        $dataSend = [];
                        if ($smsAlertData['waTpl'] === 'TPL1') {
                            $dataSend = [
                                'waBusinessNumber' => $whatsappNumber->contenido ?? '',
                                'waClientNumber' => $this->reemplazarValoresSalida($item->campos, $smsAlertData['waTplC']['fields']['phone']['value'] ?? ''),
                                'idTemplate' => 'c7b01c65-e2b3-40dc-8473-d6cad043bae5',
                                'createCase' => '0',
                                'idSkill' => '0',
                                'idUser' => '0',
                                'args' => [
                                    $this->reemplazarValoresSalida($item->campos, $smsAlertData['waTplC']['fields']['nombre']['value'] ?? ''),
                                    $this->reemplazarValoresSalida($item->campos, $smsAlertData['waTplC']['fields']['tareaId']['value'] ?? ''),
                                    $this->reemplazarValoresSalida($item->campos, $smsAlertData['waTplC']['fields']['url']['value'] ?? '')
                                ],
                            ];
                        }

                        $ch = curl_init(env('ISOFT_API_URL'));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        $data = curl_exec($ch);
                        //$info = curl_getinfo($ch);
                        curl_close($ch);
                        $dataResponse = @json_decode($data, true);

                        if (empty($dataResponse['code'])){
                            // Guardo la bitácora
                            $bitacoraCoti = new CotizacionBitacora();
                            $bitacoraCoti->cotizacionId = $item->id;
                            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                            $bitacoraCoti->log = "Error al enviar WhatsApp, verifique sus credenciales de acceso o integración";
                            $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                            $bitacoraCoti->logType = 'error';
                            $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                            $bitacoraCoti->save();
                        }
                        else {
                            $bitacoraCoti = new CotizacionBitacora();
                            $bitacoraCoti->cotizacionId = $item->id;
                            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                            $bitacoraCoti->log = "Enviado Whatsapp a número: {$dataSend['waClientNumber']}";
                            $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                            $bitacoraCoti->logType = 'wasa';
                            $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                            $bitacoraCoti->save();
                        }
                    }
                }

                // salto automático para outputs
                if (!empty($flujo['next']['saltoAutomatico']) && empty($flujo['next']['salidaIsHTML'])) {
                    $autoSaltarASiguiente = true;
                }

                /*if (!empty($flujo['next']['salidaIsWhatsapp'])) {
                    $whatsappToken = $flujo['next']['procesoWhatsapp']['token'] ?? '';
                    $whatsappUrl = $flujo['next']['procesoWhatsapp']['url'] ?? '';
                    $whatsappAttachments = $flujo['next']['procesoWhatsapp']['attachments'] ?? '';

                    $whatsappData = (!empty($flujo['next']['procesoWhatsapp']['data'])) ? $this->reemplazarValoresSalida($item->campos, $flujo['next']['procesoWhatsapp']['data']) : false;

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
                    $server_output = @json_decode($server_output, true);
                    // dd($server_output);
                    curl_close($ch);

                    if (empty($server_output['success'])) {
                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->onlyPruebas = 1;
                        $bitacoraCoti->log = "Error al enviar WhatsApp: {$whatsappData}";
                        $bitacoraCoti->save();
                    }
                    else {
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->log = "Enviado WhatsApp con éxito";
                        $bitacoraCoti->save();
                    }
                }*/
            }
            else if ($flujo['next']['typeObject'] === 'ocr') {

                $payGatewayAPIKEY = SistemaVariable::where('slug', 'API_PAYGATEWAY')->where('marcaId', $item->marcaId)->first();

                if (!empty($payGatewayAPIKEY)) {
                    $headers = array(
                        'Content-Type: application/json',
                        'Authorization: Bearer '. $payGatewayAPIKEY->contenido
                    );

                    $dataSend = [
                        "process"=>"auto",
                        "pageSeparator"=> 1,
                        "removePages"=> 1,
                        "htmlEndlines"=> 0,
                        "noReturnEndlines"=> 0,
                        "includeText"=> 0,
                        "detectQRBar"=> 0,
                        "encodingFrom"=> 0,
                        "encodingTo"=> 0
                    ];

                    $dataSend['templateToken'] =  $this->reemplazarValoresSalida($item->campos, $flujo['next']['procesos'][0]['templateToken']);
                    $dataSend['filepath'] = $this->reemplazarValoresSalida($item->campos, $flujo['next']['procesos'][0]['filepath']);

                    $ch = curl_init(env('PAYGATEWAY_API_URL', '').'/formularios/docs-plus/ocr');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    $data = curl_exec($ch);
                    $info = curl_getinfo($ch);
                    curl_close($ch);
                    $resultado = @json_decode($data, true);

                    $dataLog = "<h5>Data recibida</h5> <br> " . json_encode($resultado);
                    if (empty($resultado['status'])) {
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->onlyPruebas = 1;
                        $bitacoraCoti->dataInfo = $dataLog;
                        $bitacoraCoti->log = "Error ejecutando proceso de ocr. Saliendo de \"{$flujo['actual']['nodoName']}\" ";
                        $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
                        $bitacoraCoti->logType = 'error';
                        $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
                        $bitacoraCoti->save();
                        return $this->ResponseError('COCR-001', "Ha ocurrido realizando el proceso de envío de datos. {$resultado['msg']}");
                    }
                    else {
                        if(!empty($resultado['data']) && !empty($resultado['data']['tokens'])) {
                            if(!empty($resultado['data']['tokens']['pages'])){
                                foreach($resultado['data']['tokens']['pages'] as $optionvar){
                                    foreach($optionvar as $keyToken => $tokenOcr){

                                        foreach($tokenOcr as $ocrOption){
                                            $tokenOcrValue = new CotizacionesOcrTokens();
                                            $tokenOcrValue->cotizacionId = $item->id;
                                            $tokenOcrValue->nodoId = $flujo['next']['nodoId'];
                                            $tokenOcrValue->tipo = 'pages';
                                            $tokenOcrValue->tokenId = $keyToken;
                                            $tokenOcrValue->valorLong = $ocrOption;
                                            $tokenOcrValue->save();
                                        }
                                    }
                                }
                            }

                            if(!empty($resultado['data']['tokens']['tables'])){
                                foreach($resultado['data']['tokens']['tables'] as $tableName => $table){
                                    foreach($table['data'] as $keyrow => $row){
                                        foreach($row as $keyfield => $field){
                                            $tokenOcrValue = new CotizacionesOcrTokens();
                                            $tokenOcrValue->cotizacionId = $item->id;
                                            $tokenOcrValue->nodoId = $flujo['next']['nodoId'];
                                            $tokenOcrValue->tipo = 'tables';
                                            $tokenOcrValue->tokenId = $tableName;
                                            $tokenOcrValue->valorLong = $field;
                                            $tokenOcrValue->header = $keyfield;
                                            $tokenOcrValue->row = $keyrow;
                                            $tokenOcrValue->save();
                                        }
                                    }
                                }
                            }
                        }
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = $usuarioLogueadoId;
                        $bitacoraCoti->onlyPruebas = 1;
                        $bitacoraCoti->dataInfo = "<h5>URL:</h5> {$flujo['next']['procesos'][0]['url']} <br/><br/>" . $dataLog;
                        $bitacoraCoti->log = "Ejecutado proceso ocr saliendo de \"{$flujo['actual']['nodoName']}\"";
                        $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
                        $bitacoraCoti->logType = 'process';
                        $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
                        $bitacoraCoti->save();
                    }
                }
            }

            // Cambio el flujo al nodo next si existe
            if (!empty($flujo['next']['nodoId'])) {
                $item->nodoActual = $flujo['next']['nodoId'];
                $item->save();// Cambio el flujo al nodo next
            }
            else {
                $autoSaltarASiguiente = false;
            }
        }
        else if ($paso === 'prev') {

            if (!empty($item->nodoPrevio)) {
                if (empty($flujo['prev'])) {
                    return $this->ResponseSuccess('Tarea sin nodo previo', ['id' => $item->id, 'initial' => true]);
                }
                if ((!empty($flujo['prev']['procesos'][0]) && !empty($flujo['prev']['procesos'][0]['url']) || ($flujo['prev']['typeObject'] === 'condition'))) {
                    $autoSaltarASiguiente = true;
                }

                else if ($flujo['prev']['typeObject'] === 'setuser') {
                    $autoSaltarASiguiente = true;
                }

                // Cambio el flujo al nodo next
                $item->nodoActual = $flujo['prev']['nodoId'];
            }
            else {
                $item->nodoActual = $item->nodoPrevio;
            }

            $item->save();

            // Guardo la bitácora
            $bitacoraCoti = new CotizacionBitacora();
            $bitacoraCoti->cotizacionId = $item->id;
            $bitacoraCoti->usuarioId = $usuarioLogueadoId;
            $bitacoraCoti->log = "Regreso de paso \"{$flujo['actual']['nodoName']}\" -> \"{$flujo['prev']['nodoName']}\"";
            $bitacoraCoti->nodoName = $this->nodoLabel($flujo['actual']['nodoName'] ?? '');
            $bitacoraCoti->logType = 'back';
            $bitacoraCoti->nodoId = $flujo['actual']['id'] ?? null;
            $bitacoraCoti->save();
        }

        // expiración
        $expiracionTiempo = 0;
        $expiracionTipo = '';

        $notifyNoAtenTipo = '';
        $notifyNoAtenTiempo = 0;
        $notifyNoAtenAlertId = 0;

        if ($paso === 'next') {
            $expiracionTiempo = intval($flujo['next']['expiracionNodo'] ?? 0);
            $expiracionTipo = $flujo['next']['expiracionType'] ?? '';

            $notifyNoAtenTipo = $flujo['next']['noAttNType'] ?? '';
            $notifyNoAtenTiempo = intval($flujo['next']['noAttN'] ?? 0);
            $notifyNoAtenAlertId = $flujo['next']['noAttId'] ?? false;
        }
        else if ($paso === 'prev') {
            $expiracionTiempo = intval($flujo['prev']['expiracionNodo'] ?? 0);
            $expiracionTipo = $flujo['prev']['expiracionType'] ?? '';

            $notifyNoAtenTipo = $flujo['prev']['noAttNType'] ?? '';
            $notifyNoAtenTiempo = intval($flujo['prev']['noAttN'] ?? 0);
            $notifyNoAtenAlertId = $flujo['prev']['noAttId'] ?? false;
        }

        // fecha de expiración de arranque
        $fechaHoy = Carbon::now();
        $fechaNueva = null;
        if (!empty($expiracionTipo) && !empty($expiracionTiempo)) {

            if ($expiracionTipo === 'D') {
                $fechaNueva = $fechaHoy->addDays($expiracionTiempo)->format('Y-m-d H:i:s');
            }
            else if ($expiracionTipo === 'H') {
                $fechaNueva = $fechaHoy->addHours($expiracionTiempo)->format('Y-m-d H:i:s');
        }
            else if ($expiracionTipo === 'M') {
                $fechaNueva = $fechaHoy->addMinutes($expiracionTiempo)->format('Y-m-d H:i:s');
            }

            if (!empty($fechaNueva)) {
                $item->dateExpire = $fechaNueva;
                $item->save();
            }
        }

        // fecha de expiración de arranque
        $fechaHoy = Carbon::now();
        $fechaNuevaNotify = null;
        if (!empty($notifyNoAtenTipo) && !empty($notifyNoAtenTiempo) && !empty($notifyNoAtenAlertId)) {

            if ($notifyNoAtenTipo === 'D') {
                $fechaNuevaNotify = $fechaHoy->addDays($notifyNoAtenTiempo)->format('Y-m-d H:i:s');
            }
            else if ($notifyNoAtenTipo === 'H') {
                $fechaNuevaNotify = $fechaHoy->addHours($notifyNoAtenTiempo)->format('Y-m-d H:i:s');
            }
            else if ($notifyNoAtenTipo === 'M') {
                $fechaNuevaNotify = $fechaHoy->addMinutes($notifyNoAtenTiempo)->format('Y-m-d H:i:s');
            }
            if (!empty($fechaNuevaNotify)) {
                $item->dateNotifyNoAtention = $fechaNuevaNotify;
                $item->alertIdNotifyNoAtention = $notifyNoAtenAlertId;
                $item->countNotifyNoAtention = 0; // guarda como cero las notificaciones porque con cada cambio de etapa se reinician
                $item->save();
            }
        }

        if ($autoSaltarASiguiente) {
            if ($watchDog > 8) {
                return $this->ResponseError('TASK-WD', 'Error, recursión descontrolada, verifique su configuración');
            }
            $watchDog = $watchDog + 1; // watchdog
            return $this->CambiarEstadoCotizacion($request, true, $decisionTomada, $originalStep, $public, $artificio, $watchDog);
        }

        if ($item->save()) {
            return $this->ResponseSuccess('Tarea actualizada con éxito', ['id' => $item->id]);
        }
        else {
            return $this->ResponseError('TASK-016', 'Error al actualizar tarea, por favor intente de nuevo');
        }
    }

    public function CambiarEstadoCotizacionAuto(Request $request) {

        $token = $request->get('token');
        $tokenFlujo = $request->get('flujo');
        $campos = $request->get('campos');
        $newTask = false;
        $cotizacionNueva = null;

        if (empty($token)) {
            $item = Cotizacion::where([['token', '=', $token]])->first();

            if (empty($item)) {
                $requestTmp = new \Illuminate\Http\Request();
                $requestTmp->replace(['token' => $tokenFlujo]);
                $newTask = true;
                $cotizacionNueva = $this->IniciarCotizacion($requestTmp, true);
                //dd($cotizacionNueva);

                if (empty($cotizacionNueva['token'])) {
                    return $cotizacionNueva; // aqui viene siempre un json
                }

                if (!empty($cotizacionNueva['token'])) {
                    $token = $cotizacionNueva['token'];
                }
            }
            else {
                $token = $item->token;
            }
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

        if (method_exists($tmp, 'getData')) {
            $tmp = (array) ($tmp->getData() ?? []);
            $tmp['data'] = (array) ($tmp['data'] ?? []);
        }

        if ($newTask) {
            return $this->ResponseSuccess('Tarea iniciada con éxito', $cotizacionNueva);
        }
        else {
            if (empty($tmp['status'])) {
                return $this->ResponseError('TASK-421', 'Error al actualizar tarea');
            }
            else {
                if(!empty($tmp) && !empty($tmp['data']) && !empty($tmp['data']['actual']) &&  ($tmp['data']['actual']['typeObject'] === 'output')){
                    $data =  json_decode($tmp['data']['actual']['jsonwsReplaced'],true);
                    return $this->ResponseSuccess('Tarea actualizada con éxito', $data);
                }
                return $this->ResponseSuccess('Tarea actualizada con éxito');
            }
        }
    }

    public function getFlujoFromCotizacion($cotizacionObject) {

        $fromCache = false;
        if (empty($cotizacionObject)) {
            return $this->ResponseError('TASK-4211', 'Flujo inválido', [], false, false);
        }

        $cacheH = ClassCache::getInstance();
        $producto = $cotizacionObject->producto;

        if (empty($producto)) {
            return $this->ResponseError('TASK-4213', 'Producto no válido', [], false, false);
        }

        $flujo = $producto->flujo->first();

        if (empty($flujo)) {
            return $this->ResponseError('TASK-4212', 'Flujo no válido', [], false, false);
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);

        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-610', 'Error al interpretar flujo, por favor, contacte a su administrador', [], false, false);
        }

        return $this->ResponseSuccess(($fromCache ? 'From cache' : 'Ok'), $flujoConfig,false);
    }

    public function CalcularPasos(Request $request, $onlyArray = false, $public = false, $toggle = false, $cotizacionTmp = false) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        $cache = ClassCache::getInstance();

        if(!empty($cotizacionTmp)){
            $cotizacion = $cotizacionTmp;
        }
        else {
            $cotizacionId = $request->get('token');
            $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();
        }

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-632', 'Cotización no válida');
        }

        // Estados
        $estados = [];
        $producto = $cotizacion->producto;

        if (!$public && isset($producto->extraData) && $producto->extraData !== '') {
            $estados = json_decode($producto->extraData, true);
            $estados = $estados['e'] ?? [];

            // estados default
            $estados[] = 'expirada';
        }

        $flujoConfig = $this->getFlujoFromCotizacion($cotizacion);

        if (!$flujoConfig['status']) {
            return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
        }
        else {
            $flujoConfig = $flujoConfig['data'];
        }

        // El flujo se va a orientar en orden según un array
        $allFields = [];
        $allFieldsRepetible = [];
        $flujoOrientado = [];
        $flujoNoVisible = false;
        $flujoPrev = [];
        $flujoActual = [];
        $flujoNext = [];

        // dd($flujoConfig['nodes']);
        // usuario asignado, variables
        $userAsigTmp = User::where('id', $cotizacion->usuarioIdAsignado)->first();
        $userAsigTmpVars = (!empty($userAsigTmp->userVars) ? @json_decode($userAsigTmp->userVars, true) : false);

        $camposAll = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->get();

        $allCampos = [];
        $allCamposRepetible = [];
        foreach ($camposAll as $tmpItem) {

            if (!empty($tmpItem->repetibleId) && !empty($tmpItem->repetibleKey)) {
                $allCamposRepetible[$tmpItem->repetibleId][$tmpItem->repetibleKey][$tmpItem->campo] = $tmpItem;
            }
            else {
                $allCampos[$tmpItem->campo] = $tmpItem;
            }
        }

        $tmpUser = User::where('id', $cotizacion->usuarioId)->first();
        $grupoNombre = '';
        $tmpUserGrupo = UserGrupoUsuario::where('userId', $tmpUser->id ?? 0)->first();
        if (!empty($tmpUserGrupo)) {
            $grupoNombre = $tmpUserGrupo->grupo->nombre ?? '';
        }

        // variables de sistema
        /*if (!$public) {
            $tmpUserGrupo = SistemaVariable::all();
            foreach ($tmpUserGrupo as $varTmp) {
                $allFields[$varTmp->slug] = ['id' => $varTmp->slug, 'nombre' => '', 'valor' => $varTmp->contenido];
            }
        }*/

        // Variables defecto
        $allFields['FECHA_FORMULARIO'] = ['id' => 'FECHA_FORMULARIO', 'nombre' => '', 'valor' => Carbon::parse($cotizacion->dateCreated)->setTimezone('America/Guatemala')->toDateTimeString()];
        $allFields['FECHA_HOY'] = ['id' => 'FECHA_HOY', 'nombre' => '', 'valor' => Carbon::now()->toDateTimeString()];
        $allFields['FECHA_MODIFICACION'] = ['id' => 'FECHA_MODIFICACION', 'nombre' => '', 'valor' => Carbon::parse($cotizacion->dateUpdated)->setTimezone('America/Guatemala')->toDateTimeString()];

        // variables de usuario
        if (!$public) {

            $allFields['CREADOR_NOMBRE'] = ['id' => 'CREADOR_NOMBRE', 'nombre' => '', 'valor' => (!empty($tmpUser) ? $tmpUser->name : 'Sin nombre')];
            $allFields['CREADOR_CORP'] = ['id' => 'CREADOR_CORP', 'nombre' => '', 'valor' => (!empty($tmpUser) ? $tmpUser->corporativo : 'Sin corporativo')];
            $allFields['CREADOR_GRUPO'] = ['id' => 'CREADOR_GRUPO', 'nombre' => '', 'valor' => $grupoNombre];

            if (is_array($userAsigTmpVars)) {
                foreach ($userAsigTmpVars as $varTmp) {
                    $allFields[$varTmp['nombre']] = ['id' => $varTmp['nombre'], 'nombre' => '', 'valor' => $varTmp['valor'], 'ed' => ''];
                }
            }
        }

        // Recorro las lineas primero
        foreach ($flujoConfig['nodes'] as $nodo) {

            if (empty($nodo['typeObject'])) continue;

            // todos los campos
            foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                //$allFields[$keySeccion]['nombre'] = $seccion['nombre'];
                foreach ($seccion['campos'] as $campo) {

                    if (empty($campo['id'])) continue;

                    // $campoTmp = $camposAll->where('campo', $campo['id'])->first();

                    $campoTmp = $allCampos[$campo['id']] ?? false;

                    //dd($campoTmp);
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
                        'ed' => $campoTmp->extraData ?? ''
                    ];
                }
            }
            // dd($allFields);

            $lineasTemporalEntrada = [];
            $lineasTemporalSalida = [];
            $lineasTemporalSalidaDecision = ['si' => [], 'no' => [],];
            foreach ($flujoConfig['edges'] as $linea) {
                if ($linea['source'] === $nodo['id']) {
                    $lineasTemporalSalida[] = $linea['target'];

                    if ($linea['sourceHandle'] === 'salidaTrue') {
                        $lineasTemporalSalidaDecision['si'] = $linea['target'];
                    }
                    else if ($linea['sourceHandle'] === 'salidaFalse') {
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
                'estOut' => $nodo['estOut'] ?? 'Sin estado', // Estado out
                'cmT' => $nodo['cmT'] ?? '', // Comentarios Tipo
                'expiracionNodo' => $nodo['expiracionNodo'] ?? false,
                'expiracionType' => $nodo['expiracionType'] ?? '',
                'noAttNType' => $nodo['noAttNType'] ?? false,
                'noAttN' => $nodo['noAttN'] ?? '',
                'noAttId' => $nodo['noAttId'] ?? '',
                'nodoName' => $nodo['nodoName'],
                'type' => $nodo['type'],
                'label' => $nodo['label'] ?? '',
                'formulario' => $nodo['formulario'] ?? [],
                'btnText' => [
                    'prev' => $nodo['btnTextPrev'] ?? '',
                    'next' => $nodo['btnTextNext'] ?? '',
                    'finish' => $nodo['btnTextFinish'] ?? '',
                    'cancel' => $nodo['btnTextCancel'] ?? '',
                ],
                'btnS' => [
                    'n' => $nodo['btnLNext'] ?? '',
                    'p' => $nodo['btnLPrev'] ?? '',
                    'f' => $nodo['btnLFinish'] ?? '',
                    'c' => $nodo['btnLCancel'] ?? '',
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
                'setuser_method' => $nodo['setuser_method'] ?? [],
            ];
            $flujoOrientado[$nodo['id']]['expiracionNodo'] = $nodo['expiracionNodo'] ?? false;
            $flujoOrientado[$nodo['id']]['expiracionType'] = $nodo['expiracionType'] ?? '';
            $flujoOrientado[$nodo['id']]['procesos'] = $nodo['procesos'];
            $flujoOrientado[$nodo['id']]['decisiones'] = $nodo['decisiones'];
            $flujoOrientado[$nodo['id']]['salidas'] = $nodo['salidas'];
            $flujoOrientado[$nodo['id']]['salidaIsPDF'] = $nodo['salidaIsPDF'] ?? false;
            $flujoOrientado[$nodo['id']]['salidaIsSMS'] = $nodo['salidaIsSMS'] ?? false;
            $flujoOrientado[$nodo['id']]['salMAlerts'] = $nodo['salMAlerts'] ?? false;
            $flujoOrientado[$nodo['id']]['salMAlertsId'] = $nodo['salMAlertsId'] ?? false;
            $flujoOrientado[$nodo['id']]['salidaSMSstl'] = $nodo['salidaSMSstl'] ?? false;
            $flujoOrientado[$nodo['id']]['salidaIsHTML'] = $nodo['salidaIsHTML'] ?? false;
            $flujoOrientado[$nodo['id']]['salidaIsEmail'] = $nodo['salidaIsEmail'];
            $flujoOrientado[$nodo['id']]['salidaIsWhatsapp'] = $nodo['salidaIsWhatsapp'];
            $flujoOrientado[$nodo['id']]['procesoWhatsapp'] = $nodo['procesoWhatsapp'];
            $flujoOrientado[$nodo['id']]['procesoEmail'] = $nodo['procesoEmail'];
            $flujoOrientado[$nodo['id']]['roles_assign'] = $nodo['roles_assign'];
            $flujoOrientado[$nodo['id']]['tareas_programadas'] = $nodo['tareas_programadas'];
            $flujoOrientado[$nodo['id']]['pdfTpl'] = $nodo['pdfTpl'] ?? [];
            $flujoOrientado[$nodo['id']]['salidaPDFId'] = $nodo['salidaPDFId'] ?? '';
            $flujoOrientado[$nodo['id']]['salidaPDFLabel'] = $nodo['salidaPDFLabel'] ?? '';
            $flujoOrientado[$nodo['id']]['salidaPDFDocsTk'] = $nodo['salidaPDFDocsTk'] ?? '';
            $flujoOrientado[$nodo['id']]['salidaSmsNum'] = $nodo['salidaSmsNum'] ?? '';
            $flujoOrientado[$nodo['id']]['salidaSmsMsg'] = $nodo['salidaSmsMsg'] ?? '';
            $flujoOrientado[$nodo['id']]['saltoAutomatico'] = $nodo['saltoAutomatico'] ?? '';
            $flujoOrientado[$nodo['id']]['tablasOcrCampos'] = $nodo['tablasOcrCampos'] ?? [];
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
                    }
                    else {
                        return $this->ResponseError('TASK-048', 'El flujo se encuentra mal configurado, existen dos nodos de entrada');
                    }
                }
            }
        }
        else {
            foreach ($flujoOrientado as $nodo) {
                if ($nodo['nodoId'] === $cotizacion->nodoActual) {
                    $flujoActual = $nodo;
                }
            }
        }

        if (empty($flujoActual)) {
            return $this->ResponseError('TASK-058', 'Esta cotización no puede visualizarse, ha cambiado o se han eliminado etapas');
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

        // repetibles
        $repetibleInsertBefore = [];

        // Se calculan los valores que se traen
        if (!empty($flujoActual['formulario']['secciones'])) {
            foreach ($flujoActual['formulario']['secciones'] as $keySeccion => $seccion) {

                $keySeccion = (string)$keySeccion;

                $flujoActual['formulario']['secciones'][$keySeccion]['seccionId'] = $keySeccion;

                //$camposList = $camposAll->where('seccionKey', $keySeccion);

                foreach ($seccion['campos'] as $keyCampo => $campo) {

                    //$campoTmp = $camposList->where('campo', $campo['id'])->first();

                    // defaults
                    if (empty($flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMax'])) $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMax'] = 150;

                    // Reemplazo de parámetros de campo
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['requerido'] = (empty($flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['requerido'] || $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['requerido'] === "false") ? 0 : 1);
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['ph'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['ph'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['ttp'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['ttp'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['desc'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['desc'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['nombre'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['nombre'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMax'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMax'] ?? '');
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMin'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['longitudMin'] ?? '');

                    if (isset($flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['proceso'])) {
                        unset($flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['proceso']);
                    }

                    // si es audio
                    if ($campo['tipoCampo'] === 'audio') {
                        $valorTmp = $allFields[$campo['id']]['valor'] ?? '';
                        if (!empty($valorTmp)) {
                            $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($valorTmp, now()->addMinutes(60));
                            $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['urlTmp'] = $temporarySignedUrl;
                        }
                    }

                    if (!empty($allFields[$campo['id']]['ed'])) {
                        $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['exd'] = $allFields[$campo['id']]['ed'];
                    }

                    /*if (!empty($campoTmp) && !empty($campoTmp->valorLong)) {
                        $tmpJson = @json_decode($campoTmp->valorLong, true);

                        $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['valor'] = (!empty($tmpJson) ? $tmpJson : $campoTmp->valorLong);
                    }*/

                    if (!empty($campo['repetible']) && !empty($campo['repetibleId'])) {
                        if (!empty($allCamposRepetible[$campo['repetibleId']])) {
                            $repetibleInsertBefore[$campo['repetibleId']] = $keyCampo;
                        }
                    }

                    // procesa los por defecto
                    $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['valor'] = $this->reemplazarValoresSalida($allFields, $flujoActual['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['valor'] ?? '');
                }
            }
        }

        /*var_dump($repetibleInsertBefore);
        die;*/
        // var_dump($allCamposRepetible);

        // Repetibles
        if (!empty($flujoActual['formulario']['secciones'])) {
            foreach ($flujoActual['formulario']['secciones'] as $keySeccion => $seccion) {

                $arrFieldsTmp = [];

                foreach ($seccion['campos'] as $keyCampo => $campo) {

                    // Valida si el campo coincide con el último de los repetibles (con ese agrupador) para insertarlo inmediatamente después
                    if (!empty($campo['repetible']) && !empty($campo['repetibleId']) && !empty($allCamposRepetible[$campo['repetibleId']])) {
                        foreach ($allCamposRepetible[$campo['repetibleId']] as $repetibleKey => $camposRep) {

                            if (!empty($camposRep["{$campo['id']}|{$repetibleKey}"])) {
                                $cotidetalle = $camposRep["{$campo['id']}|{$repetibleKey}"];
                                $clone = $campo;
                                $clone['id'] = $cotidetalle->campo;
                                $clone['valor'] = $cotidetalle->valorLong;
                                $clone['repetibleId'] = $cotidetalle->repetibleId;
                                $clone['repetibleKey'] = $cotidetalle->repetibleKey;
                                $arrFieldsTmp[$campo['repetibleId']][$repetibleKey][$clone['id']] = $clone;
                            }
                        }
                    }
                }

                $arrFieldsFinish = [];

                // insertamos el valor en en lugar que toca
                foreach ($seccion['campos'] as $keyCampo => $campo) {

                    // agregamos todos los campos en orden normal
                    $arrFieldsFinish[] = $campo;


                    if (!empty($campo['repetibleId']) && !empty($repetibleInsertBefore[$campo['repetibleId']]) && $repetibleInsertBefore[$campo['repetibleId']] === $keyCampo) {

                        foreach ($arrFieldsTmp[$campo['repetibleId']] as $valueRep) {
                            foreach ($valueRep as $repetibleF) {
                                $arrFieldsFinish[] = $repetibleF;
                            }
                        }
                        // $arrFieldsFinish = array_merge($arrFieldsFinish, $arrFieldsTmp);
                    }

                    /*if (!empty($campo['repetible']) && !empty($campo['repetibleId']) && !empty($allCamposRepetible[$campo['repetibleId']])) {

                    }*/

                }

                // var_dump($arrFieldsFinish);

                //var_dump($arrRepeat);
                $flujoActual['formulario']['secciones'][$keySeccion]['campos'] = $arrFieldsFinish;

                // array_splice( $flujoActual['formulario']['secciones'][$keySeccion]['campos'], $insertBefore, 0, $arrRepeat );

                // $flujoActual['formulario']['secciones'][$keySeccion]['campos'] = $camposNew;
            }
        }

        // dd($flujoActual);

        // Si es una salida, hay que procesar la salida con la data ya guardada
        if ($flujoActual['typeObject'] === 'output') {
            $dataToSend = $this->reemplazarValoresSalida($cotizacion->campos, $flujoActual['salidas']);
            $flujoActual['salidaReplaced'] = $dataToSend;

            if(!empty($flujoActual['saltoAutomatico']) && !empty($flujoActual['salidaIsHTML']) && !$toggle){
                $request->merge(['paso' => 'next']);
                $producto = $this->CambiarEstadoCotizacion($request, false, false, false, false);
            }

        }

        if($flujoActual['typeObject'] === 'ocr') {
            // pages
            $optionsOcr = CotizacionesOcrTokens::where('cotizacionId', $cotizacion->id)
                ->where('nodoId', $flujoActual['nodoId'])
                ->where('tipo', 'pages')
                ->get();
            $flujoActual['ocrOptions'] = [];
            $identificadorWs = $flujoActual['procesos'][0]['identificadorWs'];
            foreach ($optionsOcr as $opt) {
                if(empty($flujoActual['ocrOptions'][$opt->tokenId])) {
                    $campoTmp = $camposAll->where('campo', $identificadorWs .'.'. $opt->tokenId)->first();
                    $flujoActual['ocrOptions'][$opt->tokenId] = [
                        'id'=> $identificadorWs .'.'. $opt->tokenId,
                        'nombre' => $opt->tokenId,
                        'valor' => $campoTmp->valorLong ?? '',
                        'tipoCampo' => $campoTmp->tipo ?? '',
                        'options'=> []
                    ];
                }
                $flujoActual['ocrOptions'][$opt->tokenId]['options'][] = $opt->valorLong;
            }
            //tables
            $optionsOcrTables = CotizacionesOcrTokens::where('cotizacionId', $cotizacion->id)
                ->where('nodoId', $flujoActual['nodoId'])
                ->where('tipo', 'tables')
                ->orderBy('row', 'asc')
                ->get();

            if(count($optionsOcrTables) > 0){
                $flujoActual['ocrOptionsTables'] = [];
                $headersTable = [];
                foreach ($optionsOcrTables as $opt) {

                    //if ($opt['header'] === 'linea') continue;
                    $campoTmp = $camposAll->where('campo', $identificadorWs .'.'. $opt->tokenId .'.'. $opt->header .'.'. ($opt->row + 1))->first();
                    $flujoActual['ocrData'][$opt->tokenId][$opt->row][$opt->header] = [
                        'id'=> $identificadorWs .'.'. $opt->tokenId .'.'. $opt->header .'.'. ($opt->row + 1),
                        'nombre' => $opt->tokenId,
                        'valor' => $campoTmp->valorLong ?? $opt->valorLong,
                        'tipoCampo' => !empty($campoTmp->tipo)? $campoTmp->tipo : 'text',
                        'header' => $opt->header,
                        'row' => $opt->row,
                        //'options' => $opt->valorLong,
                    ];
                    if(empty($headersTable[$opt->tokenId])) $headersTable[$opt->tokenId] = [];
                    if(!in_array($opt->header, $headersTable[$opt->tokenId])) $headersTable[$opt->tokenId][] = $opt->header;
                    /* if(empty($flujoActual['ocrOptionsTables'][$opt->tokenId]))
                        $flujoActual['ocrOptionsTables'][$opt->tokenId] = ['header'=> [], 'data' => []];
                    if(!in_array($opt->header, $flujoActual['ocrOptionsTables'][$opt->tokenId]['header']))
                        $flujoActual['ocrOptionsTables'][$opt->tokenId]['header'][] = $opt->header;
                    if(empty($flujoActual['ocrOptionsTables'][$opt->tokenId]['data'][$opt->row])) $flujoActual['ocrOptionsTables'][$opt->tokenId]['data'][$opt->row] = [];
                    $campoTmp = $camposAll->where('campo', $identificadorWs .'.'. $opt->tokenId .'.'. $opt->header .'.'. ($opt->row + 1))->first();
                    $flujoActual['ocrOptionsTables'][$opt->tokenId]['data'][$opt->row][$opt->header] = [
                        'id'=> $identificadorWs .'.'. $opt->tokenId .'.'. $opt->header .'.'. ($opt->row + 1),
                        'nombre' => $opt->tokenId,
                        'valor' => $campoTmp->valorLong ?? $opt->valorLong,
                        'tipoCampo' => !empty($campoTmp->tipo)? $campoTmp->tipo : 'text',
                        'options' => $opt->valorLong,
                    ]; */
                }

                foreach ($flujoActual['ocrData'] as $tableId => $table) {
                    foreach ($table as $rowIndex => $row) {
                        foreach ($headersTable[$tableId] as $head) {
                            if(empty($row[$head])){
                                $campoTmp = $camposAll->where('campo', $identificadorWs .'.'. $tableId .'.'. $head .'.'. ($rowIndex + 1))->first();
                                $flujoActual['ocrData'][$tableId][$rowIndex][$head] = [
                                    'id'=> $identificadorWs .'.'. $tableId .'.'. $head .'.'. ($rowIndex + 1),
                                    'nombre' => $tableId,
                                    'valor' => $campoTmp->valorLong ?? '',
                                    'tipoCampo' => !empty($campoTmp->tipo)? $campoTmp->tipo : 'text',
                                    //'options' => '',
                                    'header' => $head,
                                    'row' => $rowIndex,
                                ];
                            }
                        }
                    }
                }

                //tablasOcrCampos
                foreach ($flujoActual['ocrData'] as $tableId => $table) {
                    foreach ($table as $rowIndex => $row) {
                        foreach ($flujoActual['tablasOcrCampos'] as $newColCampo) {
                            $campoTmp = $camposAll->where('campo', $identificadorWs .'.'. $tableId .'.'. $newColCampo['id'] .'.'. ($rowIndex + 1))->first();

                            $newColCampoMod = $newColCampo;
                            $newColCampoMod['id'] =  $identificadorWs .'.'. $tableId .'.'. $newColCampo['id'] .'.'. ($rowIndex + 1);
                            $newColCampoMod['nombre'] = $newColCampo['nombre'];
                            $newColCampoMod['valor'] = $campoTmp->valorLong ?? '';
                            $newColCampoMod['tipoCampo'] = 'ocrTableService';
                            $newColCampoMod['header'] = $newColCampo['nombre'];
                            $newColCampoMod['row'] = $rowIndex;
                            $newColCampoMod['options'] = [];

                            $optionsTmp = CotizacionDetalleCatalogo::where('cotizacionId', $cotizacion->id)
                                ->where('campo', $identificadorWs .'.'. $tableId .'.'. $newColCampo['id'] .'.'. ($rowIndex + 1))
                                ->get();
                            $option = [];
                            foreach($optionsTmp as $optmp){
                                $option[$optmp->valorKey] = $optmp->valorLong;
                            }
                            if(count($option) > 0) $newColCampoMod['options'][] = $option;
                            $flujoActual['ocrData'][$tableId][$rowIndex][$newColCampo['id']] = $newColCampoMod;
                        }
                    }
                }

                /*foreach ($flujoActual['ocrOptionsTables'] as $tableId => $table) {
                    foreach ($table['data'] as $rowIndex => $row) {
                        foreach ($table['header'] as $head) {
                            if(empty($row[$head])){
                                $campoTmp = $camposAll->where('campo', $identificadorWs .'.'. $tableId .'.'. $head .'.'. ($rowIndex + 1))->first();
                                $flujoActual['ocrOptionsTables'][$tableId]['data'][$rowIndex][$head] = [
                                    'id'=> $identificadorWs .'.'. $tableId .'.'. $head .'.'. ($rowIndex + 1),
                                    'nombre' => $tableId,
                                    'valor' => $campoTmp->valorLong ?? '',
                                    'tipoCampo' => !empty($campoTmp->tipo)? $campoTmp->tipo : 'text',
                                    'options' => '',
                                ];
                            }
                        }
                    }
                }

                //tablasOcrCampos
                foreach ($flujoActual['ocrOptionsTables'] as $tableId => $table) {

                    foreach ($flujoActual['tablasOcrCampos'] as $colCampo) {
                        $flujoActual['ocrOptionsTables'][$tableId]['header'][] = $colCampo['nombre'];
                        foreach ($table['data'] as $rowIndex => $row) {
                            $newColCampo = $colCampo;
                            $campoTmp = $camposAll->where('campo', $identificadorWs .'.'. $tableId .'.'. $newColCampo['id'] .'.'. ($rowIndex + 1))->first();
                            foreach($flujoActual['ocrOptionsTables'][$tableId]['header'] as $head){

                                foreach($newColCampo as $keycolcampo => $valuecolcampo){
                                    if(is_string($valuecolcampo)) {
                                        $newColCampo[$keycolcampo] = preg_replace(
                                        "/" . "{{table:col={$head}}}" . "/",
                                        "{{" . $identificadorWs .'.'. $tableId .'.'. $head .'.'. ($rowIndex + 1) . "}}",
                                        $valuecolcampo
                                        );
                                    }
                                    if(is_array($valuecolcampo)){
                                        foreach($valuecolcampo as $keyarray => $valuearray){
                                            if(is_string($valuearray)) {
                                                $newColCampo[$keycolcampo][$keyarray] = preg_replace(
                                                    "/" . "{{table:col={$head}}}" . "/",
                                                    "{{" . $identificadorWs .'.'. $tableId .'.'. $head .'.'. ($rowIndex + 1) . "}}",
                                                    $valuearray
                                                );
                                            }
                                        }
                                    }
                                }
                            }
                            $flujoActual['ocrOptionsTables'][$tableId]['data'][$rowIndex][$newColCampo['nombre']] = $newColCampo;
                            $flujoActual['ocrOptionsTables'][$tableId]['data'][$rowIndex][$newColCampo['nombre']]['id'] =  $identificadorWs .'.'. $tableId .'.'. $newColCampo['id'] .'.'. ($rowIndex + 1);
                            $flujoActual['ocrOptionsTables'][$tableId]['data'][$rowIndex][$newColCampo['nombre']]['valor'] = $campoTmp->valorLong ?? '';
                        }
                    }
                }*/

            }

            //enviar el formado imagen o pdf;
            $file = $this->reemplazarValoresSalida($cotizacion->campos, $flujoActual['procesos'][0]['filepath']);

            $type = '';
            $ext = explode('?', pathinfo($file, PATHINFO_EXTENSION));
            $ext = !empty($ext) && (count($ext)>0) ? $ext[0] : false;

            if ($ext == 'jpg' || $ext == 'jpeg' || $ext == 'png' || $ext == 'tiff' || $ext == 'gif') {
                $type = 'image';
            }
            else if ($ext == 'pdf') {
                $type = 'application';
            }
            $arrContextOptions=array(
                "ssl"=>array(
                    "verify_peer"=>false,
                    "verify_peer_name"=>false,
                ),
            );

            if (!empty($file) && !str_contains($file, '{{')) {
                $tmpFile = file_get_contents($file, false, stream_context_create($arrContextOptions));
                $dataFile = "data:{$type}/{$ext};base64," . base64_encode($tmpFile);
                $flujoActual['ocrFile'] = ['type' => $type, 'data' => $dataFile];
            }
        }

        $flujoTmp = Flujos::where('productoId', $cotizacion->productoId)->where('activo', 1)->first('modoPruebas');
        if (empty($flujoTmp)) {
            return $this->ResponseError('TASK-254', 'No existe ningún flujo activo para este producto');
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
                $bit->createdAt = Carbon::parse($bit->createdAt)->setTimezone('America/Guatemala')->toDateTimeString();
                $bit->makeHidden(['usuario']);

                $bitacoraView[] = $bit;
            }
        }

        // Salto el nodo ya que no corresponde a mi usuario
        $rolUsuarioLogueado = ($usuarioLogueado) ? $usuarioLogueado->rolAsignacion->rol : 0;
        $calcularVisibilidad = function ($flujo) use ($usuarioLogueadoId, $rolUsuarioLogueado, $public) {

            $hasConfigUsers = false;
            $usersDetalle = [];

            if (($public && $flujo['formulario']['tipo'] === 'publico') || (($public && $flujo['formulario']['tipo'] === 'mixto') || (!$public && $flujo['formulario']['tipo'] === 'mixto' && count($flujo['roles_assign']) > 1))) {
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

        $expiraDate = '';
        $expiro = false;

        if (!empty($cotizacion->dateExpire)) {
            $fechaHoy = Carbon::now();
            $fechaExpira = Carbon::parse($cotizacion->dateExpire);
            if ($fechaHoy->gt($fechaExpira)) {
                $expiro = true;
            }
            $expiraDate = $fechaExpira->format('d-m-Y');

            if ($AC->CheckAccess(['tareas/admin/operar-expirado'])) {
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
        ];


        // si es supervisor
        $visibilidad = false;
        $userHandler = new AuthController();
        if (!$public) {

            $CalculateAccess = $userHandler->CalculateAccess();

            if (in_array($usuarioLogueadoId, $CalculateAccess['sup'])) {
                $visibilidad = in_array($usuarioLogueadoId, $CalculateAccess['all']);
            }
            else {
                $visibilidad = in_array($usuarioLogueadoId, $CalculateAccess['det']);
            }
        }

        // si no tiene jerarquia, valida visibilidad de nodo
        if (!empty($flujoActual['formulario']['tipo']) && !$visibilidad) {
            $visibilidad = $calcularVisibilidad($flujoActual);
        }

        // acceso
        $cotizacionData['acc'] = $visibilidad;

        // si no es público
        if (!$public) {
            $usuarioAsig = $cotizacion->usuarioAsignado;

            if (!empty($usuarioAsig)) {
                $rolAsignado = $usuarioAsig->rolAsignacion->rol->name ?? 'N/D';
                $usuarioDesc = "";

                if ($AC->CheckAccess(['users/listar'])) {
                    $usuarioDesc = "{$usuarioAsig->name} ({$usuarioAsig->nombreUsuario})";
                }
                $cotizacionData['ed'] = "{$usuarioDesc}";
            }

            if (!$cotizacionData['acc']) {
                $cotizacionData['ed'] .= ', no posees acceso a este formulario.';
            }
        }
        else {
            $cotizacionData['ed'] = "";
        }

        // valido si es nodo de salida
        if ($onlyArray) {
            return ['actual' => $flujoActual, 'next' => $flujoNext, 'prev' => $flujoPrev, 'bit' => $bitacoraView, 'd' => $allFields, 'c' => $cotizacionData];
        }
        else {

            if ($public && $cotizacionData['acc']) {
                unset($flujoActual['nodosEntrada']);
                unset($flujoActual['userAssign']);
                unset($flujoActual['nodosEntrada']);
                unset($flujoActual['nodosSalida']);
                unset($flujoActual['nodosSalidaDecision']);
                unset($flujoActual['expiracionNodo']);
                unset($flujoActual['expiracionType']);
                unset($flujoActual['noAttId']);
                unset($flujoActual['noAttN']);
                unset($flujoActual['noAttNType']);
                unset($flujoActual['salidas']);
                unset($flujoActual['salidaPDFDocsTk']);
                unset($flujoActual['salidaIsPDF']);
                unset($flujoActual['salMAlerts']);
                unset($flujoActual['salMAlertsId']);
                unset($flujoActual['salidaIsSMS']);
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
                unset($flujoActual['tablasOcrCampos']);
            }

            if (!$cotizacionData['acc']) {
                $flujoActual = false;
            }

            return $this->ResponseSuccess('Flujo calculado con éxito', ['estado' => $cotizacion->estado, 'actual' => $flujoActual, 'next' => (count($flujoNext) > 0), 'prev' => (count($flujoPrev) > 0), 'bit' => $bitacoraView, 'd' => $allFields, 'c' => $cotizacionData, 'e' => $estados]);
        }
    }

    public function CalcularPasosPublic(Request $request) {
        return $this->CalcularPasos($request, false, true);
    }

    public function GetValueFromConn($slugVar, $data) {

        $value = explode('.', $slugVar);
        $deepCount = count($value);

        if ($deepCount === 1) {
            return $data;
        }

        $deepCount--;

        foreach ($value as $val) {

            $newSlug = str_replace("{$val}.", '', $slugVar);

            if ($val === 'x') {
                $response = [];
                foreach ($data as $tmpVal) {
                    $response[] = $this->GetValueFromConn($newSlug, $tmpVal);
                }
                return $response;
            }
            else {

                if (isset($data[$val])) {

                    if ($deepCount > 1) {
                        return $this->GetValueFromConn($newSlug, $data[$val]);
                    }
                    else {
                        return $data[$val];
                    }
                }
            }
        }
    }

    public function CalcularCatalogo(Request $request) {

        $nodoActual = $request->get('factual');
        $depends = $request->get('depends');
        $valor = $request->get('value');
        $cotizacionId = $request->get('token');
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();
        $producto = $cotizacion->producto;
        //$campos = $cotizacion->campos;

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-608', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-610', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        // get catálogos
        $conexiones = FlujoConexion::where('marcaId', $producto->marcaId)->get();
        $conexionesList = [];

        $arrNodosCatalogo = [];
        foreach ($flujoConfig['nodes'] as $nodo) {

            if (empty($nodo['typeObject'])) continue;
            if ($nodo['id'] !== $nodoActual) continue;

            // todos los campos
            foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                //$allFields[$keySeccion]['nombre'] = $seccion['nombre'];
                foreach ($seccion['campos'] as $campo) {

                    if (!empty($campo['fillByCon'])) {
                        $conexionTmp = $conexiones->where('id', $campo['fillByCon'])->first();
                        if (!empty($conexionTmp)) {
                            $conexionesList[$campo['fillByCon']]['conn'] = $conexionTmp;
                            $conexionesList[$campo['fillByCon']]['conValue'] = $campo['conValue'];
                            $conexionesList[$campo['fillByCon']]['conLabel'] = $campo['conLabel'];
                            $conexionesList[$campo['fillByCon']]['conExtra'] = $campo['conExtra'] ?? [];
                        }
                    }
                    else {
                        if (empty($campo['id']) || empty($campo['catalogoId'])) continue;
                        $arrNodosCatalogo[$campo['id']] = $campo;
                    }
                }
            }
        }

        if (count($arrNodosCatalogo) === 0 && count($conexionesList) === 0) {
            return $this->ResponseSuccess('Catalogos obtenidos con éxito', []);
        }

        $tmpData = [];
        if (isset($producto->extraData) && $producto->extraData !== '') {
            $tmpData = json_decode($producto->extraData, true);
            $tmpData = $tmpData['planes'] ?? [];
        }

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
                    }
                    else {
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

        // ejecuta los servicios de catálogo
        foreach ($conexionesList as $conexion) {

            $conectionResponse = $this->executeConnection($conexion['conn'], $cotizacion);

            $slugLabel = explode('.', $conexion['conLabel']);
            $slugLabel = end($slugLabel);

            $slugValue = explode('.', $conexion['conValue']);
            $slugValue = end($slugValue);

            $levelToFind = str_replace(".{$slugValue}", '', $conexion['conValue']);
            $dataTmp = $this->GetValueFromConn($levelToFind, $conectionResponse['data']);

            $data = [];
            /*foreach ($dataTmp as $v){
                if(is_array($v)) $data = array_merge($data , array_values($v));
            }*/
            $arrCat = [];
            $count = 0;
            foreach ($dataTmp as $catData) {
                if(empty($catData) || !is_array($catData)) continue;
                foreach ($catData as $key => $value) {
                    if ($slugValue === $key) {
                        $arrCat[$count][$conexion['conValue']] = $value;
                    }
                    if ($slugLabel === $key) {
                        $arrCat[$count][$conexion['conLabel']] = $value;
                    }

                    if(!empty($conexion['conExtra'])
                        && is_array($conexion['conExtra'])
                        && in_array($levelToFind . '.'. $key, $conexion['conExtra'])){
                        $arrCat[$count][$levelToFind . '.'. $key] = $value;
                    }
                }
                $count++;
            }

            $arrResponse['conn_' . $conexion['conn']->id] = array_values($arrCat);
        }

        //dd($arrResponse);
        return $this->ResponseSuccess('Catalogos obtenidos con éxito', $arrResponse);

    }

    public function CalcularCatalogoTablaOcr(Request $request) {

        $nodoActual = $request->get('factual');
        $depends = $request->get('depends');
        $valor = $request->get('value');
        $cotizacionId = $request->get('token');
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();
        $producto = $cotizacion->producto;
        $row = $request->get('row');
        $tokenId = $request->get('tokenId');
        $dataRow = $request->get('dataRow');

        $dataForRowProcess = [];

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-608', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-610', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        // get catálogos
        $conexiones = FlujoConexion::where('marcaId', $producto->marcaId)->get();
        $conexionesList = [];

        $arrNodosCatalogo = [];

        foreach ($flujoConfig['nodes'] as $nodo) {

            if (empty($nodo['typeObject'])) continue;
            if ($nodo['id'] !== $nodoActual) continue;
            if(empty($nodo['tablasOcrCampos'])) continue;

            foreach ($nodo['tablasOcrCampos'] as $campo) {
                if (!empty($campo['fillByCon'])) {
                    $conexionTmp = $conexiones->where('id', $campo['fillByCon'])->first();
                    if (!empty($conexionTmp)) {
                        $conexionesList[$campo['fillByCon']]['conn'] = $conexionTmp;
                        $conexionesList[$campo['fillByCon']]['conValue'] = $campo['conValue'];
                        $conexionesList[$campo['fillByCon']]['conLabel'] = $campo['conLabel'];
                    }
                }
                else {
                    if (empty($campo['id']) || empty($campo['catalogoId'])) continue;
                    $arrNodosCatalogo[$campo['id']] = $campo;
                }
            }
        }

        if (count($arrNodosCatalogo) === 0 && count($conexionesList) === 0) {
            return $this->ResponseSuccess('Catalogos obtenidos con éxito', []);
        }

        $tmpData = [];
        if (isset($producto->extraData) && $producto->extraData !== '') {
            $tmpData = json_decode($producto->extraData, true);
            $tmpData = $tmpData['planes'] ?? [];
        }

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
                    }
                    else {
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

        foreach ($conexionesList as $conexion) {
            if(!empty($conexion['conn']) && !empty($conexion['conn']['requestData'])) {
                foreach($dataRow as $row){
                    $idField = 'ocrRow:' . $row['header'];
                    $token = "{{" . $idField . "}}";
                    $conexion['conn']['requestData'] = preg_replace("/" . preg_quote($token) . "/", $row['valor'], $conexion['conn']['requestData']);
                }
            }

            $conectionResponse = $this->executeConnection($conexion['conn'], $cotizacion);

            $slugLabel = explode('.', $conexion['conLabel']);
            $slugLabel = end($slugLabel);

            $slugValue = explode('.', $conexion['conValue']);
            $slugValue = end($slugValue);

            $levelToFind = str_replace(".{$slugValue}", '', $conexion['conValue']);
            $dataTmp = $this->GetValueFromConn($levelToFind, $conectionResponse['data']);

            $data = [];

            $arrCat = [];
            $count = 0;
            foreach ($dataTmp as $catData) {
                if(empty($catData) || !is_array($catData)) continue;
                foreach ($catData as $key => $value) {
                    $arrCat[$count][$levelToFind . '.' . $key] = $value;
                    /*if ($slugValue === $key) {
                        $arrCat[$count][$conexion['conValue']] = $value;
                    }
                    if ($slugLabel === $key) {
                        $arrCat[$count][$conexion['conLabel']] = $value;
                    }*/
                }
                $count++;
            }

            $arrResponse['conn_' . $conexion['conn']->id] = array_values($arrCat);
        }

        //dd($arrResponse);
        return $this->ResponseSuccess('Catalogos obtenidos con éxito', $arrResponse);

    }

    public function flatArray($array, $prefix = '', $onlyKeys = false, $child = false) {
        $return = [];

        if ($onlyKeys) {

            if (isset($array[0])) {
                $arrayTmp = $array;
                $array = [];
                $array[] = $arrayTmp[0];
            }

            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    $return = array_merge($return, $this->flatArray($value, $prefix . $key . '.', $onlyKeys, $child));
                }
                else {
                    $return[$prefix . $key] = $value;
                }
            }

            $returnFinish = [];
            foreach ($return as $key => $value) {
                $tmpKey = str_replace('.0.', '.x.', $key);
                $returnFinish[$tmpKey] = $value;
            }
            $return = $returnFinish;
        }
        else {
            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    $return = array_merge($return, $this->flatArray($value, $prefix . $key . '.'));
                }
                else {
                    $return[$prefix . $key] = $value;
                }
            }
        }

        return $return;
    }

    public function executeConnection($conectionObject, $cotizacionObject = false, $type = 'ws', $log =false, $prefix = 'WS') {

        $authData = [];
        if ($type === 'auth') {
            $dataResponse = @json_decode($conectionObject->authResponseData);
            $dataRequest = @json_decode($conectionObject->authRequestData);
            $url = ($conectionObject->modoActivo === 'DEV' ? $conectionObject->authUrlDev : $conectionObject->authUrl);
            $typeSend = $conectionObject->authTypeSend;
        }
        else {
            $dataResponse = @json_decode($conectionObject->responseData);
            $dataRequest = @json_decode($conectionObject->requestData);
            $url = ($conectionObject->modoActivo === 'DEV' ? $conectionObject->urlDev : $conectionObject->url);
            $typeSend = $conectionObject->typeSend;

            // si requiere autenticación
            if (!empty($conectionObject->authWs)) {

                $authTmp = $this->executeConnection($conectionObject, false, 'auth');
                if (!empty($authTmp['parsed'])) {
                    foreach ($authTmp['parsed'] as $key => $value) {
                        $tmpKey = "THIS.{$key}";
                        $authData[] = [
                            'id' => $tmpKey,
                            'campo' => $tmpKey,
                            'valorLong' => trim($value ?? ''),
                        ];
                    }
                }
            }
        }

        $responseExpected = (is_string($dataResponse->expected) ? @json_decode($dataResponse->expected, true) : $dataResponse->expected);
        $expectedFormat = $dataResponse->format ?? false;
        $formatedResponse = $dataResponse->parsed ?? '';
        $responseFilters = (is_string($dataResponse->filters) ? @json_decode($dataResponse->filters, true) : $dataResponse->filters);
        $requestHeaders = (is_string($dataRequest->headers) ? @json_decode($dataRequest->headers, true) : $dataRequest->headers);
        $requestPreFormat = (is_string($dataRequest->preFormat) ? @json_decode($dataRequest->preFormat, true) : $dataRequest->preFormat);
        $requestDataSend = $dataRequest->dataSend;

        //$expectedFormat = $responseExpected->expectedFormat;

        // reemplazo de variables
        if (!empty($cotizacionObject)) {
            $campos = $cotizacionObject->campos;
            $requestDataSend = $this->reemplazarValoresSalida($campos, $requestDataSend, false, true); // En realidad es salida pero lo guardan como entrada
            $requestDataSend = trim($requestDataSend);

            $url = $this->reemplazarValoresSalida($campos, $url);
            //$headers = $this->reemplazarValoresSalida($campos, $proceso['header']);
        }

        $headers = [];

        if (is_array($requestHeaders) && count($requestHeaders) > 0) {
            foreach ($requestHeaders as $key => $value) {
                $headerTmp = "{$key}:{$value}";
                $headerTmp = $this->reemplazarValoresSalida($authData, $headerTmp);
                $headers[] = $headerTmp;
            }
        }

        /*if ($type !== 'auth') {
            var_dump($requestHeaders);
            var_dump($authData);
            var_dump($headers);
            die('asdfasdf');
        }*/

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// curl_setopt($ch, CURLOPT_USERPWD, $soapUser . ":" . $soapPassword); // username and password - declared at the top of the doc
// curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        switch ($conectionObject->type) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                break;
        }

        // tipo de envío
        if ($typeSend === 'FD') {
            $requestDataSend = @json_decode($requestDataSend, true);
        }

        if ($conectionObject->type !== 'GET' && !empty($requestDataSend)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestDataSend);
        }

        $response = [];

        // converting
        $dataExec = curl_exec($ch);
        curl_close($ch);

        $response['status'] = false;
        $response['msg'] = '';
        $response['raw'] = $dataExec;
        $response['parsed'] = '';

        $responseTMP = '';

        if (empty($expectedFormat)) {
            $response['msg'] = 'Debe configurar el tipo de parseo de respuesta';
        }
        else {

            if ($expectedFormat === 'JSON') {
                $responseTMP = @json_decode($dataExec, true);
            }

            if (!is_array($responseTMP)) {
                $response['msg'] = 'Error al parsear JSON de respuesta';
            }
            $resultFull = [];
            $result = array();

            if (is_array($responseTMP)) {

                $tmp = [
                    $prefix => $responseTMP,
                ];
                $resultFull = $this->flatArray($tmp, '', true);
            }

            if (!is_array($responseTMP)) {
                $responseTMP = [];
            }
            $response['parsed'] = $resultFull;
            $response['data'] = $responseTMP;
            $response['status'] = (count($resultFull) > 0) ? true : false;

            if ($log) {
                $response['log'] = [
                    'url' => $url,
                    'enviado' => print_r($requestDataSend, true),
                    'enviadoH' => print_r($headers, true),
                    'recibido' => print_r($dataExec, true),
                    'data' => print_r($resultFull, true),
                ];
            }
        }

        return $response;
    }

    public function consumirServicio($proceso = [], $data = [], $respond = false) {
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
            }
            else if ($proceso['method'] == 'post') {
                $dataSend = $acsel->post($url, $dataToSend ?? [], false, $respuestaXml);
            }

            if (!empty($dataSend)) {
                $arrResponse['status'] = true;
                $arrResponse['msg'] = 'Petición realizada con éxito';
            }
            else {
                $arrResponse['msg'] = 'Error al consumir servicio, verifique su autenticación y url';
            }

            $dataResponse['enviadoH'] = (!empty($acsel->rawHeaders) ? $acsel->rawHeaders : $headers);
            $dataResponse['recibidoProcesado'] = $dataSend;
            $dataResponse['recibido'] = $acsel->rawResponse;
        }
        else {

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
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            switch (strtolower($proceso['method'])) {
                case 'get':
                    curl_setopt($ch, CURLOPT_HTTPGET, true);
                    break;
                case 'post':
                    curl_setopt($ch, CURLOPT_POST, true);
                    break;
                case 'put':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                    break;
                case 'delete':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                    break;
            }
            if (in_array(strtolower($proceso['method']), ['post', 'put', 'delete'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $dataToSend);
            }

            //dd($hadersSend);

            // converting
            $dataSend = curl_exec($ch);
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
                }
                else {
                    $dataSend = @json_decode(json_encode((array)simplexml_load_string($dataSend)), true);
                }

                $searchJson = function ($data) use (&$searchJson) {

                    $res = [];

                    foreach ($data as $key => $tmp) {
                        if(is_array($tmp)) {
                            $res[$key] = $searchJson($tmp);
                        }
                        else {
                            if (is_string($tmp)) {
                                $isJson = @json_decode($tmp, true);
                                if (is_array($isJson)) {
                                    $res = $isJson;
                                }
                                else {
                                    // chapus para addiuva, acá no debería llegar nunca si el json viene estándar
                                    $json = trim($tmp, '"');
                                    $json = str_replace("'", '"', $json);
                                    $isJson = @json_decode($json, true);

                                    if (is_array($isJson)) {
                                        $res = $isJson;
                                    }
                                    else {
                                        $res = $tmp;
                                    }
                                }
                            }
                            else {
                                $res = $tmp;
                            }
                        }
                    }

                    return $res;
                };

                $dataSend = $searchJson($dataSend);
            }
            else {
                $dataSend = @json_decode($dataSend, true);
            }
            $dataResponse['recibidoProcesado'] = print_r($dataSend, true);
        }
        if($respond) return $dataSend;
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

    public function reemplazarValoresSalida($arrayValores, $texto, $convertirMayuscula = false, $removeNotReplacedVars = false) {

        //var_dump($arrayValores);

        //die();
        $tokenCotizacion = '';
        $result = $texto;
        foreach ($arrayValores as $dataItem) {

            if (empty($dataItem['id'])) continue;
            $tmpFileUrl = '';

            if (!isset($dataItem['valorLong'])) {
                $dataItem['valorLong'] = $dataItem['valor'] ?? '';
            }

            if (!empty($dataItem['isFile']) && !empty($dataItem['valorLong'])) {
                $tmpFileUrl = Storage::disk('s3')->temporaryUrl($dataItem['valorLong'], now()->addDays(6));
            }

            if (!empty($tmpFileUrl)) {
                $stringData = $tmpFileUrl;
            }
            else {
                $stringData = $dataItem['valorLong'];
            }

            if (!is_array($stringData)) $stringData = trim($stringData);

            if ($dataItem['valorLong'] === '{}') $stringData = '';
            $jsonTmp = is_array($dataItem['valorLong']) ? $dataItem['valorLong'] :  @json_decode($dataItem['valorLong'], true);

            if ($jsonTmp && is_array($jsonTmp)) {
                $stringData = $this->flattenJson($jsonTmp);
            }
            $idField = (!empty($dataItem['campo']) ? $dataItem['campo'] : $dataItem['id']);
            $token = "{{" . $idField . "}}";
            $result = preg_replace("/" . preg_quote($token) . "/", $stringData, $result);

            if (!empty($dataItem['tipo']) && $dataItem['tipo'] === 'date') {
                $token = "{{" . $idField . "[H]}}";
                $stringData = @Carbon::parse($stringData)->format('d/m/Y');
                $result = preg_replace("/" . preg_quote($token) . "/", $stringData, $result);
            }

            if($idField === 'ID_FORMULARIO'){
                $infoCoti = Cotizacion::where('id', intval($stringData))->first();
                if(!empty($infoCoti)) $tokenCotizacion = $infoCoti->token;
            }
        }

        // Reemplazo en caso se necesite token
        if(!empty($tokenCotizacion)){
            // ID_FORMULARIO
            $token = "{{TLINK_FORM}}";
            $result = preg_replace("/" . preg_quote($token) . "/", "view?tokenLinking=".$tokenCotizacion, $result);
        }

        // se eliminan las que no se reemplazaron
        if ($removeNotReplacedVars) {
            $result = preg_replace('#\s*\{\{.+}}\s*#U', '', $result);
        }

        $result = trim($result);

        return $result;
    }

    public function validateUserInGroup($user, $userGroups = [], $roles = []) {

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

    private function flattenJson($json, $prefix = ''): string {
        $result = [];
        foreach ($json as $key => $value) {
            $newKey = $prefix . (empty($prefix) ? '' : '.') . $key;
            if (is_array($value)) {
                $result = array_merge($result, (array)$this->flattenJson($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }
        return implode(', ', $result);
    }

    // Subida de archivos
    public function uploadFileAttach(Request $request) {

        $archivo = $request->file('file');
        $cotizacionId = $request->get('token');
        $seccionKey = $request->get('seccionKey');
        $campoId = $request->get('campoId');
        $audioFile = $request->get('audioFile');

        $usuarioLogueado = auth('sanctum')->user();
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (!empty($usuarioLogueado)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/uploadfiles'])) return $AC->NoAccess();
        }

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-632', 'La tarea no existe o está asociada a otro usuario');
        }

        $flujoConfig = $this->getFlujoFromCotizacion($cotizacion);

        if (!$flujoConfig['status']) {
            return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
        }
        else {
            $flujoConfig = $flujoConfig['data'];
        }

        // Recorro campos para hacer resumen
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

        $dir = '';
        $tipoArchivo = '';
        $arrMimeTypes = [];
        $label = '';
        $ocr = false;
        $ocrConf = '';
        $arrMimeTypesTmp = [];

        $marcaToken = $cotizacion->marca->token ?? false;

        if (empty($marcaToken)) {
            return $this->ResponseError('T-15', 'Error al subir archivo, marca inválida');
        }

        $dir = "{$marcaToken}/{$cotizacion->token}";

        if (!empty($campos[$campoId]['mime'])) {
            $arrMimeTypesTmp = explode(',', $campos[$campoId]['mime']);
        }
        if (!empty($campos[$campoId]['nombre'])) {
            $label = $campos[$campoId]['nombre'];
        }
        if (!empty($campos[$campoId]['ocr'])) {
            $ocr = $campos[$campoId]['ocrTPl'] ?? '';
            $ocrConf = $campos[$campoId]['ocrCF'] ?? '';
        }

        $resultMimes = array_map('trim', $arrMimeTypesTmp);

        foreach ($resultMimes as $mime) {
            $peso = explode('|', $mime);
            if (!empty($peso[0])) {
                $arrMimeTypes[$peso[0]] = $peso[1] ?? 0;
            }
        }

        // Valido los mime
        $fileType = $archivo->getMimeType();
        $fileSize = $archivo->getSize();
        $fileSize = $fileSize / 1000000;

        if (empty($audioFile)) {
            /*$arrMimeTypes['video/webm'] = 5;
            $arrMimeTypes['audio/ogg'] = 5;*/

            if (!array_key_exists($fileType, $arrMimeTypes)) {
                return $this->ResponseError('T-12', 'Tipo de archivo no permitido para subida');
            }

            // valido peso
            if (floatval($arrMimeTypes[$fileType]) < floatval($fileSize)) {
                return $this->ResponseError('T-13', "Peso de archivo excedido, máximo " . number_format($arrMimeTypes[$fileType], 2) . " mb");
            }
        }

        $hashName = md5($archivo->getClientOriginalName()); // Obtiene el nombre generado por Laravel
        $extension = $archivo->extension();
        $filenameWithExtension = $hashName . '.' . $extension; // Concatena el nombre generado por Laravel con la extensión

        try {
            //dd($archivo);
            $extensions = [
                'png', 'jpg', 'jpeg',
            ];

            // guardo en local
            $disk = Storage::disk('local');
            $fileTmp = $dir.'/'.$filenameWithExtension;
            $disk->putFileAs($dir, $archivo, $filenameWithExtension);
            $diskPath = Storage::disk('local')->path($dir).'/'.$filenameWithExtension;

            if (in_array($extension, $extensions)) {
                $image = new ImageManager(['driver' => 'imagick']);
                $image = $image->make($diskPath);
                $image->resize(1200, null, function ($constraint) use ($request) {
                    $constraint->aspectRatio();
                });
                $image->save($diskPath, '60');
            }

            // subida al s3
            $disk = Storage::disk('s3');
            $disk->put($fileTmp, file_get_contents($diskPath));
            $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($fileTmp, now()->addMinutes(30));

            $extraData = '';
            if (!empty($audioFile)) {
                $campo = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('campo', $campoId)->first();
                if (empty($campo)) {
                    $campo = new CotizacionDetalle();
                }

                // transcripción
                $enableTranscription = $campos[$campoId]['audioTr'] ?? false;
                $trans = false;

                if ($enableTranscription) {
                    //$audio_url = $disk->url($fileTmp);
                    $dataSend = [
                        'url' => $temporarySignedUrl,
                    ];

                    /*var_dump($dataSend);
                    die;*/

                    // Deep
                    $headers = array(
                        'Content-Type: application/json',
                        'Authorization: Token '.env('DEEP_API_KEY')
                    );

                    $url = env('DEEP_API_URL')."/listen?punctuate=true&model=nova-2&utt_split=10&language=es";
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15); //timeout in seconds
                    $data = curl_exec($ch);
                    curl_close($ch);
                    $arr_data = json_decode($data, true);

                    $errors = false;
                    if (empty($data)) {
                        $errors = true;
                        $extraData = 'Error al transcribir archivo';
                    }
                    else {
                        $confidencia = $arr_data['results']['channels'][0]['alternatives'][0]['confidence'] ?? 0;
                        $requestId = $arr_data['metadata']['request_id'] ?? null;
                        if (!empty($arr_data['results']['channels'][0]['alternatives'][0]['transcript'])) {
                            $extraData = $arr_data['results']['channels'][0]['alternatives'][0]['transcript'];
                        }
                    }

                    // var_dump($arr_data);




                    // Create Amazon Transcribe Client
                    /*$awsTranscribeClient = new TranscribeServiceClient([
                        'region' => 'us-east-2',
                        'version' => 'latest',
                        'credentials' => [
                            'key'    => env('AWS_TRANSCRIBE_KEY'),
                            'secret' => env('AWS_TRANSCRIBE_SECRET')
                        ]
                    ]);

                    // Start a Transcription Job
                    $job_id = $cotizacion->marcaId."_".date('Ymd').'_'.uniqid();
                    $transcriptionResult = $awsTranscribeClient->startTranscriptionJob([
                        'LanguageCode' => 'es-ES',
                        'Media' => [
                            'MediaFileUri' => $audio_url,
                        ],
                        'TranscriptionJobName' => $job_id,
                    ]);*/

                    // inserto la transcripción
                    if (!$errors) {
                        $trans = new CotizacionTranscribe();
                        $trans->marcaId = $cotizacion->marcaId;
                        $trans->cotizacionId = $cotizacion->id;
                        $trans->jobId = $requestId;
                        $trans->campo = $campoId;
                        $campo->label = $label ?? null;
                        $trans->createdAt = date('Y-m-d H:i:s');
                        $trans->contenido = $extraData;
                        $trans->save();
                    }
                }
            }
            else {
                $campo = new CotizacionDetalle();
            }

            $campo->cotizacionId = $cotizacion->id;
            $campo->seccionKey = $seccionKey;
            $campo->tipo = 'file';
            $campo->campo = $campoId;
            $campo->valorLong = $fileTmp;
            $campo->label = $label ?? null;
            $campo->isFile = 1;
            $campo->extraData = $extraData;
            $campo->save();
            $campoId = $campo->id;

            if (!empty($trans)) {
                $trans->cotizacionDetalleId = $campo->id;
                $trans->save();
            }

            // ejecución de ocr
            if ($ocr) {
                $payGatewayAPIKEY = SistemaVariable::where('slug', 'API_PAYGATEWAY')->where('marcaId', $cotizacion->marcaId)->first();

                // procesa ocr
                $headers = array(
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$payGatewayAPIKEY->contenido ?? ''
                );

                $dataSend = [
                    "templateToken"=> $ocr,
                    "fileLink"=> $temporarySignedUrl,
                ];

                $link = env('PAYGATEWAY_API_URL', '').'/formularios/docs-plus/ocr-process/gen3';
                $ch = curl_init($link);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataSend));
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $data = curl_exec($ch);
                $info = curl_getinfo($ch);
                curl_close($ch);
                $resultado = @json_decode($data, true);

                if (!empty($ocrConf)) {
                    $desdeVariables = explode(',', $ocrConf);

                    foreach ($desdeVariables as $varTmp) {

                        $vars = explode('=>', $varTmp);
                        $var = (!empty($vars[0]) ? trim($vars[0]) : false);
                        $field = (!empty($vars[1]) ? trim($vars[1]) : false);
                        /*var_dump($var);
                        var_dump($resultado['data']);*/

                        if ($var && $field && isset($resultado['data']['tokens'][$var])) {

                            $campo = CotizacionDetalle::where('campo', $field)->where('cotizacionId', $cotizacion->id)->first();

                            if (empty($campo)) {
                                $campo = new CotizacionDetalle();
                            }
                            $campo->cotizacionId = $cotizacion->id;
                            $campo->seccionKey = $seccionKey;
                            $campo->campo = $field;
                            $campo->valorLong = $resultado['data']['tokens'][$var] ?? '';
                            $campo->isFile = 0;
                            $campo->save();
                        }
                    }
                }

            }

            return $this->ResponseSuccess('Archivo subido con éxito', [
                'key' => $temporarySignedUrl, 'id' => $campoId, 'ed' => $extraData, 'sok' => 1,
            ]);

        } catch (\Exception $e) {
            //var_dump($e->getMessage());
            //dd($e->getMessage());
            //$response['msg'] = 'Error en subida, por favor intente de nuevo '.$e;
            return $this->ResponseError('T-121', 'Error al cargar archivo ');
        }
    }

    public function TranscribeAudioCheck(Request $request) {

        $cotizacionId = $request->get('token');
        $campoId = $request->get('campoId');
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('TRAUD-637', 'La tarea no existe o está asociada a otro usuario');
        }

        if (empty($campoId)) {
            return $this->ResponseError('TRAUD-637', 'El archivo de transcripción no se encuentra');
        }

        $transcribe = $this->TranscribeJobStatus($cotizacion, $campoId);

        return $transcribe;
    }

    public function TranscribeJobStatus($cotizacion, $campoId) {

        // traigo el detalle
        $transcripcionJob = CotizacionTranscribe::where('cotizacionId', $cotizacion->id)->where('campo', $campoId)->orderBy('id', 'DESC')->first();

        if (empty($transcripcionJob)) {
            return $this->ResponseError('TRAUD-638', 'La transcripción aún no se encuentra disponible');
        }

        $awsTranscribeClient = new TranscribeServiceClient([
            'region' => 'us-east-2',
            'version' => 'latest',
            'credentials' => [
                'key'    => env('AWS_TRANSCRIBE_KEY'),
                'secret' => env('AWS_TRANSCRIBE_SECRET')
            ]
        ]);

        $status = $awsTranscribeClient->getTranscriptionJob([
            'TranscriptionJobName' => $transcripcionJob->jobId
        ]);

        $extraData = '';
        if ($status->get('TranscriptionJob')['TranscriptionJobStatus'] == 'COMPLETED') {

            $url = $status->get('TranscriptionJob')['Transcript']['TranscriptFileUri'] ?? false;
            if (!empty($url)) {
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_HEADER, false);
                $data = curl_exec($curl);
                if (curl_errno($curl)) {
                    $error_msg = curl_error($curl);
                    //echo $error_msg;
                }
                curl_close($curl);
                $arr_data = json_decode($data);
                $extraData = $arr_data->results->transcripts[0]->transcript ?? '';
            }
        }

        $transcripcionJob->contenido = $extraData;
        $transcripcionJob->save();

        // actualizdo detalle de cotización
        $detalle = CotizacionDetalle::where('id', $transcripcionJob->cotizacionDetalleId)->first();
        if (!empty($detalle)) {
            $detalle->extraData = $extraData;
            $detalle->save();
        }

        if (!empty($extraData)) {
            return $this->ResponseSuccess('Transcripción obtenida con éxito', $extraData);
        }
        else {
            return $this->ResponseError('TRAUD-639', 'El archivo de transcripción aún no está disponible');
        }
    }

    public function GetFilePreview(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $token = $request->get('token');
        $seccionKey = $request->get('seccionKey');

        $usuarioLogueado = $usuario = auth('sanctum')->user();
        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseSuccess('Cotización sin adjuntos');
        }

        $producto = $cotizacion->producto;
        if (empty($producto)) {
            return $this->ResponseError('TASK-700', 'Producto no válido');
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-701', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-701', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        $camposList = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('isFile', 1)->get();

        $camposSalidaFirma = [];

        // Recorro campos para hacer resumen
        $campos = [];
        foreach ($flujoConfig['nodes'] as $nodo) {
            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {

                    foreach ($seccion['campos'] as $keyCampo => $campo) {

                        // campos tipo archivo
                        if ($campo['tipoCampo'] !== 'file' && $campo['tipoCampo'] !== 'signature' ) continue;

                        if (!empty($campo['grupos_assign']) || !empty($campo['roles_assign'])) {
                            $isInGroup = $this->validateUserInGroup($usuarioLogueado, $campo['grupos_assign'] ?? [], $campo['roles_assign'] ?? []);
                            if (!$isInGroup) continue;
                        }

                        $dbValores = $camposList->where('campo', $campo['id']);

                        foreach ($dbValores as $dbValor) {
                            if (!empty($dbValor['valorLong'])) {
                                //dd($dbValor['valorLong']);
                                $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($dbValor['valorLong'], now()->addMinutes(60));

                                $type = '';
                                $ext = pathinfo($dbValor['valorLong'], PATHINFO_EXTENSION);

                                if ($dbValor->tipo === 'signature') {
                                    $type = 'signature';
                                }
                                else {
                                    if ($ext == 'jpg' || $ext == 'jpeg' || $ext == 'png' || $ext == 'tiff' || $ext == 'gif') {
                                        $type = 'image';
                                    }
                                    else if ($ext == 'pdf') {
                                        $type = 'pdf';
                                    }
                                }

                                $campos[$campo['id'] . '_' . $dbValor->id] = [
                                    'field' => $campo['id'],
                                    'label' => $campo['label'] ?? 'Sin etiqueta',
                                    'name' => $campo['nombre'] ?? 'Sin nombre',
                                    'valor' => $dbValor['valorLong'],
                                    'url' => $temporarySignedUrl,
                                    'type' => $type,
                                    'salida' => false
                                ];
                            }
                        }
                    }
                }
            }

            // salidas
            if (!empty($nodo['salidaIsPDF']) && !empty($nodo['salidaPdfS'])) {
                $campoIdTmp = $camposList->where('campo', $nodo['salidaPDFId'])->first();
                if (!empty($campoIdTmp)) {
                    $firmaDoc = FirmaElectronica::where('marcaId', $cotizacion->marcaId)->where('cotizacionDetalleId', $campoIdTmp->id)->first();
                }
                $camposSalidaFirma[$nodo['salidaPDFId']] = [
                    'e' => $firmaDoc->estado ?? 'crear',
                    'i' => $firmaDoc->id ?? false,
                ];
            }
        }

        //var_dump($camposSalidaFirma);

        // Salidas
        foreach ($camposList as $campo) {
            if ($campo->fromSalida) {

                // dd($campo);

                if (!empty($campo['valorLong'])) {

                    $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($campo['valorLong'], now()->addMinutes(10));

                    $ext = pathinfo($campo['valorLong'], PATHINFO_EXTENSION);

                    $campos[$campo['id']] = [
                        'id' => $campo['id'],
                        'label' => $campo['label'],
                        'name' => $campo['nombre'],
                        'valor' => $campo['valorLong'],
                        'url' => $temporarySignedUrl,
                        'type' => $ext,
                        'salida' => $campo['fromSalida'],
                        'sign' => (!empty($camposSalidaFirma[$campo['campo']])),
                        'signE' => (!empty($camposSalidaFirma[$campo['campo']]) ? $camposSalidaFirma[$campo['campo']]['e'] : false),
                        'signI' => (!empty($camposSalidaFirma[$campo['campo']]) ? $camposSalidaFirma[$campo['campo']]['i'] : false),
                    ];
                }
            }
        }

        return $this->ResponseSuccess('Adjuntos actualizados con éxito', $campos);
    }

    public function GetProgression(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['tareas/mis-tareas'])) return $AC->NoAccess();

        $usuarioLogueado = $usuario = auth('sanctum')->user();
        $cotizacionId = $request->get('token');

        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-632', 'Cotización no válida');
        }

        $flujoConfig = $this->getFlujoFromCotizacion($cotizacion);

        if (!$flujoConfig['status']) {
            return $this->ResponseError($flujoConfig['error-code'], $flujoConfig['msg']);
        }
        else {
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

    public function CalcularCampos(Request $request) {

        $campos = $request->get('campos');

        // dd($campos);

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-601', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-601', 'Error al interpretar flujo, por favor, contacte a su administrador');
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

    public function GetCatalogo(Request $request) {

        $campos = $request->get('campos');

        // dd($campos);

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-601', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-601', 'Error al interpretar flujo, por favor, contacte a su administrador');
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

    public function revertFileAttach(Request $request) {
        $cotizacionDetalleId = $request->get('id');
        $usuarioLogueado = auth('sanctum')->user();
        $cotizacionDetalle = CotizacionDetalle::where([['id','=', $cotizacionDetalleId]])->first();
        if (!empty($usuarioLogueado)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/uploadfiles'])) return $AC->NoAccess();
        }
        if (empty($cotizacionDetalle)) {
            return $this->ResponseError('TASK-632', 'El campo no existe o está asociada a otro usuario');
        }
        try {
            $cotizacionDetalle->cotizacionId = null;
            $cotizacionDetalle->save();
            $campoId = $cotizacionDetalle->id;

            return $this->ResponseSuccess('Archivo subido con éxito', [ 'id' => $campoId ]);

        } catch (\Exception $e) {
            return $this->ResponseError('T-122', 'Error al revertir archivo ');
        }
    }

    // plantillas pdf
    public function uploadPdfTemplate(Request $request) {

        $archivo = $request->file('file');
        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $activo = $request->get('activo');

        $item = PdfTemplate::where('id', $id)->first();

        if (empty($item)) {
            $item = new PdfTemplate();
        }

        $item->id = $id;
        $item->nombre = $nombre;
        $item->activo = intval($activo);
        $item->save();

        if (!empty($archivo)) {
            $disk = Storage::disk('s3');
            $path = $disk->putFileAs("/system-templates", $archivo, "tpl_{$item->id}.docx");

            $item->urlTemplate = $path;
            $item->save();
        }

        return $this->ResponseSuccess('Plantilla guardada con éxito', ['id' => $item->id]);
    }

    public function getPdfTemplateList(Request $request) {

        $item = PdfTemplate::all();

        return $this->ResponseSuccess('Plantillas obtenidas con éxito', $item);
    }

    public function getPdfTemplate(Request $request, $id) {

        $item = PdfTemplate::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('TPL-145', 'Error al obtener plantilla');
        }

        $item->urlShow = (!empty($item->urlTemplate)) ? Storage::disk('s3')->temporaryUrl($item->urlTemplate, now()->addMinutes(30)) : false;

        return $this->ResponseSuccess('Plantilla obtenida con éxito', $item);
    }

    public function deletePdfTemplate(Request $request) {

        $id = $request->get('id');
        $item = PdfTemplate::where('id', $id)->first();

        if (empty($item)) {
            return $this->ResponseError('TPL-145', 'Plantilla inválida');
        }

        $item->delete();

        return $this->ResponseSuccess('Plantilla eliminada con éxito', $item);
    }

    // Comentarios
    public function CrearComentario(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $token = $request->get('token');
        $comment = $request->get('comment');
        $comentarioAcceso = $request->get('comentarioAcceso');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = (!empty($usuarioLogueado) ? $usuarioLogueado->id : 0);
        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('CM-002', 'Cotización inválida');
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
        }
        else {
            return $this->ResponseError('CM-003', 'El comentario no puede estar vacío');
        }
    }

    public function GetComentarios(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $token = $request->get('token');
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = (!empty($usuarioLogueado) ? $usuarioLogueado->id : 0);

        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('CM-001', 'Cotización inválida');
        }

        $arrResult = [];

        $comentariosTmp = CotizacionComentario::where([['cotizacionId', '=', $cotizacion->id], ['deleted', '=', null]]);

        if (!$usuarioLogueadoId) {
            $comentariosTmp->where('acceso', 'publico');
        }

        $comentarios = $comentariosTmp->get();

        foreach ($comentarios as $comment) {
            $arrResult[$comment->id]['date'] = Carbon::parse($comment->createdAt)->format('d/m/Y H:i');
            $arrResult[$comment->id]['usuario'] = $arrResult[$comment->id]['date'] . ' - ' . ($usuarioLogueadoId ? ($comment->usuario->name ?? 'Usuario sin nombre') : 'Cliente');
            $arrResult[$comment->id]['comentario'] = $comment->comentario;
            $arrResult[$comment->id]['a'] = $comment->acceso;
        }

        return $this->ResponseSuccess('Comentarios obtenidos con éxito', $arrResult);
    }


    // publicos
    public function VerCampos(Request $request, $returnArray = false) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $usuarioLogueado = $usuario = auth('sanctum')->user();
        $token = $request->get('token');

        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-632', 'Tarea no válida');
        }

        $producto = $cotizacion->producto;
        if (empty($producto)) {
            return $this->ResponseError('TASK-600', 'Flujo no válido');
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-601', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-601', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        $camposCoti = $cotizacion->campos;

        // Recorro campos para hacer resumen
        $resumen = [];
        foreach ($flujoConfig['nodes'] as $nodo) {
            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                    $resumen[$keySeccion]['nombre'] = $seccion['nombre'];
                    foreach ($seccion['campos'] as $campo) {
                        $resumen[$keySeccion]['campos'][$campo['id']] = ['type' => $campo['tipoCampo'], 'label' => $campo['nombre'], 'seccion' => $keySeccion, 'id' => $campo['id']];
                    }
                }
            }
        }

        return $this->ResponseSuccess('Campos api éxito', $resumen);
    }

    public function crearSlug($text, string $divider = '-')
    {
        // replace non letter or digits by divider
        $text = preg_replace('~[^\pL\d]+~u', $divider, $text);

        // transliterate
        //$text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // trim
        $text = trim($text, $divider);

        // remove duplicate divider
        $text = preg_replace('~-+~', $divider, $text);

        // lowercase
        $text = strtolower($text);

        if (empty($text)) {
            return 'n-a';
        }

        return $text;
    }


    public function VerCamposPorSeccion(Request $request, $returnArray = false) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['users/role/admin'])) return $AC->NoAccess();

        $usuarioLogueado = $usuario = auth('sanctum')->user();
        $token = $request->get('token');

        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-632', 'Tarea no válida');
        }

        $producto = $cotizacion->producto;
        if (empty($producto)) {
            return $this->ResponseError('TASK-600', 'Flujo no válido');
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-601', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-601', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        $camposCoti = $cotizacion->campos->toArray();
        //dd($camposCoti);

        // Recorro campos para hacer resumen
        $resumen = [];
        foreach ($flujoConfig['nodes'] as $nodo) {
            //$resumen
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {

                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                    $nombreSeccion = empty($seccion['id'])? $this->crearSlug($seccion['nombre'], '_'): $seccion['id'] ;
                    //$resumen[$keySeccion]['nombre'] = $seccion['nombre'];
                    foreach ($seccion['campos'] as $campo) {

                        if ($campo['tipoCampo'] === 'subtitulo' || $campo['tipoCampo'] === 'title' || $campo['tipoCampo'] === 'txtlabel') {
                            continue;
                        }

                        $valorTmp = '';
                        foreach ($camposCoti as $campoTmp) {
                            if ($campoTmp['campo'] !== $campo['id']) continue;
                            $valorTmp = $campoTmp['valorLong'] ?? '';
                        }

                        if ($campo['tipoCampo'] === 'signature' || $campo['tipoCampo'] === 'file') {
                            if (!empty($valorTmp)) {
                                $valorTmp = Storage::disk('s3')->temporaryUrl($valorTmp, now()->addMinutes(30));
                            }
                        }

                        $resumen[$nombreSeccion][$campo['id']] = ['type' => $campo['tipoCampo'], 'label' => $campo['nombre'], 'seccion' => $keySeccion, 'id' => $campo['id'], 'value' => $valorTmp];
                    }
                }
            }
        }

        return response()->json($this->ResponseSuccess('Campos api éxito', $resumen, false));
    }

    public function RerunStepInProcess(Request $request){
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
        }

        $token = $request -> get('token');
        $flujosToRerun = Cotizacion::where([['token', '=', $token]])->first();
        if(empty($flujosToRerun)) return $this->ResponseError('TASK-017', 'Tarea inválida');

        $request->merge(['paso' => 'prev']);
        $prevresult = $this->CambiarEstadoCotizacion($request, false, false, false, false);

        if(empty(@json_decode($prevresult)-> data -> initial)){
            $request->merge(['paso' => 'next']);
            $this->CambiarEstadoCotizacion($request, false, false, false, false);
        }

        $flujosToRerun->outputRep += 1;
        $flujosToRerun->save();
        return $this->ResponseSuccess('Cambios ejecutados con exito', ['token'=> $flujosToRerun -> token ]);
    }

    public function ReSendEmailCopy(Request $request){
        $fechaLimite = '2023-11-20';
        $flujosToRerun = Cotizacion::where([['outputRep', '<', 4], ['nodoActual', '!=', null]])
            ->whereDate('dateCreated', '>', $fechaLimite)
            ->first();
        //instancia
        if(empty($flujosToRerun)) return $this->ResponseError('TAS-234','No se Encuentran mas formularios');

        $request->merge(['paso' => 'prev','token' => $flujosToRerun->token]);
        $prevresult = $this->CambiarEstadoCotizacion($request, false, false, false, true);
        if(empty(@json_decode($prevresult)-> data -> initial)){
            $request->merge(['paso' => 'next']);
            $this->CambiarEstadoCotizacion($request, false, false, false, true, false);
        }
        $flujosToRerun->outputRep += 4;
        $flujosToRerun->save();
        return $this->ResponseSuccess('Cambios ejecutados con exito', $flujosToRerun-> id);
    }

    public function saveFieldOnBlur(Request $request){
        $valor = $request->get('campo'); // solo seria un campo
        $token = $request->get('token');
        $seccionKey = $request->get('seccionKey');
        $campoKey = $request->get('campoKey');
        $showInReports = $request->get('showInReports');

        // repetibles
        $repetibleId = $valor['r'] ?? null;
        $repetibleKey = $valor['rK'] ?? null;
        $repetibleRemove = $valor['rR'] ?? false;
        $presaveIds = $valor['pId'] ?? false;

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
        }

        $item = Cotizacion::where([['token', '=', $token]])->first();
        // verificar que campokey exista en el flujo

        if (empty($item)) {
            return $this->ResponseError('TASK-015', 'Tarea inválida');
        }

        if ($valor['v'] === '__SKIP__FILE__') return $this->ResponseError('TASK-016', 'No se guarda');

        // tipos de archivo que no se guardan
        if (!empty($valor['t']) && ($valor['t'] === 'txtlabel' || $valor['t'] === 'subtitle' || $valor['t'] === 'title')) {
            return $this->ResponseError('TASK-016', 'No se guarda');
        }

        // preguardado de ids
        if (is_array($presaveIds)) {
            foreach ($presaveIds as $id) {
                $campo = new CotizacionDetalle();
                $campo->cotizacionId = $item->id;
                $campo->repetibleId = $repetibleId ?? null;
                $campo->repetibleKey = $repetibleKey;
                $campo->seccionKey = 0;
                $campo->campo = $id;
                $campo->useForSearch = 0;
                $campo->save();
            }
            return $this->ResponseSuccess('Agregado con éxito');
        }

        if ($repetibleRemove) {
            CotizacionDetalle::where('cotizacionId', $item->id)->where('repetibleId', $repetibleId)->where('repetibleKey', $repetibleKey)->delete();
            return $this->ResponseSuccess('Eliminado con éxito', $campoKey);
        }

        if (empty($repetibleId)) {
            $campo = CotizacionDetalle::where('campo', $campoKey)->where('cotizacionId', $item->id)->first();
        }
        else {
            $campo = CotizacionDetalle::where('campo', $campoKey)->where('cotizacionId', $item->id)->where('repetibleId', $repetibleId)->where('repetibleKey', $repetibleKey)->first();
        }

        if (empty($campo)){
            $campo = new CotizacionDetalle();
        }
        $campo->cotizacionId = $item->id;
        $campo->repetibleId = $repetibleId ?? null;
        $campo->repetibleKey = $repetibleKey;
        $campo->seccionKey = $seccionKey;
        $campo->campo = $campoKey;
        $campo->useForSearch = $showInReports ? 1 : 0;

        $campo->tipo = $valor['t'] ?? 'default';

        if ($campo->tipo === 'signature') {
            // solo se guarda la firma si viene en base 64, quiere decir que cambió
            if (str_contains($valor['v'], 'data:image/')) {
                $marcaToken = $item->marca->token ?? false;
                $name = md5(uniqid()) . '.png';
                $dir = "{$marcaToken}/{$item->token}/{$name}";
                $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $valor['v']));
                $disk = Storage::disk('s3');
                $path = $disk->put($dir, $image);
                $campo->isFile = 1;
                $campo->valorLong = $dir;
            }
        } else {
            if (is_array($valor['v'])) {
                $campo->valorLong = json_encode($valor['v'], JSON_FORCE_OBJECT);
            }
            else {
                $campo->valorLong = $valor['v'];
                if (!empty($campo->useForSearch)) {
                    $campo->searchField = trim(substr($campo->valorLong, 0, 145));
                }
            }
        }
        $campo->valorShow = (!empty($valor['vs']) ? $valor['vs'] : null);
        $campo->save();

        if (empty($item->isFilled)) {
            $item->isFilled = 1;
            $item->save();
        }

        return $this->ResponseSuccess('Cambios ejecutados con exito', $campoKey);
    }

    public function createFieldCuenta(Request $request){
        $fechaLimite = '2023-12-13';
        $flujos =
            Cotizacion::
            whereDate('dateCreated', '>=', $fechaLimite)
                ->where('nodoActual', '!=', null)
                ->where(function ($query) {
                    $query->where('productoId', 1)
                        ->orWhere('productoId', 6);
                })
                ->whereIn('id', function ($query) {
                    $query->select('cotizacionId')
                        ->from('cotizacionesDetalle')
                        ->where('campo', '=', 'WSA.soapBody.AsistenciaResponse.nombre_cuenta');
                })
                ->whereIn('id', function ($query) {
                    $query->select('cotizacionId')
                        ->from('cotizacionesDetalle')
                        ->whereNotExists(function ($queryNotExists) {
                            $queryNotExists->select(DB::raw(1))
                                ->from('cotizacionesDetalle as cd')
                                ->whereColumn('cd.cotizacionId', 'cotizaciones.id')
                                ->where('cd.campo', '=', 'cuenta');
                        });
                })
                ->get();
        $data = [];
        foreach ($flujos as $flujo) {
            $cotizacionId = $flujo -> id;
            $cuenta = CotizacionDetalle::
            where('campo', 'WSA.soapBody.AsistenciaResponse.nombre_cuenta')
                ->where('cotizacionId', $cotizacionId)
                ->first();

            $valCuenta = 'Otros';
            $campo = new CotizacionDetalle();
            $campo->cotizacionId = $cotizacionId;
            $campo->campo = 'cuenta';
            $campo->label = 'Seleccione cuenta:';
            $campo->tipo = 'select';

            if($cuenta->valorLong === 'ROBLE VIAL'){
                $valCuenta = 'El Roble';
            }

            $campo->valorLong = $valCuenta;
            $campo->valorShow = $valCuenta;
            $campo->useForSearch = 0;
            $campo->save();
            $data[] = $cotizacionId;
        }

        if(count($data) < 1) return $this->ResponseError('TASK-0001', 'No hay mas formularios');
        return $this->ResponseSuccess('Cambios ejecutados con exito', $data);
    }

    public function changeStateExpired(){
        $todayDate = Carbon::now()->setTimezone('America/Guatemala')->toDateString();
        $updatedRows = Cotizacion::whereRaw("DATE(dateExpire) < ?", [$todayDate])
            ->whereNotIn('estado', ['expirado', 'finalizado', 'cancelado'])
            ->update(['estado'=> 'expirado']);
        return $this->ResponseSuccess('Actualizacion de estado exitosa', $updatedRows);
    }

    public function linkingCotizacionesPublic(Request $request){
        return $this->linkingCotizaciones($request, true);
    }

    public function linkingCotizaciones(Request $request, $public = false){
        $token = $request->get('token');
        $lToken = $request->get('lToken');

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
        }
        $userHandler = new AuthController();

        $cotizacionLToken = Cotizacion::where([['token', '=', $lToken]])->first();

        $cotizacion = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($cotizacionLToken)) {
            return $this->ResponseError('TASK-731', 'Tarea no válida');
        }

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-732', 'Tarea no válida');
        }

        $producto = $cotizacion->producto;
        if (empty($producto)) {
            return $this->ResponseError('TASK-700', 'Flujo no válido');
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-701', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-701', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }
        foreach ($flujoConfig['nodes'] as $nodo) {
            if (!empty($nodo['formulario']['secciones']) && count($nodo['formulario']['secciones']) > 0) {
                foreach ($nodo['formulario']['secciones'] as $keySeccion => $seccion) {
                    foreach ($seccion['campos'] as $campo) {
                        $campoLToken = CotizacionDetalle::where('campo', $campo['id'])->where('cotizacionId', $cotizacionLToken->id)->first();
                        $campoExistente = CotizacionDetalle::where('campo', $campo['id'])->where('cotizacionId', $cotizacion->id)->first();
                        if(!empty($campoLToken) && empty($campoExistente)){
                            $newCampo = new CotizacionDetalle();
                            $newCampo->cotizacionId = $cotizacion->id;
                            $newCampo->seccionKey = $keySeccion;
                            $newCampo->campo = $campo['id'];
                            $newCampo->useForSearch =$campo['showInReports'] ? 1 : 0;
                            $newCampo->tipo = $campo['tipoCampo'] ?? 'default';
                            $newCampo->valorLong = $campoLToken -> valorLong;
                            $newCampo->valorShow = $campoLToken -> valorShow;
                            $newCampo->save();
                        }
                    }
                }
            }
        }

        return $this->ResponseSuccess('Cambios ejecutados con exito');

    }

    public function ObtainProcessField (Request $request, $public = false){
        $proceso = $request->get('proceso');
        $cotizacionId = $request->get('token');
        $cotizacion = Cotizacion::where([['token', '=', $cotizacionId]])->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('TASK-632', 'Cotización no válida');
        }

        $resultado = $this->consumirServicio($proceso, $cotizacion->campos, true);

        if (empty($resultado['status'])) {
            return $this->ResponseError('CC-01', "Ha ocurrido realizando el proceso de envío de datos. {$resultado['msg']}");
        }

        return $this->ResponseSuccess('Consulta Ejecutada con Exito', $resultado['data']);
    }

    public function ObtainProcessFieldPublic (Request $request){
        return $this->ObtainProcessField($request, true);
    }

    public function saveOCRTableCat (Request $request){
        $row = $request->get('row');
        $tokenId = $request->get('tokenId');
        $campo = $request->get('campo');
        $option = $request->get('option');
        $token = $request->get('token');

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
        }
        $userHandler = new AuthController();

        $item = Cotizacion::where([['token', '=', $token]])->first();

        if (empty($item)) {
            return $this->ResponseError('TASK-015', 'Tarea inválida');
        }

        $beforeOptions = CotizacionDetalleCatalogo::where('cotizacionId', $item->id)
            ->where('tokenId', $tokenId)
            ->where('row', $row)
            ->where('campo', $campo)
            ->delete();

        foreach($option as $valorKey => $valorLong){
            $newCatOp = new CotizacionDetalleCatalogo();
            $newCatOp->cotizacionId = $item->id;
            $newCatOp->tokenId = $tokenId;
            $newCatOp->row = $row;
            $newCatOp->campo = $campo;
            $newCatOp->valorKey = $valorKey;
            $newCatOp->valorLong = $valorLong;
            $newCatOp->save();
        }

        return $this->ResponseSuccess('Cambios ejecutados con exito');
    }

    // expirar tareas
    public function ExpirarTareas() {

        $fechaHoy = Carbon::now()->format('Y-m-d H:i:s');
        Cotizacion::where([['dateExpire', '<=', $fechaHoy]])->update(['estado' => 'expirada']);
        return $this->ResponseSuccess('Tareas expiradas con éxito');
    }

    // Notificar falta de atención
    public function NotificarFaltaAtencion() {

        // traigo alertas
        $alertTmp = Alerta::where('activo', 1)->get();
        $alertas = [];
        foreach ($alertTmp as $tmp) {
            $alertas[$tmp->id] = [
                'type' => $tmp->type,
                'modoActivo' => $tmp->modoActivo,
                'textAlert' => $tmp->textAlert,
                'configData' => json_decode($tmp->configData, true),
            ];
        }
        unset($alertTmp);

        $cache = ClassCache::getInstance();

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $cotizaciones = Cotizacion::where([['dateNotifyNoAtention', '<=', $now], ['countNotifyNoAtention', '<=', 3]])->take(10)->get();

        $processed = [];

        foreach ($cotizaciones as $item) {

            if (!empty($item->alertIdNotifyNoAtention) && isset($alertas[$item->alertIdNotifyNoAtention])) {

                $fechaHoy = Carbon::now();
                $fechaUltimaAtencion = Carbon::parse($item->dateNotifyNoAtention);
                $fechaDiff = $fechaHoy->diffInDays($fechaUltimaAtencion);
                $fechaDiff = $fechaDiff + 1; // le sumo 1 porque para zona horaria siempre era 0

                $request = new \Illuminate\Http\Request();
                //$request->replace(['foo' => 'bar']);
                $flujo = $this->CalcularPasos($request, true, false, true, $item);

                if (!is_array($flujo) || (empty($flujo['next']) || empty($flujo['actual']))) {
                    $item->alertIdNotifyNoAtention = null;
                    $item->save();
                    continue;
                }

                $destinos = "";
                $alertContent = <<<EOD
                                <!DOCTYPE html>
                                <html>
                                  <head>
                                    <title>Notificación de falta de atención</title>
                                    <meta charset="UTF-8">
                                  </head>
                                  <body>
                                    <h1>Estimado usuario de Cloud Workflow</h1>
                                    <p>Le notificamos que la tarea <b>No. {$item->id}</b> se encuentra sin atención</p>
                                    <p>Por favor atender a la brevedad, muchas gracias</p>
                                  </body>
                                </html>
EOD;

                if($alertas[$item->alertIdNotifyNoAtention]['modoActivo'] === 'DEV') {
                    $destinos = $alertas[$item->alertIdNotifyNoAtention]['configData']['destDev'];
                }
                else {
                    $destinos = $alertas[$item->alertIdNotifyNoAtention]['configData']['destProd'];
                }

                $usuarioAsig = $item->usuarioAsignado;

                if ($alertas[$item->alertIdNotifyNoAtention]['type'] === 'EMAIL') {
                    if (!empty($alertas[$item->alertIdNotifyNoAtention]['textAlert'])) {
                        $alertContent = <<<EOD
                                <!DOCTYPE html>
                                <html>
                                  <head>
                                    <title>Notificación de falta de atención</title>
                                    <meta charset="UTF-8">
                                  </head>
                                  <body>
                                    {$alertas[$item->alertIdNotifyNoAtention]['textAlert']}
                                  </body>
                                </html>
EOD;
                    }

                    // envía alerta por EMAIL
                    try {
                        // reemplazo por USUARIO_ASIGNADO
                        $destinos = str_replace('{{USUARIO_ASIGNADO}}', $usuarioAsig->email ?? '', $destinos);
                        $alertContent = str_replace('{{DIAS_FALTA_ATENCION}}', $fechaDiff, $alertContent);
                        $alertContent = $this->reemplazarValoresSalida($item->campos, $alertContent);


                        // Salida de email
                        $mailgun = $this->GetBrandConfig($item->marcaId, 'mailgun');
                        $domainSalida = $mailgun['MAILGUN_DEFAULT_DOMAIN'] ?? env('MAILGUN_DEFAULT_DOMAIN');
                        $from = $mailgun['MAILGUN_DEFAULT_SENDER'] ?? env('MAILGUN_DEFAULT_SENDER');
                        $mg = Mailgun::create($mailgun['MAILGUN_SEND_API_KEY'] ?? env('MAILGUN_SEND_API_KEY')); // For US servers
                        $arrConfig = [
                            'from' => $from,
                            'to' => $destinos ?? '',
                            'subject' => "Cloud Workflow - Tarea No.{$item->id} sin atender",
                            'html' => $alertContent,
                        ];

                        $email = $mg->messages()->send($domainSalida, $arrConfig);

                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = null;
                        $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                        $bitacoraCoti->logType = 'email';
                        $bitacoraCoti->log = "Error al notificar falta de atención: \"{$destinos}\"";
                        $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                        $bitacoraCoti->save();
                        // return $this->ResponseSuccess( 'Si tu cuenta existe, llegará un enlace de recuperación');
                    }
                    catch (HttpClientException $e) {
                        // Guardo la bitácora
                        $bitacoraCoti = new CotizacionBitacora();
                        $bitacoraCoti->cotizacionId = $item->id;
                        $bitacoraCoti->usuarioId = null;
                        $bitacoraCoti->nodoName = $this->nodoLabel($flujo['next']['nodoName'] ?? '');
                        $bitacoraCoti->logType = 'error';
                        $bitacoraCoti->nodoId = $flujo['next']['id'] ?? null;
                        $bitacoraCoti->log = "Error al enviar correos electrónicos: \"{$destinos}\" desde \"{$from}\", dominio de salida: {$domainSalida}";
                        $bitacoraCoti->save();
                    }
                }

                $notifyNoAtenTipo = $flujo['actual']['noAttNType'] ?? '';
                $notifyNoAtenTiempo = intval($flujo['actual']['noAttN'] ?? 0);
                $notifyNoAtenAlertId = $flujo['actual']['noAttId'] ?? false;

                $fechaHoy = Carbon::now();
                $fechaNuevaNotify = null;
                if (!empty($notifyNoAtenTipo) && !empty($notifyNoAtenTiempo) && !empty($notifyNoAtenAlertId)) {

                    if ($notifyNoAtenTipo === 'D') {
                        $fechaNuevaNotify = $fechaHoy->addDays($notifyNoAtenTiempo)->format('Y-m-d H:i:s');
                    }
                    else if ($notifyNoAtenTipo === 'H') {
                        $fechaNuevaNotify = $fechaHoy->addHours($notifyNoAtenTiempo)->format('Y-m-d H:i:s');
                    }
                    else if ($notifyNoAtenTipo === 'M') {
                        $fechaNuevaNotify = $fechaHoy->addMinutes($notifyNoAtenTiempo)->format('Y-m-d H:i:s');
                    }
                    if (!empty($fechaNuevaNotify)) {
                        $item->dateNotifyNoAtention = $fechaNuevaNotify;
                        $item->alertIdNotifyNoAtention = $notifyNoAtenAlertId;
                        $item->save();
                    }
                }

                $item->countNotifyNoAtention = (intval($item->countNotifyNoAtention) + 1);
                $item->save();

                $processed[] = $item->id;
            }
        }

        return $this->ResponseSuccess('Tareas notificadas con éxito', $processed);
    }


    // firma electrónica avanzada
    public function startFirmaElectronica(Request $request) {

        $token = $request->get('t');
        $campoId = $request->get('c');

        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        if (!empty($usuarioLogueadoId)) {
            $AC = new AuthController();
            //if (!$AC->CheckAccess(['tareas/admin/cambio-paso'])) return $AC->NoAccess();
        }

        $cotizacion = Cotizacion::where('token', $token)->first();

        return $this->ResponseError('DGS-01', 'Módulo inactivo');

        if (empty($cotizacion)) {
            return $this->ResponseError('DGS-01', 'Tarea inválida');
        }

        $cotizacionDetalle = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->where('id', $campoId)->first();
        if (empty($cotizacionDetalle)) {
            return $this->ResponseError('DGS-04', 'Archivo a firmar inválido');
        }

        $producto = $cotizacion->producto;
        if (empty($producto)) {
            return $this->ResponseError('TASK-700', 'Producto no válido');
        }

        $flujo = $producto->flujo->first();
        if (empty($flujo)) {
            return $this->ResponseError('TASK-701', 'Flujo no válido');
        }

        $flujoConfig = @json_decode($flujo->flujo_config, true);
        if (!is_array($flujoConfig)) {
            return $this->ResponseError('TASK-701', 'Error al interpretar flujo, por favor, contacte a su administrador');
        }

        // Recorro campos para hacer resumen
        $camposSalidaFirma = [];
        foreach ($flujoConfig['nodes'] as $nodo) {

            if ($cotizacionDetalle->campo !== $nodo['salidaPDFId']) continue;

            // salidas
            if (!empty($nodo['salidaIsPDF']) && !empty($nodo['salidaPdfS'])) {
                $camposSalidaFirma[$nodo['salidaPDFId']] = [
                    'nombre' => $nodo['sPdfN'] ?? '',
                    'apellido' => $nodo['sPdfAP'] ?? '',
                    'telefono' => $nodo['sPdfT'] ?? '',
                    'correo' => $nodo['sPdfC'] ?? '',
                    'ciudad' => $nodo['sPdfCT'] ?? '',
                    'direccion' => $nodo['sPdfD'] ?? '',
                ];
            }
        }

        if (empty($camposSalidaFirma[$cotizacionDetalle->campo])) {
            return $this->ResponseError('TASK-703', 'Error al cargar configuración de firma, intente nuevamente');
        }

        // Detalle completo
        $allFields = [];
        $allDetalle = CotizacionDetalle::where('cotizacionId', $cotizacion->id)->get();
        foreach ($allDetalle as $detalle) {
            $allFields[$detalle->campo] = $detalle->valorLong;
        }

        // reemplazo de valores
        foreach ($camposSalidaFirma[$cotizacionDetalle->campo] as $key => $value) {
            $camposSalidaFirma[$cotizacionDetalle->campo][$key] = $allFields[$value] ?? '';
        }


        // trae la firma asociada a ese archivo
        $firmaElectronica = FirmaElectronica::where('marcaId', $cotizacion->marcaId)->where('cotizacionDetalleId', $campoId)->first();

        if (!empty($firmaElectronica->linkFirma)) {
            return $this->ResponseSuccess('El proceso de firma ya ha sido iniciado');
        }

        $data = [
            "username" => "1108124",
            "password" => "29yqdGGw",
            "pin" => "belorado74*",
            "mobile_phone_number" => "+502{$camposSalidaFirma[$cotizacionDetalle->campo]['nombre']}",
            "email" => $camposSalidaFirma[$cotizacionDetalle->campo]['correo'],
            "registration_authority" => "98",
            "profile" => "CCPNIndividual",
            "residence_city" => $camposSalidaFirma[$cotizacionDetalle->campo]['ciudad'],
            "residence_address" => $camposSalidaFirma[$cotizacionDetalle->campo]['direccion'],
            "videoid_mode" => 1,
            "billing_username" => "ccg@ccg",
            "billing_password" => "dDJHOVQ3MU8=",
            "env" => "sandbox",
            "token" => "9accf22868f54da2ac7e61a30ddd84e6",
            "given_name" => $camposSalidaFirma[$cotizacionDetalle->campo]['nombre'],
            "surname_1" => $camposSalidaFirma[$cotizacionDetalle->campo]['nombre'],
            "surname_2" => $camposSalidaFirma[$cotizacionDetalle->campo]['apellido']
        ];


        $headers = array(
            'Content-Type: application/json',
        );

        $ch = curl_init(env('ADVANCED_SIGNATURE_URL', '').'/api/v1/videoid');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        $dataResponse = @json_decode($data, true);

        // var_dump($dataResponse);

        if (!empty($dataResponse)) {
            if (!empty($dataResponse['details']['videoid_link'])) {
                if (empty($firmaElectronica)) {
                    $firmaElectronica = new FirmaElectronica();
                    $firmaElectronica->marcaId = $cotizacion->marcaId;
                    $firmaElectronica->cotizacionId = $cotizacion->id;
                    $firmaElectronica->cotizacionDetalleId = $campoId;
                    $firmaElectronica->usuarioId = $usuarioLogueadoId;
                    $firmaElectronica->estado = 'creada';
                    $firmaElectronica->linkFirma = $dataResponse['details']['videoid_link'];
                    $firmaElectronica->identificadorPk = $dataResponse['details']['videoid_pk'];
                    $firmaElectronica->requestPk = $dataResponse['details']['request_pk'];
                    $firmaElectronica->save();
                }

                return $this->ResponseSuccess('Firma iniciada con éxito', $firmaElectronica->linkFirma);
            }
            else {
                return $this->ResponseError('DGS-03', 'Error al generar enlace de firma, por favor, intente de nuevo');
            }
        }
        else {
            return $this->ResponseError('DGS-02', 'No es posible comunicarse con el certificador de firma, intente más tarde');
        }
    }

    public function getSignaturesForUser(Request $request) {

        $token = $request->get('t');

        $cotizacion = Cotizacion::where('token', $token)->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('DGP-01', 'Tarea inválida');
        }

        // trae la firma asociada a ese archivo
        $firmaElectronica = FirmaElectronica::where('marcaId', $cotizacion->marcaId)->where('cotizacionId', $cotizacion->id)->where('estado', 'creada')->get();

        $firmas = [];
        foreach ($firmaElectronica as $firma) {

            if (empty($firma->detalle->valorLong)) continue;

            $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($firma->detalle->valorLong, now()->addMinutes(60));

            $firmas[] = [
                'i' => $firma->cotizacionDetalleId,
                'l' => $firma->linkFirma,
                'lf' => $temporarySignedUrl,
                'n' => $firma->detalle->label,
            ];
        }

        return $this->ResponseSuccess('Ok', $firmas);
    }

    public function validarFirmaElectronica(Request $request) {

        $token = $request->get('t');
        $firmaId = $request->get('i');

        $cotizacion = Cotizacion::where('token', $token)->first();

        if (empty($cotizacion)) {
            return $this->ResponseError('DGP-010', 'Tarea inválida');
        }

        // trae la firma asociada a ese archivo
        $firmaElectronica = FirmaElectronica::where('marcaId', $cotizacion->marcaId)->where('id', $firmaId)->where('estado', 'creada')->first();

        if (empty($firmaElectronica)) {
            return $this->ResponseError('DGP-11', 'Firma electrónica inválida');
        }
        // var_dump($firmaElectronica);

        $data = [
            "username" => "1108124",
            "password" => "29yqdGGw",
            "pin" => "belorado74*",
            "token" => "9accf22868f54da2ac7e61a30ddd84e6",
            "rao" => "",
        ];


        return $this->ResponseError('DGP-12', 'Error al validar firma, no puede emitir certificados digitales en sandbox');

        $headers = array(
            'Content-Type: application/json',
        );

        $url = env('ADVANCED_SIGNATURE_URL', '').'/api/request/'.$firmaElectronica->requestPk.'/approve';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        $dataResponse = @json_decode($data, true);

        var_dump($dataResponse);


        die;

        $firmas = [];
        foreach ($firmaElectronica as $firma) {

            if (empty($firma->detalle->valorLong)) continue;

            $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($firma->detalle->valorLong, now()->addMinutes(60));

            $firmas[] = [
                'i' => $firma->cotizacionDetalleId,
                'l' => $firma->linkFirma,
                'lf' => $temporarySignedUrl,
                'n' => $firma->detalle->label,
            ];
        }

        return $this->ResponseSuccess('Ok', $firmas);
    }

}
