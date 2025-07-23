<?php
function buatZipDariString($zipName, $pathDiZip, $dataString) {
    $zip = new ZipArchive();
    if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        trigger_error("Tidak dapat membuka atau membuat file ZIP \"$zipName\".", E_USER_WARNING);
        return false;
    }
    if (!$zip->addFromString($pathDiZip, $dataString)) {
        trigger_error("Gagal menambahkan data ke arsip sebagai \"$pathDiZip\".", E_USER_WARNING);
        $zip->close();
        return false;
    }
    $zip->close();
    return true;
}

function formatVersiAsli($input) {
    // Hapus semua karakter selain angka
    $onlyNumbers = preg_replace('/\D/', '', $input);

    // Ambil 9 digit terakhir
    $onlyNumbers = substr($onlyNumbers, -9);
    // Pola potongan dari belakang: [1, 4, 2, 1, 1]
    $pattern = [1, 4, 2, 1, 1];
    $parts = [];
    $index = strlen($onlyNumbers);

    foreach ($pattern as $length) {
        $start = max($index - $length, 0);
        $parts[] = substr($onlyNumbers, $start, $index - $start);
        $index -= $length;
    }
    // Gabungkan sesuai urutan dari belakang
    return implode('.', array_reverse($parts));
}

function formatVersiFile($input) {
    // Bersihkan karakter non-angka
    $onlyNumbers = preg_replace('/\D/', '', $input);

    // Ambil 5 digit terakhir saja
    $onlyNumbers = substr($onlyNumbers, -5);

    // Jika kurang dari 5 digit, tetap proses sesuai sisa
    $pattern = [1, 4]; // dari belakang: 1 digit, lalu 4 digit
    $parts = [];
    $index = strlen($onlyNumbers);

    foreach ($pattern as $length) {
        $start = max($index - $length, 0);
        $parts[] = substr($onlyNumbers, $start, $index - $start);
        $index -= $length;
    }

    // Gabungkan dari belakang ke depan
    return implode('.', array_reverse($parts));
}

function uploadToPixeldrain($file_path, $api_key) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://pixeldrain.com/api/file/" . urlencode(basename($file_path)),
        CURLOPT_PUT => true,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_INFILE => fopen($file_path, 'r'),
        CURLOPT_INFILESIZE => filesize($file_path),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Basic " . base64_encode(":" . $api_key)
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : false;
}