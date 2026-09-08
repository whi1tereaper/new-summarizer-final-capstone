<?php

use App\Src\Support\Env;

$storageRoot = Env::requirePath('STORAGE_ROOT');
$storageUploads = Env::requirePath('STORAGE_UPLOADS');
$storageAudio = Env::requirePath('STORAGE_AUDIO');
$storageLogs = Env::requirePath('STORAGE_LOGS');
$storageTmp = Env::requirePath('STORAGE_TMP');

return [
    'app' => [
        'name' => Env::requireString('APP_NAME'),
        'env' => Env::requireString('APP_ENV'),
        'debug' => Env::requireBool('APP_DEBUG'),
        'url' => Env::requireString('APP_URL'),
    ],

    'database' => [
        'host' => Env::requireString('DB_HOST'),
        'port' => Env::requireString('DB_PORT'),
        'name' => Env::requireString('DB_NAME'),
        'username' => Env::requireString('DB_USERNAME'),
        'password' => Env::requireString('DB_PASSWORD'),
        'charset' => Env::requireString('DB_CHARSET'),
    ],

    'python' => [
        'bin' => Env::requireString('LOCAL_PYTHON_BIN'),
        'script' => Env::requirePath('LOCAL_PYTHON_SCRIPT'),
        'timeout' => Env::requireInt('LOCAL_PYTHON_TIMEOUT'),
        'nltk_data' => Env::requireString('NLTK_DATA'),
        'tesseract_cmd' => Env::requireString('TESSERACT_CMD'),
        'poppler_path' => Env::requireString('POPPLER_PATH'),
    ],

    'storage' => [
        'root' => $storageRoot,
        'uploads' => $storageUploads,
        'audio' => $storageAudio,
        'logs' => $storageLogs,
        'tmp' => $storageTmp,
    ],

    'translation' => [
        'provider' => Env::requireString('TRANSLATION_PROVIDER'),
        'target_lang' => Env::requireString('TRANSLATION_TARGET_LANG'),
    ],

    'tts' => [
        'max_chars' => Env::requireInt('TTS_MAX_CHARS'),
        'max_text_length' => Env::requireInt('TTS_MAX_TEXT_LENGTH'),
        'timeout_seconds' => Env::requireInt('TTS_REQUEST_TIMEOUT_SECONDS'),
        'output_dir' => Env::requirePath('TTS_OUTPUT_DIR'),
        'audio_output_dir' => Env::requirePath('AUDIO_OUTPUT_DIR'),
        'audio_public_url' => Env::requireString('AUDIO_PUBLIC_URL'),
        'piper_bin' => Env::requireString('PIPER_BIN'),
        'piper_voice' => Env::requireString('PIPER_VOICE'),
        'piper_model_en' => Env::requireString('PIPER_MODEL_EN'),
        'piper_model_fil' => Env::requireString('PIPER_MODEL_FIL'),
        'piper_config_en' => Env::requireString('PIPER_CONFIG_EN'),
        'piper_config_fil' => Env::requireString('PIPER_CONFIG_FIL'),
        'piper_cache_dir' => Env::requirePath('PIPER_CACHE_DIR'),
        'piper_data_dir' => Env::requirePath('PIPER_DATA_DIR'),
        'piper_download_timeout_seconds' => Env::requireInt('PIPER_DOWNLOAD_TIMEOUT_SECONDS'),
        'piper_use_cuda' => Env::requireBoolString('PIPER_USE_CUDA'),
    ],

    'smtp' => [
        'host' => Env::requireString('SMTP_HOST'),
        'port' => Env::requireInt('SMTP_PORT'),
        'user' => Env::requireString('SMTP_USER'),
        'pass' => Env::requireString('SMTP_PASS'),
        'from' => Env::requireString('SMTP_FROM'),
    ],
];
