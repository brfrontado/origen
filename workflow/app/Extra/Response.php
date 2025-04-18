<?php
namespace app\core;
use App\Models\Marca;


trait Response {

    protected function ResponseSuccess(string $message = null, $data = [], $json = true) {
        $data = [
            'status' => 1,
            'msg' => $message,
            'data' => $data
        ];

        if ($json) {
            //return json_encode($data);
            return \Illuminate\Support\Facades\Response::json($data);
        }
        else {
            return $data;
        }
    }

    protected function ResponseError(string $errorCode = '', string $message = null, $data = [], $appendErrorToMsg = true, $json = true) {
        if ($appendErrorToMsg) {
            $message = "{$message} ({$errorCode})";
        }
        $data = [
            'status' => 0,
            'msg' => $message,
            'data' => $data,
            'error-code' => $errorCode,
        ];

        if ($json) {
            return \Illuminate\Support\Facades\Response::json($data);

            //return json_encode($data);
        }
        else {
            return $data;
        }
    }

    protected function GetBrandConfig($marcaId, $configKey) {
        $marca = Marca::where('id', $marcaId)->first();
        if (!empty($marca->config)) {
            $decode = json_decode($marca->config, true);
            return $decode[$configKey] ?? [];
        }
        else {
            return [];
        }
    }
}
