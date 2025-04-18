<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Extra\ClassCache;
use App\Extra\Tools;
use App\Models\Clientes;
use App\Models\Etapas;
use App\Models\Expedientes;
use App\Models\ExpedientesEtapas;
use App\Models\Flujos;
use App\Models\FlujoConexion;
use App\Models\Marca;
use App\Models\Productos;
use App\Models\RequisitosCategorias;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductosController extends Controller {

    use Response;

    /**
     * Get Steps
     * @param Request $request
     * @return array|false|string
     */
    public function getProductosFilter(Request $request) {
        if (!empty($request->idProducto)) {
            $productos = DB::table('productos')->where('marcaId', SSO_BRAND_ID)->where('productos.id', '=', $request->idProducto)->get(['id', 'nombreProducto']);

        }
        else {
            $productos = DB::table('productos')->where('marcaId', SSO_BRAND_ID)->groupBy('productos.id')->get(['id', 'nombreProducto']);
        }

        try {
            return $this->ResponseSuccess('Ok', $productos);
        } catch (\Throwable $th) {
            return $this->ResponseError('PROD-854', 'Error al obtener productos' . $th);
        }
    }

    public function getProductsList() {

        $productos = DB::table('productos')->where('marcaId', SSO_BRAND_ID)->groupBy('productos.id')->get();
        $authHandler = new AuthController();

        $arrProductos = [];
        foreach ($productos as $producto) {

            $pr = $producto;
            if (isset($producto->extraData) && $producto->extraData !== '') {
                $pr->extraData = json_decode($producto->extraData, true);
                $pr->roles_assign = $pr->extraData['roles_assign'] ?? [];
                $pr->grupos_assign = $pr->extraData['grupos_assign'] ?? [];
                $pr->canales_assign = $pr->extraData['canales_assign'] ?? [];
            }

            $access = $authHandler->CalculateVisibility(SSO_USER_ID, SSO_USER_ROL_ID, false, $pr->roles_assign ?? [], $pr->grupos_assign ?? [], $pr->canales_assign ?? []);
            if (!$access) continue;
            $arrProductos[] = $producto;
        }

        return $this->ResponseSuccess('Ok', $arrProductos);
    }

    public function getProducts(Request $request, $token = false, $validateAccess = false) {

        $removeCatalogos = $request->get('rc');

        if (!empty($request->idProducto)) {
            $productos = DB::table('productos')->where('marcaId', SSO_BRAND_ID)->where('productos.id', '=', $request->idProducto)->get();

        }
        else if (!empty($token)){
            $productos = DB::table('productos')->where('productos.token', '=', $token)->get();
        }
        else {
            $productos = DB::table('productos')->where('marcaId', SSO_BRAND_ID)->groupBy('productos.id')->get();
        }
        //dd($productos);
        //dd(md5(uniqid()).uniqid());

        $productos->map(function ($producto) use ($removeCatalogos) {
            if (isset($producto->extraData) && $producto->extraData !== '') {
                $producto->extraData = json_decode($producto->extraData, true);
            }
            $flujo = Flujos::Where('productoId', '=', $producto->id)->Where('activo', '=', 1)->first();
            if (!empty($flujo)) {
                $producto->flujo = @json_decode($flujo->flujo_config, true);
                $producto->flujoId = $flujo->id;
                $producto->modoPruebas = $flujo->modoPruebas;

                /*if (!empty($usuarioLogueado)) {
                    $producto->userVars = @json_decode($usuarioLogueado->userVars);
                }*/
            }
            else {
                $producto->flujo = [];
                $producto->flujoId = 0;
                $producto->modoPruebas = 0;
                $producto->userVars = [];
            }

            if (!$removeCatalogos) {
                $producto->roles_assign = $producto->extraData['roles_assign'] ?? [];
                $producto->grupos_assign = $producto->extraData['grupos_assign'] ?? [];
                $producto->canales_assign = $producto->extraData['canales_assign'] ?? [];
            }

            // no devuelvo catálogos
            if ($removeCatalogos && isset($producto->extraData['planes'])) {
                $producto->flujo = false;
                unset($producto->extraData['planes']);
            }

            // links de productos banners
            $producto->bannerSP = (!empty($producto->bannerSP)) ? Storage::disk('s3')->temporaryUrl($producto->bannerSP, now()->addMinutes(60)) : '';
            $producto->bannerSM = (!empty($producto->bannerSM)) ? Storage::disk('s3')->temporaryUrl($producto->bannerSM, now()->addMinutes(60)) : '';


            return $producto;
        });

        $authHandler = new AuthController();

        $arrProductos = [];
        foreach ($productos as $pr) {
            if ($validateAccess) {
                //dd($pr->roles_assign);
                //dd($pr);
                $access = $authHandler->CalculateVisibility(SSO_USER_ID, SSO_USER_ROL_ID, false, $pr->roles_assign ?? [], $pr->grupos_assign ?? [], $pr->canales_assign ?? []);
                if (!$access) continue;
            }
            $arrProductos[] = $pr;
        }

        try {
            return $this->ResponseSuccess('Ok', $arrProductos);
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-AF60F', 'Error al generar pasos' . $th);
        }
    }

    public function editProductos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        $validateForm = Validator::make($request->all(), ['nombreProducto' => 'required|string', 'descripcion' => '', 'isVirtual' => '', 'extraData' => '', 'id' => 'required', 'imagenData' => '', 'codigoInterno' => '', 'imagen' => '']);

        if ($validateForm->fails()) {
            return $this->ResponseError('AUTH-AdfF10dsF', 'Faltan Campos');
        }

        $cache = ClassCache::getInstance();

        $marca = Marca::where('id', SSO_BRAND_ID)->first();
        if (empty($marca)) {
            return $this->ResponseError('T-15', 'Error al subir archivo, marca inválida');
        }


        // guardamos la imagen al s3
        $bannerSuperiorPc = $request->bannerSP ?? false;
        $bannerSuperiorMv = $request->bannerSM ?? false;

        $saveBanners = function ($imageBase64, $productoId, $name) use ($marca){
            $tools = new Tools();
            $bannerFile = $tools->saveImgBase64ToFile($imageBase64);

            if ($bannerFile) {
                $dir = "{$marca->token}/_banners/{$productoId}";
                $disk = Storage::disk('s3');
                $path = $disk->putFileAs($dir, $bannerFile['path'], "{$name}.{$bannerFile['ext']}");
                if (file_exists($bannerFile['path'])) unlink($bannerFile['path']);
                return $path;
            }
            else {
                return false;
            }
        };

        if ($request->id === 0) {
            $producto = new Productos();
            $producto->marcaId = SSO_BRAND_ID;
            $producto->nombreProducto = $request->nombreProducto ?? '';
            $producto->version = uniqid();
            $producto->descripcion = $request->descripcion ?? '';
            $producto->codigoInterno = $request->codigoInterno ?? '';
            $producto->imagenData = $request->imagenData ?? '';
            $producto->isVirtual = $request->isVirtual ?? 0;
            $producto->logoDes = $request->logoDes ?? 0;
            $producto->status = $request->status ?? 0;
            $producto->extraData = json_encode($request->extraData ?? '');
            $producto->token = trim(bin2hex(random_bytes(6))) . time();
            $producto->save();

            if (!empty($bannerSuperiorPc) || !empty($bannerSuperiorMv)) {

                // parece locura pero evita el intento de subida
                if (!empty($bannerSuperiorPc)) {
                    $bannerSuperiorPc = $saveBanners($bannerSuperiorPc, $producto->id, 'BSP');
                    $producto->bannerSP = $bannerSuperiorPc;
                }
                if (!empty($bannerSuperiorMv)) {
                    $bannerSuperiorMv = $saveBanners($bannerSuperiorMv, $producto->id, 'BSM');
                    $producto->bannerSM = $bannerSuperiorMv;
                }

                $producto->save();
            }

            $cache->setMemcached("producto_{$producto->id}", false, 300);
            $cache->setMemcached("pr_estados_{$producto->id}", false, 300);

            return $this->ResponseSuccess('Ok', $producto);
        }
        else {
            $producto = Productos::where('marcaId', SSO_BRAND_ID)->where('id', $request->id)->first();
            if (!empty($producto)) {
                $producto->nombreProducto = $request->nombreProducto ?? '';
                $producto->descripcion = $request->descripcion ?? '';
                $producto->version = uniqid();
                $producto->codigoInterno = $request->codigoInterno ?? '';
                $producto->imagenData = $request->imagenData ?? '';
                $producto->isVirtual = $request->isVirtual ?? 0;
                $producto->logoDes = $request->logoDes ?? 0;
                $producto->status = $request->status ?? 0;
                $producto->extraData = json_encode($request->extraData ?? '');
                $producto->save();

                if (!empty($bannerSuperiorPc) || !empty($bannerSuperiorMv)) {

                    // parece locura pero evita el intento de subida
                    if (!empty($bannerSuperiorPc)) {
                        $bannerSuperiorPc = $saveBanners($bannerSuperiorPc, $producto->id, 'BSP');
                        $producto->bannerSP = $bannerSuperiorPc;
                    }
                    if (!empty($bannerSuperiorMv)) {
                        $bannerSuperiorMv = $saveBanners($bannerSuperiorMv, $producto->id, 'BSM');
                        $producto->bannerSM = $bannerSuperiorMv;
                    }

                    $producto->save();
                }

                $cache->setMemcached("producto_{$producto->id}", false, 300);
                $cache->setMemcached("pr_estados_{$producto->id}", false, 300);

                return $this->ResponseSuccess('Ok', $producto);
            }
            else {
                return $this->ResponseError('AUTH-AFd10dsF', 'Producto no existe');
            }
        }
    }

    public function deleteProductos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        $validateForm = Validator::make($request->all(), ['id' => 'required',

        ]);

        if ($validateForm->fails()) {
            return $this->ResponseError('AUTH-AdfF10dsF', 'Faltan Campos');
        }
        if ($request->id === 0) {
            return $this->ResponseError('AUTH-AFdd10dsF', 'Producto no existe');
        }
        else {
            $producto = Productos::where('marcaId', SSO_BRAND_ID)->where('id', $request->id)->first();
            if (!empty($producto)) {
                $producto->delete();
                return $this->ResponseSuccess('Eliminado con éxito', $producto);
            }
            else {
                return $this->ResponseError('AUTH-AFd10dsF', 'Producto no existe');
            }
        }
    }

    public function getProductsPanel(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        $tareasController = new TareaController();
        $productos = $tareasController->GetProductsFilter(true);
        return $this->ResponseSuccess('Flujos obtenidos con éxito', $productos);
    }

    public function copyProductos(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        $validateForm = Validator::make($request->all(), ['id' => 'required']);

        if ($validateForm->fails()) {
            return $this->ResponseError('AUTH-AdfF10dsF', 'Faltan Campos');
        }
            $producto = Productos::where('marcaId', SSO_BRAND_ID)->where('id', $request->id)->first();
            $flujo = Flujos::Where('productoId', '=', $producto->id)->Where('activo', '=', 1)->first();

            if (!empty($producto)) {
                $nuevaCopia = new Productos();
                $nuevaCopia->marcaId = SSO_BRAND_ID;
                $nuevaCopia->nombreProducto = 'Nueva copia de ' . $producto->nombreProducto;
                $nuevaCopia->descripcion = $producto->descripcion ?? '';
                $nuevaCopia->codigoInterno = $producto->codigoInterno ?? '';
                $nuevaCopia->imagenData = $producto->imagenData ?? '';
                $nuevaCopia->isVirtual = $producto->isVirtual ?? 0;
                $nuevaCopia->status = $producto->status ?? 0;
                $nuevaCopia->extraData = $producto->extraData?? '';
                $nuevaCopia->token = md5(uniqid()).uniqid();
                $nuevaCopia->status = $producto->prActivo;
                $nuevaCopia->save();

                $nuevoflujo = new Flujos();
                $nuevoflujo->nombre = $flujo->nombre??'';
                $nuevoflujo->flujo_config = $flujo->flujo_config;
                $nuevoflujo->productoId = $nuevaCopia->id;
                $nuevoflujo->activo = $flujo->activo;
                $nuevoflujo->modoPruebas = $flujo->modoPrueba;
                $nuevoflujo->save();

                return $this->ResponseSuccess('Ok', $nuevaCopia);
            }
            else {
                return $this->ResponseError('AUTH-AFd10dsF', 'Producto no existe');
            }
    }

    public function downloadCatalogo(Request $request){
        try{
            // ordenar datos finales
            $AC = new AuthController();
            if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();
            $usuarioLogueado = auth('sanctum')->user();

            $datosFinal = $request->get('dataToSend');

            $spreadsheet = new Spreadsheet();

            $spreadsheet
                ->getProperties()
                ->setCreator("GastosMedicos-ElRoble")
                ->setLastModifiedBy('Automator') // última vez modificado por
                ->setTitle('Reporte de '. $usuarioLogueado->name)
                ->setDescription('Reporte');

            // first sheet
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Hoja 1");
            $sheet->fromArray($datosFinal, NULL, 'A1');

            $writer = new Xlsx($spreadsheet);
            $fileNameHash = md5(uniqid());
            $tmpPath = storage_path("tmp/{$fileNameHash}.xlsx");
            $writer->save($tmpPath);

            $disk = Storage::disk('s3');
            $path = $disk->putFileAs("/tmp/files", $tmpPath, "{$fileNameHash}.xlsx");
            $temporarySignedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(10));

            return $this->ResponseSuccess('Reporte generado con éxito', ['url' => $temporarySignedUrl]);

        } catch (\Throwable $th){
            return $this->ResponseError('AUTH-AF65F', 'Error' . $th);
        }
    }

    public function getGraph(Request $request) {

        $AC = new AuthController();
        //if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        $fechaIni = $request->get('fechaIni');
        $fechaFin = $request->get('fechaFin');
        $fechaIni = Tools::dateConvertToDB($fechaIni, true);
        $fechaFin = Tools::dateConvertToDB($fechaFin, false, true);

        $userHandler = new AuthController();
        $CalculateAccess = $userHandler->CalculateAccess();
        /*$items = Cotizacion::where([['dateCreated', '>=', $fechaIni], ['dateCreated', '<=', $fechaFin]])->whereIn('usuarioIdAsignado', $CalculateAccess['all']);
        $items = $items->with(['usuario', 'usuarioAsignado', 'producto', 'campos'])->limit(10)->orderBy('id', 'DESC')->get();*/

        $usuarios = implode(",", $CalculateAccess['all']);

        $cotizaciones = [];
        // conteo por productos
        $strQueryFull = "SELECT COUNT(C.id) as c, P.nombreProducto as p, P.id as pid
                        FROM cotizaciones AS C
                        JOIN productos AS P ON C.productoId = P.id
                        WHERE 
                            C.usuarioIdAsignado IN ($usuarios)
                            AND C.dateCreated >= '{$fechaIni}'
                            AND C.dateCreated <= '{$fechaFin}'
                        GROUP BY P.nombreProducto, P.id";

        /*var_dump($strQueryFull);
        die();*/
        $cotizaciones = DB::select(DB::raw($strQueryFull));

        // conteo por productos
        /*$strQueryFull = "SELECT COUNT(C.id) as c, YEAR(C.dateCreated) as anio, MONTH(C.dateCreated) as mes
                        FROM cotizaciones AS C
                        WHERE
                            C.usuarioIdAsignado IN ($usuarios)
                        GROUP BY YEAR(C.dateCreated), MONTH(C.dateCreated)";

        $cotizacionesY = DB::select(DB::raw($strQueryFull));

        $porYear = [];
        foreach ($cotizacionesY as $item) {
            if (!isset($porYear[$item->anio][$item->mes])) $porYear[$item->anio][$item->mes] = 0;
            $porYear[$item->anio][$item->mes] = $porYear[$item->anio][$item->mes] + $item->c;
        }*/

        return $this->ResponseSuccess('Gráfica obtenida con éxito', [
            'p' => $cotizaciones
        ]);
    }

    // conexiones
    public function getConnections(Request $request) {

        $conexiones = FlujoConexion::where('marcaId', SSO_BRAND_ID)->get(['id', 'token', 'nombre', 'type', 'url', 'urlDev', 'activo', 'responseData']);

        $response = [];
        foreach ($conexiones as $conexion) {

            $tmpFields = @json_decode($conexion->responseData, true);
            //var_dump($tmpFields);

            $fields = [];
            if (isset($tmpFields['parsed']) && is_array($tmpFields['parsed'])) {

                foreach ($tmpFields['parsed'] as $key => $tmp) {
                    $fields[] = $key;
                }
            }

            $response[] = [
                'id' => $conexion->id,
                'token' => $conexion->token,
                'nombre' => $conexion->nombre,
                'type' => $conexion->type,
                'url' => $conexion->url,
                'urlDev' => $conexion->urlDev,
                'activo' => $conexion->activo,
                'fields' => $fields,
            ];
        }

        return $this->ResponseSuccess('Ok', $response);
    }

    public function getConnection(Request $request) {
        $id = $request->get('id');
        $conexiones = FlujoConexion::where('marcaId', SSO_BRAND_ID)->where('id', $id)->get(['id', 'token', 'nombre', 'slug', 'type', 'typeSend', 'authType', 'authTypeSend', 'url', 'urlDev', 'authUrl', 'authUrlDev', 'modoActivo', 'activo', 'responseData', 'requestData', 'authResponseData', 'authRequestData', 'authWs'])->first();

        if (empty($conexiones)) {
            return $this->ResponseError('CONN-451', 'Conexión inválida');
        }

        $conexion = $conexiones->toArray();
        $conexion['responseData'] = @json_decode($conexion['responseData']);
        $conexion['requestData'] = @json_decode($conexion['requestData']);
        $conexion['authRequestData'] = @json_decode($conexion['authRequestData']);
        $conexion['authResponseData'] = @json_decode($conexion['authResponseData']);

        return $this->ResponseSuccess('Conexión obtenida con éxito', $conexion);
    }

    public function saveConnection(Request $request) {

        $id = $request->get('id');
        $nombre = $request->get('nombre');
        $slug = $request->get('identificador');
        $type = $request->get('type');
        $typeSend = $request->get('typeSend');
        $authType = $request->get('authType');
        $authTypeSend = $request->get('authTypeSend');
        $modoActivo = $request->get('modoActivo');
        $url = $request->get('url');
        $urlDev = $request->get('urlDev');
        $authUrl = $request->get('authUrl');
        $authUrlDev = $request->get('authUrlDev');
        $responseData = $request->get('responseData');
        $requestData = $request->get('requestData');
        $authResponseData = $request->get('authResponseData');
        $authRequestData = $request->get('authRequestData');
        $authWs = $request->get('authWs');


        $conexion = FlujoConexion::where('marcaId', SSO_BRAND_ID)->where('id', $id)->first();

        if (empty($conexion)) {
            $conexion = new FlujoConexion();
            $conexion->token = trim(bin2hex(random_bytes(4)));
        }

        $conexion->marcaId = SSO_BRAND_ID;
        $conexion->nombre = $nombre;
        $conexion->slug = $slug;
        $conexion->type = $type;
        $conexion->typeSend = $typeSend;
        $conexion->authType = $authType;
        $conexion->authTypeSend = $authTypeSend;
        $conexion->modoActivo = $modoActivo;
        $conexion->authWs = intval($authWs);
        $conexion->url = $url;
        $conexion->urlDev = $urlDev;
        $conexion->authUrl = $authUrl;
        $conexion->authUrlDev = $authUrlDev;
        $conexion->responseData = @json_encode($responseData);
        $conexion->requestData = @json_encode($requestData);
        $conexion->authResponseData = @json_encode($authResponseData);
        $conexion->authRequestData = @json_encode($authRequestData);
        $conexion->save();

        return $this->ResponseSuccess('Conexión guardada con éxito', ['id' => $conexion->id]);
    }

    public function getConnectionExpectedResponse(Request $request) {

        $id = $request->get('id');
        $type = $request->get('type');

        $conexion = FlujoConexion::where('marcaId', SSO_BRAND_ID)->where('id', $id)->first();

        if (empty($conexion)) {
            return $this->ResponseError('CONN-F47', 'Conexión inválida');
        }

        $tareaController = new TareaController();
        $conexionResponse = $tareaController->executeConnection($conexion, false, $type);

        if (!empty($conexionResponse['status'])) {
            return $this->ResponseSuccess('Conexión ejecutada con éxito', ['raw' => $conexionResponse['raw'], 'parsed' => $conexionResponse['parsed']]);
        }
        else {
            return $this->ResponseError('CONN-F47', $conexionResponse['msg']);
        }


    }
}
