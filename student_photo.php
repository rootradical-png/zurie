<?php
$nomatrik = $_GET['nomatrik'] ?? '';
$nomatrik = preg_replace('/[^A-Za-z0-9]/', '', $nomatrik);

if ($nomatrik == '') {
    http_response_code(400);
    exit('No matric');
}

$base = "http://mis.kmp.matrik.edu.my/misv3/pictures/student/";
// Prioriti MIS: jpg -> jpeg -> png. Uppercase sebagai fallback sahaja.
$extensions = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'];

foreach ($extensions as $ext) {
    $url = $base . $nomatrik . "." . $ext;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ZuriePhotoProxy/1.0');

    $img = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code == 200 && $img !== false && strlen($img) > 500) {
        $info = @getimagesizefromstring($img);
        if ($info && !empty($info['mime']) && stripos($info['mime'], 'image/') === 0) {
            header('Content-Type: ' . $info['mime']);
            header('Cache-Control: public, max-age=86400');
            echo $img;
            exit;
        }
    }
}

http_response_code(404);
exit('Image not found');
