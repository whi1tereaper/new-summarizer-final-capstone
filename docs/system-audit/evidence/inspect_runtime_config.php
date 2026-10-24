<?php
// Only public operational settings are emitted. No application bootstrap.
require_once dirname(__DIR__, 3) . '/app/src/Support/Env.php';
use App\Src\Support\Env;
Env::load();
$keys=['APP_NAME','APP_ENV','APP_DEBUG','SUMMARIZER_ENGINE_VERSION','SUMMARIZER_LLM_ENABLED','SUMMARIZER_LLM_PROVIDER','SUMMARIZER_LLM_MODEL','TRANSLATION_PROVIDER','TRANSLATION_TARGET_LANG','LOCAL_PYTHON_TIMEOUT','TTS_MAX_CHARS','TTS_MAX_TEXT_LENGTH','TTS_REQUEST_TIMEOUT_SECONDS'];
$out=['php_version'=>PHP_VERSION,'settings'=>[],'extensions'=>get_loaded_extensions()];
foreach($keys as $key)$out['settings'][$key]=Env::get($key,'not set');
file_put_contents(__DIR__.'/runtime-config-safe.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
