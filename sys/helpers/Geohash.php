<?php
declare(strict_types=1);

/**
 * this is a part of camcima/php-geohash prject.
*/


namespace sys\helpers;

class Geohash
{
    private static $characterTable = '0123456789bcdefghjkmnpqrstuvwxyz';
    public static function encode(float $lat, float $lng, int $precision = 7) :string
    {
/*
        if (isset($this->precision)) {
            $precision = $this->precision;
        } else {
            $latitudePrecision = strlen((string) $lat) - strpos((string) $lat, '.');
            $longitudePrecision = strlen((string) $lng) - strpos((string) $lng, '.');
            $precision = pow(10, -max($latitudePrecision - 1, $longitudePrecision - 1, 0)) / 2;
            $this->precision = $precision;
        }
*/

        $minLatitude = (float) -90;
        $maxLatitude = (float) 90;
        $minLongitude = (float) -180;
        $maxLongitude = (float) 180;
        $latitudeError = (float) 90;
        $longitudeError = (float) 180;
        $error = (float) 180;

        $i = 0;
        $hash = '';
        while ($error >= $precision) {
            $digitValue = 0;
            for ($bit = 4; $bit >= 0; --$bit) {
                if ((1 & $bit) == (1 & $i)) { // even char, even bit OR odd char, odd bit...a lng
                    $next = ($minLongitude + $maxLongitude) / 2;

                    if ($lng > $next) {
                        $digitValue |= pow(2, $bit);
                        $minLongitude = $next;
                    } else {
                        $maxLongitude = $next;
                    }

                    $longitudeError /= 2;
                } else { // odd char, even bit OR even char, odd bit...a lat
                    $next = ($minLatitude + $maxLatitude) / 2;

                    if ($lat > $next) {
                        $digitValue |= pow(2, $bit);
                        $minLatitude = $next;
                    } else {
                        $maxLatitude = $next;
                    }

                    $latitudeError /= 2;
                }
            }
            $hash .= self::$characterTable[$digitValue];
            $error = min($latitudeError, $longitudeError);
            $i++;
        }
        return $hash;
    }


    public static function decode(string $hash) : array
    {
        $minLatitude = -90;
        $maxLatitude = 90;
        $minLongitude = -180;
        $maxLongitude = 180;
        $latitudeError = 90;
        $longitudeError = 180;

        for ($i = 0; $i < strlen($hash); $i++) {
            $characterValue = strpos(static::$characterTable, $hash[$i]);

            if (1 & $i) {
                if (16 & $characterValue) {
                    $minLatitude = ($minLatitude + $maxLatitude) / 2;
                } else {
                    $maxLatitude = ($minLatitude + $maxLatitude) / 2;
                }

                if (8 & $characterValue) {
                    $minLongitude = ($minLongitude + $maxLongitude) / 2;
                } else {
                    $maxLongitude = ($minLongitude + $maxLongitude) / 2;
                }

                if (4 & $characterValue) {
                    $minLatitude = ($minLatitude + $maxLatitude) / 2;
                } else {
                    $maxLatitude = ($minLatitude + $maxLatitude) / 2;
                }

                if (2 & $characterValue) {
                    $minLongitude = ($minLongitude + $maxLongitude) / 2;
                } else {
                    $maxLongitude = ($minLongitude + $maxLongitude) / 2;
                }

                if (1 & $characterValue) {
                    $minLatitude = ($minLatitude + $maxLatitude) / 2;
                } else {
                    $maxLatitude = ($minLatitude + $maxLatitude) / 2;
                }

                $latitudeError /= 8;
                $longitudeError /= 4;
            } else {
                if (16 & $characterValue) {
                    $minLongitude = ($minLongitude + $maxLongitude) / 2;
                } else {
                    $maxLongitude = ($minLongitude + $maxLongitude) / 2;
                }

                if (8 & $characterValue) {
                    $minLatitude = ($minLatitude + $maxLatitude) / 2;
                } else {
                    $maxLatitude = ($minLatitude + $maxLatitude) / 2;
                }

                if (4 & $characterValue) {
                    $minLongitude = ($minLongitude + $maxLongitude) / 2;
                } else {
                    $maxLongitude = ($minLongitude + $maxLongitude) / 2;
                }

                if (2 & $characterValue) {
                    $minLatitude = ($minLatitude + $maxLatitude) / 2;
                } else {
                    $maxLatitude = ($minLatitude + $maxLatitude) / 2;
                }

                if (1 & $characterValue) {
                    $minLongitude = ($minLongitude + $maxLongitude) / 2;
                } else {
                    $maxLongitude = ($minLongitude + $maxLongitude) / 2;
                }

                $latitudeError /= 4;
                $longitudeError /= 8;
            }
        }
        return [
            'lat'=>(float) round(($minLatitude + $maxLatitude) / 2, (int) max(1, -round(log10($latitudeError))) - 1),
            'lng'=>(float) round(($minLongitude + $maxLongitude) / 2, (int) max(1, -round(log10($longitudeError))) - 1),
            'prec'=>(float) max($latitudeError, $longitudeError),
        ];
    }
}