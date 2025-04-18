<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Clientes;
use App\Models\Cotizacion;
use App\Models\Etapas;
use App\Models\Expedientes;
use App\Models\ExpedientesEtapas;
use App\Models\Flujos;
use App\Models\Productos;
use App\Models\RequisitosCategorias;
use App\Models\SistemaVariable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Models\UserCanal;
use App\Models\UserGrupo;
use App\Models\UserRol;
use App\Models\User;
use App\Models\Rol;
use App\Models\RolAccess;
use App\Models\UserJerarquia;
use App\Models\PdfTemplate;
use App\Models\Reporte;
use App\Models\Archivador;
use App\Models\CotizacionLoteOrden;
use App\Models\ProductoMigracion;
use App\Models\UserGrupoRol;
use App\Models\UserGrupoUsuario;
use App\Models\UserCanalGrupo;
use App\Models\ArchivadorDetalle;
use App\Http\Controllers\TareaController;

class ProductosMigracionController extends Controller {

    use Response;

    /**
     * Get Steps
     * @param Request $request
     * @return array|false|string
     */

    public function migrationProduct(Request $request) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();

        $data = [];
        $canales = [];
        $grupos = [];
        $roles = [];
        $users = [];

        $producto = Productos::where('id', $request->idProducto)->first();
        $flujo = Flujos::Where('productoId', '=', $producto->id)->Where('activo', '=', 1)->first();

        if (!empty($producto)) $data['producto'][$producto->id] = $producto->toArray();
        if (!empty($flujo)) $data['flujo'][$flujo->id] = $flujo->toArray();

        $data['interaccionUsers'] = [];

        $visibilidadProducto = json_decode($producto->extraData, true);
        $flujoConfig = @json_decode($flujo->flujo_config ?? 'null', true);

        $flujoExtractData = $this->roamFlujo($flujoConfig);

        //Plantillas
        $data['plantillas'] = [];
        $plantillas = PdfTemplate::whereIn('id',$flujoExtractData['pdfTpl'])->get();

        foreach($plantillas as $plantilla){
            $dataWORD  = null;
            if(!empty($plantilla->urlTemplate)){
                $s3_file = Storage::disk('s3')->get($plantilla->urlTemplate);
                $mime_type = Storage::disk('s3')->mimeType($plantilla->urlTemplate);
                $dataWORD = 'data:'. $mime_type.';base64,' . base64_encode($s3_file);
            }
            $data['plantillas'][$plantilla->id] = $plantilla->toArray();
            $data['plantillas'][$plantilla->id]['file'] = $dataWORD;
        }

        //Reportes
        $reportes = Reporte::all();
        $data['reportes'] = [];
        foreach($reportes as $item){
            $config = @json_decode($item->config, true);
            if(!in_array($producto->id, $config['p'])) continue;

            $visibilidad = $config['visibilidad']?? [];
            $canales = array_unique(array_merge($canales, $visibilidad['canales']?? []));
            $grupos = array_unique(array_merge($grupos, $visibilidad['grupos']?? []));
            $roles = array_unique(array_merge($roles, $visibilidad['roles']?? []));
            $users = array_unique(array_merge($users, $visibilidad['users']?? []));
            $data['reportes'][$item->id] = $item->toArray();
            $data['reportes'][$item->id]['integrants'] = $visibilidad;
        }

        //Variables de Sistema
        $variablesSistema = SistemaVariable::all();
        $data['variables'] = [];
        foreach($variablesSistema as $variable){
            if($variable->slug !== 'AREAS_USUARIOS' && strpos($flujo->flujo_config ?? '', '{{' . $variable->slug . '}}') === false) continue;
            $data['variables'][$variable->id] = $variable->toArray();
        }

        //Archivadores
        $archivadores= ArchivadorDetalle::whereIn('id', $flujoExtractData['archivadores'])->get();
        $data['archivadoresDetalle'] = [];
        $archivadoresId = [];
        foreach($archivadores as $archivo){
            $archivadoresId[] = $archivo->archivadorId;
            $data['archivadoresDetalle'][$archivo->id] = $archivo->toArray(); 
        }

        //Archivadores
        $archivadores= Archivador::whereIn('id', $archivadoresId)->get();
        $data['archivadores'] = [];
        foreach($archivadores as $archivo){
            $detail = $archivo->detalle;
            $data['archivadores'][$archivo->id] = $archivo->toArray();
            $data['archivadores'][$archivo->id]['detalle'] = $detail->toArray();
        }

        //LoteOrden
        $loteOrdenes = CotizacionLoteOrden::where('productoId', $producto->id)->get();
        $data['loteOrdenes'] = [];
        foreach($loteOrdenes as $lote){
            $data['loteOrdenes'][$lote->id] = $lote->toArray();
        }


        //Canales, Grupos, Roles, Usuarios, Jerarquia
        $data['interaccionUsers']['roles'] = array_values(array_unique(array_merge($visibilidadProducto['roles_assign'] ?? [], $flujoExtractData['roles'])));
        $data['interaccionUsers']['grupos'] = array_values(array_unique(array_merge($visibilidadProducto['grupos_assign']?? [], $flujoExtractData['grupos'])));
        $data['interaccionUsers']['canales'] = array_values(array_unique(array_merge($visibilidadProducto['canales_assign']?? [], $flujoExtractData['canales'])));
        $data['interaccionUsers']['users'] = $flujoExtractData['users'];

        //Jerarquia
        $data['jerarquias'] = [];
        $jerarquias = UserJerarquia::where(function ($query) use ($data){
            $query
                ->whereHas('supervisor', function ($subQuery) use ($data) {
                    $subQuery
                    ->whereIn('rolId', $data['interaccionUsers']['roles'])
                    ->orWhereIn('userId', $data['interaccionUsers']['users'])
                    ->orWhereIn('userGroupId', $data['interaccionUsers']['grupos']);
                })
                ->orWhereHas('detalle', function ($subQuery) use ($data) {
                    $subQuery
                    ->whereIn('canalId', $data['interaccionUsers']['canales'])
                    ->orWhereIn('rolId', $data['interaccionUsers']['roles'])
                    ->orWhereIn('userId', $data['interaccionUsers']['users'])
                    ->orWhereIn('userGroupId', $data['interaccionUsers']['grupos']);
                });
        })->get();

        foreach($jerarquias as $jerarquia){
            $detalle =  $jerarquia->detalle->toArray();
            $supervisor = $jerarquia->supervisor->toArray();
            $integrants = [
                'canales' => [],
                'grupos' => [],
                'roles' => [],
                'users' => [],
            ];

            foreach($detalle as $det){
                if(!empty($det['canalId'])) {
                    $canales[] = $det['canalId'];
                    $integrants['canales'][] = $det['canalId'];
                }
                if(!empty($det['userGroupId'])) {
                    $grupos[] = $det['userGroupId'];
                    $integrants['grupos'][] = $det['userGroupId'];
                }
                if(!empty($det['rolId'])) {
                    $roles[] = $det['rolId'];
                    $integrants['roles'][] = $det['rolId'];
                }
                if(!empty($det['userId'])) {
                    $users[] = $det['userId'];
                    $integrants['users'][] = $det['userId'];
                }
            }

            foreach($supervisor as $sup){
                if(!empty($sup['userGroupId'])) {
                    $grupos[] = $sup['userGroupId'];
                    $integrants['grupos'][] = $sup['userGroupId'];
                }
                if(!empty($sup['rolId'])) {
                    $roles[] = $sup['rolId'];
                    $integrants['roles'][] = $sup['rolId'];
                }
                if(!empty($sup['userId'])) {
                    $users[] = $sup['userId'];
                    $integrants['users'][] = $sup['userId'];
                }
            }

            $data['jerarquias'][$jerarquia->id] = $jerarquia->toArray();
            $data['jerarquias'][$jerarquia->id]['detalle'] = $detalle;
            $data['jerarquias'][$jerarquia->id]['supervisor'] = $supervisor;
            $data['jerarquias'][$jerarquia->id]['integrants'] = $integrants;
        }

        //Canales, Grupos, Roles, Usuarios
        $canales = array_unique(array_merge($canales, $data['interaccionUsers']['canales']));
        $grupos = array_unique(array_merge($grupos, $data['interaccionUsers']['grupos']));
        $roles = array_unique(array_merge($roles, $data['interaccionUsers']['roles']));
        $users = array_unique(array_merge($users, $data['interaccionUsers']['users']));

        $userCanal = UserCanal::whereIn('id', $canales)->get();
        $data['canales'] = [];

        foreach($userCanal as $canal){
            $gruposCanal = array_map(function($group){ return $group->userGroupId;}, $canal->grupos->all());
            $data['canales'][$canal->id] = $canal->toArray();
            $data['canales'][$canal->id]['grupos'] = $gruposCanal;
            $grupos = array_unique(array_merge($grupos,$gruposCanal));
        };

        $userGrupo = UserGrupo::whereIn('id', $grupos)->get();
        $data['grupos'] = [];

        foreach($userGrupo as $grupo){
            $gruposRoles = array_map(function($rol){ return $rol->rolId;}, $grupo->roles->all());
            $gruposUsers = array_map(function($user){ return $user->userId;}, $grupo->users->all());
            $data['grupos'][$grupo->id] = $grupo->toArray();
            $data['grupos'][$grupo->id]['roles'] = $gruposRoles;
            $data['grupos'][$grupo->id]['users'] = $gruposUsers;

            $roles = array_unique(array_merge($roles, $gruposRoles));
            $users = array_unique(array_merge($users, $gruposUsers));
        };

        $rolInUsers = UserRol::select('rolId', DB::raw('COUNT(*) as count'))
            ->whereIn('userId',$users)
            ->groupBy('rolId')
            ->get();
        $rolInUsers = array_map(function($rol){ return $rol->rolId;}, $rolInUsers->all());
        $roles = array_unique(array_merge($roles, $rolInUsers));

        $rolesAccess = Rol::whereIn('id', $roles)->get();
        $data['roles'] = [];

        foreach($rolesAccess as $rol){
            $usersAsig = array_map(function($user){ return $user->userId;}, $rol->usersAsig->all());
            $data['roles'][$rol->id] = $rol->toArray();
            $data['roles'][$rol->id]['users'] = $usersAsig;
            $data['roles'][$rol->id]['access'] = $rol->access->toArray();
            $users = array_unique(array_merge($users, $usersAsig));
        };
        
        $usersData = User::whereIn('id', $users)->get();
        $data['users'] = [];
        foreach($usersData as $user){
            $user->makeHidden(['ssoToken', 'email_verified_at', 'updated_at']);
            $rolUser = $user->rolAsignacion ?? null;
            $data['users'][$user->id] = $user->toArray();
            $data['users'][$user->id]['rolId'] = $rolUser->rolId ?? null;
        }

        return $this->ResponseSuccess('Migracion Completa', $data);
    }

    function roamFlujo ($flujo) {
        //[nodes]
            //[formulario]
            //[secciones]
            //[campos]

            //obtener el flujo y en cada nodo recorrer esto y guardar; 
            //nodeSelection.canales_assign
            //nodeSelection.grupos_assign
            //nodeSelection.roles_assign

            //campito.grupos_assign
            //campito.roles_assign

            //nodeSelection.setuser_roles
            //nodeSelection.setuser_group
            //nodeSelection.setuser_user

        $data = [
            'canales'=> [],
            'grupos'=> [],
            'roles'=> [],
            'users' => [],
            'pdfTpl' => [],
            'archivadores'=>[],
        ];
        if(!empty($flujo) && !empty($flujo['nodes'])){
            foreach($flujo['nodes'] as $node){
                $data['canales'] = array_unique(array_merge($data['canales'], $node['canales_assign']));
                $data['grupos'] = array_unique(array_merge($data['grupos'], $node['grupos_assign']));
                $data['roles'] = array_unique(array_merge($data['roles'], $node['roles_assign']));
                if(!empty($node['pdfTpl'])) $data['pdfTpl'][] = $node['pdfTpl'];

                if(!empty($node['setuser_roles']) && !in_array($node['setuser_roles'], $data['roles'])) $data['roles'][] = $node['setuser_roles'];
                if(!empty($node['setuser_group']) && !in_array($node['setuser_group'], $data['grupos'])) $data['grupos'][] = $node['setuser_group'];
                if(!empty($node['setuser_user']) && !in_array($node['setuser_user'], $data['users'])) $data['users'][] = $node['setuser_user'];

                foreach($node['formulario']['secciones'] as $seccion){
                    foreach($seccion['campos'] as $campo){
                        $data['grupos'] = array_unique(array_merge($data['grupos'],$campo['grupos_assign']));
                        $data['roles'] = array_unique(array_merge($data['roles'],$campo['roles_assign']));
                        if(!empty($campo['archivadorRel']) && !is_array($campo['archivadorRel'])) $data['archivadores'][] = $campo['archivadorRel'];
                    }
                }

            }
        }
        return $data;
    }

    public function createMigrationProduct(Request $request){
        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        $data = $request->get('data');
        $update = $request->get('update');
        $producto = Productos::where('id', $request->idProducto)->first();
        if (empty($producto))  return $this->ResponseError('COT-M011', 'Tarea no válida');
        $productMigration = new ProductoMigracion();
        $productMigration->productoId = $request->idProducto;
        $productMigration->usuarioId = $usuarioLogueadoId;
        $productMigration->dataSave = json_encode($data);
        $productMigration->updateData = json_encode($update);
        $productMigration->save();

        return $this->ResponseSuccess('Tarea actualizada con éxito', $productMigration);
    }

    public function updateMigrationProduct(Request $request){
        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        $update = $request->get('update');
        $producto = Productos::where('id', $request->idProducto)->first();
        if (empty($producto))  return $this->ResponseError('COT-M011', 'Producto no valido');
        $productMigration = ProductoMigracion::where('id', $request->idMigration)->where('usuarioId', $usuarioLogueadoId)->first();
        if (empty($productMigration))  return $this->ResponseError('COT-M012', 'Migracion no valida');
        $productMigration->updateData = json_encode($update);
        $productMigration->save();
        return $this->ResponseSuccess('Tarea actualizada con éxito', $productMigration);
    }

    public function getMigrationProduct(Request $request){
        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        $producto = Productos::where('id', $request->idProducto)->first();
        if (empty($producto))  return $this->ResponseError('COT-M011', 'Producto no valido');
        $productMigration = ProductoMigracion::where('productoId', $request->idProducto)->where('usuarioId', $usuarioLogueadoId)->where('status', 1)->first();
        if (empty($productMigration))  return $this->ResponseError('COT-M012', 'No existe migration');
        return $this->ResponseSuccess('Tarea actualizada con éxito', $productMigration);
    }

    public function discardMigrationProduct (Request $request){
        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        $producto = Productos::where('id', $request->idProducto)->first();
        if (empty($producto))  return $this->ResponseError('COT-M011', 'Producto no valido');
        $productMigration = ProductoMigracion::where('id', $request->idMigration)->where('usuarioId', $usuarioLogueadoId)->delete();
        if (empty($productMigration))  return $this->ResponseError('COT-M012', 'Migracion no valida');
        //$productMigration -> status = 0;
        //$productMigration -> save();
        return $this->ResponseSuccess('Migracion descartada con exito', $productMigration);
    }

    public function findConflictsMigrationProduct (Request $request){
       //try{
        $conflicts = [];

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        $update = $request->get('update');
        $data = $request->get('data');

        $producto = Productos::where('id', $request->idProducto)->first();
        if (empty($producto))  return $this->ResponseError('COT-M011', 'Producto no valido');
        
        //funcion de recorrido de visibilidad para cada campo . 

        //Producto
        
        $items = Productos::get();
        $columns = array('nombreProducto', 'descripcion', 'codigoInterno', 
        'status', 'public', 'group', 'isVirtual', 'linksManuales', 'imagenData', 'extraData', 
        'isFree', 'cssCustom', 'jsCustom','notificationData' );
        $conflicts['producto'] = $this->findConflictsInData ($update, $data, $items, $columns, 'producto', $request->idProducto);

        //Flujo
        // $flujo = Flujos::Where('productoId', '=', $producto->id)->Where('activo', '=', 1)->first();
        foreach($data['flujo'] as $flujoData){
            $flujo = @json_decode($flujoData['flujo_config'], true);
            $flujoConflict = $this->roamFlujoConflicts($flujo);
        }

        //Plantillas
        $items = PdfTemplate::get();
        $columns = array('nombre', 'activo', 'file');
        $conflicts['plantillas'] = $this->findConflictsInData ($update, $data, $items, $columns, 'plantillas', $request->idProducto);

        //Reportes
        $items = Reporte::get();
        $columns = array('nombre', 'activo', 'tipo', 'config', 'sendReport', 'mailconfig', 'dateToSend', 'period');
        $conflicts['reportes'] = $this->findConflictsInData ($update, $data, $items, $columns, 'reportes', $request->idProducto);
        //$visibilidad

        //Variables de Sistema
        $items = SistemaVariable::get();
        $columns = array('slug', 'contenido');
        $conflicts['variables'] = $this->findConflictsInData ($update, $data, $items, $columns, 'variables', $request->idProducto);

        //Archivadores
        $items = Archivador::get();
        $columns = array('nombre', 'activo', 'detalle');
        $conflicts['archivadores'] = $this->findConflictsInData ($update, $data, $items, $columns, 'archivadores', $request->idProducto);

        //Archivadores Detalle
        $items = ArchivadorDetalle::get();
        $columns = array('nombre', 'tipoCampo', 'mascara', 'longitudMin', 'longitudMax', 'activo');
        $conflicts['archivadoresDetalle'] = $this->findConflictsInData ($update, $data, $items, $columns, 'archivadoresDetalle', $request->idProducto);

        //LoteOrden
        $items = CotizacionLoteOrden::where('productoId', $request->idProducto)->get();
        $columns = array('productoId', 'campo', 'orden', 'useForSearch');
        $conflicts['loteOrdenes'] = $this->findConflictsInData ($update, $data, $items, $columns, 'loteOrdenes', $request->idProducto);

        //Jerarquia
        $items = UserJerarquia::get();
        $columns = array('nombre', 'activo');
        $conflicts['jerarquias'] = $this->findConflictsInData ($update, $data, $items, $columns, 'jerarquias', $request->idProducto);

        //Canales
        $items = UserCanal::get();
        $columns = array('nombre', 'activo');
        $conflicts['canales'] = $this->findConflictsInData ($update, $data, $items, $columns, 'canales', $request->idProducto);

        //Grupos
        $items = UserGrupo::get();
        $columns = array('nombre', 'activo');
        $conflicts['grupos'] = $this->findConflictsInData ($update, $data, $items, $columns, 'grupos', $request->idProducto);

        //Roles
        $items = Rol::get();
        $columns = array('name', 'guard_name', 'access');
        $conflicts['roles'] = $this->findConflictsInData ($update, $data, $items, $columns, 'roles', $request->idProducto);
        
        //Users
        $items = User::get();
        $columns = array('id', 'name', 'email', 'telefono', 'corporativo', 'nombreUsuario', 'resetPassword', 
        'active', 'fueraOficina', 'username', 'userVars');
        $conflicts['users'] = $this->findConflictsInData ($update, $data, $items, $columns, 'users', $request->idProducto);

        $count = 0;
        //total conflictos
        foreach($conflicts as $keyTable => $table){
            if(in_array($keyTable, ['canales', 'grupos', 'roles', 'users', 'archivadoresDetalle'])) continue;
            foreach($table as $keyCol => $col){
                if(!empty($conflicts[$keyTable][$keyCol]['conflict']) && $conflicts[$keyTable][$keyCol]['estado'] === 'C' && count($conflicts[$keyTable][$keyCol]['conflict']) > 0)
                $count += 1;
            }
        }
        if(!empty($data['reportes'])){
            foreach($data['reportes'] as $id => $info){
                if(!empty($info['integrants'])){
                    $count += $this->findConflictsInEntities ($info['integrants'], $conflicts, $data);
                }
            }
        }

        if(!empty($data['jerarquias'])){
            foreach($data['jerarquias'] as $id => $info){
                if(!empty($info['integrants'])){
                    $count += $this->findConflictsInEntities ($info['integrants'], $conflicts, $data);
                }
            }
        }

        if(!empty($data['interaccionUsers'])){
            $count += $this->findConflictsInEntities ($data['interaccionUsers'], $conflicts, $data);
        }


        $conflicts['flujo'] = [];
        $conflicts['flujo'][array_keys($data['flujo'])[0]] = ['estado' => 'N', 'conflict' => []];

        return $this->ResponseSuccess('Tarea actualizada con éxito', ['conflicts' => $conflicts, 'count' => $count, 'flujo' => $flujoConflict]);
        /* } catch (\Throwable $th) {
            return $this->ResponseError('PROD-854', 'Error al obtener productos' . $th);
        } */
    }

    function findConflictsInData ($update, $mgData, $items, $columns, $type, $productoId){
        $result = [];
       
        foreach($mgData[$type] as  $mgid => $mgitem){
            $updateId = $update[$type][$mgid] ?? null;

            $dataConflict = [];
            $estado = 'N';
            $item = null;
            if(empty($updateId) || empty($updateId['id'])){
                $item = $items->where('id', $mgid)->first();
            } else {
                $item = $items->where('id', $updateId['id'])->first();
            }

            if(!empty($item)){
                $estado = 'E';
                foreach($columns as $col){
                    $mgword = $mgitem[$col] ?? null;
                    $itemword = $item->$col ?? null;

                    if($col === 'productoId'){
                        $mgword = $productoId;
                    }

                    if($type === 'plantillas' && $col === 'file' && !empty($item->urlTemplate)){
                        $s3_file = Storage::disk('s3')->get($item->urlTemplate);
                        $mime_type = Storage::disk('s3')->mimeType($item->urlTemplate);
                        $dataWORD = 'data:'. $mime_type.';base64,' . base64_encode($s3_file);
                        
                        if($mgword !== $dataWORD){
                            $dataConflict[] = $col;
                            $result[$mgid][$col] = $dataWORD;
                        }
                    }
                    else if($type === 'archivadores' && $col === 'detalle' && !empty($itemword)){
                        if(json_encode($mgword) !== json_encode($item->detalle->toArray())){
                            $dataConflict[] = $col;
                            $result[$mgid][$col] = $item->detalle->toArray();
                        }
                    }
                    else if($type === 'roles' && $col === 'access' && !empty($itemword)){
                        if(json_encode($mgword) !== json_encode($item->access->toArray())){
                            $dataConflict[] = $col;
                            $result[$mgid][$col] = $item->access->toArray();
                        }
                    }
                    else if($type === 'reportes' && $col === 'config'){
                        $config = @json_decode($item->$col, true);
                        if(!in_array($productoId, $config['p'])){
                            $config['p'] = implode(", ", $config['p']) . " (ProductoId Configurado a otro Flujo)";
                            $dataConflict[] = $col;
                            $result[$mgid][$col] = json_encode($config);
                        }
                    }
                    else if(strval($itemword) !== strval($mgword) && (!empty($itemword) || !empty($mgword)))  {
                        $dataConflict[] = $col;
                        $result[$mgid][$col] = $item->$col;
                    };

                    //REVISAR EN REPORTES EL P: DE LA CONFIGURACION DEL PRODUCTO
                }
                if(count($dataConflict) > 0) $estado = 'C'; 
            }

            if(in_array($updateId['action'], [1,2,3])){
                $estado = 'R';
            }
            $result[$mgid]['estado'] = $estado;
            $result[$mgid]['conflict'] = $dataConflict;
        }
        return  $result;
    }

    function findConflictsInEntities ($integrants, $conflicts, $data, $prevEntities = []){
        $dictionary = [
            'canales' => ['grupos'],
            'grupos' => ['roles','users'],
            'roles' => ['users'],
            'users' => ['roles'],
        ];
    
        $count = 0;
        foreach($integrants as $ent => $contEnt){
            if(empty($ent) || empty($dictionary[$ent])) continue;
            foreach($contEnt as $keyCol){
                if(!empty($conflicts[$ent][$keyCol]['conflict']) && $conflicts[$ent][$keyCol]['estado'] === 'C' && count($conflicts[$ent][$keyCol]['conflict']) > 0)
                $count += 1;
            }
            if(!in_array($ent, $prevEntities)){
                $newPrev = $prevEntities;
                $newPrev[] = $ent; 
                $count += $this->findConflictsInEntities($data[$ent], $conflicts, $data, $newPrev);
            }
        }

        return $count;
    }

    public function finishMigration(Request $request){
        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/flujos'])) return $AC->NoAccess();
        $usuarioLogueado = auth('sanctum')->user();
        $usuarioLogueadoId = ($usuarioLogueado) ? $usuarioLogueado->id : 0;
        $update = $request->get('update');
        $data = $request->get('data');
        $producto = Productos::where('id', $request->idProducto)->first();
        if (empty($producto))  return $this->ResponseError('COT-M011', 'Producto no valido');
        $productMigration = ProductoMigracion::where('id', $request->idMigration)->where('usuarioId', $usuarioLogueadoId)->first();
        if (empty($productMigration))  return $this->ResponseError('COT-M012', 'Migracion no valida o no guardada');
        //crear el origin

        $origin = json_decode($this->migrationProduct($request), true)['data'];
        
        $conflicts = json_decode($this->findConflictsMigrationProduct($request), true);
        if(empty($conflicts) || !$conflicts['status'] ||  empty($conflicts['data']) || $conflicts['data']['count'] > 0){
            return $this->ResponseError('COT-M013', 'Existen Conflictos');
        }

        $conflicts = $conflicts['data']['conflicts'];

        //$update = $request->get('update');
        //$data = $request->get('data');
        //modificar la data donde se guarda la info posterior a todo
        //USERS id null action es null dejarlo en null 
        //si action o es null entonces hacer un proceso
        $ordenTables = [
            'roles', 
            'users',
            'grupos',
            'canales',
            'jerarquias',
            'plantillas',
            'variables',
            'archivadores',
            'archivadoresDetalle',
            'producto',
            'loteOrdenes',
            'reportes',
            'flujo'];

        foreach($ordenTables as $keyTable){
            $table = $conflicts[$keyTable];
            $allIds = [];

            foreach($table as $keyRow => $row){
                //estado    C E  N R
                if($keyTable === 'archivadoresDetalle') {
                    $row['estado'] = 'R';
                    $update[$keyTable][$keyRow]['action'] = '2';
                };
                if(!in_array($row['estado'], ['N','R'])){
                    $update[$keyTable][$keyRow]['id'] = $keyRow;
                    continue;
                };
                if($row['estado'] === 'N' &&  empty($update[$keyTable][$keyRow]['action'])){
                    $update[$keyTable][$keyRow]['action'] = '1';
                }
                if(!in_array($update[$keyTable][$keyRow]['action'], ['1','2','3'])) continue;
                if($update[$keyTable][$keyRow]['action'] === '3'){
                    $update[$keyTable][$keyRow]['id'] = $keyRow;
                    continue;
                };

                $estado = 'N';
                if($update[$keyTable][$keyRow]['action'] === '2'){
                    $estado = 'E';
                }

                $dataActionMigration = $data[$keyTable][$keyRow];
                $dataActionMigration['productoId'] = $request->idProducto;

                if($keyTable === 'archivadoresDetalle'){
                    $archivadorId =  $dataActionMigration['archivadorId'];
                    if(empty($archivadorId) || empty($update['archivadores'][$archivadorId])) continue;
                    if(!in_array($update['archivadores'][$archivadorId]['action'], ['1','2'])){
                        $update[$keyTable][$keyRow]['id'] = $keyRow;
                        continue;  
                    }
                    $archivadorDet = ArchivadorDetalle::where('archivadorId',  $update['archivadores'][$archivadorId]['id'])
                    ->where('nombre',  $dataActionMigration['nombre'])
                    ->first();
                    $update[$keyTable][$keyRow]['id'] = $archivadorDet->id;
                    continue;
                }

                $item = $this->actionDataInMigration($estado, $keyTable, $dataActionMigration , $update);
                $update[$keyTable][$keyRow]['id'] = $item->id;
                $allIds[] = $item->id;
                //guardar item
                /*
                {value: '1', text: 'Crear nuevo'},
                {value: '2', text: 'Modificar existente'},
                {value: '3', text: 'Mantener existente'},
                 */
            }
            if($keyTable === 'loteOrdenes'){
                $deleteLotes = CotizacionLoteOrden::where('productoId', $request->idProducto)
                ->whereNotIn('id', $allIds)->delete();
            }
        }

        //INICIAR CON ROLES
        
        $productMigration->updateData = json_encode($update);
        $productMigration->origin = json_encode($origin);
        $productMigration->status = 0;
        $productMigration->success = 1;
        $productMigration->save();

        return $this->ResponseSuccess('Tarea actualizada con éxito', $productMigration);
    }

    public function actionDataInMigration($estado, $table, $data, $update){
        $item = null;
        $columns = null;

        switch ($table) {

            case 'producto':
                $item = Productos::where('id', $data['productoId'])->first();
                $columns = array('nombreProducto', 'descripcion', 'codigoInterno', 
                'status', 'public', 'group', 'isVirtual', 'linksManuales', 'imagenData', 'extraData', 
                'isFree', 'cssCustom', 'jsCustom','notificationData' );
                break;

            case 'plantillas':
                $item = $estado === 'N' 
                    ? new PdfTemplate() 
                    : PdfTemplate::where('id', $data['id'])->first();
                $columns = array('nombre', 'activo');
                break;

            case 'reportes':
                $item = $estado === 'N' 
                    ? new Reporte() 
                    : Reporte::where('id', $data['id'])->first();
                $columns = array('nombre', 'activo', 'tipo', 'config', 'sendReport', 'mailconfig', 'dateToSend', 'period');
                break;

            case 'variables':
                $item = $estado === 'N' 
                    ? new SistemaVariable() 
                    : SistemaVariable::where('id', $data['id'])->first();
                $columns = array('slug', 'contenido');
                break;

            case 'archivadores':
                $item = $estado === 'N' 
                    ? new Archivador() 
                    : Archivador::where('id', $data['id'])->first();
                $columns = array('nombre', 'activo');
                break;

            case 'loteOrdenes':
                $item = $estado === 'N' 
                    ? new CotizacionLoteOrden() 
                    : CotizacionLoteOrden::where('id', $data['id'])->first();
                $columns = array('productoId', 'campo', 'orden', 'useForSearch');
                break;

            case 'jerarquias':
                $item = $estado === 'N' 
                    ? new UserJerarquia() 
                    : UserJerarquia::where('id', $data['id'])->first();
                $columns = array('id', 'nombre', 'activo');
                break;

            case 'canales':
                $item = $estado === 'N' 
                    ? new UserCanal() 
                    : UserCanal::where('id', $data['id'])->first();
                $columns = array('nombre', 'activo');
                break;

            case 'grupos':
                $item = $estado === 'N' 
                    ? new UserGrupo() 
                    : UserGrupo::where('id', $data['id'])->first();
                $columns = array('nombre', 'activo');
                break;

            case 'roles':
                $item = $estado === 'N' 
                    ? new Rol() 
                    : Rol::where('id', $data['id'])->first();
                $columns = array('name', 'guard_name');
                break;

            case 'users':
                $item = $estado === 'N' 
                    ? new User() 
                    : User::where('id', $data['id'])->first();
                $columns = array('name', 'email', 'telefono', 'corporativo', 'nombreUsuario', 'resetPassword', 
                'active', 'fueraOficina', 'username', 'userVars');
                break;

            case 'flujo':
                $item = new Flujos();
                $columns = array('slug', 'nombre', 'descripcion', 'flujo_config', 'productoId', 'activo', 
                'modoPruebas');
                break;
        }

        //FIN
        if(empty($item)) return $this->ResponseError('COT-M013', 'Error al crear data');
        $this->saveDataInDataBase($item, $columns, $data);

        //Tablas enlazadas a Roles
        if($table === 'roles'){
            $accessMigration = [];
            foreach($data['access'] as $access){
                $accessMigration[] = $access['access'];
                $accessDb = RolAccess::where('rolId', $item->id)->where('access', $access['access'])->first();
                if(empty($accessDb)){
                    $newAccessDb = new RolAccess();
                    $newAccessDb->rolId = $item->id;
                    $newAccessDb->access = $access['access'];
                    $newAccessDb->save();
                }
            }
            $deleteAcess = RolAccess::where('rolId', $item->id)->whereNotIn('access', $accessMigration)->delete();
            //hacer un recorrido de todos los users y verificar que no tengan conflicto y accion
            foreach($data['users'] as $user){
                if(empty($update['users'][$user]) || $update['users'][$user]['action'] === '1') continue;
                $userRol = UserRol::where('userId', $user)->first();
                $userRol->rolId = $item->id;
                $userRol->save();
            }
        }
        //Tablas enlazadas Users
        if($table === 'users'){
            $rolId = $data['rolId'];
            $updateRol = !empty($rolId)? $update['roles'][$rolId] : null;
            if(!empty($updateRol) && !empty($updateRol['id'])){
                $rolId = $updateRol['id'];
            }
            $userRol = UserRol::where('userId', $item->id)->first();
            if(empty($userRol)) new UserRol();
            $userRol->userId = $item->id;
            $userRol->rolId = $rolId;
            $userRol->save();
        }

        //Tablas enlazadas a Grupos
        if($table === 'grupos'){
            //roles
            $roles = $data['roles'];
            $rolesMigration = [];
            foreach($roles as $rol){
                $rolId = $rol;
                if(!empty($update['roles'][$rolId])){
                    $rolId = $update['roles'][$rolId]['id'];
                }
                $gruposRoles = UserGrupoRol::where('userGroupId', $item->id)
                ->where('rolId', $rolId)->first();
                if(empty($gruposRoles)){
                    $gruposRoles = new UserGrupoRol();
                    $gruposRoles->userGroupId = $item->id;
                    $gruposRoles->rolId = $rolId;
                    $gruposRoles->save();
                };
                $rolesMigration[] = $rolId;

            }
            $deleteAcess = UserGrupoRol::where('userGroupId', $item->id)
            ->whereNotIn('rolId', $rolesMigration)->delete();

            //users
            $users = $data['users'];
            $usersMigration = [];
            foreach($users as $user){
                $userId = $user;
                if(!empty($update['users'][$userId])){
                    $userId = $update['users'][$userId]['id'];
                }
                $gruposUsers = UserGrupoUsuario::where('userGroupId', $item->id)
                ->where('userId', $userId)->first();
                if(empty($gruposUsers)){
                    $gruposUsers = new UserGrupoUsuario();
                    $gruposUsers->userGroupId = $item->id;
                    $gruposUsers->userId = $userId;
                    $gruposUsers->save();
                };
                $usersMigration[] = $userId;

            }
            $deleteAcess = UserGrupoUsuario::where('userGroupId', $item->id)
            ->whereNotIn('userId', $usersMigration)->delete();
        }

        //Tablas enlazadas a Canales
        if($table === 'canales'){
            //grupos
            $grupos = $data['grupos'];
            $gruposMigration = [];
            foreach($grupos as $grupo){
                $grupoId = $grupo;
                if(!empty($update['grupos'][$grupoId]) && !empty($update['grupos'][$grupoId]['id'])){
                    $grupoId = $update['grupos'][$grupoId]['id'];
                }
                $gruposCanales = UserCanalGrupo::where('userCanalId', $item->id)
                ->where('userGroupId', $grupoId)->first();
                if(empty($gruposCanales)){
                    $gruposCanales = new UserCanalGrupo();
                    $gruposCanales->userCanalId = $item->id;
                    $gruposCanales->userGroupId = $grupoId;
                    $gruposCanales->save();
                };
                $gruposMigration[] = $grupoId;

            }
            $deleteAcess = UserCanalGrupo::where('userCanalId', $item->id)
            ->whereNotIn('userGroupId', $gruposMigration)->delete();

        }

        //Tablas enlazadas a Jerarquias
        if($table === 'jerarquias'){
            $detalles = $data['detalle'];
            $detallesMigration = [];
            foreach($detalles as $det){
                $canalId = $det['canalId'];
                $rolId = $det['rolId'];
                $userId = $det['userId'];
                $userGroupId = $det['userGroupId'];

                if(!empty($update['canales'][$canalId]) && !empty($update['canales'][$canalId]['id'])){
                    $canalId = $update['canales'][$canalId]['id'];

                }
                if(!empty($update['grupos'][$userGroupId]) && !empty($update['grupos'][$userGroupId]['id'])){
                    $rolId = $update['grupos'][$userGroupId]['id'];
                }
                if(!empty($update['roles'][$rolId]) && !empty($update['roles'][$rolId]['id'])){
                    $userId = $update['roles'][$rolId]['id'];

                }
                if(!empty($update['users'][$userId]) && !empty($update['users'][$userId]['id'])){
                    $userGroupId = $update['users'][$userId]['id'];
                }
                $jerarquiaDetail = 
                UserJerarquiaDetail::where('jerarquiaId', $item->id)
                    ->where('canalId', $canalId)
                    ->where('rolId', $rolId)
                    ->where('userId', $userId)
                    ->where('userGroupId', $userGroupId)
                    ->first();

                if(empty($jerarquiaDetail)){
                    $jerarquiaDetail = new UserJerarquiaDetail();
                    $jerarquiaDetail->canalId = $canalId;
                    $jerarquiaDetail->rolId = $rolId;
                    $jerarquiaDetail->userId = $userId;
                    $jerarquiaDetail->userGroupId = $userGroupId;
                    $jerarquiaDetail->save();
                };
                $detallesMigration[] = $jerarquiaDetail->id;
            }
            $deleteDetail = UserJerarquiaDetail::where('jerarquiaId', $item->id)
            ->whereNotIn('id', $detallesMigration)->delete();

            $supervisiones = $data['supervisor'];
            $supervisionMigration = [];
            foreach($supervisiones as $sup){
                $rolId = $det['rolId'];
                $userId = $det['userId'];
                $userGroupId = $det['userGroupId'];

                if(!empty($update['grupos'][$userGroupId]) && !empty($update['grupos'][$userGroupId]['id'])){
                    $rolId = $update['grupos'][$userGroupId]['id'];
                }
                if(!empty($update['roles'][$rolId]) && !empty($update['roles'][$rolId]['id'])){
                    $userId = $update['roles'][$rolId]['id'];

                }
                if(!empty($update['users'][$userId]) && !empty($update['users'][$userId]['id'])){
                    $userGroupId = $update['users'][$userId]['id'];
                }
                $jerarquiaSupervision = 
                UserJerarquiaSupervisor::where('jerarquiaId', $item->id)
                    ->where('rolId', $rolId)
                    ->where('userId', $userId)
                    ->where('userGroupId', $userGroupId)
                    ->first();

                if(empty($jerarquiaSupervision)){
                    $jerarquiaSupervision = new UserJerarquiaSupervisor();
                    $jerarquiaSupervision->rolId = $rolId;
                    $jerarquiaSupervision->userId = $userId;
                    $jerarquiaSupervision->userGroupId = $userGroupId;
                    $jerarquiaSupervision->save();
                };
                $supervisionMigration[] = $jerarquiaDetail->id;
            }
            $deleteSupervision = UserJerarquiaSupervisor::where('jerarquiaId', $item->id)
            ->whereNotIn('id', $supervisionMigration)->delete();
        }

        //Tablas enlazadas a Archivadores
        if($table === 'archivadores'){
            //ArchivadorDetalle
           $detallesArchivadores =  $data['detalle'];
           $columns = [
            'nombre',
            'archivadorId',
            'tipoCampo',
            'mascara',
            'longitudMin',
            'longitudMax',
            'activo'];
            $archivadoresMigration = [];
            foreach($detallesArchivadores as $det){
                $dataArchivadorDet = ArchivadorDetalle::where('archivadorId', $item->id)
                ->where('id', $det['id'])
                ->first();
                if(empty($dataArchivadorDet)){
                    $dataArchivadorDet = new ArchivadorDetalle();
                }
                $det['archivadorId'] = $item->id;
                $this->saveDataInDataBase($dataArchivadorDet, $columns, $det);
                $archivadoresMigration[] = $dataArchivadorDet->id;
            }
            $deleteArchivadores = ArchivadorDetalle::where('archivadorId', $item->id)
            ->whereNotIn('id', $archivadoresMigration)->delete();
        }

        //Productos
        if($table === 'producto'){
            $visibilidadProducto = json_decode($item->extraData, true);
            $roles = [];
            $grupos = [];
            $canales = [];

            foreach($visibilidadProducto['roles_assign'] as $rol){
                $rolId = $rol;
                if(!empty($update['roles'][$rolId]) && !empty($update['roles'][$rolId]['id'])){
                    $rolId = $update['roles'][$rolId]['id'];
                }
                $roles[] = $rolId;
            }
            foreach($visibilidadProducto['grupos_assign'] as $grupo){
                $grupoId = $grupo;
                if(!empty($update['grupos'][$grupoId]) && !empty($update['grupos'][$grupoId]['id'])){
                    $grupoId = $update['grupos'][$grupoId]['id'];
                }
                $grupos[] = $grupoId;    
            }
            foreach($visibilidadProducto['canales_assign'] as $canal){
                $canalId = $canal;
                if(!empty($update['canales'][$canalId]) && !empty($update['canales'][$canalId]['id'])){
                    $canalId = $update['canales'][$canalId]['id'];
                }
                $canales[] = $canalId;
            }

            $visibilidadProducto['roles_assign'] = $roles;
            $visibilidadProducto['grupos_assign'] = $grupos;
            $visibilidadProducto['canales_assign'] = $canales;
            $item->extraData = json_encode($visibilidadProducto);
            $item->save();
        }

        //Visualizacion de Reporte
        if($table === 'reportes'){
            $config = @json_decode($item->config, true);
            $productIdBefore = strval(array_keys($update['producto'])[0]);
            $config['p'] = array_map(
                function($p) use ($productIdBefore, $data){
                    return $p === $productIdBefore
                    ? $data['productoId'] : $p;}, 
                $config['p']
            );

            $config['c'] = array_map(
                function($c) use ($data, $productIdBefore){
                    foreach($c as $ckey => $cvalue){
                       if($ckey === 'id' || $ckey === 'p'){
                            $replaceC = explode("_", $cvalue);
                            if($replaceC[0] !== $productIdBefore) continue;
                            $replaceC[0] = $data['productoId'];
                            $c[$ckey] = implode('_', $replaceC);
                       }
                    }
                    return $c;
                }, 
                $config['c']
            );

            $visibilidad = $config['visibilidad']?? [];

            $users = [];
            $roles = [];
            $grupos = [];
            $canales = [];

            foreach($visibilidad['users'] as $user){
                $userId = $user;
                if(!empty($update['users'][$userId]) && !empty($update['users'][$userId]['id'])){
                    $userId = $update['users'][$userId]['id'];
                }
                $users[] = $userId;
            }
            foreach($visibilidad['roles'] as $rol){
                $rolId = $rol;
                if(!empty($update['roles'][$rolId]) && !empty($update['roles'][$rolId]['id'])){
                    $rolId = $update['roles'][$rolId]['id'];
                }
                $roles[] = $rolId;
            }
            foreach($visibilidad['grupos'] as $grupo){
                $grupoId = $grupo;
                if(!empty($update['grupos'][$grupoId]) && !empty($update['grupos'][$grupoId]['id'])){
                    $grupoId = $update['grupos'][$grupoId]['id'];
                }
                $grupos[] = $grupoId;    
            }
            foreach($visibilidad['canales'] as $canal){
                $canalId = $canal;
                if(!empty($update['canales'][$canalId]) && !empty($update['canales'][$canalId]['id'])){
                    $canalId = $update['canales'][$canalId]['id'];
                }
                $canales[] = $canalId;
            }

            $config['visibilidad']['users'] = $users;
            $config['visibilidad']['roles'] = $roles;
            $config['visibilidad']['grupos'] = $grupos;
            $config['visibilidad']['canales'] = $canales;
            $item->config = json_encode($config);
            $item->save();
        }

        //Flujo
        if($table === 'flujo'){
            $flujo = json_decode($item->flujo_config, true);
            $flujoUpdate = $this->roamFlujoUpdate($flujo, $update);
            $item->flujo_config = json_encode($flujoUpdate);
            $item->save();

            $updateFlujo = Flujos::where('productoId', $data['productoId'])
            ->whereNot('id', $item->id)->update(['activo' => 0]);
        }

        //Plantilla
        if($table === 'plantillas'){
            $file = $data['file'];
            if(!empty($file)){
                $fileNameHash = md5(uniqid());
                $dir = "system-templates/tpl_{$fileNameHash}.docx";
                $file = base64_decode(preg_replace('#^data:application/\w+;base64,#i', '', $file));
                $disk = Storage::disk('s3');
                $path = $disk->put($dir, $file);
                if(!empty($path)){
                    $item->urlTemplate = $dir;
                    $item->save();
                }
            }
        }

        return $item;
    }

    public function saveDataInDataBase($item, $columns = [], $data){
        foreach($columns as $col){
            $item->$col = $data[$col];
        }
        $item->save();
    }

    function roamFlujoConflicts ($flujo) {
        $nodesId = [];
        $nodesWithOutPath = [];
        $camposWithOutPath = [];
        // ver los nodos sin informacion de routes file path 
        if(!empty($flujo) && !empty($flujo['nodes'])){
            foreach($flujo['nodes'] as $node){
                if(!empty($node['typeObject'] === 'output') && !empty($node['salidaIsPDF'])){
                    if(empty($node['pdfTpl']) 
                        || empty($node['salidaPDFconf']) 
                        || empty($node['salidaPDFconf']['path']))  $nodesWithOutPath[] = $node;
                }
                foreach($node['formulario']['secciones'] as $seccion){
                    foreach($seccion['campos'] as $campo){
                        $nodesId[$campo['id']] = (in_array($campo['id'], array_keys($nodesId)))?  $nodesId[$campo['id']] + 1 : 1;
                        if(($campo['tipoCampo'] === 'fileER' || $campo['tipoCampo'] === 'file') &&  empty($campo['filePath'])){
                            $camposWithOutPath[] = $campo;
                        }
                    }
                }
            }
        }
        $campos = array_filter($nodesId, function($value) {return $value > 1;});
        return ['campos' => $campos, 'nodesPath' => $nodesWithOutPath, 'camposPath' => $camposWithOutPath];
    }

    function roamFlujoUpdate ($flujoOrigin, $update) {
        $flujo = $flujoOrigin;
        if(!empty($flujo) && !empty($flujo['nodes'])){
            foreach($flujo['nodes'] as $keyNode => $node){
                if(!empty($node['canales_assign'])){
                    $flujo['nodes'][$keyNode]['canales_assign'] = 
                    array_map(function ($id) use ($update){
                        return  $this->replaceWithUpdateMigration($id, $update, 'canales');
                    },  $node['canales_assign']);
                }

                if(!empty($node['grupos_assign'])){
                    $flujo['nodes'][$keyNode]['grupos_assign'] =
                    array_map(function ($id) use ($update){
                        return  $this->replaceWithUpdateMigration($id, $update, 'grupos');
                    },  $node['grupos_assign']);
                }

                if(!empty($node['roles_assign'])){
                    $flujo['nodes'][$keyNode]['roles_assign'] = 
                    array_map(function ($id) use ($update){
                        return  $this->replaceWithUpdateMigration($id, $update, 'roles');
                    },  $node['roles_assign']);
                }

                if(!empty($node['pdfTpl'])){
                    $flujo['nodes'][$keyNode]['pdfTpl'] =
                    $this->replaceWithUpdateMigration ($node['pdfTpl'], $update, 'plantillas');
                }

                if(!empty($node['setuser_roles'])){
                    $flujo['nodes'][$keyNode]['setuser_roles'] = 
                    $this->replaceWithUpdateMigration ($node['setuser_roles'], $update, 'roles');
                }
                
                if(!empty($node['setuser_group'])){
                    $flujo['nodes'][$keyNode]['setuser_group'] = 
                    $this->replaceWithUpdateMigration ($node['setuser_group'], $update, 'grupos');
                }
                
                if(!empty($node['setuser_user'])){
                    $flujo['nodes'][$keyNode]['setuser_user'] = 
                    $this->replaceWithUpdateMigration ($node['setuser_user'], $update, 'users');
                }

                foreach($node['formulario']['secciones'] as $keySeccion => $seccion){
                    foreach($seccion['campos'] as $keyCampo => $campo){
                        if(!empty($campo['grupos_assign'])){
                            $flujo['nodes'][$keyNode]['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['grupos_assign'] = 
                            array_map(function ($id) use ($update){
                                return  $this->replaceWithUpdateMigration($id, $update, 'grupos');
                            },  $campo['grupos_assign']);
                        }

                        if(!empty($campo['roles_assign'])){
                            $flujo['nodes'][$keyNode]['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['roles_assign'] = 
                            array_map(function ($id) use ($update){
                                return  $this->replaceWithUpdateMigration($id, $update, 'roles');
                            },  $campo['roles_assign']);
                        }

                        if(!empty($campo['archivadorRel'])){
                            $flujo['nodes'][$keyNode]['formulario']['secciones'][$keySeccion]['campos'][$keyCampo]['archivadorRel'] = 
                            $this->replaceWithUpdateMigration ($campo['archivadorRel'], $update, 'archivadoresDetalle');
                        }
                    }
                }

            }
        }
        return $flujo;
    }

    function replaceWithUpdateMigration ($id, $update, $table){
        if(!empty($update[$table][$id]) && !empty($update[$table][$id]['id'])){
            $id = $update[$table][$id]['id'];
        }
        return $id;
    }
}
