<?php
spl_autoload_register(static function (string $class): void {
    foreach (['BaconQrCode\\'=>'bacon', 'DASPRiD\\Enum\\'=>'enum'] as $prefix => $package) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/' . $package . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});
function accountTotpSvg(string $uri): string
{
    $matrix = \BaconQrCode\Encoder\Encoder::encode($uri, \BaconQrCode\Common\ErrorCorrectionLevel::M())->getMatrix();
    $size = $matrix->getWidth() + 8;
    $path = '';
    for ($y=0; $y<$matrix->getHeight(); $y++) {
        for ($x=0; $x<$matrix->getWidth(); $x++) {
            if ($matrix->get($x,$y) === 1) $path .= 'M'.($x+4).' '.($y+4).'h1v1h-1z';
        }
    }
    return '<svg role="img" aria-label="QR-код Google Authenticator" viewBox="0 0 '.$size.' '.$size.'" xmlns="http://www.w3.org/2000/svg" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="white"/><path d="'.$path.'" fill="black"/></svg>';
}
