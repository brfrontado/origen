<?php

namespace App\Extra;
// General singleton class.
use Carbon\Carbon;

class Tools {

    public static function dateConvertToDB($date, $startDayTime = false, $endDayTime = false) {
        if ($startDayTime) {
            $date = $date. " 00:00:00";
        }
        else if ($endDayTime) {
            $date = $date. " 23:59:59";
        }
        $date = Carbon::createFromFormat('Y-m-d H:i:s', $date, 'America/Guatemala');

        // set new timezone
        $date->setTimezone('UTC');
        return $date->format('Y-m-d H:i:s');
    }

    public static function dateConvertFromDB($date, $format = 'd-m-Y H:i', $startDayTime = false, $endDayTime = false) {
        if ($startDayTime) {
            $date = $date. " 00:00:00";
        }
        else if ($endDayTime) {
            $date = $date. " 23:59:59";
        }
        $date = Carbon::createFromFormat('Y-m-d H:i:s', $date, 'UTC');

        // set new timezone
        $date->setTimezone('America/Guatemala');
        return $date->format($format);
    }

    public function makeInitialsFromWords(string $name) : string {
        $words = explode(' ', $name);
        if (count($words) >= 2) {
            return mb_strtoupper(
                mb_substr($words[0], 0, 1, 'UTF-8') .
                mb_substr(end($words), 0, 1, 'UTF-8'),
                'UTF-8');
        }
        return $this->makeInitialsFromSingleWord($name);
    }

    /**
     * Make initials from a word with no spaces
     *
     * @param string $name
     * @return string
     */
    protected function makeInitialsFromSingleWord(string $name) : string
    {
        preg_match_all('#([A-Z]+)#', $name, $capitals);
        if (count($capitals[1]) >= 2) {
            return mb_substr(implode('', $capitals[1]), 0, 2, 'UTF-8');
        }
        return mb_strtoupper(mb_substr($name, 0, 2, 'UTF-8'), 'UTF-8');
    }

    public function objectToArray($obj) {
        if(is_object($obj)) $obj = (array) $obj;
        if(is_array($obj)) {
            $new = array();
            foreach($obj as $key => $val) {
                $new[$key] = $this->objectToArray($val);
            }
        }
        else $new = $obj;
        return $new;
    }

    public function saveImgBase64ToFile($image) {

        $matchings = [];
        if(preg_match("/^data:image\/(?<extension>(?:png|gif|jpg|jpeg));base64,(?<image>.+)$/", $image, $matchings)) {
            $imageData = base64_decode($matchings['image']);
            $extension = $matchings['extension'];
            $name = uniqid() . ".{$extension}";
            $tmpFile = storage_path("tmp/" . $name);

            if(file_put_contents($tmpFile, $imageData)) {
                return [
                    'name' => $name,
                    'ext' => $extension,
                    'path' => $tmpFile,
                ];
            }
            else {
                return false;
            }
        }
    }
}
