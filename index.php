<?php
require_once("config.php");
$urlDownload = null;
$processComplete = false;

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

function shortenUrlWithBicolink($longUrl, $bico_key) {
    $bicoUrl = "https://bicolink.com/api?api=$bico_key&url=" . urlencode($longUrl);
    $bch = curl_init();
    curl_setopt_array($bch, [
        CURLOPT_URL => $bicoUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ["Authorization: Basic " . base64_encode(":" . $bico_key)],
    ]);
    $hasilBico = curl_exec($bch);
    curl_close($bch);
    return json_decode($hasilBico, true);
}

if (isset($_POST["submit"])) {
    $input_asli = htmlspecialchars($_POST["versi"]);
    $userVersion = formatVersiAsli($input_asli);
    $userServer = htmlspecialchars($_POST["server"]);

    if (empty($userVersion) || empty($userServer)) {
        echo("<script>alert('Harap isi bidang kosong')</script>");
    } else {
        $stmt = $conn->prepare("SELECT * FROM file_fix WHERE versi = ? AND server = ?");
        $stmt->bind_param("ss", $userVersion, $userServer);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $dataVersi = $result->fetch_assoc();
            $urlDownload = $dataVersi["url_shortlink"];
            $processComplete = true;
        } else {
            $dataDir = "_data/$userServer";
            if (file_exists($dataDir) && is_file($dataDir)) {
                $content = file_get_contents($dataDir);
                $formatedVersiFile = formatVersiFile($userVersion);
                $combineData = "$formatedVersiFile\n$content";
                $folderArchive = __DIR__ . '/archive/';
                if (!is_dir($folderArchive)) {
                    mkdir($folderArchive, 0755, true);
                }
                if($userServer==="ori"){
                  $server = "Original";
                }else{
                  $server = "Advanced";
                }
                $namaZip = $folderArchive . "Fix_Download_Data_versi_".$userVersion."_[".$server. "_server].zip";
                $pathDiZip = "com.mobile.legends/files/dragon2017/assets/loadres_module_info";

                if (buatZipDariString($namaZip, $pathDiZip, $combineData)) {
                    $api_key = 'APIKEY';
                    $uploadResponse = uploadToPixeldrain($namaZip, $api_key);
                    if (!$uploadResponse) {
                        unlink($namaZip);
                        echo "<script>alert('server error')</script>";
                    } else {
                        unlink($namaZip);
                        $longUrl = "https://pixeldrain.com/u/" . $uploadResponse['id'];
                        $bico_key = "APIKEY";
                        $hasilBicoJson = shortenUrlWithBicolink($longUrl, $bico_key);

                        if (isset($hasilBicoJson["status"]) && $hasilBicoJson["status"] === "success") {
                            $sl = urldecode($hasilBicoJson["shortenedUrl"]);
                            $stmt = $conn->prepare("INSERT INTO file_fix (server, versi, url_pd, url_shortlink) VALUES (?, ?, ?, ?)");
                            $stmt->bind_param("ssss", $userServer, $userVersion, $longUrl, $sl);
                            if ($stmt->execute()) {
                                $urlDownload = $sl;
                                $processComplete = true;
                            } else {
                                echo("<script>alert('Gagal menyimpan data')</script>");
                            }
                        } else {
                            echo "<script>alert('Gagal: " . json_encode($hasilBicoJson) . "')</script>";
                        }
                    }
                } else {
                    echo "<script>alert('Gagal membuat file ZIP.')</script>";
                }
            } else {
                echo "<script>alert('File $dataDir tidak ditemukan atau bukan file yang valid.')</script>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create File</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.10.3/dist/cdn.min.js" defer></script>
  <style>
    @keyframes fadeIn {
      0% { opacity: 0; transform: translateY(10px); }
      100% { opacity: 1; transform: translateY(0); }
    }
    .fade-in {
      animation: fadeIn 0.5s ease-in-out;
    }
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.7; }
    }
    .pulse-slow {
      animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
  </style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen flex items-center justify-center py-12" x-data="{ 
  loading: false,
  server: '',
  input: '',
  digitCount: 0,
  nonDigitCount: 0,
  isValid: false,
  
  validateInput() {
    const inputValue = this.input;
    const digits = inputValue.replace(/\D/g, '');
    const nonDigits = inputValue.replace(/\d/g, '');
    
    this.digitCount = digits.length;
    this.nonDigitCount = nonDigits.length;
    
    this.isValid = (
      this.server !== '' && 
      this.digitCount >= 9 && 
      this.digitCount <= 15 && 
      this.nonDigitCount <= 6
    );
  }
}">
  
  <!-- Loading overlay -->
  <div x-show="loading" 
       class="fixed inset-0 bg-gray-900 bg-opacity-90 backdrop-blur-xl flex flex-col items-center justify-center z-50 transition duration-300">
    <!-- Bars fade loading animation from svg-spinners -->
    <svg width="44" height="44" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" class="text-sky-500">
      <rect x="1" y="6" width="2.8" height="12" class="fill-current">
        <animate attributeName="opacity" begin="0s" dur="1s" values="1;.2;1" repeatCount="indefinite" />
      </rect>
      <rect x="5.8" y="6" width="2.8" height="12" class="fill-current">
        <animate attributeName="opacity" begin="0.1s" dur="1s" values="1;.2;1" repeatCount="indefinite" />
      </rect>
      <rect x="10.6" y="6" width="2.8" height="12" class="fill-current">
        <animate attributeName="opacity" begin="0.2s" dur="1s" values="1;.2;1" repeatCount="indefinite" />
      </rect>
      <rect x="15.4" y="6" width="2.8" height="12" class="fill-current">
        <animate attributeName="opacity" begin="0.3s" dur="1s" values="1;.2;1" repeatCount="indefinite" />
      </rect>
      <rect x="20.2" y="6" width="2.8" height="12" class="fill-current">
        <animate attributeName="opacity" begin="0.4s" dur="1s" values="1;.2;1" repeatCount="indefinite" />
      </rect>
    </svg>
    <p class="text-lg text-sky-100 font-medium p-5 text-center">Tunggu sebentar untuk generate link download...</p>
    <p class="text-sm text-sky-300 mt-2 pulse-slow">Mohon tidak menutup tab ini</p>
  </div>
  
  <div class="container mx-auto px-4 max-w-md">
    <?php if (isset($processComplete) && isset($urlDownload) && $processComplete && $urlDownload): ?>
      <!-- Success state -->
      <div class="bg-gray-800 border border-gray-700 rounded-xl shadow-xl overflow-hidden mb-8 fade-in">
        <div class="bg-gradient-to-r from-sky-700 to-indigo-700 px-6 py-4">
          <h2 class="text-xl font-bold text-white text-center">File Berhasil Dibuat!</h2>
        </div>
        <div class="p-6 flex flex-col items-center">
          <div class="w-24 h-24 mb-4 bg-gray-700 rounded-full flex items-center justify-center">
            <img src="https://media1.tenor.com/m/eqYP-eXdCicAAAAd/nilou-genshin.gif" class="w-[150px] aspect-square rounded" alt="">
          </div>
          <p class="mb-4 text-gray-300 text-center">link download sudah siapp >3< 🥰❤️😘</p>
          <a href="<?php echo $urlDownload; ?>" target="_blank" 
             class="bg-gradient-to-r from-blue-700 to-blue-900 hover:from-blue-700 hover:to-blue-500 text-white font-medium py-3 px-6 rounded-lg transition duration-300 flex items-center w-full justify-center shadow-lg">
            
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            
            Download File
          </a>
        </div>
      </div>
    <?php else: ?>
      <!-- Form state -->
      <div class="bg-gray-800 rounded-xl shadow-xl overflow-hidden border border-gray-700">
        <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-5 border-b border-gray-700">
          <h1 class="text-2xl font-bold text-center text-white flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Create File
          </h1>
        </div>
        
        <form method="post" action="" id="create-form" class="p-6 space-y-5" @submit="loading = true">
          <!-- Server Selection -->
          <div>
            <label for="server" class="block text-sm font-medium text-gray-300 mb-2">Select Server</label>
            <div class="relative">
              <select 
                name="server" 
                id="server" 
                x-model="server" 
                @change="validateInput()" 
                class="block w-full pl-4 pr-10 py-3 bg-gray-700 border border-gray-600 text-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500 appearance-none" 
                required
              >
                <option value="">Pilih Server ML</option>
                <option value="ori">Original</option>
                <option value="adv">Advanced</option>
              </select>
              <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none">
                <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
              </div>
            </div>
          </div>
          
          <!-- Input Field -->
          <div>
            <label for="input" class="block text-sm font-medium text-gray-300 mb-2">Input</label>
            <div class="relative rounded-lg">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <!-- Number SVG from svgrepo -->
                <svg width="24px" height="24px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400">
                  <path d="M4 9.5C4 8.12 5.12 7 6.5 7H17.5C18.88 7 20 8.12 20 9.5V14.5C20 15.88 18.88 17 17.5 17H6.5C5.12 17 4 15.88 4 14.5V9.5ZM6.5 9C6.22 9 6 9.22 6 9.5V14.5C6 14.78 6.22 15 6.5 15H17.5C17.78 15 18 14.78 18 14.5V9.5C18 9.22 17.78 9 17.5 9H6.5Z" fill="currentColor"/>
                  <path d="M14.0184 13H15.0183V11H17.0183V10H15.0183V8H14.0184V10H12.0183V11H14.0184V13Z" fill="currentColor"/>
                  <path d="M8.01826 13C8.5477 13 8.92578 12.7761 9.1512 12.3284L9.56723 11.6716C9.62237 11.597 9.56723 11.5 9.48027 11.5H7.88739C7.58163 11.5 7.33025 11.2687 7.33025 10.9833C7.33025 10.6979 7.58163 10.4667 7.88739 10.4667H9.14079V10.0333H7.88739V9.5H10.0183V10.4667C10.0183 10.5503 10.0099 10.6299 9.9932 10.7045C10.1124 10.4582 10.3519 10.3 10.6345 10.3C11.0683 10.3 11.4209 10.6358 11.4209 11.0483C11.4209 11.2478 11.3312 11.4271 11.1867 11.5503C11.3312 11.6736 11.4209 11.8529 11.4209 12.0524C11.4209 12.4649 11.0683 12.8008 10.6345 12.8008C10.3628 12.8008 10.1318 12.6538 10.0096 12.4225C9.76366 12.7955 9.35098 13 8.86987 13H7.01826V12.4667H8.01826Z" fill="currentColor"/>
                </svg>
              </div>
              <input 
                name="versi" 
                maxlength="20"
                required 
                type="text" 
                inputmode="text" 
                id="input" 
                x-model="input"
                @input="validateInput()"
                :class="{'border-red-500': (input.length > 0 && (digitCount < 9 || digitCount > 15 || nonDigitCount > 6))}"
                class="block w-full pl-10 pr-4 py-3 bg-gray-700 border border-gray-600 text-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500" 
                placeholder="Contoh X.X.XX.XXXX.X"
              >
            </div>
            <!-- Input validation messages -->
            <div class="text-red-500 text-sm mt-1" x-show="input.length > 0 && (digitCount < 9 || digitCount > 15 || nonDigitCount > 6)">
              <template x-if="digitCount < 9">
                <p>Minimal 9 digit angka diperlukan</p>
              </template>
              <template x-if="digitCount > 15">
                <p>Maksimal 15 digit angka diperbolehkan</p>
              </template>
              <template x-if="nonDigitCount > 6">
                <p>Maksimal 6 karakter selain angka diperbolehkan</p>
              </template>
            </div>
          </div>

          <!-- Requirements Info -->
          <div class="bg-gray-700 rounded-lg p-4 text-sm">
            <p class="text-gray-300 mb-2 font-medium">Persyaratan input:</p>
            <ul class="space-y-1 text-gray-400 pl-5 list-disc">
              <li>Minimal 9 digit angka</li>
              <li>Maksimal 15 digit angka</li>
              <li>Maksimal 6 karakter selain angka</li>
              <li>Ex : 1.9.68.1063.1</li>
            </ul>
            <div class="mt-3 flex gap-4">
              <div>
                <span class="text-gray-400">Digit: </span>
                <span :class="{'text-green-400': digitCount >= 9 && digitCount <= 15, 'text-red-400': digitCount < 9 || digitCount > 15}" x-text="digitCount"></span>
              </div>
              <div>
                <span class="text-gray-400">Non-digit: </span>
                <span :class="{'text-green-400': nonDigitCount <= 6, 'text-red-400': nonDigitCount > 6}" x-text="nonDigitCount"></span>
              </div>
            </div>
          </div>
          
          <!-- Submit Button -->
          <button 
            type="submit" 
            name="submit" 
            :disabled="!isValid"
            class="w-full flex items-center justify-center text-white py-3 px-4 rounded-lg font-medium transition-all duration-300 shadow-lg"
            :class="isValid ? 'bg-gradient-to-r from-blue-500 to-blue-800' : 'bg-gray-600 cursor-not-allowed opacity-70'"
          >
            <span>Create</span>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
            </svg>
          </button>
        </form>
      </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <div class="text-center mt-6 text-gray-400 text-sm">
      &copy; <?php echo date('Y'); ?> File Creator. All rights reserved RIZKID.
    </div>
  </div>
</body>
</html>