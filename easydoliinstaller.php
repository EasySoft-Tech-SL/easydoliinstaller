<?php
/**
 * ============================================================================
 *  EasyDoliInstaller  -  Instalador "todo en uno" para Dolibarr (estilo Duplicator)
 *  (c) Easysoft Tech S.L.  -  https://github.com/easySoft-Tech-SL
 * ============================================================================
 *
 *  Un único archivo que:
 *    1. Comprueba requisitos del servidor.
 *    2. Pide la configuración (base de datos + administrador) en un formulario.
 *    3. Descomprime el ZIP de Dolibarr (solo el contenido de htdocs) en la raíz.
 *    4. Escribe install.forced.php + conf.php y ejecuta el instalador NATIVO de
 *       Dolibarr de forma desatendida (step1 -> step2 -> step5).
 *    5. Verifica el resultado, bloquea la instalación y se autodestruye.
 *
 *  Tiene además un MODO ULTRASENCILLO: solo descomprime htdocs y te redirige
 *  al asistente nativo install/ de Dolibarr.
 *
 *  USO
 *  ---
 *    - Sube a tu hosting (en la carpeta que será la raíz de Dolibarr) SOLO:
 *          easydoliinstaller.php   (este archivo)
 *      Opcionalmente, junto a un dolibarr-XX.Y.Z.zip si prefieres no descargar:
 *      el asistente puede DESCARGAR la versión que elijas automáticamente.
 *    - Abre en el navegador:  https://tu-dominio/easydoliinstaller.php
 *    - Sigue el asistente. Al terminar, el instalador se borra solo.
 *
 *  SEGURIDAD: es una herramienta de UN SOLO USO. No la dejes en un servidor
 *  público: al acabar se autodestruye, pero si interrumpes el proceso, BÓRRALA
 *  manualmente junto al ZIP y la carpeta temporal __doli_installer_tmp__.
 *
 *  El ZIP debe ser el paquete oficial de Dolibarr (contiene "<dir>/htdocs/...").
 *
 *  Licencia: GPL-3.0-or-later (igual que Dolibarr).
 * ============================================================================
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

define('DI_VERSION', '1.3.0');
define('DI_DIR', __DIR__);
define('DI_SELF', basename(__FILE__));
define('DI_TMPDIR', DI_DIR . '/__doli_installer_tmp__');
define('DI_CONFIG', DI_TMPDIR . '/config.php');     // .php con guardia: no servible como datos
define('DI_CONFIG_MARK', '###EDI-JSON###');         // separador cabecera-PHP / JSON
define('DI_CONFIG_TTL', 21600);                     // caduca la config a las 6h (instalador olvidado)
define('DI_COOKIES', DI_TMPDIR . '/cookies.txt');
define('DI_LOG', DI_TMPDIR . '/install.log');

define('DI_PHP_MIN', '7.1.0');                 // mínimo que exige Dolibarr 23
define('DI_EXTRACT_CHUNK', 2500);              // entradas del ZIP por petición AJAX (extracción nativa)

/* ===========================================================================
 *  HELPERS GENERALES
 * ======================================================================== */

function di_log($msg)
{
    @file_put_contents(DI_LOG, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/** Crea (si hace falta) el directorio temporal y lo protege del exterior. */
function di_ensure_tmp()
{
    if (!is_dir(DI_TMPDIR)) {
        @mkdir(DI_TMPDIR, 0700, true);
    }
    if (is_dir(DI_TMPDIR)) {
        if (!file_exists(DI_TMPDIR . '/.htaccess')) {
            @file_put_contents(DI_TMPDIR . '/.htaccess', di_deny_htaccess());
        }
        if (!file_exists(DI_TMPDIR . '/web.config')) {
            @file_put_contents(DI_TMPDIR . '/web.config', di_deny_webconfig());
        }
        if (!file_exists(DI_TMPDIR . '/index.html')) {
            @file_put_contents(DI_TMPDIR . '/index.html', '');
        }
    }
}

/** .htaccess de denegación tolerante a Apache 2.2 y 2.4 (envuelto en IfModule). */
function di_deny_htaccess()
{
    return "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
}

/** web.config (IIS) de denegación. */
function di_deny_webconfig()
{
    return "<?xml version=\"1.0\"?>\n<configuration><system.webServer><security><authorization>"
        . "<deny users=\"*\"/></authorization></security></system.webServer></configuration>\n";
}

/** Cabeceras de seguridad (anti-clickjacking, no-store, nosniff). */
function di_security_headers()
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; "
        . "script-src 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; "
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}

/**
 * Devuelve TODOS los ZIP candidatos junto al instalador.
 * Prioriza los que empiezan por "dolibarr-"; si no hay ninguno, usa cualquier .zip.
 * Ordena por versión descendente (el más nuevo primero).
 */
function di_find_zips()
{
    $list = glob(DI_DIR . '/dolibarr-*.zip');
    if (!is_array($list)) {
        $list = array();
    }
    if (empty($list)) {
        $all = glob(DI_DIR . '/*.zip');
        $list = is_array($all) ? $all : array();
    }
    // Orden "natural" inverso: dolibarr-23.0.10 por encima de dolibarr-23.0.3
    usort($list, function ($a, $b) {
        return strnatcasecmp(basename($b), basename($a));
    });
    return array_values(array_filter($list, 'is_file'));
}

/** Devuelve el primer ZIP candidato (o null). */
function di_find_zip()
{
    $list = di_find_zips();
    return empty($list) ? null : $list[0];
}

/**
 * Valida que el nombre de ZIP recibido del formulario corresponde a un archivo
 * real junto al instalador (evita path traversal). Devuelve la ruta absoluta o null.
 */
function di_resolve_zip($name)
{
    if (!$name) {
        return null;
    }
    $name = basename(str_replace('\\', '/', $name)); // solo el nombre, nunca rutas
    foreach (di_find_zips() as $z) {
        if (basename($z) === $name) {
            return $z;
        }
    }
    return null;
}

/**
 * Detecta el prefijo del contenido de Dolibarr dentro del ZIP. Cascada:
 *   1) "<topdir>/htdocs/"  (paquete oficial)         -> devuelve ese prefijo
 *   2) "htdocs/" en la raíz del ZIP                  -> devuelve "htdocs/"
 *   3) el ZIP YA es el contenido de htdocs (>=2 marcadores Dolibarr en la raíz) -> devuelve ""
 *   4) no reconocible                                -> devuelve null
 * Nota: '' (cadena vacía) es un prefijo VÁLIDO; distíngalo de null con === null.
 */
function di_detect_prefix($zipPath)
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return null;
    }
    $n = $zip->numFiles;
    $hasHtdocsRoot = false;
    $rootMarkers = 0;
    $markerSet = array('conf/' => 1, 'install/' => 1, 'core/' => 1, 'public/' => 1,
        'main.inc.php' => 1, 'master.inc.php' => 1, 'index.php' => 1);
    $seenRoot = array();

    for ($i = 0; $i < $n; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        if (preg_match('#^([^/]+/htdocs/)#', $name, $m)) {
            $zip->close();
            return $m[1]; // caso 1 (el más común)
        }
        if (strncmp($name, 'htdocs/', 7) === 0) {
            $hasHtdocsRoot = true;
        }
        // marcadores en la raíz del ZIP (sin barra previa)
        if (strpos($name, '/') === false || preg_match('#^(conf|install|core|public)/#', $name)) {
            $top = preg_match('#^([^/]+/)#', $name, $mm) ? $mm[1] : $name;
            if (isset($markerSet[$top]) && empty($seenRoot[$top])) {
                $seenRoot[$top] = true;
                $rootMarkers++;
            }
        }
    }
    $zip->close();

    if ($hasHtdocsRoot) {
        return 'htdocs/'; // caso 2
    }
    if ($rootMarkers >= 2) {
        return ''; // caso 3: el ZIP ya es htdocs
    }
    return null; // caso 4
}

/* ===========================================================================
 *  DESCARGA AUTOMÁTICA DE DOLIBARR (SourceForge) + LISTADO DE VERSIONES (GitHub)
 * ======================================================================== */

/** GET remoto sencillo (GitHub API, etc.). Devuelve el body o null. */
function di_remote_get($url, $timeout = 8)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'EasyDoliInstaller/' . DI_VERSION,
        ));
        $b = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($b !== false && $code >= 200 && $code < 400) ? $b : null;
    }
    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $ctx = stream_context_create(array(
            'http' => array('timeout' => $timeout, 'header' => "User-Agent: EasyDoliInstaller\r\n"),
            'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
        ));
        $b = @file_get_contents($url, false, $ctx);
        return $b === false ? null : $b;
    }
    return null;
}

/** Lista de versiones estables (desc). Fuente: GitHub releases, con caché 1h y fallback. */
function di_fetch_versions()
{
    di_ensure_tmp();
    $cache = DI_TMPDIR . '/versions.json';
    if (is_file($cache) && (time() - filemtime($cache) < 3600)) {
        $v = json_decode(@file_get_contents($cache), true);
        if (is_array($v) && $v) {
            return $v;
        }
    }
    $body = di_remote_get('https://api.github.com/repos/Dolibarr/dolibarr/releases?per_page=100');
    $vers = array();
    if ($body) {
        $data = json_decode($body, true);
        if (is_array($data)) {
            foreach ($data as $rel) {
                if (!empty($rel['prerelease'])) {
                    continue;
                }
                $tag = isset($rel['tag_name']) ? $rel['tag_name'] : '';
                if (preg_match('/^\d+\.\d+\.\d+$/', $tag)) {
                    $vers[] = $tag;
                }
            }
        }
    }
    if ($vers) {
        $vers = array_values(array_unique($vers));
        usort($vers, function ($a, $b) {
            return version_compare($b, $a);
        });
        @file_put_contents($cache, json_encode($vers));
        return $vers;
    }
    return di_fallback_versions();
}

/** Lista de respaldo si no hay conectividad con GitHub. */
function di_fallback_versions()
{
    return array('23.0.3', '22.0.5', '21.0.1', '20.0.3', '19.0.4', '18.0.10');
}

/** Versión saneada -> x.y.z o null. */
function di_sanitize_version($v)
{
    $v = preg_replace('#[^0-9.]#', '', (string) $v);
    return preg_match('/^\d+\.\d+\.\d+$/', $v) ? $v : null;
}

/** URL de descarga del paquete oficial en SourceForge. */
function di_download_url($ver)
{
    return 'https://downloads.sourceforge.net/project/dolibarr/Dolibarr%20ERP-CRM/'
        . rawurlencode($ver) . '/dolibarr-' . rawurlencode($ver) . '.zip';
}

/** Ruta local destino del ZIP descargado. */
function di_download_target($cfg)
{
    return DI_DIR . '/dolibarr-' . $cfg['download_version'] . '.zip';
}

/**
 * Descarga un bloque del ZIP por HTTP Range y lo añade al archivo. Devuelve
 * [next,total,done,received] o ['error'=>...]. Requiere cURL.
 */
function di_download_chunk($cfg, $offset)
{
    if (!function_exists('curl_init')) {
        return array('error' => 'La descarga automática requiere la extensión cURL.');
    }
    $url = di_download_url($cfg['download_version']);
    $file = di_download_target($cfg);
    $chunkSize = 4 * 1024 * 1024; // 4 MB por petición
    $end = $offset + $chunkSize - 1;

    $fp = @fopen($file, $offset === 0 ? 'wb' : 'ab');
    if (!$fp) {
        return array('error' => 'No se puede escribir el archivo de descarga: ' . $file);
    }

    $total = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8,
        CURLOPT_RANGE => $offset . '-' . $end,
        CURLOPT_FILE => $fp,
        CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 300,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'EasyDoliInstaller/' . DI_VERSION,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$total) {
            if (stripos($h, 'Content-Range:') === 0 && preg_match('#/(\d+)#', $h, $m)) {
                $total = (int) $m[1];
            }
            return strlen($h);
        },
    ));
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    $dl = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    fclose($fp);

    if ($err) {
        return array('error' => 'Descarga fallida: ' . $err);
    }
    if ($code === 200) {
        // El mirror ignoró el Range y envió el archivo completo en esta petición.
        clearstatcache(true, $file);
        $sz = filesize($file);
        return array('next' => $sz, 'total' => $sz, 'done' => true, 'received' => $dl);
    }
    if ($code !== 206) {
        return array('error' => 'Respuesta inesperada del servidor de descargas (HTTP ' . $code . ').');
    }
    if ($total <= 0) {
        $total = $offset + $dl;
    }
    $next = $offset + $dl;
    $done = ($next >= $total) || ($dl <= 0);
    return array('next' => $next, 'total' => $total, 'done' => $done, 'received' => $dl);
}

/** Esquema + host + subdirectorio donde vive el instalador (sin barra final). */
function di_self_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '/';
    $dir = rtrim($dir, '/');
    return $scheme . '://' . $host . $dir;
}

/**
 * Valida un baseurl introducido a mano (anti-SSRF): solo http/https y host = el
 * propio servidor (o localhost/127.0.0.1). Si no, devuelve $fallback.
 */
function di_validate_baseurl($posted, $fallback)
{
    $u = rtrim(trim((string) $posted), '/');
    $p = parse_url($u);
    $selfHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $okScheme = isset($p['scheme']) && in_array(strtolower($p['scheme']), array('http', 'https'), true);
    $host = isset($p['host']) ? strtolower($p['host']) : '';
    if (isset($p['port'])) {
        $host .= ':' . $p['port'];
    }
    $okHost = $host !== '' && ($host === $selfHost
        || in_array($host, array('localhost', '127.0.0.1', '[::1]'), true)
        || strtok($host, ':') === strtok($selfHost, ':'));
    return ($okScheme && $okHost) ? $u : $fallback;
}

function di_load_config()
{
    if (!is_file(DI_CONFIG)) {
        return null;
    }
    $raw = @file_get_contents(DI_CONFIG);
    if ($raw === false) {
        return null;
    }
    $p = strpos($raw, DI_CONFIG_MARK);
    if ($p !== false) {
        $raw = substr($raw, $p + strlen(DI_CONFIG_MARK));
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    // Caducidad: una config olvidada (instalador abandonado) deja de ser válida.
    if (!empty($data['ts']) && (time() - (int) $data['ts']) > DI_CONFIG_TTL) {
        return null;
    }
    return $data;
}

function di_save_config($cfg)
{
    di_ensure_tmp();
    if (!isset($cfg['ts'])) {
        $cfg['ts'] = time();
    }
    // Guardamos como .php con guardia: si el servidor ignora el .htaccess (Nginx,
    // LiteSpeed, AllowOverride None) y ejecuta el .php, devuelve 403; si lo sirviera
    // como texto, el JSON queda tras un die() y no es trivialmente accesible.
    $body = "<?php http_response_code(403); die('Forbidden: EasyDoliInstaller'); ?>\n"
        . DI_CONFIG_MARK . json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    @file_put_contents(DI_CONFIG, $body);
    @chmod(DI_CONFIG, 0600);
}

function di_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Borrado recursivo de un directorio. */
function di_rrmdir($dir)
{
    if (!is_dir($dir)) {
        @unlink($dir);
        return;
    }
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $p = $dir . '/' . $it;
        if (is_dir($p) && !is_link($p)) {
            di_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/* ===========================================================================
 *  ESTADO DE LA INSTALACIÓN
 * ======================================================================== */

/** ¿Ya hay un Dolibarr instalado en la raíz de destino? */
function di_already_installed($cfg = null)
{
    $target = $cfg && !empty($cfg['target']) ? $cfg['target'] : DI_DIR;
    $conf = $target . '/conf/conf.php';
    if (is_file($conf)) {
        $c = @file_get_contents($conf);
        if ($c && strpos($c, 'dolibarr_main_db_name') !== false && preg_match('/dolibarr_main_db_name\s*=\s*[\'"]\S/', $c)) {
            return true;
        }
    }
    return false;
}

/* ===========================================================================
 *  CLIENTE HTTP (para ejecutar el instalador nativo de Dolibarr)
 * ======================================================================== */

function di_http($url, $post = null, $timeout = 0)
{
    $parts = parse_url($url);
    $host = isset($parts['host']) ? $parts['host'] : 'localhost';

    // Intento directo y, si el host público no resuelve desde el propio
    // servidor, reintento contra 127.0.0.1 enviando la cabecera Host original.
    $attempts = array(array('url' => $url, 'hostheader' => null));
    $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
    if (!$isIp && strtolower($host) !== 'localhost' && $host !== '127.0.0.1') {
        $fb = $parts;
        $portsuffix = isset($parts['port']) ? ':' . $parts['port'] : '';
        $fb['host'] = '127.0.0.1';
        $fbUrl = $fb['scheme'] . '://127.0.0.1' . $portsuffix
            . (isset($fb['path']) ? $fb['path'] : '')
            . (isset($fb['query']) ? '?' . $fb['query'] : '');
        $attempts[] = array('url' => $fbUrl, 'hostheader' => $host . $portsuffix);
    }

    $last = array('code' => 0, 'body' => '', 'error' => 'sin intentos');
    foreach ($attempts as $a) {
        $res = di_http_once($a['url'], $post, $timeout, $a['hostheader']);
        $last = $res;
        if ($res['code'] > 0 && $res['error'] === '') {
            return $res;
        }
    }
    return $last;
}

function di_http_once($url, $post, $timeout, $hostheader)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_COOKIEJAR => DI_COOKIES,
            CURLOPT_COOKIEFILE => DI_COOKIES,
            CURLOPT_USERAGENT => 'DoliInstaller/' . DI_VERSION,
        );
        $headers = array();
        if ($hostheader !== null) {
            $headers[] = 'Host: ' . $hostheader;
        }
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = is_array($post) ? http_build_query($post) : $post;
        }
        if ($headers) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);
        return array('code' => $code, 'body' => (string) $body, 'error' => $err);
    }

    // ---- Fallback con stream context (sin curl) ----
    $cookieHeader = '';
    if (is_file(DI_COOKIES)) {
        $cookieHeader = trim(@file_get_contents(DI_COOKIES));
    }
    $headers = "User-Agent: DoliInstaller/" . DI_VERSION . "\r\n";
    if ($hostheader !== null) {
        $headers .= "Host: " . $hostheader . "\r\n";
    }
    if ($cookieHeader !== '') {
        $headers .= "Cookie: " . $cookieHeader . "\r\n";
    }
    $ctxopts = array(
        'http' => array(
            'method' => $post !== null ? 'POST' : 'GET',
            'header' => $headers,
            'timeout' => $timeout > 0 ? $timeout : 600,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ),
        'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
    );
    if ($post !== null) {
        $body = is_array($post) ? http_build_query($post) : $post;
        $ctxopts['http']['header'] = "Content-Type: application/x-www-form-urlencoded\r\n" . $headers
            . "Content-Length: " . strlen($body) . "\r\n";
        $ctxopts['http']['content'] = $body;
    }
    $ctx = stream_context_create($ctxopts);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
            if (stripos($h, 'Set-Cookie:') === 0) {
                $cv = trim(substr($h, strlen('Set-Cookie:')));
                $cv = explode(';', $cv, 2)[0];
                @file_put_contents(DI_COOKIES, $cv);
            }
        }
    }
    return array('code' => $code, 'body' => $resp === false ? '' : $resp, 'error' => $resp === false ? 'fallo de conexión' : '');
}

/* ===========================================================================
 *  BASE DE DATOS (verificación independiente del resultado)
 * ======================================================================== */

/** Devuelve [ok, error, rows] tras ejecutar un SELECT. Soporta MySQL y PostgreSQL. */
function di_db_query($cfg, $sql)
{
    $db = $cfg['db'];
    $type = isset($db['type']) ? $db['type'] : 'mysqli';
    $host = $db['host'];
    $port = (int) $db['port'];

    // ---------- PostgreSQL ----------
    if ($type === 'pgsql') {
        if (!$port) {
            $port = 5432;
        }
        if (function_exists('pg_connect')) {
            $q = function ($v) {
                return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $v) . "'";
            };
            $cs = 'host=' . $q($host) . ' port=' . (int) $port . ' dbname=' . $q($db['name'])
                . ' user=' . $q($db['user']) . ' password=' . $q($db['pass']) . ' connect_timeout=10';
            $c = @pg_connect($cs);
            if (!$c) {
                return array(false, 'No se pudo conectar a PostgreSQL (' . $host . ':' . $port . ')', array());
            }
            $r = @pg_query($c, $sql);
            if ($r === false) {
                $e = pg_last_error($c);
                pg_close($c);
                return array(false, $e, array());
            }
            $rows = array();
            if (is_resource($r) || $r instanceof \PgSql\Result) {
                while ($row = pg_fetch_row($r)) {
                    $rows[] = $row;
                }
            }
            pg_close($c);
            return array(true, '', $rows);
        }
        if (class_exists('PDO')) {
            try {
                $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $db['name'];
                $pdo = new PDO($dsn, $db['user'], $db['pass'], array(PDO::ATTR_TIMEOUT => 10));
                $stmt = $pdo->query($sql);
                return array(true, '', $stmt->fetchAll(PDO::FETCH_NUM));
            } catch (Exception $e) {
                return array(false, $e->getMessage(), array());
            }
        }
        return array(false, 'Sin driver PostgreSQL (pgsql/PDO) para verificar', array());
    }

    // ---------- MySQL / MariaDB ----------
    if (!$port) {
        $port = 3306;
    }
    if (function_exists('mysqli_connect')) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @mysqli_connect($host, $db['user'], $db['pass'], $db['name'], $port);
        if (!$m) {
            return array(false, mysqli_connect_error(), array());
        }
        $r = @mysqli_query($m, $sql);
        if ($r === false) {
            $e = mysqli_error($m);
            mysqli_close($m);
            return array(false, $e, array());
        }
        $rows = array();
        if ($r !== true) {
            while ($row = mysqli_fetch_row($r)) {
                $rows[] = $row;
            }
        }
        mysqli_close($m);
        return array(true, '', $rows);
    }
    if (class_exists('PDO')) {
        try {
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db['name'];
            $pdo = new PDO($dsn, $db['user'], $db['pass'], array(PDO::ATTR_TIMEOUT => 10));
            $stmt = $pdo->query($sql);
            return array(true, '', $stmt->fetchAll(PDO::FETCH_NUM));
        } catch (Exception $e) {
            return array(false, $e->getMessage(), array());
        }
    }
    return array(false, 'Sin driver MySQL (mysqli/PDO) para verificar', array());
}

/** Lista de tablas del esquema (array de nombres) o null si no se puede verificar. */
function di_list_tables($cfg)
{
    $type = isset($cfg['db']['type']) ? $cfg['db']['type'] : 'mysqli';
    $sql = ($type === 'pgsql')
        ? "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
        : 'SHOW TABLES';
    list($ok, $err, $rows) = di_db_query($cfg, $sql);
    if (!$ok) {
        return null;
    }
    $names = array();
    foreach ($rows as $r) {
        $names[] = $r[0];
    }
    return $names;
}

function di_count_tables($cfg)
{
    $names = di_list_tables($cfg);
    if ($names === null) {
        return -1;
    }
    $prefix = $cfg['db']['prefix'];
    $c = 0;
    foreach ($names as $n) {
        if (strpos($n, $prefix) === 0) {
            $c++;
        }
    }
    return $c;
}

/* ===========================================================================
 *  ESCRITURA DE conf.php Y install.forced.php
 * ======================================================================== */

function di_write_install_files($cfg)
{
    $target = $cfg['target'];
    $confDir = $target . '/conf';
    $installDir = $target . '/install';

    if (!is_dir($confDir)) {
        @mkdir($confDir, 0755, true);
    }
    // conf.php vacío y escribible: el instalador nativo lo rellenará.
    $confFile = $confDir . '/conf.php';
    @file_put_contents($confFile, "<?php\n");
    @chmod($confFile, 0666);

    if (!is_dir($installDir)) {
        return array(false, 'No existe el directorio install/ tras la extracción.');
    }

    // Modo ultrasencillo: solo dejamos un conf.php vacío y escribible; del resto
    // se encarga el asistente nativo de Dolibarr (install/).
    if (($cfg['mode'] ?? 'full') === 'simple') {
        return array(true, '');
    }

    $db = $cfg['db'];
    $admin = $cfg['admin'];
    $ve = function ($v) {
        return var_export($v, true);
    };

    // Directorio de documentos robusto: por defecto Dolibarr usa el hermano de la
    // raíz (../documents). Si el padre no es escribible (o open_basedir lo impide),
    // lo ubicamos DENTRO de la raíz, protegido con .htaccess/web.config.
    $dataRoot = di_compute_dataroot($cfg);   // null = autodetección estándar de Dolibarr
    $createUser = !empty($db['create']);     // si creamos la BD, creamos también el usuario de app

    // NOTA: NO ponemos un .htaccess "deny" en install/ porque el propio instalador
    // conduce install/step*.php por HTTP (lo bloquearía). La protección del forced es
    // chmod 0600 + borrado inmediato tras step5; install/ se elimina al finalizar.

    $forced = "<?php\n"
        . "/* Generado por DoliInstaller " . DI_VERSION . " - instalación desatendida */\n"
        . "\$force_install_distrib = 'custom';\n"
        . "\$force_install_nophpinfo = true;\n"
        . "\$force_install_noedit = 2;\n"
        . "\$force_install_message = '';\n"
        . "\$force_install_main_data_root = " . ($dataRoot === null ? 'null' : $ve($dataRoot)) . ";\n"
        . "\$force_install_mainforcehttps = " . $ve(!empty($cfg['forcehttps'])) . ";\n"
        . "\$force_install_type = " . $ve(isset($db['type']) ? $db['type'] : 'mysqli') . ";\n"
        . "\$force_install_dbserver = " . $ve($db['host']) . ";\n"
        . "\$force_install_port = " . $ve((int) $db['port']) . ";\n"
        . "\$force_install_database = " . $ve($db['name']) . ";\n"
        . "\$force_install_prefix = " . $ve($db['prefix']) . ";\n"
        . "\$force_install_databaselogin = " . $ve($db['user']) . ";\n"
        . "\$force_install_databasepass = " . $ve($db['pass']) . ";\n"
        . "\$force_install_createdatabase = " . $ve(!empty($db['create'])) . ";\n"
        . "\$force_install_createuser = " . $ve($createUser) . ";\n"
        . "\$force_install_databaserootlogin = " . $ve($db['rootuser']) . ";\n"
        . "\$force_install_databaserootpass = " . $ve($db['rootpass']) . ";\n"
        . "\$force_install_dolibarrlogin = " . $ve($admin['login']) . ";\n"
        . "\$force_install_dolibarrpassword = " . $ve($admin['pass']) . ";\n"
        . "\$force_install_lockinstall = '0444';\n"
        . "\$force_install_module = '';\n";

    $ok = @file_put_contents($installDir . '/install.forced.php', $forced);
    if ($ok === false) {
        return array(false, 'No se pudo escribir install/install.forced.php');
    }
    @chmod($installDir . '/install.forced.php', 0600); // contiene secretos: solo el propietario

    return array(true, '');
}

/**
 * Decide el directorio de documentos de Dolibarr.
 *  - null  => dejar la autodetección estándar (hermano de la raíz: ../documents),
 *             válida cuando el directorio padre es escribible y accesible.
 *  - ruta  => documents DENTRO de la raíz (cuando el padre no sirve), ya creado
 *             y protegido con .htaccess/web.config para que no sea accesible por web.
 */
function di_compute_dataroot($cfg)
{
    $target = $cfg['target'];
    $parent = dirname($target);

    // ¿open_basedir deja ver el padre?
    $parentBlocked = false;
    $obd = trim((string) ini_get('open_basedir'));
    if ($obd !== '') {
        $parentBlocked = true;
        $rp = realpath($parent) ?: $parent;
        foreach (preg_split('#[:;]#', $obd) as $base) {
            $base = realpath($base) ?: $base;
            if ($base !== '' && strpos($rp, $base) === 0) {
                $parentBlocked = false;
                break;
            }
        }
    }

    if (!$parentBlocked && @is_writable($parent)) {
        return null; // hermano ../documents (comportamiento nativo, más seguro)
    }

    // Fallback: documents dentro de la raíz, protegido.
    $docs = $target . '/documents';
    @mkdir($docs, 0755, true);
    @file_put_contents($docs . '/.htaccess', di_deny_htaccess());
    @file_put_contents($docs . '/web.config', di_deny_webconfig());
    @file_put_contents($docs . '/index.html', '');
    return $docs;
}

/* ===========================================================================
 *  EXTRACCIÓN POR BLOQUES (nativa, rápida como 7zip)
 *
 *  Estrategia: ZipArchive::extractTo() en C (una pasada por bloque grande) hacia
 *  un directorio temporal, y al terminar se mueven los hijos de "htdocs/" a la
 *  raíz con rename() (instantáneo en el mismo volumen). Mucho más rápido que
 *  escribir archivo a archivo desde PHP.
 * ======================================================================== */

function di_extract_tmpdir($cfg)
{
    return $cfg['target'] . '/__doli_extract__';
}

function di_extract_chunk($cfg, $offset)
{
    $zip = new ZipArchive();
    if ($zip->open($cfg['zip']) !== true) {
        return array('error' => 'No se pudo abrir el ZIP: ' . $cfg['zip']);
    }
    $prefix = $cfg['prefix'];
    $plen = strlen($prefix);
    $num = $zip->numFiles;
    $end = min($offset + DI_EXTRACT_CHUNK, $num);

    $tmp = di_extract_tmpdir($cfg);
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0755, true);
    }

    // Nombres de las entradas de este bloque que están bajo htdocs/.
    $names = array();
    for ($i = $offset; $i < $end; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name !== false && strncmp($name, $prefix, $plen) === 0 && strlen($name) > $plen) {
            $names[] = $name;
        }
    }

    // Extracción NATIVA del bloque (en C). Cuidado: extractTo() con array vacío
    // extraería TODO el ZIP, así que solo llamamos si hay nombres.
    if (!empty($names)) {
        if (!$zip->extractTo($tmp, $names)) {
            $zip->close();
            return array('error' => 'Fallo al extraer el bloque (offset ' . $offset . '). ¿Espacio en disco o permisos?');
        }
    }
    $zip->close();

    return array(
        'next' => $end,
        'total' => $num,
        'done' => $end >= $num,
        'written' => count($names),
    );
}

/**
 * Tras extraer todo a __doli_extract__/<root>/htdocs/, mueve los hijos de htdocs
 * a la raíz de destino (rename, instantáneo) y elimina el temporal.
 */
function di_finalize_extraction($cfg)
{
    $tmp = di_extract_tmpdir($cfg);
    $prefix = isset($cfg['prefix']) ? $cfg['prefix'] : '';
    // Prefijo '' = el ZIP ya era htdocs (extraído directamente en $tmp).
    $src = ($prefix === '' || $prefix === null) ? $tmp : $tmp . '/' . rtrim($prefix, '/');
    if (!is_dir($src)) {
        return array(false, 'No se encontró el contenido extraído en ' . $src);
    }
    $target = $cfg['target'];
    if (!is_dir($target)) {
        @mkdir($target, 0755, true);
    }

    $items = scandir($src);
    if ($items === false) {
        return array(false, 'No se pudo leer el directorio temporal de extracción.');
    }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        // Cuando $src === $tmp, no movemos el propio temporal sobre sí mismo.
        if ($src === $tmp && $it === basename($tmp)) {
            continue;
        }
        $from = $src . '/' . $it;
        $to = $target . '/' . $it;
        if (!file_exists($to)) {
            if (!@rename($from, $to) && !di_copy_recursive($from, $to)) {
                return array(false, 'No se pudo mover/copiar "' . $it . '" al destino. Revisa espacio en disco y permisos.');
            }
        } else {
            // Ya existe (reintento/instalación previa): fusionar contenidos.
            di_move_merge($from, $to);
        }
    }
    di_rrmdir($tmp);

    return array(true, '');
}

/** Fusiona el contenido de $from dentro de $to (mueve lo que falte). */
function di_move_merge($from, $to)
{
    if (is_dir($from)) {
        if (!is_dir($to)) {
            @mkdir($to, 0755, true);
        }
        foreach (scandir($from) as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            di_move_merge($from . '/' . $it, $to . '/' . $it);
        }
        @rmdir($from);
    } else {
        if (!@rename($from, $to)) {
            @copy($from, $to);
            @unlink($from);
        }
    }
}

/**
 * Copia recursiva (solo si rename falla, p. ej. volúmenes distintos).
 * Devuelve bool de éxito y BORRA el origen ya copiado (copy+unlink real).
 */
function di_copy_recursive($from, $to)
{
    if (is_dir($from)) {
        if (!is_dir($to) && !@mkdir($to, 0755, true)) {
            return false;
        }
        foreach (scandir($from) as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            if (!di_copy_recursive($from . '/' . $it, $to . '/' . $it)) {
                return false;
            }
        }
        @rmdir($from);
        return true;
    }
    if (!@copy($from, $to)) {
        return false;
    }
    @unlink($from);
    return true;
}

/* ===========================================================================
 *  EJECUCIÓN DE LOS PASOS NATIVOS DE DOLIBARR
 * ======================================================================== */

function di_install_url($cfg, $script)
{
    return rtrim($cfg['baseurl'], '/') . '/install/' . $script;
}

/** Escapa una cadena como literal SQL (portable MySQL/PostgreSQL: duplica la comilla). */
function di_sql_str($s)
{
    return "'" . str_replace("'", "''", (string) $s) . "'";
}

/** Mensaje de error legible; detecta bloqueos WAF / errores 4xx-5xx del servidor. */
function di_blocked_hint($cfg, $res)
{
    $code = (int) $res['code'];
    if ($code === 401 || $code === 403 || $code === 406 || $code === 501 || $code >= 500) {
        return 'El servidor respondió HTTP ' . $code . ' al instalador nativo (posible bloqueo de '
            . 'mod_security/WAF o error del servidor). Añade una excepción para /install/ o termina '
            . 'manualmente en ' . $cfg['baseurl'] . '/install/.';
    }
    return di_extract_error($res['body']);
}

/** ¿Existen las tablas núcleo de Dolibarr? true/false, o null si no se puede verificar. */
function di_core_tables_ok($cfg)
{
    $names = di_list_tables($cfg);
    if ($names === null) {
        return null;
    }
    $set = array_flip($names);
    $p = $cfg['db']['prefix'];
    foreach (array('const', 'user', 'menu', 'rights_def', 'societe') as $t) {
        if (!isset($set[$p . $t])) {
            return false;
        }
    }
    return true;
}

/** Corrige dolibarr_main_url_root en conf.php si Dolibarr lo grabó con 127.0.0.1/localhost. */
function di_fix_main_url($cfg, $conf, $c)
{
    $want = rtrim($cfg['baseurl'], '/');
    if ($want === '' || !filter_var($want, FILTER_VALIDATE_URL)) {
        return;
    }
    if (!preg_match('/(\$dolibarr_main_url_root\s*=\s*)([\'"])(.*?)\2(\s*;)/', $c, $mm)) {
        return;
    }
    $got = $mm[3];
    $gotHost = strtolower((string) parse_url($got, PHP_URL_HOST));
    $wantHost = strtolower((string) parse_url($want, PHP_URL_HOST));
    $loop = in_array($gotHost, array('127.0.0.1', 'localhost', '::1'), true);
    $pub = !in_array($wantHost, array('127.0.0.1', 'localhost', '::1'), true);
    if (rtrim($got, '/') !== $want && (($loop && $pub) || $got === '')) {
        $fixed = preg_replace(
            '/(\$dolibarr_main_url_root\s*=\s*)([\'"]).*?\2(\s*;)/',
            '${1}\'' . addcslashes($want, "'\\") . '\'${3}',
            $c,
            1
        );
        if ($fixed !== null && $fixed !== $c) {
            @file_put_contents($conf, $fixed);
            di_log('step1 main_url corregido: ' . $got . ' -> ' . $want);
        }
    }
}

function di_run_substep($cfg, $sub)
{
    $lang = !empty($cfg['lang']) ? $cfg['lang'] : 'auto';

    if ($sub === 'step1') {
        $url = di_install_url($cfg, 'step1.php');
        $res = di_http($url, array('action' => 'set', 'selectlang' => $lang), 600);
        di_log("step1 http=" . $res['code'] . " err=" . $res['error']);
        if ($res['code'] === 0) {
            return array('ok' => false, 'msg' => 'No se pudo contactar con ' . $url . ' (' . $res['error'] . '). '
                . 'Si tu servidor atiende una sola petición a la vez (php -S, 1 worker), termina en ' . $cfg['baseurl'] . '/install/.');
        }
        // Verificación: conf.php ahora contiene los datos de conexión.
        $conf = $cfg['target'] . '/conf/conf.php';
        $c = @file_get_contents($conf);
        if ($c && preg_match('/dolibarr_main_db_name\s*=\s*[\'"]' . preg_quote($cfg['db']['name'], '/') . '/', $c)) {
            di_fix_main_url($cfg, $conf, $c);
            return array('ok' => true, 'msg' => 'Configuración creada y conexión a la base de datos establecida.');
        }
        return array('ok' => false, 'msg' => 'step1 no escribió conf.php correctamente. ' . di_blocked_hint($cfg, $res));
    }

    if ($sub === 'step2') {
        $url = di_install_url($cfg, 'step2.php');
        $res = di_http($url, array('action' => 'set', 'selectlang' => $lang), 600);
        di_log("step2 http=" . $res['code'] . " err=" . $res['error']);
        if ($res['code'] === 0) {
            return array('ok' => false, 'msg' => 'No se pudo contactar con ' . $url . ' (' . $res['error'] . ').');
        }
        if ((int) $res['code'] >= 400) {
            return array('ok' => false, 'msg' => 'step2 falló. ' . di_blocked_hint($cfg, $res));
        }
        $core = di_core_tables_ok($cfg);
        if ($core === true) {
            $n = di_count_tables($cfg);
            return array('ok' => true, 'msg' => $n . ' tablas creadas y datos de referencia cargados.');
        }
        if ($core === null) {
            // Sin driver para verificar: confiamos en la señal del HTML.
            if (stripos($res['body'], 'step4') !== false || stripos($res['body'], 'CreateDatabaseObjects') !== false) {
                return array('ok' => true, 'msg' => 'Tablas creadas (no verificable por driver).');
            }
        }
        return array('ok' => false, 'msg' => 'No se crearon todas las tablas básicas de Dolibarr. ' . di_blocked_hint($cfg, $res));
    }

    if ($sub === 'step5') {
        $t0 = time();
        $url = di_install_url($cfg, 'step5.php');
        $login = $cfg['admin']['login'];
        $res = di_http($url, array(
            'action' => 'set',
            'selectlang' => $lang,
            'login' => $login,
            'pass' => $cfg['admin']['pass'],
            'pass_verif' => $cfg['admin']['pass'],
            'installlock' => '0444',
        ), 600);
        di_log("step5 http=" . $res['code'] . " err=" . $res['error']);
        if ($res['code'] === 0) {
            return array('ok' => false, 'msg' => 'No se pudo contactar con ' . $url . ' (' . $res['error'] . ').');
        }
        // Verificación 1: existe EL administrador solicitado (no cualquier fila previa).
        list($ok, $err, $rows) = di_db_query(
            $cfg,
            'SELECT COUNT(*) FROM ' . $cfg['db']['prefix'] . 'user WHERE login = ' . di_sql_str($login)
        );
        $adminOk = ($ok && isset($rows[0][0]) && (int) $rows[0][0] >= 1);
        // Verificación 2: install.lock recién creado (no heredado).
        $lock = di_find_lock($cfg);
        $lockFresh = ($lock && @filemtime($lock) >= $t0 - 5);

        if ($adminOk || $lockFresh || (!$ok && $lock)) {
            // Éxito: borramos YA el archivo con secretos (contraseña root de la BD).
            @unlink($cfg['target'] . '/install/install.forced.php');
            $warn = $lock ? '' : ' (AVISO: no se encontró install.lock; revisa y elimina /install/ a mano)';
            return array('ok' => true, 'msg' => 'Administrador "' . $login . '" creado e instalación bloqueada.' . $warn);
        }
        return array('ok' => false, 'msg' => 'No se pudo confirmar la creación del administrador "' . $login . '". ' . di_blocked_hint($cfg, $res));
    }

    return array('ok' => false, 'msg' => 'Subpaso desconocido: ' . $sub);
}

/** Busca install.lock en las ubicaciones probables del directorio de documentos. */
function di_find_lock($cfg)
{
    $candidates = array();
    // 1) Valor real escrito en conf.php.
    $conf = $cfg['target'] . '/conf/conf.php';
    $c = @file_get_contents($conf);
    if ($c && preg_match('/dolibarr_main_data_root\s*=\s*[\'"]([^\'"]+)[\'"]/', $c, $m)) {
        $candidates[] = $m[1] . '/install.lock';
    }
    // 2) Detección estándar: hermano de la raíz.
    $candidates[] = preg_replace('#/[^/]+$#', '/documents', $cfg['target']) . '/install.lock';
    // 3) Dentro de la raíz.
    $candidates[] = $cfg['target'] . '/documents/install.lock';
    foreach ($candidates as $p) {
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

/** Extrae un mensaje de error legible del HTML devuelto por un paso. */
function di_extract_error($html)
{
    if (!$html) {
        return 'Respuesta vacía del servidor.';
    }
    if (preg_match_all('#<div class="error"[^>]*>(.*?)</div>#is', $html, $m)) {
        $msgs = array();
        foreach ($m[1] as $x) {
            $t = trim(strip_tags($x));
            if ($t !== '') {
                $msgs[] = $t;
            }
        }
        if ($msgs) {
            return 'Dolibarr informó: ' . implode(' | ', array_slice(array_unique($msgs), 0, 3));
        }
    }
    if (preg_match('#(Fatal error|Parse error|Uncaught)[^<\n]{0,200}#i', $html, $m)) {
        return 'PHP: ' . trim($m[0]);
    }
    return 'Revisa ' . di_h('') . 'el log o ejecuta /install/ manualmente.';
}

/* ===========================================================================
 *  ROUTER AJAX (devuelve JSON)
 * ======================================================================== */

if (isset($_GET['ajax'])) {
    di_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    $ajax = $_GET['ajax'];
    $cfg = di_load_config();

    if ($ajax === 'versiones') {
        echo json_encode(array('versions' => di_fetch_versions()));
        exit;
    }

    if ($ajax === 'descargar') {
        if (!$cfg || empty($cfg['download_version'])) {
            echo json_encode(array('error' => 'No hay versión seleccionada para descargar.'));
            exit;
        }
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        $r = di_download_chunk($cfg, $offset);
        if (isset($r['error'])) {
            echo json_encode($r);
            exit;
        }
        if ($r['done']) {
            // El ZIP ya está local: validamos y fijamos zip + prefix en la config.
            $file = di_download_target($cfg);
            $prefix = di_detect_prefix($file);
            if ($prefix === null) {
                @unlink($file);
                echo json_encode(array('error' => 'El ZIP descargado no es un paquete Dolibarr válido (descarga corrupta). Reinténtalo.'));
                exit;
            }
            $cfg['zip'] = $file;
            $cfg['prefix'] = $prefix;
            di_save_config($cfg);
        }
        echo json_encode($r);
        exit;
    }

    if ($ajax === 'extraer') {
        if (!$cfg) {
            echo json_encode(array('error' => 'No hay configuración guardada.'));
            exit;
        }
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        $r = di_extract_chunk($cfg, $offset);
        if (isset($r['error'])) {
            echo json_encode($r);
            exit;
        }
        if ($r['done']) {
            list($fok, $ferr) = di_finalize_extraction($cfg);
            if (!$fok) {
                echo json_encode(array('error' => $ferr));
                exit;
            }
            list($wok, $werr) = di_write_install_files($cfg);
            if (!$wok) {
                echo json_encode(array('error' => $werr));
                exit;
            }
        }
        echo json_encode($r);
        exit;
    }

    if ($ajax === 'instalar') {
        if (!$cfg) {
            echo json_encode(array('ok' => false, 'msg' => 'No hay configuración guardada.'));
            exit;
        }
        $sub = isset($_GET['sub']) ? $_GET['sub'] : '';
        $r = di_run_substep($cfg, $sub);
        if (!empty($r['ok'])) {
            // Persistimos el progreso para poder reanudar tras un F5.
            $cfg['progress'] = $sub;
            di_save_config($cfg);
        }
        echo json_encode($r);
        exit;
    }

    if ($ajax === 'limpiar') {
        $cfg = di_load_config();
        $target = $cfg && !empty($cfg['target']) ? $cfg['target'] : DI_DIR;
        $base = $cfg && !empty($cfg['baseurl']) ? rtrim($cfg['baseurl'], '/') : rtrim(di_self_base_url(), '/');
        $mode = $cfg['mode'] ?? 'full';

        if ($mode === 'simple') {
            // Conservamos install/ y conf.php: el usuario terminará con el asistente nativo.
            $appurl = $base . '/install/index.php';
        } else {
            // Modo automático: install/ ya no hace falta y contiene secretos (forced).
            @di_rrmdir($target . '/install');
            $appurl = $base . '/';
        }
        // En ambos casos borramos el ZIP, los temporales y el propio instalador.
        if ($cfg && !empty($cfg['zip']) && is_file($cfg['zip'])) {
            @unlink($cfg['zip']);
        }
        @di_rrmdir(DI_TMPDIR);
        @unlink(__FILE__);
        echo json_encode(array('ok' => true, 'appurl' => $appurl, 'selfdeleted' => !file_exists(__FILE__)));
        exit;
    }

    echo json_encode(array('error' => 'acción AJAX desconocida'));
    exit;
}

/* ===========================================================================
 *  PROCESADO DEL FORMULARIO DE CONFIGURACIÓN
 * ======================================================================== */

$formError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    // Origen del paquete: 'local' (ZIP existente) o 'download' (descargar versión).
    $allZips = di_find_zips();
    $pkgsource = (($_POST['pkgsource'] ?? '') === 'download') ? 'download' : 'local';
    $zip = null;
    $prefix = null;
    $downloadVer = null;
    if ($pkgsource === 'download') {
        $downloadVer = di_sanitize_version($_POST['download_version_manual'] ?? '');
        if (!$downloadVer) {
            $downloadVer = di_sanitize_version($_POST['download_version'] ?? '');
        }
    } else {
        $zip = di_resolve_zip($_POST['zipfile'] ?? '');
        if (!$zip && count($allZips) === 1) {
            $zip = $allZips[0];
        }
        $prefix = $zip ? di_detect_prefix($zip) : null;
    }

    $subpath = trim($_POST['subpath'] ?? '');
    $subpath = trim(str_replace('\\', '/', $subpath), '/');
    $subpath = preg_replace('#[^A-Za-z0-9_\-/]#', '', $subpath);

    $target = DI_DIR . ($subpath !== '' ? '/' . $subpath : '');
    $baseurl = di_self_base_url() . ($subpath !== '' ? '/' . $subpath : '');
    if (!empty($_POST['baseurl'])) {
        // Anti-SSRF: solo se acepta si apunta al propio host (o localhost).
        $baseurl = di_validate_baseurl($_POST['baseurl'], $baseurl);
    }

    $dbType = in_array($_POST['db_type'] ?? 'mysqli', array('mysqli', 'pgsql'), true) ? $_POST['db_type'] : 'mysqli';
    $dbPort = (int) ($_POST['db_port'] ?? 0);
    if ($dbPort <= 0) {
        $dbPort = ($dbType === 'pgsql') ? 5432 : 3306;
    }

    $cfg = array(
        'mode' => 'full',
        'zip' => $zip,
        'prefix' => $prefix,
        'download_version' => $downloadVer,
        'subpath' => $subpath,
        'target' => $target,
        'baseurl' => $baseurl,
        'lang' => preg_replace('#[^a-zA-Z_]#', '', $_POST['lang'] ?? 'es_ES'),
        'forcehttps' => !empty($_POST['forcehttps']),
        'db' => array(
            'type' => $dbType,
            'host' => trim($_POST['db_host'] ?? 'localhost'),
            'port' => $dbPort,
            'name' => trim($_POST['db_name'] ?? ''),
            'prefix' => trim($_POST['db_prefix'] ?? 'llx_'),
            'user' => trim($_POST['db_user'] ?? ''),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
            'create' => !empty($_POST['db_create']),
            'rootuser' => trim($_POST['db_rootuser'] ?? ''),
            'rootpass' => (string) ($_POST['db_rootpass'] ?? ''),
        ),
        'admin' => array(
            'login' => trim($_POST['admin_login'] ?? 'admin'),
            'pass' => (string) ($_POST['admin_pass'] ?? ''),
        ),
    );

    // Validaciones
    $errs = array();
    if ($pkgsource === 'download') {
        if (!$downloadVer) {
            $errs[] = 'Selecciona o escribe una versión válida (formato x.y.z) para descargar.';
        }
    } elseif (!$zip) {
        if (empty($allZips)) {
            $errs[] = 'No hay ningún ZIP local. Sube un dolibarr-*.zip o elige "Descargar versión".';
        } else {
            $errs[] = 'Selecciona cuál de los ' . count($allZips) . ' paquetes ZIP quieres instalar.';
        }
    } elseif (!$prefix) {
        $errs[] = 'El ZIP "' . basename($zip) . '" no parece un paquete oficial de Dolibarr (no contiene "*/htdocs/").';
    }
    if ($cfg['db']['name'] === '') {
        $errs[] = 'El nombre de la base de datos es obligatorio.';
    }
    if ($cfg['db']['user'] === '') {
        $errs[] = 'El usuario de la base de datos es obligatorio.';
    }
    if (!preg_match('/^[a-z0-9]+_$/i', $cfg['db']['prefix'])) {
        $errs[] = 'El prefijo de tablas debe ser alfanumérico y terminar en "_" (p. ej. llx_).';
    }
    if ($cfg['db']['create'] && $cfg['db']['rootuser'] === '') {
        $errs[] = 'Para crear la base de datos necesitas el usuario root/admin de MySQL.';
    }
    if ($cfg['db']['create'] && $cfg['db']['pass'] === '') {
        $errs[] = 'Para crear la base de datos y su usuario, la contraseña del usuario de BD no puede estar vacía.';
    }
    if ($cfg['admin']['login'] === '') {
        $errs[] = 'El login del administrador es obligatorio.';
    }
    if (strlen($cfg['admin']['pass']) < 8) {
        $errs[] = 'La contraseña del administrador debe tener al menos 8 caracteres.';
    }
    // El instalador nativo de Dolibarr lee 'pass' con GETPOST('pass','alpha'), que
    // ELIMINA  "  < > \  ../  y entidades HTML. Si la contraseña los contiene, Dolibarr
    // crearía el admin con OTRA contraseña y no podrías entrar. La rechazamos aquí.
    if (preg_match('#["\\\\<>]|\.\./#', $cfg['admin']['pass'])
        || stripos($cfg['admin']['pass'], '&#') !== false
        || stripos($cfg['admin']['pass'], '&quot') !== false) {
        $errs[] = 'La contraseña del administrador no puede contener comillas dobles ("), los caracteres < > \\, la secuencia ../ ni entidades HTML (&#..., &quot): el instalador de Dolibarr los elimina y te dejaría fuera.';
    }

    if ($errs) {
        $formError = $errs;
    } else {
        di_save_config($cfg);
        header('Location: ' . DI_SELF . '?paso=' . ($pkgsource === 'download' ? 'descargar' : 'extraer'));
        exit;
    }
}

/* ---------------------------------------------------------------------------
 *  PROCESADO DEL FORMULARIO ULTRASENCILLO (solo extraer + ir a install/)
 * ------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'guardar_simple') {
    $allZips = di_find_zips();
    $pkgsource = (($_POST['pkgsource'] ?? '') === 'download') ? 'download' : 'local';
    $zip = null;
    $prefix = null;
    $downloadVer = null;
    if ($pkgsource === 'download') {
        $downloadVer = di_sanitize_version($_POST['download_version_manual'] ?? '');
        if (!$downloadVer) {
            $downloadVer = di_sanitize_version($_POST['download_version'] ?? '');
        }
    } else {
        $zip = di_resolve_zip($_POST['zipfile'] ?? '');
        if (!$zip && count($allZips) === 1) {
            $zip = $allZips[0];
        }
        $prefix = $zip ? di_detect_prefix($zip) : null;
    }

    $subpath = trim(str_replace('\\', '/', $_POST['subpath'] ?? ''), '/');
    $subpath = preg_replace('#[^A-Za-z0-9_\-/]#', '', $subpath);
    $target = DI_DIR . ($subpath !== '' ? '/' . $subpath : '');
    $baseurl = di_self_base_url() . ($subpath !== '' ? '/' . $subpath : '');
    if (!empty($_POST['baseurl'])) {
        $baseurl = di_validate_baseurl($_POST['baseurl'], $baseurl);
    }

    $errs = array();
    if ($pkgsource === 'download') {
        if (!$downloadVer) {
            $errs[] = 'Selecciona o escribe una versión válida (x.y.z) para descargar.';
        }
    } elseif (!$zip) {
        $errs[] = empty($allZips)
            ? 'No hay ZIP local. Sube un dolibarr-*.zip o elige "Descargar versión".'
            : 'Selecciona cuál de los ' . count($allZips) . ' paquetes ZIP quieres extraer.';
    } elseif (!$prefix) {
        $errs[] = 'El ZIP "' . basename($zip) . '" no parece un paquete oficial de Dolibarr (no contiene "*/htdocs/").';
    }

    if ($errs) {
        $formError = $errs;
        $formMode = 'simple';
    } else {
        di_save_config(array(
            'mode' => 'simple',
            'zip' => $zip,
            'prefix' => $prefix,
            'download_version' => $downloadVer,
            'subpath' => $subpath,
            'target' => $target,
            'baseurl' => $baseurl,
        ));
        header('Location: ' . DI_SELF . '?paso=' . ($pkgsource === 'download' ? 'descargar' : 'extraer'));
        exit;
    }
}

/* ===========================================================================
 *  CHEQUEO DE REQUISITOS
 * ======================================================================== */

function di_requisitos()
{
    $r = array();
    $r[] = array(
        'ok' => version_compare(PHP_VERSION, DI_PHP_MIN, '>='),
        'label' => 'Versión de PHP ≥ ' . DI_PHP_MIN,
        'val' => PHP_VERSION,
        'crit' => true,
    );
    foreach (array('zip', 'mysqli', 'json', 'mbstring', 'xml', 'gd', 'curl') as $ext) {
        $crit = in_array($ext, array('zip', 'json'), true);
        $r[] = array(
            'ok' => extension_loaded($ext),
            'label' => 'Extensión PHP: ' . $ext . ($crit ? ' (obligatoria)' : ' (recomendada)'),
            'val' => extension_loaded($ext) ? 'sí' : 'no',
            'crit' => $crit,
        );
    }
    // Driver de BD: MySQL/MariaDB (mysqli/pdo_mysql) o PostgreSQL (pgsql/pdo_pgsql).
    $mysqlOk = extension_loaded('mysqli') || extension_loaded('pdo_mysql');
    $pgOk = extension_loaded('pgsql') || extension_loaded('pdo_pgsql');
    $avail = array();
    if ($mysqlOk) {
        $avail[] = 'MySQL/MariaDB';
    }
    if ($pgOk) {
        $avail[] = 'PostgreSQL';
    }
    $r[] = array(
        'ok' => ($mysqlOk || $pgOk),
        'label' => 'Driver de base de datos (MySQL y/o PostgreSQL)',
        'val' => $avail ? implode(' + ', $avail) : 'ninguno',
        'crit' => true,
    );
    // curl o allow_url_fopen
    $httpok = function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    $r[] = array('ok' => $httpok, 'label' => 'cURL o allow_url_fopen (para ejecutar el instalador)', 'val' => function_exists('curl_init') ? 'curl' : (ini_get('allow_url_fopen') ? 'fopen' : 'no'), 'crit' => true);
    // Directorio destino escribible
    $r[] = array('ok' => is_writable(DI_DIR), 'label' => 'Directorio de instalación escribible', 'val' => DI_DIR, 'crit' => true);
    // Directorio padre escribible (para crear ../documents)
    $parent = dirname(DI_DIR);
    $r[] = array('ok' => is_writable($parent), 'label' => 'Directorio padre escribible (para ../documents)', 'val' => $parent, 'crit' => false);
    // ZIP presente (uno o varios)
    $zips = di_find_zips();
    $r[] = array(
        'ok' => count($zips) > 0,
        'label' => 'Paquete ZIP de Dolibarr presente',
        'val' => count($zips) === 0 ? 'no encontrado'
            : (count($zips) === 1 ? basename($zips[0]) : count($zips) . ' paquetes: ' . implode(', ', array_map('basename', $zips))),
        'crit' => true,
    );

    return $r;
}

/* ===========================================================================
 *  VISTA (HTML)
 * ======================================================================== */

$paso = $_GET['paso'] ?? 'bienvenida';

/* ===========================================================================
 *  VISTA (HTML) — Estética "terminal CRT" (verde fósforo sobre negro)
 * ======================================================================== */

function di_steps_for_mode($mode)
{
    if ($mode === 'simple') {
        return array('bienvenida' => 'inicio', 'simple' => 'paquete', 'extraer' => 'extraer', 'redir' => 'lanzar');
    }
    return array(
        'bienvenida' => 'inicio', 'requisitos' => 'requisitos', 'config' => 'config',
        'extraer' => 'extraer', 'instalar' => 'instalar', 'finalizar' => 'listo',
    );
}

function di_header($title, $current = null)
{
    di_security_headers();
    if ($current === null) {
        $current = $_GET['paso'] ?? 'bienvenida';
    }
    $cfg = di_load_config();
    $mode = ($cfg && !empty($cfg['mode'])) ? $cfg['mode'] : (($current === 'simple') ? 'simple' : 'full');
    $steps = di_steps_for_mode($mode);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo di_h($title); ?> :: DoliInstaller</title>
<style>
  :root{
    --grn:#43ff7d; --grn-dim:#1f9e4e; --grn-soft:#0c5a2b;
    --amber:#ffb454; --red:#ff5b5b; --bg:#04070a; --panel:#070d0a;
    --line:#103a22; --glow:0 0 4px rgba(67,255,125,.45);
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);min-height:100%}
  body{
    font-family:"Cascadia Code","JetBrains Mono",Consolas,"DejaVu Sans Mono",Menlo,monospace;
    color:var(--grn); font-size:14px; line-height:1.55;
    text-shadow:var(--glow);
    padding:26px 14px 60px;
  }
  /* Scanlines + viñeta sobre toda la pantalla */
  body::before{
    content:""; position:fixed; inset:0; z-index:9; pointer-events:none;
    background:repeating-linear-gradient(0deg, rgba(0,0,0,0) 0px, rgba(0,0,0,0) 2px, rgba(0,0,0,.16) 3px, rgba(0,0,0,.16) 4px);
    mix-blend-mode:multiply;
  }
  body::after{
    content:""; position:fixed; inset:0; z-index:8; pointer-events:none;
    background:radial-gradient(ellipse at center, rgba(0,0,0,0) 55%, rgba(0,0,0,.55) 100%);
  }
  @keyframes flick{0%,97%{opacity:1}98%{opacity:.86}100%{opacity:1}}
  .crt{max-width:920px;margin:0 auto;animation:flick 6s infinite}
  @media (prefers-reduced-motion:reduce){.crt{animation:none}.cursor{animation:none}}

  a{color:var(--amber);text-decoration:none}
  a:hover{text-decoration:underline}
  .dim{color:var(--grn-dim);text-shadow:none}
  .amber{color:var(--amber)} .red{color:var(--red)}
  hr{border:0;border-top:1px solid var(--line);margin:16px 0}

  .banner{color:var(--grn);font-size:11px;line-height:1.1;margin:0 0 4px;white-space:pre;overflow:hidden}
  .tagline{color:var(--grn-dim);text-shadow:none;margin:0 0 18px;font-size:12px}

  .bar-top{color:var(--grn-dim);text-shadow:none;font-size:12px;border-bottom:1px solid var(--line);padding-bottom:8px;margin-bottom:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px}

  /* "Ventana" estilo terminal */
  .win{border:1px solid var(--line);background:var(--panel);margin:0 0 16px;box-shadow:inset 0 0 24px rgba(67,255,125,.04)}
  .win>.t{border-bottom:1px solid var(--line);padding:7px 12px;color:var(--grn);font-size:12px;letter-spacing:1px;background:rgba(67,255,125,.04)}
  .win>.t::before{content:"┌─ ";color:var(--grn-dim)}
  .win>.b{padding:14px 16px}

  .steps{display:flex;flex-wrap:wrap;gap:4px 14px;margin:0 0 16px;font-size:12px;color:var(--grn-dim);text-shadow:none}
  .steps .st.cur{color:var(--amber);text-shadow:0 0 4px rgba(255,180,84,.5)}
  .steps .st.done{color:var(--grn)}

  table.kv{width:100%;border-collapse:collapse;font-size:13px}
  table.kv td{padding:6px 6px;border-bottom:1px dotted var(--line);vertical-align:top}
  table.kv td.s{width:64px;white-space:nowrap}
  .tagOK{color:var(--grn)} .tagFAIL{color:var(--red)} .tagWARN{color:var(--amber)}

  label.f{display:block;font-size:12px;color:var(--grn-dim);text-shadow:none;margin:14px 0 4px}
  label.f::before{content:"> ";color:var(--grn)}
  input[type=text],input[type=password],input[type=number],select{
    width:100%;background:#02110a;border:1px solid var(--line);color:var(--grn);
    font-family:inherit;font-size:14px;padding:9px 11px;text-shadow:var(--glow);outline:none
  }
  input:focus,select:focus{border-color:var(--grn);box-shadow:0 0 0 1px var(--grn),0 0 10px rgba(67,255,125,.25)}
  select option{background:#02110a;color:var(--grn)}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}
  .grid .full{grid-column:1/-1}
  .chk{display:flex;align-items:center;gap:8px;margin-top:14px;font-size:13px;cursor:pointer}
  .chk input{width:auto}
  .hint{color:var(--grn-dim);text-shadow:none;font-size:11px;margin-top:3px}

  .btn{display:inline-block;background:transparent;border:1px solid var(--grn);color:var(--grn);
    font-family:inherit;font-size:14px;padding:10px 18px;cursor:pointer;text-shadow:var(--glow);letter-spacing:1px}
  .btn:hover{background:var(--grn);color:#02110a;text-shadow:none}
  .btn:hover{text-decoration:none}
  .btn.amber{border-color:var(--amber);color:var(--amber)}
  .btn.amber:hover{background:var(--amber);color:#1a1003}
  .btn.dim{border-color:var(--grn-dim);color:var(--grn-dim)}
  .btn:disabled{opacity:.4;cursor:not-allowed}
  .row{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:20px;flex-wrap:wrap}

  .choice{display:block;border:1px solid var(--line);padding:16px 18px;margin:0 0 12px;background:var(--panel);cursor:pointer;color:var(--grn)}
  .choice:hover{border-color:var(--grn);background:rgba(67,255,125,.05);text-decoration:none}
  .choice .h{font-size:15px;letter-spacing:1px}
  .choice .d{color:var(--grn-dim);text-shadow:none;font-size:12px;margin-top:5px}

  .msg{border:1px solid var(--line);padding:10px 12px;margin:0 0 14px;font-size:13px}
  .msg.err{border-color:var(--red);color:var(--red)}
  .msg.warn{border-color:var(--amber);color:var(--amber)}
  .msg.ok{border-color:var(--grn);color:var(--grn)}

  /* Barra de progreso blocky */
  .pbar{border:1px solid var(--line);height:20px;position:relative;background:#02110a;margin:6px 0}
  .pbar>i{display:block;height:100%;width:0;background:var(--grn);box-shadow:0 0 10px rgba(67,255,125,.5);transition:width .2s linear}
  .pbar>span{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;color:#02110a;mix-blend-mode:difference;text-shadow:none}

  /* Log en vivo */
  pre.log{margin:0;background:#01080500;border:1px solid var(--line);padding:12px;height:300px;overflow:auto;
    font-size:12.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word;color:var(--grn);scrollbar-width:thin}
  pre.log::-webkit-scrollbar{width:8px}
  pre.log::-webkit-scrollbar-thumb{background:var(--grn-soft)}
  .cursor{display:inline-block;width:9px;height:15px;background:var(--grn);vertical-align:-2px;animation:blink 1s steps(1) infinite;box-shadow:var(--glow)}
  @keyframes blink{50%{opacity:0}}
  .foot{color:var(--grn-dim);text-shadow:none;font-size:11px;text-align:center;margin-top:22px}
</style>
</head>
<body><div class="crt">
<div class="bar-top">
  <span>EasyDoliInstaller v<?php echo DI_VERSION; ?> :: terminal de instalación</span>
  <span><?php echo di_h(date('Y-m-d H:i')); ?></span>
</div>
<?php if (!empty($steps)) {
        $order = array_keys($steps);
        $ci = array_search($current, $order, true);
        echo '<div class="steps">';
        foreach ($order as $i => $k) {
            $cls = 'st';
            if ($ci !== false && $i < $ci) {
                $cls .= ' done';
            }
            if ($i === $ci) {
                $cls .= ' cur';
            }
            $tag = ($ci !== false && $i < $ci) ? '[x]' : ($i === $ci ? '[>]' : '[ ]');
            echo '<span class="' . $cls . '">' . $tag . ' ' . di_h($steps[$k]) . '</span>';
        }
        echo '</div>';
    } ?>
<?php
}

function di_footer()
{
    ?>
<div class="foot">// EasyDoliInstaller · Easysoft Tech S.L. · GPL-3.0 · si interrumpes el proceso, borra este archivo y el .zip a mano</div>
</div></body></html>
<?php
}

/**
 * Selector de origen del paquete (compartido por los dos formularios):
 * usar un ZIP local o descargar una versión de Dolibarr. Emite su propio <div class="win">.
 */
function di_package_picker($prev, $zips)
{
    $prevZip = basename((string) (($prev['zip'] ?? '')));
    $prevVer = (string) ($prev['download_version'] ?? '');
    $hasCurl = function_exists('curl_init');
    // Por defecto: local si hay ZIPs y no había versión elegida; si no, descargar.
    $src = (!empty($zips) && $prevVer === '') ? 'local' : 'download';
    if (empty($zips) && !$hasCurl) {
        $src = 'local';
    }
    ?>
<div class="win"><div class="t">PAQUETE DE DOLIBARR</div><div class="b">
    <div class="chk"><input type="radio" name="pkgsource" id="src_local" value="local" <?php echo $src === 'local' ? 'checked' : ''; ?>> usar un ZIP que ya está aquí<?php echo empty($zips) ? ' <span class="dim">(no hay ninguno)</span>' : ''; ?></div>
    <div class="chk"><input type="radio" name="pkgsource" id="src_dl" value="download" <?php echo $src === 'download' ? 'checked' : ''; ?> <?php echo $hasCurl ? '' : 'disabled'; ?>> descargar una versión automáticamente<?php echo $hasCurl ? '' : ' <span class="dim">(requiere cURL)</span>'; ?></div>

    <div id="blk_local" style="margin-top:12px">
    <?php if (empty($zips)) { ?>
        <div class="dim">No hay ningún .zip junto al instalador.</div>
    <?php } elseif (count($zips) === 1) { ?>
        <input type="hidden" name="zipfile" value="<?php echo di_h(basename($zips[0])); ?>">
        <div>ZIP local: <span class="amber"><?php echo di_h(basename($zips[0])); ?></span> <span class="dim">(<?php echo round(filesize($zips[0]) / 1048576); ?> MB)</span></div>
    <?php } else { ?>
        <label class="f">elige ZIP local (<?php echo count($zips); ?> detectados)</label>
        <select name="zipfile">
            <?php foreach ($zips as $z) {
                $bn = basename($z);
                echo '<option value="' . di_h($bn) . '"' . ($prevZip === $bn ? ' selected' : '') . '>' . di_h($bn) . ' — ' . round(filesize($z) / 1048576) . ' MB</option>';
            } ?>
        </select>
    <?php } ?>
    </div>

    <div id="blk_dl" style="margin-top:12px">
        <label class="f">versión a descargar (paquete oficial de sourceforge.net)</label>
        <select name="download_version" id="dlver">
            <?php foreach (di_fallback_versions() as $v) {
                echo '<option value="' . di_h($v) . '"' . ($prevVer === $v ? ' selected' : '') . '>' . di_h($v) . '</option>';
            } ?>
        </select>
        <label class="f">o escribe una versión exacta (x.y.z)</label>
        <input type="text" name="download_version_manual" value="<?php echo di_h($prevVer); ?>" placeholder="(opcional)">
        <div class="hint">~85 MB. Se descarga al servidor por bloques, con barra de progreso real.</div>
    </div>
</div></div>
<script>
  (function(){
    var rl=document.getElementById('src_local'),rd=document.getElementById('src_dl'),
        bl=document.getElementById('blk_local'),bd=document.getElementById('blk_dl');
    function tog(){var dl=rd.checked;bd.style.display=dl?'block':'none';bl.style.display=dl?'none':'block';}
    rl.addEventListener('change',tog);rd.addEventListener('change',tog);tog();
    // Refresca la lista de versiones en vivo desde GitHub (no bloquea la página).
    fetch('<?php echo DI_SELF; ?>?ajax=versiones',{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){ if(!d||!d.versions||!d.versions.length)return;
        var s=document.getElementById('dlver'),cur=s.value;s.innerHTML='';
        d.versions.forEach(function(v){var o=document.createElement('option');o.value=v;o.textContent=v;if(v===cur)o.selected=true;s.appendChild(o);});
      }).catch(function(){});
  })();
</script>
<?php
}

/* ----- Guarda de "ya instalado" ----- */
$cfgExisting = di_load_config();

// Autolimpieza agresiva: si la instalación está REALMENTE completa (existe
// install.lock) y el instalador quedó abandonado, retira secretos y se autodestruye
// al cargar cualquier paso (salvo la propia pantalla final).
if ($cfgExisting && di_already_installed($cfgExisting) && di_find_lock($cfgExisting)
    && !in_array($paso, array('finalizar', 'redir'), true)) {
    @di_rrmdir($cfgExisting['target'] . '/install');
    if (!empty($cfgExisting['zip']) && is_file($cfgExisting['zip'])) {
        @unlink($cfgExisting['zip']);
    }
    @di_rrmdir(DI_TMPDIR);
    @unlink(__FILE__);
}

// La guarda NO bloquea 'instalar'/'extraer' (los pasos son idempotentes y deben
// poder reanudarse tras un F5); solo evita relanzar el asistente sobre lo ya hecho.
if (di_already_installed($cfgExisting) && !in_array($paso, array('finalizar', 'redir', 'instalar', 'extraer', 'descargar'), true)) {
    di_header('Ya instalado', $paso);
    echo '<div class="win"><div class="t">AVISO</div><div class="b">';
    echo '<div class="msg warn">Parece que Dolibarr YA está instalado en este directorio (existe conf/conf.php con datos).</div>';
    echo '<p class="dim">Para reinstalar desde cero, borra antes <span class="amber">conf/conf.php</span> y el archivo <span class="amber">install.lock</span> del directorio de documentos.</p>';
    echo '<div class="row"><span></span><a class="btn" href="' . di_h(di_self_base_url()) . '/">ABRIR DOLIBARR &gt;</a></div>';
    echo '</div></div>';
    di_footer();
    exit;
}

/* ===========================================================================
 *  PÁGINAS
 * ======================================================================== */

if ($paso === 'bienvenida') {
    di_header('Inicio');
    $zips = di_find_zips();
    ?>
<pre class="banner"> ____   ___  _     ___ ____    _    ____  ____
|  _ \ / _ \| |   |_ _| __ )  / \  |  _ \|  _ \
| | | | | | | |    | ||  _ \ / _ \ | |_) | |_) |
| |_| | |_| | |___ | || |_) / ___ \|  _ &lt;|  _ &lt;
|____/ \___/|_____|___|____/_/   \_\_| \_\_| \_\</pre>
<div class="tagline">// instalador automático de Dolibarr — descomprime y configura todo</div>

<div class="win"><div class="t">PAQUETE</div><div class="b">
<?php
    if (count($zips) === 0) {
        echo '<div class="msg err">No se detecta ningún paquete. Sube un <span class="amber">dolibarr-*.zip</span> junto a este archivo y recarga.</div>';
    } elseif (count($zips) === 1) {
        echo '<div>detectado: <span class="amber">' . di_h(basename($zips[0])) . '</span> <span class="dim">(' . round(filesize($zips[0]) / 1048576) . ' MB)</span></div>';
    } else {
        echo '<div><span class="amber">' . count($zips) . ' paquetes</span> detectados — elegirás cuál en el siguiente paso.</div>';
    }
    echo '<div class="dim">destino: ' . di_h(DI_DIR) . '</div>';
    ?>
</div></div>

<div class="win"><div class="t">ELIGE MODO</div><div class="b">
    <a class="choice" href="?paso=requisitos">
        <div class="h">[ 1 ]  INSTALACIÓN AUTOMÁTICA</div>
        <div class="d">Descomprime + crea base de datos + tablas + administrador + bloqueo. Cero clics en el asistente de Dolibarr. Se autodestruye al terminar.</div>
    </a>
    <a class="choice" href="?paso=simple">
        <div class="h">[ 2 ]  SOLO EXTRAER  <span class="dim">(modo experto)</span></div>
        <div class="d">Solo descomprime htdocs y te redirige al asistente nativo install/ de Dolibarr para que lo configures tú.</div>
    </a>
</div></div>
<?php
    di_footer();
    exit;
}

if ($paso === 'requisitos') {
    di_header('Requisitos');
    $reqs = di_requisitos();
    $blocking = false;
    echo '<div class="win"><div class="t">COMPROBACIÓN DEL SISTEMA</div><div class="b">';
    echo '<table class="kv">';
    foreach ($reqs as $q) {
        if (!$q['ok'] && $q['crit']) {
            $blocking = true;
        }
        if ($q['ok']) {
            $tag = '<span class="tagOK">[ OK ]</span>';
        } elseif ($q['crit']) {
            $tag = '<span class="tagFAIL">[FAIL]</span>';
        } else {
            $tag = '<span class="tagWARN">[warn]</span>';
        }
        echo '<tr><td class="s">' . $tag . '</td><td>' . di_h($q['label']) . '<div class="dim">' . di_h($q['val']) . '</div></td></tr>';
    }
    echo '</table>';
    if ($blocking) {
        echo '<div class="msg err">Faltan requisitos obligatorios. Corrígelos (consulta a tu hosting) y reintenta.</div>';
    }
    echo '<div class="row"><a class="btn dim" href="?paso=bienvenida">&lt; ATRÁS</a>';
    echo $blocking ? '<a class="btn" href="?paso=requisitos">REINTENTAR</a>' : '<a class="btn" href="?paso=config">CONTINUAR &gt;</a>';
    echo '</div></div></div>';
    di_footer();
    exit;
}

if ($paso === 'simple') {
    di_header('Paquete', 'simple');
    $zips = di_find_zips();
    $prev = di_load_config();
    ?>
<div class="win"><div class="t">MODO ULTRASENCILLO — SOLO EXTRAER</div><div class="b">
<div class="dim">// se descomprime htdocs y te llevamos al asistente nativo install/ de Dolibarr</div>
<?php if (!empty($GLOBALS['formError'])) {
        echo '<div class="msg err" style="margin-top:10px">';
        foreach ($GLOBALS['formError'] as $e) {
            echo '· ' . di_h($e) . '<br>';
        }
        echo '</div>';
    } ?>
</div></div>
<form method="post" action="<?php echo DI_SELF; ?>?paso=simple">
    <input type="hidden" name="accion" value="guardar_simple">
    <?php di_package_picker($prev, $zips); ?>
    <div class="win"><div class="t">DESTINO</div><div class="b">
    <label class="f">subcarpeta de instalación (opcional, vacío = aquí)</label>
    <input type="text" name="subpath" value="<?php echo di_h($prev['subpath'] ?? ''); ?>" placeholder="(vacío)">
    </div></div>
    <div class="row">
        <a class="btn dim" href="?paso=bienvenida">&lt; ATRÁS</a>
        <button class="btn amber" type="submit">EXTRAER &gt;</button>
    </div>
</form>
<?php
    di_footer();
    exit;
}

if ($paso === 'config') {
    di_header('Configuración');
    $prev = di_load_config();
    $g = function ($path, $def = '') use ($prev) {
        if (!$prev) {
            return $def;
        }
        $v = $prev;
        foreach (explode('.', $path) as $k) {
            if (!isset($v[$k])) {
                return $def;
            }
            $v = $v[$k];
        }
        return $v;
    };
    $zips = di_find_zips();
    $prevZip = basename((string) $g('zip', ''));
    ?>
<?php if (!empty($GLOBALS['formError']) && ($GLOBALS['formMode'] ?? 'full') !== 'simple') {
        echo '<div class="msg err"><b>Revisa:</b><br>';
        foreach ($GLOBALS['formError'] as $e) {
            echo '· ' . di_h($e) . '<br>';
        }
        echo '</div>';
    } ?>
<form method="post" action="<?php echo DI_SELF; ?>?paso=config">
<input type="hidden" name="accion" value="guardar">

<?php di_package_picker($prev, $zips); ?>

<div class="win"><div class="t">BASE DE DATOS</div><div class="b">
<?php $dbtype = $g('db.type', 'mysqli'); ?>
<div class="grid">
    <div><label class="f">tipo de base de datos</label>
        <select name="db_type" id="db_type">
            <option value="mysqli"<?php echo $dbtype === 'mysqli' ? ' selected' : ''; ?>>MySQL / MariaDB</option>
            <option value="pgsql"<?php echo $dbtype === 'pgsql' ? ' selected' : ''; ?>>PostgreSQL</option>
        </select>
    </div>
    <div><label class="f">servidor (host)</label><input type="text" name="db_host" value="<?php echo di_h($g('db.host', 'localhost')); ?>"></div>
    <div><label class="f">puerto</label><input type="number" name="db_port" id="db_port" value="<?php echo di_h($g('db.port', '3306')); ?>"></div>
    <div><label class="f">nombre de la base de datos</label><input type="text" name="db_name" value="<?php echo di_h($g('db.name', 'dolibarr')); ?>"></div>
    <div><label class="f">prefijo de tablas</label><input type="text" name="db_prefix" value="<?php echo di_h($g('db.prefix', 'llx_')); ?>"></div>
    <div><label class="f">usuario</label><input type="text" name="db_user" value="<?php echo di_h($g('db.user', '')); ?>"></div>
    <div><label class="f">contraseña</label><input type="password" name="db_pass" value="<?php echo di_h($g('db.pass', '')); ?>"></div>
</div>
<label class="chk"><input type="checkbox" name="db_create" id="db_create" value="1" <?php echo $g('db.create') ? 'checked' : ''; ?>> crear la base de datos automáticamente (requiere usuario administrador del SGBD)</label>
<div id="rootbox" style="display:none">
    <div class="grid">
        <div><label class="f">usuario admin del SGBD (root / postgres)</label><input type="text" name="db_rootuser" value="<?php echo di_h($g('db.rootuser', 'root')); ?>"></div>
        <div><label class="f">contraseña del admin del SGBD</label><input type="password" name="db_rootpass" value="<?php echo di_h($g('db.rootpass', '')); ?>"></div>
    </div>
</div>
</div></div>

<div class="win"><div class="t">ADMINISTRADOR DOLIBARR</div><div class="b">
<div class="grid">
    <div><label class="f">login</label><input type="text" name="admin_login" value="<?php echo di_h($g('admin.login', 'admin')); ?>"></div>
    <div><label class="f">contraseña (mín. 8)</label><input type="password" name="admin_pass" value="<?php echo di_h($g('admin.pass', '')); ?>"></div>
</div>
</div></div>

<div class="win"><div class="t">OPCIONES</div><div class="b">
<div class="grid">
    <div><label class="f">idioma por defecto</label>
        <select name="lang">
        <?php
        $langs = array('es_ES' => 'Español', 'en_US' => 'English', 'fr_FR' => 'Français', 'ca_ES' => 'Català', 'pt_PT' => 'Português', 'de_DE' => 'Deutsch', 'it_IT' => 'Italiano');
        $sel = $g('lang', 'es_ES');
        foreach ($langs as $k => $v) {
            echo '<option value="' . $k . '"' . ($sel === $k ? ' selected' : '') . '>' . $v . '</option>';
        }
        ?>
        </select>
    </div>
    <div><label class="f">subcarpeta (opcional, vacío = aquí)</label><input type="text" name="subpath" value="<?php echo di_h($g('subpath', '')); ?>" placeholder="(vacío)"></div>
    <div class="full"><label class="chk"><input type="checkbox" name="forcehttps" value="1" <?php echo $g('forcehttps') ? 'checked' : ''; ?>> forzar HTTPS</label></div>
    <div class="full"><label class="f">URL base detectada</label><input type="text" name="baseurl" value="<?php echo di_h($g('baseurl', di_self_base_url())); ?>"><div class="hint">URL pública de la raíz de Dolibarr; normalmente correcta.</div></div>
</div>
</div></div>

<div class="row">
    <a class="btn dim" href="?paso=requisitos">&lt; ATRÁS</a>
    <button class="btn amber" type="submit">GUARDAR Y DESCOMPRIMIR &gt;</button>
</div>
</form>
<script>
  var cb=document.getElementById('db_create'),rb=document.getElementById('rootbox');
  function tog(){rb.style.display=cb.checked?'block':'none';}
  cb.addEventListener('change',tog);tog();
  // Ajusta el puerto por defecto al cambiar de motor (solo si está en el otro valor por defecto).
  var dt=document.getElementById('db_type'),dp=document.getElementById('db_port');
  dt.addEventListener('change',function(){
    if(dt.value==='pgsql' && (dp.value===''||dp.value==='3306')) dp.value='5432';
    if(dt.value==='mysqli' && (dp.value===''||dp.value==='5432')) dp.value='3306';
  });
</script>
<?php
    di_footer();
    exit;
}

if ($paso === 'descargar') {
    $cfg = di_load_config();
    if (!$cfg || empty($cfg['download_version'])) {
        header('Location: ' . DI_SELF . '?paso=bienvenida');
        exit;
    }
    di_header('Descarga', 'extraer');
    $ver = $cfg['download_version'];
    ?>
<div class="win"><div class="t">DESCARGANDO :: dolibarr-<?php echo di_h($ver); ?>.zip</div><div class="b">
<div class="pbar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="pbar"><i id="bar"></i><span id="pct">0%</span></div>
<pre class="log" id="log" aria-live="polite"></pre>
<noscript><div class="msg err">Este asistente necesita JavaScript para descargar por bloques. Actívalo y recarga.</div></noscript>
<div class="msg err" id="err" style="display:none" role="alert"></div>
<div class="row"><span class="dim">origen: sourceforge.net</span>
<a class="btn" id="next" style="display:none" href="?paso=extraer">CONTINUAR &gt;</a></div>
</div></div>
<script>
  var VER=<?php echo json_encode($ver); ?>;
  var log=document.getElementById('log'),bar=document.getElementById('bar'),pct=document.getElementById('pct'),
      pbar=document.getElementById('pbar'),errb=document.getElementById('err'),next=document.getElementById('next'),cur=null,nb=0;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function mb(b){return (b/1048576).toFixed(1)+' MB';}
  function ascii(p){var w=22,f=Math.round(p/100*w);return '['+Array(f+1).join('#')+Array(w-f+1).join('-')+']';}
  function put(s){if(cur){cur.remove();cur=null;}log.insertAdjacentText('beforeend',s+'\n');cur=document.createElement('span');cur.className='cursor';log.appendChild(cur);log.scrollTop=log.scrollHeight;}
  function fail(m,off){errb.style.display='block';errb.innerHTML='ERROR: '+m+'<br><button class="btn" onclick="errb.style.display=\'none\';step('+off+',0)">REINTENTAR</button>';}
  put(ts()+'  conectando con sourceforge.net ...');
  function step(offset,tries){
    tries=tries||0;
    fetch('<?php echo DI_SELF; ?>?ajax=descargar&offset='+offset,{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.error){put('  !! '+d.error);fail(d.error,offset);return;}
        nb++;
        var p=d.total?Math.round(d.next/d.total*100):0;
        bar.style.width=p+'%';pct.textContent=p+'%';pbar.setAttribute('aria-valuenow',p);
        put(ts()+'  bloque '+pad(nb,3)+'  '+ascii(p)+' '+p+'%  '+mb(d.next)+' / '+mb(d.total));
        if(d.done){put(ts()+'  descarga COMPLETA ('+mb(d.next)+'). validando ZIP ...');put(ts()+'  paquete listo.');next.style.display='inline-block';setTimeout(function(){location.href='?paso=extraer';},700);}
        else step(d.next,0);
      })
      .catch(function(e){
        if(tries<6){put(ts()+'  reintentando (offset '+offset+', '+(tries+1)+') ...');setTimeout(function(){step(offset,tries+1);},2000*(tries+1));}
        else{put('  !! red: '+e);fail('Fallo de red en offset '+offset+': '+e,offset);}
      });
  }
  step(0,0);
</script>
<?php
    di_footer();
    exit;
}

if ($paso === 'extraer') {
    $cfg = di_load_config();
    if (!$cfg) {
        header('Location: ' . DI_SELF . '?paso=bienvenida');
        exit;
    }
    di_header('Descompresión', 'extraer');
    $mode = $cfg['mode'] ?? 'full';
    $zipname = basename((string) ($cfg['zip'] ?? 'paquete.zip'));
    ?>
<div class="win"><div class="t">DESCOMPRIMIENDO :: <?php echo di_h($zipname); ?></div><div class="b">
<div class="pbar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="pbar"><i id="bar"></i><span id="pct">0%</span></div>
<pre class="log" id="log" aria-live="polite"></pre>
<noscript><div class="msg err">Este asistente necesita JavaScript para descomprimir por bloques. Actívalo (o desactiva la extensión que bloquea fetch) y recarga la página.</div></noscript>
<div class="msg err" id="err" style="display:none" role="alert"></div>
<div class="row"><span class="dim">destino: <?php echo di_h($cfg['target']); ?></span>
<a class="btn" id="next" style="display:none" href="<?php echo $mode === 'simple' ? '?paso=redir' : '?paso=instalar'; ?>">CONTINUAR &gt;</a></div>
</div></div>
<script>
  var MODE=<?php echo json_encode($mode); ?>, ZIP=<?php echo json_encode($zipname); ?>;
  var log=document.getElementById('log'),bar=document.getElementById('bar'),pct=document.getElementById('pct'),
      pbar=document.getElementById('pbar'),errb=document.getElementById('err'),next=document.getElementById('next'),nchunk=0,cur=null;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function ascii(p){var w=22,f=Math.round(p/100*w);return '['+Array(f+1).join('#')+Array(w-f+1).join('-')+']';}
  function put(s){ if(cur){cur.remove();cur=null;} log.insertAdjacentText('beforeend',s+'\n'); cur=document.createElement('span'); cur.className='cursor'; log.appendChild(cur); log.scrollTop=log.scrollHeight; }
  function fail(m,off){errb.style.display='block';errb.innerHTML='ERROR: '+m+'<br><button class="btn" onclick="errb.style.display=\'none\';step('+off+',0)">REINTENTAR ESTE BLOQUE</button>';}
  put(ts()+'  abriendo '+ZIP+' ...');
  function step(offset,tries){
    tries=tries||0;
    fetch('<?php echo DI_SELF; ?>?ajax=extraer&offset='+offset,{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.error){put('  !! '+d.error);fail(d.error,offset);return;}
        nchunk++;
        var p=d.total?Math.round(d.next/d.total*100):100;
        bar.style.width=p+'%';pct.textContent=p+'%';pbar.setAttribute('aria-valuenow',p);
        put(ts()+'  bloque '+pad(nchunk,3)+'  '+pad(offset,5)+'-'+pad(d.next,5)+'  '+ascii(p)+' '+p+'%  ('+d.written+' arch.)');
        if(d.done){
          put(ts()+'  '+d.total+' entradas procesadas. moviendo htdocs -> raíz ...');
          put(ts()+'  extracción COMPLETA.');
          next.style.display='inline-block';
          setTimeout(function(){location.href = MODE==='simple' ? '?paso=redir' : '?paso=instalar';},700);
        } else { step(d.next,0); }
      })
      .catch(function(e){
        if(tries<5){ put(ts()+'  reintentando bloque (offset '+offset+', intento '+(tries+1)+') ...'); setTimeout(function(){step(offset,tries+1);},1500*(tries+1)); }
        else { put('  !! red: '+e); fail('Fallo de red en offset '+offset+': '+e,offset); }
      });
  }
  step(0,0);
</script>
<?php
    di_footer();
    exit;
}

if ($paso === 'instalar') {
    $cfg = di_load_config();
    if (!$cfg || ($cfg['mode'] ?? 'full') === 'simple') {
        header('Location: ' . DI_SELF . '?paso=bienvenida');
        exit;
    }
    di_header('Instalación', 'instalar');
    ?>
<div class="win"><div class="t">EJECUTANDO INSTALADOR NATIVO (desatendido)</div><div class="b">
<pre class="log" id="log" aria-live="polite"></pre>
<noscript><div class="msg err">Este asistente necesita JavaScript para ejecutar la instalación. Actívalo y recarga, o termina manualmente en <?php echo di_h($cfg['baseurl']); ?>/install/.</div></noscript>
<div class="msg err" id="err" style="display:none" role="alert"></div>
<div class="row"><span class="dim">// el paso de tablas puede tardar varios minutos en hostings lentos; no cierres la ventana</span>
<a class="btn" id="next" style="display:none" href="?paso=finalizar">FINALIZAR &gt;</a></div>
</div></div>
<script>
  var steps=[['step1','crear configuración y base de datos'],['step2','crear tablas y datos de referencia'],['step5','crear administrador y bloquear instalación']];
  var DONE=<?php echo json_encode($cfg['progress'] ?? ''); ?>;
  var log=document.getElementById('log'),errb=document.getElementById('err'),next=document.getElementById('next'),cur=null,timer=null,t0=0;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function fmt(s){var m=Math.floor(s/60),x=s%60;return (m?m+'m ':'')+x+'s';}
  function put(s){ if(cur){cur.remove();cur=null;} log.insertAdjacentText('beforeend',s+'\n'); cur=document.createElement('span'); cur.className='cursor'; log.appendChild(cur); log.scrollTop=log.scrollHeight; }
  function replaceLast(s){ var t=log.textContent; var i=t.lastIndexOf('\n', t.length-2); log.textContent=t.substring(0,i+1); put(s); }
  function stopT(){ if(timer){clearInterval(timer);timer=null;} }
  function fail(i,m){ stopT(); errb.style.display='block';
    errb.innerHTML='ERROR: '+m+'<br><button class="btn" onclick="errb.style.display=\'none\';run('+i+')">REINTENTAR ESTE PASO</button>'
      +' <a class="btn dim" href="<?php echo di_h($cfg['baseurl']); ?>/install/" target="_blank">ABRIR /install/</a>'; }
  function run(i){
    if(i>=steps.length){put(ts()+'  INSTALACIÓN FINALIZADA.');next.style.display='inline-block';setTimeout(function(){location.href='?paso=finalizar';},900);return;}
    var s=steps[i];
    put(ts()+'  > '+s[0]+': '+s[1]+' ...');
    t0=Date.now();
    timer=setInterval(function(){ replaceLast(ts()+'  > '+s[0]+': '+s[1]+' ... trabajando ('+fmt(Math.round((Date.now()-t0)/1000))+')'); },1000);
    fetch('<?php echo DI_SELF; ?>?ajax=instalar&sub='+s[0],{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){ stopT();
        if(d.ok){put('          [ OK ] '+d.msg);run(i+1);}
        else{put('          [FAIL] '+d.msg);fail(i,d.msg);}
      })
      .catch(function(e){ stopT(); put('  !! red: '+e);fail(i,'Fallo de red: '+e);});
  }
  // Reanudación: arrancar tras el último subpaso completado (tras un F5).
  var startIdx=0;
  for(var k=0;k<steps.length;k++){ if(steps[k][0]===DONE){ startIdx=k+1; break; } }
  put(ts()+'  iniciando secuencia de instalación'+(startIdx>0?' (reanudando tras '+DONE+')':'')+' ...');
  run(startIdx);
</script>
<?php
    di_footer();
    exit;
}

if ($paso === 'redir') {
    $cfg = di_load_config();
    $base = $cfg && !empty($cfg['baseurl']) ? rtrim($cfg['baseurl'], '/') : rtrim(di_self_base_url(), '/');
    di_header('Lanzar', 'redir');
    ?>
<div class="win"><div class="t">EXTRACCIÓN COMPLETA — LANZANDO ASISTENTE DE DOLIBARR</div><div class="b">
<pre class="log" id="log"></pre>
<div class="row"><span></span><a class="btn amber" id="go" href="<?php echo di_h($base); ?>/install/index.php">IR AL ASISTENTE &gt;</a></div>
</div></div>
<script>
  var log=document.getElementById('log'),cur=null,target=<?php echo json_encode($base . '/install/index.php'); ?>;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function put(s){ if(cur){cur.remove();cur=null;} log.insertAdjacentText('beforeend',s+'\n'); cur=document.createElement('span'); cur.className='cursor'; log.appendChild(cur); log.scrollTop=log.scrollHeight; }
  put(ts()+'  htdocs desplegado y conf.php preparado.');
  put(ts()+'  retirando installer.php y .zip ...');
  fetch('<?php echo DI_SELF; ?>?ajax=limpiar',{cache:'no-store'})
    .then(function(r){return r.json();})
    .then(function(d){ if(d&&d.appurl){target=d.appurl;} put(ts()+'  listo. redirigiendo al asistente nativo ...'); setTimeout(function(){location.href=target;},1300); })
    .catch(function(e){ put(ts()+'  (no se pudo limpiar: borra installer.php a mano) redirigiendo ...'); setTimeout(function(){location.href=target;},1500); });
</script>
<?php
    di_footer();
    exit;
}

if ($paso === 'finalizar') {
    $cfg = di_load_config();
    $base = $cfg && !empty($cfg['baseurl']) ? rtrim($cfg['baseurl'], '/') : rtrim(di_self_base_url(), '/');
    $appurl = $base . '/';
    di_header('Listo', 'finalizar');
    ?>
<div class="win"><div class="t">INSTALACIÓN COMPLETADA</div><div class="b">
<pre class="banner" style="color:var(--grn)">  ___  _  __
 / _ \| |/ /     dolibarr operativo
| | | | ' /
| |_| | . \      <?php echo di_h($appurl); ?>

 \___/|_|\_\     usuario: <?php echo di_h($cfg['admin']['login'] ?? 'admin'); ?></pre>
<div class="msg ok" style="margin-top:14px">Por seguridad, pulsa LIMPIAR para borrar el instalador, el ZIP y el directorio install/.</div>
<pre class="log" id="log" style="height:120px"></pre>
<div class="row">
    <a class="btn dim" href="<?php echo di_h($appurl); ?>" target="_blank">ABRIR DOLIBARR</a>
    <button class="btn amber" id="clean" onclick="limpiar()">LIMPIAR Y ENTRAR &gt;</button>
</div>
</div></div>
<script>
  var log=document.getElementById('log'),cur=null,appurl=<?php echo json_encode($appurl); ?>;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function put(s){ if(cur){cur.remove();cur=null;} log.insertAdjacentText('beforeend',s+'\n'); cur=document.createElement('span'); cur.className='cursor'; log.appendChild(cur); log.scrollTop=log.scrollHeight; }
  function limpiar(){
    var b=document.getElementById('clean');b.disabled=true;b.textContent='LIMPIANDO...';
    put(ts()+'  borrando install/, .zip e installer.php ...');
    fetch('<?php echo DI_SELF; ?>?ajax=limpiar',{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){ if(d&&d.appurl){appurl=d.appurl;} put(ts()+'  instalador eliminado. redirigiendo ...'); setTimeout(function(){location.href=appurl;},1300); })
      .catch(function(e){ put(ts()+'  (limpieza manual necesaria) '+e); });
  }
</script>
<?php
    di_footer();
    exit;
}

// Fallback
header('Location: ' . DI_SELF . '?paso=bienvenida');
exit;
