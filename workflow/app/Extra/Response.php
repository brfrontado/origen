<?php
namespace app\core;

trait Response {

    protected function ResponseSuccess(string $message = null, $data = [], $json = true) {
        $data = [
            'status' => 1,
            'msg' => $message,
            'data' => $data
        ];

        if ($json) {
            return json_encode($data);
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
            return json_encode($data);
        }
        else {
            return $data;
        }
    }
}
