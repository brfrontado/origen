<?php

namespace App\Http\Controllers;

use app\core\Response;
use App\Models\Paginas;
use App\Models\PaginasAyuda;
use App\Models\PaginasAccess;
use App\Models\PaginasAyudaAccess;
use App\Models\UserGrupoRol;
use App\Models\UserGrupoUsuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;


class PaginasController extends Controller {

    use Response;

    public function calcularVisibilidad($grupos, $usuarioLogueado) {
        $flujoNoVisible = false;

        $usuarioLogueadoId = (!empty($usuarioLogueado)) ? $usuarioLogueado->id : 0;
        $rolUsuarioLogueado = ($usuarioLogueado) ? $usuarioLogueado->rolAsignacion->rol : 0;

        //dd($rolUsuarioLogueado->id);

        $gruposTmp = [];
        foreach ($grupos as $grupo) {
            $gruposTmp[] = $grupo->grupoUsuarioId;
        }

        if (count($gruposTmp) > 0) {

            // verifico roles
            $rolesGroup = UserGrupoRol::whereIn('userGroupId', $gruposTmp)->get();

            // verifico usuarios específicos
            $usersGroup = UserGrupoUsuario::whereIn('userGroupId', $gruposTmp)->get();

            $usersGroupArr = [];
            foreach ($usersGroup as $rolG) {
                $usersGroupArr[] = $rolG->userId;
            }

            if (!in_array($usuarioLogueadoId, $usersGroupArr)) {
                $flujoNoVisible = false;
            }
            else {
                $flujoNoVisible = true;
            }

            if (!empty($rolesGroup)) {
                $rolesGroupArr = [];
                foreach ($rolesGroup as $rolG) {
                    $rolesGroupArr[] = $rolG->rolId;
                }

                if (!empty($rolUsuarioLogueado->id) && !in_array($rolUsuarioLogueado->id, $rolesGroupArr)) {
                    $flujoNoVisible = false;
                }
                else {
                    $flujoNoVisible = true;
                }
            }
            else {
                $flujoNoVisible = false;
            }
        }

        return $flujoNoVisible;
    }

    /**
     * Get Steps
     * @param Request $request
     * @return array|false|string
     */
    public function getBlogDisponible(Request $request) {
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


            return $this->ResponseSuccess('Ok', $results);
        } catch (\Throwable $th) {
            return $this->ResponseError('AUTH-SLIDUTYWEOIUT', 'Error al generar tareas' . $th);
        }
    }

    public function crudPage(Request $request, $id = null) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/paginas'])) return $AC->NoAccess();

        try {
            if ($request->isMethod('post') || $request->isMethod('put')) {
                $validateForm = Validator::make($request->all(), [
                    'slug' => 'nullable|string|max:150',
                    'extracto' => 'nullable|string',
                    'img' => 'nullable',
                    'contenido' => 'nullable|string',
                    'Tags' => 'nullable|string',
                    'authorId' => 'nullable|integer',
                    'nombre' => 'nullable|string|max:200',
                    'subtitle' => 'nullable|string|max:150',
                    'status' => 'nullable',
                    'publica' => 'nullable',
                ]);

                $grupos = $request->get('grupos_assign');

                if ($validateForm->fails()) {
                    $errores = $validateForm->errors()->keys();
                    return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos' . implode(',', $errores));
                }

                if (empty($id)) {
                    $pagina = new Paginas();
                }
                else {
                    $pagina = Paginas::where('id', $id)->where('marcaId', SSO_BRAND_ID);
                    if (!$pagina) {
                        return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
                    }
                }
                $usuario = auth('sanctum')->user();


                // Validar y subir la imagen si se proporciona
                if ($request->hasFile('img')) {
                    $img = $request->file('img');
                    $nombre_archivo = time() . '_' . $img->getClientOriginalName();
                    $directorio = '_blog';


                    try {
                        $path = Storage::disk('s3')->putFileAs($directorio, $img, $nombre_archivo, 'private');
                        $pagina->img = $path ?? $pagina->img;
                    } catch (\Exception $e) {
                        // Capturar y manejar cualquier excepción
                        return $this->ResponseError('ERROR', 'Error al guardar la imagen');
                        //dd($e->getMessage());
                    }
                }

                $status = !empty($request->status);
                $pagina->marcaId = SSO_BRAND_ID;
                $pagina->slug = $request->slug ?? $pagina->slug;
                $pagina->extracto = $request->extracto ?? $pagina->extracto;
                $pagina->contenido = $request->contenido ?? $pagina->contenido;
                $pagina->Tags = $request->Tags ?? $pagina->Tags;
                $pagina->authorId = $usuario->id ?? 0;
                $pagina->subtitle = $request->subtitle ?? $pagina->subtitle;
                $pagina->nombre = $request->nombre ?? $pagina->nombre;
                $pagina->subtitle = $request->subtitle ?? $pagina->subtitle;
                $pagina->publica = intval($request->publica);
                $pagina->status = $status;

                if ($pagina->save()) {

                    // borro los accesos por rol
                    PaginasAccess::where([['paginaId', '=', $pagina->id]])->delete();

                    // guardo los accesos
                    if (!empty($grupos)) {
                        foreach ($grupos as $itemTmp) {
                            $row = new PaginasAccess();
                            $row->paginaId = $pagina->id;
                            $row->grupoUsuarioId = intval($itemTmp);
                            $row->save();
                        }
                    }

                    if (!empty($pagina->img)) {
                        $url = Storage::disk('s3')->temporaryUrl(
                            $pagina->img,
                            now()->addDays(2)
                        );
                        $pagina->img = $url;
                    }
                    return $this->ResponseSuccess('Cambios guardados', $pagina);
                }
                else {
                    return $this->ResponseError('DATABASE-ERROR', 'Error al guardar la página');
                }
            }
            elseif ($request->isMethod('get')) {
                if (empty($id)) {
                    $paginas = Paginas::select('paginas.*', 'users.name as author_name')
                        ->leftJoin('users', 'paginas.authorId', '=', 'users.id')
                        ->where('paginas.marcaId', SSO_BRAND_ID)
                        ->get();

                    foreach ($paginas as $pagina) {
                        if (!empty($pagina->img)) {
                            $url = Storage::disk('s3')->temporaryUrl(
                                $pagina->img,
                                now()->addDays(2)
                            );
                            $pagina->img = $url;
                        }
                    }
                    return $this->ResponseSuccess('Páginas encontradas', $paginas);
                }
                else {
                    $pagina = Paginas::select('paginas.*', 'users.name as author_name')
                        ->leftJoin('users', 'paginas.authorId', '=', 'users.id')
                        ->where('paginas.marcaId', SSO_BRAND_ID)
                        ->find($id);
                    if (!empty($pagina->img)) {
                        $url = Storage::disk('s3')->temporaryUrl(
                            $pagina->img,
                            now()->addDays(2)
                        );
                        $pagina->img = $url;
                    }

                    if ($pagina) {

                        $accesoTmp = $pagina->accesos;
                        $accesos = [];
                        foreach ($accesoTmp as $acc) {
                            $accesos[] = $acc->grupoUsuarioId;
                        }
                        $pagina->acc = $accesos;
                        $pagina->makeHidden(['accesos']);
                        return $this->ResponseSuccess('Entrada encontrada', $pagina);
                    }
                    else {
                        return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
                    }
                }
            }
            elseif ($request->isMethod('delete')) {
                $pagina = Paginas::where('id', $id)->where('marcaId', SSO_BRAND_ID);

                if ($pagina) {
                    $pagina->delete();
                    return $this->ResponseSuccess('Elemento eliminado correctamente');
                }
                else {
                    return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
                }
            }
            else {
                return $this->ResponseError('INVALID-METHOD', 'Método no válido');
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('INTERNAL-ERROR', 'Error interno' . $th);
        }
    }

    public function GetPagina(Request $request, $slug = null) {

        $pagina = Paginas::select('paginas.*', 'users.name as author_name')
            ->leftJoin('users', 'paginas.authorId', '=', 'users.id')
            ->where('paginas.slug', $slug)->first();

        $usuarioLogueado = auth('sanctum')->user();

        if (!$pagina->publica && empty($usuarioLogueado)) {
            return $this->ResponseError('NOT-FOUND', 'Página no encontrada o sin acceso');
        }

        if (!empty($pagina->img)) {
            $url = Storage::disk('s3')->temporaryUrl(
                $pagina->img,
                now()->addDays(2)
            );
            $pagina->img = $url;
        }

        $visibilidad = $this->calcularVisibilidad($pagina->accesos, $usuarioLogueado);

        if (!empty($pagina->publica)) {
            $visibilidad = true;
        }

        if (!$visibilidad) {
            return $this->ResponseError('NOT-FOUND-01', 'Página no encontrada');
        }

        if ($pagina) {
            return $this->ResponseSuccess('Entrada encontrada', $pagina);
        }
        else {
            return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
        }

    }

    public function GetPaginaBanners(Request $request, $slug = null) {

        $paginas = Paginas::select('paginas.*', 'users.name as author_name')
            ->leftJoin('users', 'paginas.authorId', '=', 'users.id')->get();

        $usuarioLogueado = auth('sanctum')->user();

        $arrPaginas = [];
        foreach ($paginas as $pagina) {

            $visibilidad = $this->calcularVisibilidad($pagina->accesos, $usuarioLogueado);

            if (!empty($pagina->img) && $visibilidad) {
                $url = Storage::disk('s3')->temporaryUrl(
                    $pagina->img,
                    now()->addDays(2)
                );
                $pagina->img = $url;
                $arrPaginas[] = [
                    'nombre' => $pagina->extracto,
                    'img' => $pagina->img,
                ];
            }
        }

        return $this->ResponseSuccess('Banners listados con éxito', $arrPaginas);
    }

    public function crudPageAyuda_bk(Request $request, $id = null) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/paginas'])) return $AC->NoAccess();

        try {
            if ($request->isMethod('post') || $request->isMethod('put')) {
                $validateForm = Validator::make($request->all(), [
                    'slug' => 'nullable|string|max:150',
                    'extracto' => 'nullable|string',
                    'img' => 'nullable',
                    'contenido' => 'nullable|string',
                    'Tags' => 'nullable|string',
                    'authorId' => 'nullable|integer',
                    'nombre' => 'nullable|string|max:200',
                    'subtitle' => 'nullable|string|max:150',
                    'status' => 'nullable',
                ]);

                if ($validateForm->fails()) {
                    $errores = $validateForm->errors()->keys();
                    return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos' . implode(',', $errores));
                }

                if (empty($id)) {
                    $pagina = new PaginasAyuda();
                }
                else {
                    $pagina = PaginasAyuda::find($id);
                    if (!$pagina) {
                        return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
                    }
                }
                $usuario = auth('sanctum')->user();


                // Validar y subir la imagen si se proporciona
                if ($request->hasFile('img')) {
                    $img = $request->file('img');
                    $nombre_archivo = time() . '_' . $img->getClientOriginalName();
                    $directorio = '_blog';


                    try {
                        $path = Storage::disk('s3')->putFileAs($directorio, $img, $nombre_archivo, 'private');
                        $pagina->img = $path ?? $pagina->img;
                    } catch (\Exception $e) {
                        // Capturar y manejar cualquier excepción
                        return $this->ResponseError('ERROR', 'Error al guardar la imagen');
                        //dd($e->getMessage());
                    }

                }
                $status = !empty($request->status);
                $pagina->slug = $request->slug ?? $pagina->slug;
                $pagina->extracto = $request->extracto ?? $pagina->extracto;
                $pagina->contenido = $request->contenido ?? $pagina->contenido;
                $pagina->Tags = $request->Tags ?? $pagina->Tags;
                $pagina->authorId = $usuario->id ?? 0;
                $pagina->subtitle = $request->subtitle ?? $pagina->subtitle;
                $pagina->nombre = $request->nombre ?? $pagina->nombre;
                $pagina->subtitle = $request->subtitle ?? $pagina->subtitle;

                $pagina->status = $status;

                if ($pagina->save()) {
                    if (!empty($pagina->img)) {
                        $url = Storage::disk('s3')->temporaryUrl(
                            $pagina->img,
                            now()->addDays(2)
                        );
                        $pagina->img = $url;
                    }
                    return $this->ResponseSuccess('Cambios guardados', $pagina);
                }
                else {
                    return $this->ResponseError('DATABASE-ERROR', 'Error al guardar la página');
                }
            }
            elseif ($request->isMethod('get')) {
                if (empty($id)) {
                    $paginas = PaginasAyuda::select('paginas_ayuda.*', 'users.name as author_name')
                        ->leftJoin('users', 'paginas_ayuda.authorId', '=', 'users.id')
                        ->get();

                    foreach ($paginas as $pagina) {
                        if (!empty($pagina->img)) {
                            $url = Storage::disk('s3')->temporaryUrl(
                                $pagina->img,
                                now()->addDays(2)
                            );
                            $pagina->img = $url;
                        }
                    }
                    return $this->ResponseSuccess('Páginas encontradas', $paginas);
                }
                else {
                    $pagina = PaginasAyuda::select('paginas_ayuda.*', 'users.name as author_name')
                        ->leftJoin('users', 'paginas_ayuda.authorId', '=', 'users.id')
                        ->find($id);
                    if (!empty($pagina->img)) {
                        $url = Storage::disk('s3')->temporaryUrl(
                            $pagina->img,
                            now()->addDays(2)
                        );
                        $pagina->img = $url;
                    }

                    if ($pagina) {
                        return $this->ResponseSuccess('Entrada encontrada', $pagina);
                    }
                    else {
                        return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
                    }
                }
            }
            elseif ($request->isMethod('delete')) {
                $pagina = PaginasAyuda::find($id);

                if ($pagina) {
                    $pagina->delete();
                    $paginas = PaginasAyuda::all();
                    return $this->ResponseSuccess('Elemento eliminado correctamente', $paginas);
                }
                else {
                    return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
                }
            }
            else {
                return $this->ResponseError('INVALID-METHOD', 'Método no válido');
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('INTERNAL-ERROR', 'Error interno' . $th);
        }
    }

    public function crudPageAyuda(Request $request, $id = null) {

        $AC = new AuthController();
        if (!$AC->CheckAccess(['admin/paginas'])) return $AC->NoAccess();

        try {
            if ($request->isMethod('post') || $request->isMethod('put')) {
                $validateForm = Validator::make($request->all(), [
                    'slug' => 'nullable|string|max:150',
                    'extracto' => 'nullable|string',
                    'img' => 'nullable',
                    'contenido' => 'nullable|string',
                    'Tags' => 'nullable|string',
                    'authorId' => 'nullable|integer',
                    'nombre' => 'nullable|string|max:200',
                    'subtitle' => 'nullable|string|max:150',
                    'status' => 'nullable',
                    'publica' => 'nullable',
                ]);

                $grupos = $request->get('grupos_assign');

                if ($validateForm->fails()) {
                    $errores = $validateForm->errors()->keys();
                    return $this->ResponseError('AUTH-AF10dsF', 'Faltan Campos' . implode(',', $errores));
                }

                if (empty($id)) {
                    $pagina = new PaginasAyuda();
                }
                else {
                    $pagina = PaginasAyuda::find($id);
                    if (!$pagina) {
                        return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
                    }
                }
                $usuario = auth('sanctum')->user();


                // Validar y subir la imagen si se proporciona
                if ($request->hasFile('img')) {
                    $img = $request->file('img');
                    $nombre_archivo = time() . '_' . $img->getClientOriginalName();
                    $directorio = '_blog';


                    try {
                        $path = Storage::disk('s3')->putFileAs($directorio, $img, $nombre_archivo, 'private');
                        $pagina->img = $path ?? $pagina->img;
                    } catch (\Exception $e) {
                        // Capturar y manejar cualquier excepción
                        return $this->ResponseError('ERROR', 'Error al guardar la imagen');
                        //dd($e->getMessage());
                    }
                }

                $status = !empty($request->status);
                $pagina->slug = $request->slug ?? $pagina->slug;
                $pagina->extracto = $request->extracto ?? $pagina->extracto;
                $pagina->contenido = $request->contenido ?? $pagina->contenido;
                $pagina->Tags = $request->Tags ?? $pagina->Tags;
                $pagina->authorId = $usuario->id ?? 0;
                $pagina->subtitle = $request->subtitle ?? $pagina->subtitle;
                $pagina->nombre = $request->nombre ?? $pagina->nombre;
                $pagina->subtitle = $request->subtitle ?? $pagina->subtitle;
                $pagina->publica = intval($request->publica);
                $pagina->status = $status;

                if ($pagina->save()) {

                    // borro los accesos por rol
                    PaginasAyudaAccess::where([['paginaId', '=', $pagina->id]])->delete();

                    // guardo los accesos
                    foreach ($grupos as $itemTmp) {
                        $row = new PaginasAyudaAccess();
                        $row->paginaId = $pagina->id;
                        $row->grupoUsuarioId = intval($itemTmp);
                        $row->save();
                    }

                    if (!empty($pagina->img)) {
                        $url = Storage::disk('s3')->temporaryUrl(
                            $pagina->img,
                            now()->addDays(2)
                        );
                        $pagina->img = $url;
                    }
                    return $this->ResponseSuccess('Cambios guardados', $pagina);
                }
                else {
                    return $this->ResponseError('DATABASE-ERROR', 'Error al guardar la página');
                }
            }
            elseif ($request->isMethod('get')) {
                if (empty($id)) {
                    $paginas = PaginasAyuda::select('paginas_ayuda.*', 'users.name as author_name')
                        ->leftJoin('users', 'paginas_ayuda.authorId', '=', 'users.id')
                        ->get();

                    foreach ($paginas as $pagina) {
                        if (!empty($pagina->img)) {
                            $url = Storage::disk('s3')->temporaryUrl(
                                $pagina->img,
                                now()->addDays(2)
                            );
                            $pagina->img = $url;
                        }
                    }
                    return $this->ResponseSuccess('Páginas encontradas', $paginas);
                }
                else {
                    $pagina = PaginasAyuda::select('paginas_ayuda.*', 'users.name as author_name')
                        ->leftJoin('users', 'paginas_ayuda.authorId', '=', 'users.id')
                        ->find($id);
                    if (!empty($pagina->img)) {
                        $url = Storage::disk('s3')->temporaryUrl(
                            $pagina->img,
                            now()->addDays(2)
                        );
                        $pagina->img = $url;
                    }

                    if ($pagina) {

                        $accesoTmp = $pagina->accesos;
                        $accesos = [];
                        foreach ($accesoTmp as $acc) {
                            $accesos[] = $acc->grupoUsuarioId;
                        }
                        $pagina->acc = $accesos;
                        $pagina->makeHidden(['accesos']);
                        return $this->ResponseSuccess('Entrada encontrada', $pagina);
                    }
                    else {
                        return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
                    }
                }
            }
            elseif ($request->isMethod('delete')) {
                $pagina = PaginasAyuda::find($id);

                if ($pagina) {
                    $pagina->delete();
                    $paginas = PaginasAyuda::all();
                    return $this->ResponseSuccess('Elemento eliminado correctamente', $paginas);
                }
                else {
                    return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
                }
            }
            else {
                return $this->ResponseError('INVALID-METHOD', 'Método no válido');
            }
        } catch (\Throwable $th) {
            return $this->ResponseError('INTERNAL-ERROR', 'Error interno' . $th);
        }
    }

    public function GetPaginaAyuda(Request $request, $slug = null) {

        $pagina = PaginasAyuda::select('paginas_ayuda.*', 'users.name as author_name')
            ->leftJoin('users', 'paginas_ayuda.authorId', '=', 'users.id')
            ->where('paginas_ayuda.slug', $slug)->first();

        $usuarioLogueado = auth('sanctum')->user();

        if (!empty($pagina->publica) && empty($usuarioLogueado)) {
            return $this->ResponseError('NOT-FOUND', 'Página no encontrada o sin acceso');
        }

        if (!empty($pagina->img)) {
            $url = Storage::disk('s3')->temporaryUrl(
                $pagina->img,
                now()->addDays(2)
            );
            $pagina->img = $url;
        }

        $visibilidad = $this->calcularVisibilidad($pagina->accesos, $usuarioLogueado);

        if (!$visibilidad) {
            return $this->ResponseError('NOT-FOUND-01', 'Página no encontrada');
        }

        if ($pagina) {
            return $this->ResponseSuccess('Entrada encontrada', $pagina);
        }
        else {
            return $this->ResponseError('NOT-FOUND', 'Página no encontrada');
        }

    }


}
