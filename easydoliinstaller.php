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
 *  IDIOMAS: interfaz autocontenida en EN/ES/DE/FR/IT con selector (cookie); detecta
 *  el idioma del navegador. COMPATIBILIDAD: probado en PHP 7.4 hasta 8.3.
 *
 *  Licencia: GPL-3.0-or-later (igual que Dolibarr).
 * ============================================================================
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

define('DI_VERSION', '1.6.1');
define('DI_DIR', __DIR__);
define('DI_SELF', basename(__FILE__));
define('DI_TMPDIR', DI_DIR . '/__doli_installer_tmp__');
define('DI_CONFIG', DI_TMPDIR . '/config.php');     // .php con guardia: no servible como datos
define('DI_CONFIG_MARK', '###EDI-JSON###');         // separador cabecera-PHP / JSON
define('DI_CONFIG_TTL', 7200);                      // caduca la config a las 2h (instalador olvidado)
define('DI_COOKIES', DI_TMPDIR . '/cookies.txt');
define('DI_LOG', DI_TMPDIR . '/install.log');

define('DI_PHP_MIN', '7.1.0');                 // mínimo que exige Dolibarr 23
define('DI_EXTRACT_CHUNK', 2500);              // entradas del ZIP por petición AJAX (extracción nativa)

/* ===========================================================================
 *  i18n — TRADUCCIONES AUTOCONTENIDAS (en, es, de, fr, it)
 * ======================================================================== */

/** Idiomas de interfaz soportados. */
function di_langs()
{
    return array('en' => 'English', 'es' => 'Español', 'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano');
}

/** Idioma de interfaz actual: ?ui=xx (persistido en cookie) > cookie > navegador > es. */
function di_ui_lang()
{
    static $l = null;
    if ($l !== null) {
        return $l;
    }
    $sup = array_keys(di_langs());
    if (isset($_GET['ui']) && in_array($_GET['ui'], $sup, true)) {
        $l = $_GET['ui'];
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? '') == 443);
            @setcookie('edi_ui', $l, array(
                'expires' => time() + 86400, 'path' => '/',
                'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure,
            ));
        }
        $_COOKIE['edi_ui'] = $l;
        return $l;
    }
    if (isset($_COOKIE['edi_ui']) && in_array($_COOKIE['edi_ui'], $sup, true)) {
        return $l = $_COOKIE['edi_ui'];
    }
    $al = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 0, 2));
    return $l = (in_array($al, $sup, true) ? $al : 'es');
}

/** Traduce una clave al idioma actual. $rep = reemplazos de marcadores {x}. */
function di_t($key, $rep = array())
{
    $d = di_dict();
    $l = di_ui_lang();
    $s = isset($d[$l][$key]) ? $d[$l][$key] : (isset($d['en'][$key]) ? $d['en'][$key] : $key);
    return $rep ? strtr($s, $rep) : $s;
}

/** Diccionario completo. Marcadores: {s} {n} {mb} {ver} {url} {code} {off} {try} {warn} */
function di_dict()
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $d = array(
    'en' => array(
        'topbar_sub' => 'installation terminal', 'lang' => 'lang',
        'foot' => 'if you interrupt the process, delete this file and the .zip manually',
        'st_inicio' => 'start', 'st_paquete' => 'package', 'st_requisitos' => 'requirements',
        'st_config' => 'config', 'st_extraer' => 'extract', 'st_instalar' => 'install',
        'st_listo' => 'done', 'st_lanzar' => 'launch',
        'b_back' => '< BACK', 'b_continue' => 'CONTINUE >', 'b_retry' => 'RETRY', 'b_finish' => 'FINISH >',
        'b_extract' => 'EXTRACT >', 'b_install' => 'INSTALL >', 'b_open' => 'OPEN DOLIBARR',
        'b_clean' => 'CLEAN UP & ENTER >', 'b_go' => 'GO TO WIZARD >',
        'tagline' => '// automatic Dolibarr installer — unpacks and configures everything',
        'w_pkg' => 'PACKAGE', 'w_none' => 'No local ZIP: in the next step you can download any version automatically.',
        'w_one' => 'detected: {s} ({mb} MB) — or download another version in the next step.',
        'w_many' => '{n} packages detected — you will choose which (or download another) in the next step.',
        'w_dest' => 'destination: {s}', 'w_choose' => 'CHOOSE MODE',
        'w_auto_h' => '[ 1 ]  AUTOMATIC INSTALL',
        'w_auto_d' => 'Choose the package (local or download) → creates database + tables + administrator + lock. Zero clicks in the Dolibarr wizard. Self-destructs when finished.',
        'w_simple_h' => '[ 2 ]  EXTRACT ONLY  (expert mode)',
        'w_simple_d' => 'Choose the package (local or download), unpack htdocs and redirect you to the native install/ wizard so you configure it yourself.',
        'req_title' => 'SYSTEM CHECK', 'req_block' => 'Mandatory requirements are missing. Fix them (ask your host) and retry.',
        'req_php' => 'PHP version ≥ {s}', 'req_ext' => 'PHP extension: {s}', 'req_required' => '(required)', 'req_recommended' => '(recommended)',
        'req_dbdrv' => 'Database driver (MySQL and/or PostgreSQL)', 'req_http' => 'cURL or allow_url_fopen (to run the installer)',
        'req_writable' => 'Installation directory writable', 'req_parent' => 'Parent directory writable (for ../documents)',
        'req_zip' => 'Dolibarr ZIP package present', 'req_yes' => 'yes', 'req_no' => 'no', 'req_none' => 'not found',
        'req_npkg' => '{n} packages: {s}',
        'pk_title' => 'DOLIBARR PACKAGE', 'pk_uselocal' => 'use a ZIP already here', 'pk_nonehere' => '(none available)',
        'pk_download' => 'download a version automatically', 'pk_reqcurl' => '(requires cURL)',
        'pk_nozip' => 'No .zip next to the installer.', 'pk_locallabel' => 'local ZIP:', 'pk_chooselocal' => 'choose local ZIP ({n} detected)',
        'pk_verlabel' => 'version to download (official package from sourceforge.net)',
        'pk_vermanual' => 'or type an exact version (x.y.z)', 'pk_optional' => '(optional)',
        'pk_dlhint' => '~85 MB. Downloaded to the server in blocks, with a real progress bar.',
        'pp_title' => 'CHOOSE THE DOLIBARR PACKAGE',
        'pp_intro_simple' => 'simple mode: htdocs is unpacked and we send you to the native install/ wizard',
        'pp_intro_full' => 'automatic install: after choosing the package you will configure database and administrator',
        'dest_title' => 'DESTINATION', 'dest_sub' => 'installation subfolder (optional, empty = here)', 'dest_empty' => '(empty)',
        'cf_chosen' => 'CHOSEN PACKAGE', 'cf_dl' => 'download dolibarr-{ver}.zip', 'cf_undef' => '(undefined)',
        'cf_destarrow' => '→ destination', 'cf_change' => 'change package',
        'cf_db' => 'DATABASE', 'cf_dbtype' => 'database type', 'cf_host' => 'server (host)', 'cf_port' => 'port',
        'cf_dbname' => 'database name', 'cf_prefix' => 'table prefix', 'cf_user' => 'user', 'cf_pass' => 'password',
        'cf_passempty' => '(may be empty in tests)',
        'cf_create' => 'create the database automatically (requires the DBMS admin user)',
        'cf_rootuser' => 'DBMS admin user (root / postgres)', 'cf_rootpass' => 'DBMS admin password',
        'cf_admin' => 'DOLIBARR ADMINISTRATOR', 'cf_login' => 'login',
        'cf_opts' => 'OPTIONS', 'cf_deflang' => 'default language', 'cf_https' => 'force HTTPS',
        'cf_baseurl' => 'detected base URL', 'cf_baseurl_h' => 'public URL of the Dolibarr root; usually correct.',
        'cf_review' => 'Check:',
        'v_dbname' => 'The database name is required.', 'v_dbuser' => 'The database user is required.',
        'v_prefix' => 'The table prefix must be alphanumeric and end with "_" (e.g. llx_).',
        'v_root' => 'To create the database you need the DBMS root/admin user.',
        'v_alogin' => 'The administrator login is required.',
        'v_apass' => 'The administrator password is required (Dolibarr does not allow it empty).',
        'v_achars' => 'The administrator password cannot contain double quotes ("), the characters < > \\, the sequence ../ or HTML entities (&#..., &quot): the Dolibarr installer strips them and would lock you out.',
        'v_ver' => 'Select or type a valid version (format x.y.z) to download.',
        'v_nolocal' => 'There is no local ZIP. Upload a dolibarr-*.zip or choose "Download version".',
        'v_choosezip' => 'Select which of the {n} ZIP packages you want to use.',
        'v_badzip' => 'The ZIP "{s}" does not look like an official Dolibarr package (no "*/htdocs/").',
        'ex_title' => 'EXTRACTING :: {s}',
        'ex_noscript' => 'This wizard needs JavaScript to unpack in blocks. Enable it (or disable the extension blocking fetch) and reload.',
        'ex_dest' => 'destination: {s}', 'ex_opening' => 'opening {s} ...', 'ex_block' => 'block', 'ex_files' => 'files',
        'ex_processed' => '{n} entries processed. moving htdocs -> root ...', 'ex_complete' => 'extraction COMPLETE.',
        'ex_retryblock' => 'RETRY THIS BLOCK',
        'dl_title' => 'DOWNLOADING :: dolibarr-{ver}.zip',
        'dl_noscript' => 'This wizard needs JavaScript to download in blocks. Enable it and reload.',
        'dl_origin' => 'source: sourceforge.net', 'dl_connecting' => 'connecting to sourceforge.net ...',
        'dl_complete' => 'download COMPLETE ({mb}). validating ZIP ...', 'dl_ready' => 'package ready.', 'dl_retry' => 'RETRY',
        'in_title' => 'RUNNING NATIVE INSTALLER (unattended)',
        'in_noscript' => 'This wizard needs JavaScript to run the installation. Enable it and reload, or finish manually at {url}/install/.',
        'in_tables' => '// the tables step can take several minutes on slow hosts; do not close the window',
        'in_s1' => 'create configuration and database', 'in_s2' => 'create tables and reference data',
        'in_s5' => 'create administrator and lock installation',
        'in_starting' => 'starting installation sequence', 'in_resuming' => '(resuming after {s})',
        'in_finished' => 'INSTALLATION FINISHED.', 'in_working' => 'working ({s})',
        'in_retrystep' => 'RETRY THIS STEP', 'in_openinstall' => 'OPEN /install/',
        'rd_title' => 'EXTRACTION COMPLETE — LAUNCHING THE DOLIBARR WIZARD',
        'rd_deployed' => 'htdocs deployed and conf.php prepared.', 'rd_removing' => 'removing installer and .zip ...',
        'rd_redir' => 'done. redirecting to the native wizard ...', 'rd_manual' => '(could not clean up: delete the installer manually) redirecting ...',
        'fn_title' => 'INSTALLATION COMPLETE', 'fn_op' => 'dolibarr up and running', 'fn_user' => 'user:',
        'fn_sec' => 'For security, press CLEAN UP to delete the installer, the ZIP and the install/ directory.',
        'fn_cleaning' => 'CLEANING...', 'fn_deleting' => 'deleting install/, .zip and installer ...',
        'fn_removed' => 'installer deleted. redirecting ...', 'fn_manual' => '(manual cleanup needed)',
        'gi_title' => 'NOTICE', 'gi_msg' => 'Dolibarr seems to be ALREADY installed in this directory (conf/conf.php with data exists).',
        'gi_re' => 'To reinstall from scratch, first delete conf/conf.php and the install.lock file in the documents directory.',
        'err' => 'ERROR:', 'net' => 'network:', 'retrying_block' => 'retrying block (offset {off}, attempt {try}) ...',
        'net_fail' => 'Network failure at offset {off}:',
        'ss_nocontact' => 'Could not reach {url} ({s}).',
        'ss_single' => ' If your server handles one request at a time (php -S, 1 worker), finish at {url}/install/.',
        'ss_s1ok' => 'Configuration created and database connection established.',
        'ss_s1fail' => 'step1 did not write conf.php correctly. ', 'ss_s2fail' => 'step2 failed. ',
        'ss_s2ok' => '{n} tables created and reference data loaded.', 'ss_s2nodrv' => 'Tables created (not verifiable by driver).',
        'ss_s2no' => 'Not all of Dolibarr\'s core tables were created. ',
        'ss_s5ok' => 'Administrator "{s}" created and installation locked.',
        'ss_s5warn' => ' (WARNING: install.lock not found; check and delete /install/ manually)',
        'ss_s5fail' => 'Could not confirm the creation of administrator "{s}". ',
        'ss_blocked' => 'The server replied HTTP {code} to the native installer (possible mod_security/WAF block or server error). Add an exception for /install/ or finish manually at {url}/install/.',
        'ss_emptyresp' => 'Empty response from the server.', 'ss_reported' => 'Dolibarr reported: {s}',
        'ss_checklog' => 'Check the log or run /install/ manually.',
        'e_noinstall' => 'The install/ directory does not exist after extraction.',
        'e_cantopen' => 'Could not open the ZIP: {s}', 'e_blockfail' => 'Failed to extract the block (offset {s}). Disk space or permissions?',
        'e_notfound' => 'Extracted content not found in {s}', 'e_cantread' => 'Could not read the temporary extraction directory.',
        'e_cantmove' => 'Could not move/copy "{s}" to the destination. Check disk space and permissions.',
        'e_needcurl' => 'Automatic download requires the cURL extension.', 'e_cantwrite' => 'Cannot write the download file: {s}',
        'e_dlfail' => 'Download failed: {s}', 'e_unexpected' => 'Unexpected response from the download server (HTTP {code}).',
        'e_noversion' => 'No version selected to download.', 'e_corrupt' => 'The downloaded ZIP is not a valid Dolibarr package (corrupt download). Try again.',
        'e_badhash' => 'Integrity check failed for the downloaded package (version {s}): the SHA-256 does not match. Possible MITM or corrupt mirror. Try again or upload the ZIP manually.',
        'e_noconfig' => 'No saved configuration.', 'e_unknownajax' => 'unknown AJAX action',
        'e_forbidden' => 'Forbidden: this installation is tied to the browser that started it. Reload the installer in that browser, or delete __doli_installer_tmp__ to start over.',
    ),
    'es' => array(
        'topbar_sub' => 'terminal de instalación', 'lang' => 'idioma',
        'foot' => 'si interrumpes el proceso, borra este archivo y el .zip a mano',
        'st_inicio' => 'inicio', 'st_paquete' => 'paquete', 'st_requisitos' => 'requisitos',
        'st_config' => 'config', 'st_extraer' => 'extraer', 'st_instalar' => 'instalar',
        'st_listo' => 'listo', 'st_lanzar' => 'lanzar',
        'b_back' => '< ATRÁS', 'b_continue' => 'CONTINUAR >', 'b_retry' => 'REINTENTAR', 'b_finish' => 'FINALIZAR >',
        'b_extract' => 'EXTRAER >', 'b_install' => 'INSTALAR >', 'b_open' => 'ABRIR DOLIBARR',
        'b_clean' => 'LIMPIAR Y ENTRAR >', 'b_go' => 'IR AL ASISTENTE >',
        'tagline' => '// instalador automático de Dolibarr — descomprime y configura todo',
        'w_pkg' => 'PAQUETE', 'w_none' => 'Sin ZIP local: en el siguiente paso podrás descargar la versión que quieras automáticamente.',
        'w_one' => 'detectado: {s} ({mb} MB) — o descarga otra versión en el siguiente paso.',
        'w_many' => '{n} paquetes detectados — elegirás cuál (o descargarás otro) en el siguiente paso.',
        'w_dest' => 'destino: {s}', 'w_choose' => 'ELIGE MODO',
        'w_auto_h' => '[ 1 ]  INSTALACIÓN AUTOMÁTICA',
        'w_auto_d' => 'Elige el paquete (local o descargar) → crea base de datos + tablas + administrador + bloqueo. Cero clics en el asistente de Dolibarr. Se autodestruye al terminar.',
        'w_simple_h' => '[ 2 ]  SOLO EXTRAER  (modo experto)',
        'w_simple_d' => 'Elige el paquete (local o descargar), descomprime htdocs y te redirige al asistente nativo install/ de Dolibarr para que lo configures tú.',
        'req_title' => 'COMPROBACIÓN DEL SISTEMA', 'req_block' => 'Faltan requisitos obligatorios. Corrígelos (consulta a tu hosting) y reintenta.',
        'req_php' => 'Versión de PHP ≥ {s}', 'req_ext' => 'Extensión PHP: {s}', 'req_required' => '(obligatoria)', 'req_recommended' => '(recomendada)',
        'req_dbdrv' => 'Driver de base de datos (MySQL y/o PostgreSQL)', 'req_http' => 'cURL o allow_url_fopen (para ejecutar el instalador)',
        'req_writable' => 'Directorio de instalación escribible', 'req_parent' => 'Directorio padre escribible (para ../documents)',
        'req_zip' => 'Paquete ZIP de Dolibarr presente', 'req_yes' => 'sí', 'req_no' => 'no', 'req_none' => 'no encontrado',
        'req_npkg' => '{n} paquetes: {s}',
        'pk_title' => 'PAQUETE DE DOLIBARR', 'pk_uselocal' => 'usar un ZIP que ya está aquí', 'pk_nonehere' => '(no hay ninguno)',
        'pk_download' => 'descargar una versión automáticamente', 'pk_reqcurl' => '(requiere cURL)',
        'pk_nozip' => 'No hay ningún .zip junto al instalador.', 'pk_locallabel' => 'ZIP local:', 'pk_chooselocal' => 'elige ZIP local ({n} detectados)',
        'pk_verlabel' => 'versión a descargar (paquete oficial de sourceforge.net)',
        'pk_vermanual' => 'o escribe una versión exacta (x.y.z)', 'pk_optional' => '(opcional)',
        'pk_dlhint' => '~85 MB. Se descarga al servidor por bloques, con barra de progreso real.',
        'pp_title' => 'ELIGE EL PAQUETE DE DOLIBARR',
        'pp_intro_simple' => 'modo ultrasencillo: se descomprime htdocs y te llevamos al asistente nativo install/',
        'pp_intro_full' => 'instalación automática: tras elegir el paquete configurarás base de datos y administrador',
        'dest_title' => 'DESTINO', 'dest_sub' => 'subcarpeta de instalación (opcional, vacío = aquí)', 'dest_empty' => '(vacío)',
        'cf_chosen' => 'PAQUETE ELEGIDO', 'cf_dl' => 'descargar dolibarr-{ver}.zip', 'cf_undef' => '(sin definir)',
        'cf_destarrow' => '→ destino', 'cf_change' => 'cambiar paquete',
        'cf_db' => 'BASE DE DATOS', 'cf_dbtype' => 'tipo de base de datos', 'cf_host' => 'servidor (host)', 'cf_port' => 'puerto',
        'cf_dbname' => 'nombre de la base de datos', 'cf_prefix' => 'prefijo de tablas', 'cf_user' => 'usuario', 'cf_pass' => 'contraseña',
        'cf_passempty' => '(puede ir vacía en pruebas)',
        'cf_create' => 'crear la base de datos automáticamente (requiere usuario administrador del SGBD)',
        'cf_rootuser' => 'usuario admin del SGBD (root / postgres)', 'cf_rootpass' => 'contraseña del admin del SGBD',
        'cf_admin' => 'ADMINISTRADOR DOLIBARR', 'cf_login' => 'login',
        'cf_opts' => 'OPCIONES', 'cf_deflang' => 'idioma por defecto', 'cf_https' => 'forzar HTTPS',
        'cf_baseurl' => 'URL base detectada', 'cf_baseurl_h' => 'URL pública de la raíz de Dolibarr; normalmente correcta.',
        'cf_review' => 'Revisa:',
        'v_dbname' => 'El nombre de la base de datos es obligatorio.', 'v_dbuser' => 'El usuario de la base de datos es obligatorio.',
        'v_prefix' => 'El prefijo de tablas debe ser alfanumérico y terminar en "_" (p. ej. llx_).',
        'v_root' => 'Para crear la base de datos necesitas el usuario root/admin del SGBD.',
        'v_alogin' => 'El login del administrador es obligatorio.',
        'v_apass' => 'La contraseña del administrador es obligatoria (Dolibarr no permite dejarla vacía).',
        'v_achars' => 'La contraseña del administrador no puede contener comillas dobles ("), los caracteres < > \\, la secuencia ../ ni entidades HTML (&#..., &quot): el instalador de Dolibarr los elimina y te dejaría fuera.',
        'v_ver' => 'Selecciona o escribe una versión válida (formato x.y.z) para descargar.',
        'v_nolocal' => 'No hay ningún ZIP local. Sube un dolibarr-*.zip o elige "Descargar versión".',
        'v_choosezip' => 'Selecciona cuál de los {n} paquetes ZIP quieres usar.',
        'v_badzip' => 'El ZIP "{s}" no parece un paquete oficial de Dolibarr (no contiene "*/htdocs/").',
        'ex_title' => 'DESCOMPRIMIENDO :: {s}',
        'ex_noscript' => 'Este asistente necesita JavaScript para descomprimir por bloques. Actívalo (o desactiva la extensión que bloquea fetch) y recarga.',
        'ex_dest' => 'destino: {s}', 'ex_opening' => 'abriendo {s} ...', 'ex_block' => 'bloque', 'ex_files' => 'arch.',
        'ex_processed' => '{n} entradas procesadas. moviendo htdocs -> raíz ...', 'ex_complete' => 'extracción COMPLETA.',
        'ex_retryblock' => 'REINTENTAR ESTE BLOQUE',
        'dl_title' => 'DESCARGANDO :: dolibarr-{ver}.zip',
        'dl_noscript' => 'Este asistente necesita JavaScript para descargar por bloques. Actívalo y recarga.',
        'dl_origin' => 'origen: sourceforge.net', 'dl_connecting' => 'conectando con sourceforge.net ...',
        'dl_complete' => 'descarga COMPLETA ({mb}). validando ZIP ...', 'dl_ready' => 'paquete listo.', 'dl_retry' => 'REINTENTAR',
        'in_title' => 'EJECUTANDO INSTALADOR NATIVO (desatendido)',
        'in_noscript' => 'Este asistente necesita JavaScript para ejecutar la instalación. Actívalo y recarga, o termina manualmente en {url}/install/.',
        'in_tables' => '// el paso de tablas puede tardar varios minutos en hostings lentos; no cierres la ventana',
        'in_s1' => 'crear configuración y base de datos', 'in_s2' => 'crear tablas y datos de referencia',
        'in_s5' => 'crear administrador y bloquear instalación',
        'in_starting' => 'iniciando secuencia de instalación', 'in_resuming' => '(reanudando tras {s})',
        'in_finished' => 'INSTALACIÓN FINALIZADA.', 'in_working' => 'trabajando ({s})',
        'in_retrystep' => 'REINTENTAR ESTE PASO', 'in_openinstall' => 'ABRIR /install/',
        'rd_title' => 'EXTRACCIÓN COMPLETA — LANZANDO EL ASISTENTE DE DOLIBARR',
        'rd_deployed' => 'htdocs desplegado y conf.php preparado.', 'rd_removing' => 'retirando el instalador y el .zip ...',
        'rd_redir' => 'listo. redirigiendo al asistente nativo ...', 'rd_manual' => '(no se pudo limpiar: borra el instalador a mano) redirigiendo ...',
        'fn_title' => 'INSTALACIÓN COMPLETADA', 'fn_op' => 'dolibarr operativo', 'fn_user' => 'usuario:',
        'fn_sec' => 'Por seguridad, pulsa LIMPIAR para borrar el instalador, el ZIP y el directorio install/.',
        'fn_cleaning' => 'LIMPIANDO...', 'fn_deleting' => 'borrando install/, .zip e instalador ...',
        'fn_removed' => 'instalador eliminado. redirigiendo ...', 'fn_manual' => '(limpieza manual necesaria)',
        'gi_title' => 'AVISO', 'gi_msg' => 'Parece que Dolibarr YA está instalado en este directorio (existe conf/conf.php con datos).',
        'gi_re' => 'Para reinstalar desde cero, borra antes conf/conf.php y el archivo install.lock del directorio de documentos.',
        'err' => 'ERROR:', 'net' => 'red:', 'retrying_block' => 'reintentando bloque (offset {off}, intento {try}) ...',
        'net_fail' => 'Fallo de red en offset {off}:',
        'ss_nocontact' => 'No se pudo contactar con {url} ({s}).',
        'ss_single' => ' Si tu servidor atiende una sola petición a la vez (php -S, 1 worker), termina en {url}/install/.',
        'ss_s1ok' => 'Configuración creada y conexión a la base de datos establecida.',
        'ss_s1fail' => 'step1 no escribió conf.php correctamente. ', 'ss_s2fail' => 'step2 falló. ',
        'ss_s2ok' => '{n} tablas creadas y datos de referencia cargados.', 'ss_s2nodrv' => 'Tablas creadas (no verificable por driver).',
        'ss_s2no' => 'No se crearon todas las tablas básicas de Dolibarr. ',
        'ss_s5ok' => 'Administrador "{s}" creado e instalación bloqueada.',
        'ss_s5warn' => ' (AVISO: no se encontró install.lock; revisa y elimina /install/ a mano)',
        'ss_s5fail' => 'No se pudo confirmar la creación del administrador "{s}". ',
        'ss_blocked' => 'El servidor respondió HTTP {code} al instalador nativo (posible bloqueo de mod_security/WAF o error del servidor). Añade una excepción para /install/ o termina manualmente en {url}/install/.',
        'ss_emptyresp' => 'Respuesta vacía del servidor.', 'ss_reported' => 'Dolibarr informó: {s}',
        'ss_checklog' => 'Revisa el log o ejecuta /install/ manualmente.',
        'e_noinstall' => 'No existe el directorio install/ tras la extracción.',
        'e_cantopen' => 'No se pudo abrir el ZIP: {s}', 'e_blockfail' => 'Fallo al extraer el bloque (offset {s}). ¿Espacio en disco o permisos?',
        'e_notfound' => 'No se encontró el contenido extraído en {s}', 'e_cantread' => 'No se pudo leer el directorio temporal de extracción.',
        'e_cantmove' => 'No se pudo mover/copiar "{s}" al destino. Revisa espacio en disco y permisos.',
        'e_needcurl' => 'La descarga automática requiere la extensión cURL.', 'e_cantwrite' => 'No se puede escribir el archivo de descarga: {s}',
        'e_dlfail' => 'Descarga fallida: {s}', 'e_unexpected' => 'Respuesta inesperada del servidor de descargas (HTTP {code}).',
        'e_noversion' => 'No hay versión seleccionada para descargar.', 'e_corrupt' => 'El ZIP descargado no es un paquete Dolibarr válido (descarga corrupta). Reinténtalo.',
        'e_badhash' => 'Falló la verificación de integridad del paquete descargado (versión {s}): el SHA-256 no coincide. Posible MITM o mirror corrupto. Reinténtalo o sube el ZIP a mano.',
        'e_noconfig' => 'No hay configuración guardada.', 'e_unknownajax' => 'acción AJAX desconocida',
        'e_forbidden' => 'Prohibido: esta instalación está atada al navegador que la inició. Recarga el instalador en ese navegador, o borra __doli_installer_tmp__ para empezar de nuevo.',
    ),
    'de' => array(
        'topbar_sub' => 'Installationsterminal', 'lang' => 'Sprache',
        'foot' => 'wenn Sie den Vorgang abbrechen, löschen Sie diese Datei und die .zip manuell',
        'st_inicio' => 'Start', 'st_paquete' => 'Paket', 'st_requisitos' => 'Anforderungen',
        'st_config' => 'Konfig', 'st_extraer' => 'Entpacken', 'st_instalar' => 'Installieren',
        'st_listo' => 'Fertig', 'st_lanzar' => 'Starten',
        'b_back' => '< ZURÜCK', 'b_continue' => 'WEITER >', 'b_retry' => 'WIEDERHOLEN', 'b_finish' => 'FERTIG >',
        'b_extract' => 'ENTPACKEN >', 'b_install' => 'INSTALLIEREN >', 'b_open' => 'DOLIBARR ÖFFNEN',
        'b_clean' => 'AUFRÄUMEN & ÖFFNEN >', 'b_go' => 'ZUM ASSISTENTEN >',
        'tagline' => '// automatischer Dolibarr-Installer — entpackt und konfiguriert alles',
        'w_pkg' => 'PAKET', 'w_none' => 'Kein lokales ZIP: im nächsten Schritt können Sie jede Version automatisch herunterladen.',
        'w_one' => 'erkannt: {s} ({mb} MB) — oder im nächsten Schritt eine andere Version herunterladen.',
        'w_many' => '{n} Pakete erkannt — Sie wählen im nächsten Schritt welches (oder laden ein anderes herunter).',
        'w_dest' => 'Ziel: {s}', 'w_choose' => 'MODUS WÄHLEN',
        'w_auto_h' => '[ 1 ]  AUTOMATISCHE INSTALLATION',
        'w_auto_d' => 'Wählen Sie das Paket (lokal oder Download) → erstellt Datenbank + Tabellen + Administrator + Sperre. Null Klicks im Dolibarr-Assistenten. Löscht sich am Ende selbst.',
        'w_simple_h' => '[ 2 ]  NUR ENTPACKEN  (Expertenmodus)',
        'w_simple_d' => 'Wählen Sie das Paket (lokal oder Download), entpackt htdocs und leitet Sie zum nativen install/-Assistenten weiter, damit Sie es selbst konfigurieren.',
        'req_title' => 'SYSTEMPRÜFUNG', 'req_block' => 'Pflichtanforderungen fehlen. Beheben Sie sie (fragen Sie Ihren Hoster) und versuchen Sie es erneut.',
        'req_php' => 'PHP-Version ≥ {s}', 'req_ext' => 'PHP-Erweiterung: {s}', 'req_required' => '(erforderlich)', 'req_recommended' => '(empfohlen)',
        'req_dbdrv' => 'Datenbanktreiber (MySQL und/oder PostgreSQL)', 'req_http' => 'cURL oder allow_url_fopen (zum Ausführen des Installers)',
        'req_writable' => 'Installationsverzeichnis beschreibbar', 'req_parent' => 'Übergeordnetes Verzeichnis beschreibbar (für ../documents)',
        'req_zip' => 'Dolibarr-ZIP-Paket vorhanden', 'req_yes' => 'ja', 'req_no' => 'nein', 'req_none' => 'nicht gefunden',
        'req_npkg' => '{n} Pakete: {s}',
        'pk_title' => 'DOLIBARR-PAKET', 'pk_uselocal' => 'ein bereits vorhandenes ZIP verwenden', 'pk_nonehere' => '(keines vorhanden)',
        'pk_download' => 'eine Version automatisch herunterladen', 'pk_reqcurl' => '(erfordert cURL)',
        'pk_nozip' => 'Keine .zip neben dem Installer.', 'pk_locallabel' => 'lokales ZIP:', 'pk_chooselocal' => 'lokales ZIP wählen ({n} erkannt)',
        'pk_verlabel' => 'herunterzuladende Version (offizielles Paket von sourceforge.net)',
        'pk_vermanual' => 'oder genaue Version eingeben (x.y.z)', 'pk_optional' => '(optional)',
        'pk_dlhint' => '~85 MB. Wird in Blöcken auf den Server geladen, mit echter Fortschrittsanzeige.',
        'pp_title' => 'WÄHLEN SIE DAS DOLIBARR-PAKET',
        'pp_intro_simple' => 'einfacher Modus: htdocs wird entpackt und wir leiten Sie zum nativen install/-Assistenten',
        'pp_intro_full' => 'automatische Installation: nach Wahl des Pakets konfigurieren Sie Datenbank und Administrator',
        'dest_title' => 'ZIEL', 'dest_sub' => 'Installations-Unterordner (optional, leer = hier)', 'dest_empty' => '(leer)',
        'cf_chosen' => 'GEWÄHLTES PAKET', 'cf_dl' => 'dolibarr-{ver}.zip herunterladen', 'cf_undef' => '(nicht definiert)',
        'cf_destarrow' => '→ Ziel', 'cf_change' => 'Paket ändern',
        'cf_db' => 'DATENBANK', 'cf_dbtype' => 'Datenbanktyp', 'cf_host' => 'Server (Host)', 'cf_port' => 'Port',
        'cf_dbname' => 'Datenbankname', 'cf_prefix' => 'Tabellenpräfix', 'cf_user' => 'Benutzer', 'cf_pass' => 'Passwort',
        'cf_passempty' => '(darf in Tests leer sein)',
        'cf_create' => 'Datenbank automatisch erstellen (erfordert den DBMS-Admin-Benutzer)',
        'cf_rootuser' => 'DBMS-Admin-Benutzer (root / postgres)', 'cf_rootpass' => 'DBMS-Admin-Passwort',
        'cf_admin' => 'DOLIBARR-ADMINISTRATOR', 'cf_login' => 'Login',
        'cf_opts' => 'OPTIONEN', 'cf_deflang' => 'Standardsprache', 'cf_https' => 'HTTPS erzwingen',
        'cf_baseurl' => 'erkannte Basis-URL', 'cf_baseurl_h' => 'öffentliche URL des Dolibarr-Stamms; normalerweise korrekt.',
        'cf_review' => 'Prüfen:',
        'v_dbname' => 'Der Datenbankname ist erforderlich.', 'v_dbuser' => 'Der Datenbankbenutzer ist erforderlich.',
        'v_prefix' => 'Das Tabellenpräfix muss alphanumerisch sein und mit "_" enden (z. B. llx_).',
        'v_root' => 'Zum Erstellen der Datenbank benötigen Sie den DBMS-root/admin-Benutzer.',
        'v_alogin' => 'Der Administrator-Login ist erforderlich.',
        'v_apass' => 'Das Administrator-Passwort ist erforderlich (Dolibarr erlaubt es nicht leer).',
        'v_achars' => 'Das Administrator-Passwort darf keine doppelten Anführungszeichen ("), die Zeichen < > \\, die Sequenz ../ oder HTML-Entitäten (&#..., &quot) enthalten: der Dolibarr-Installer entfernt sie und würde Sie aussperren.',
        'v_ver' => 'Wählen oder geben Sie eine gültige Version (Format x.y.z) zum Download ein.',
        'v_nolocal' => 'Es gibt kein lokales ZIP. Laden Sie eine dolibarr-*.zip hoch oder wählen Sie "Version herunterladen".',
        'v_choosezip' => 'Wählen Sie, welches der {n} ZIP-Pakete Sie verwenden möchten.',
        'v_badzip' => 'Das ZIP "{s}" sieht nicht wie ein offizielles Dolibarr-Paket aus (kein "*/htdocs/").',
        'ex_title' => 'ENTPACKEN :: {s}',
        'ex_noscript' => 'Dieser Assistent benötigt JavaScript zum blockweisen Entpacken. Aktivieren Sie es (oder deaktivieren Sie die fetch-blockierende Erweiterung) und laden Sie neu.',
        'ex_dest' => 'Ziel: {s}', 'ex_opening' => 'öffne {s} ...', 'ex_block' => 'Block', 'ex_files' => 'Dat.',
        'ex_processed' => '{n} Einträge verarbeitet. verschiebe htdocs -> Stamm ...', 'ex_complete' => 'Entpacken ABGESCHLOSSEN.',
        'ex_retryblock' => 'DIESEN BLOCK WIEDERHOLEN',
        'dl_title' => 'HERUNTERLADEN :: dolibarr-{ver}.zip',
        'dl_noscript' => 'Dieser Assistent benötigt JavaScript zum blockweisen Herunterladen. Aktivieren Sie es und laden Sie neu.',
        'dl_origin' => 'Quelle: sourceforge.net', 'dl_connecting' => 'verbinde mit sourceforge.net ...',
        'dl_complete' => 'Download ABGESCHLOSSEN ({mb}). prüfe ZIP ...', 'dl_ready' => 'Paket bereit.', 'dl_retry' => 'WIEDERHOLEN',
        'in_title' => 'NATIVER INSTALLER LÄUFT (unbeaufsichtigt)',
        'in_noscript' => 'Dieser Assistent benötigt JavaScript für die Installation. Aktivieren Sie es und laden Sie neu, oder beenden Sie manuell unter {url}/install/.',
        'in_tables' => '// der Tabellenschritt kann auf langsamen Hosts mehrere Minuten dauern; Fenster nicht schließen',
        'in_s1' => 'Konfiguration und Datenbank erstellen', 'in_s2' => 'Tabellen und Referenzdaten erstellen',
        'in_s5' => 'Administrator erstellen und Installation sperren',
        'in_starting' => 'starte Installationssequenz', 'in_resuming' => '(Fortsetzung nach {s})',
        'in_finished' => 'INSTALLATION ABGESCHLOSSEN.', 'in_working' => 'arbeite ({s})',
        'in_retrystep' => 'DIESEN SCHRITT WIEDERHOLEN', 'in_openinstall' => '/install/ ÖFFNEN',
        'rd_title' => 'ENTPACKEN ABGESCHLOSSEN — STARTE DEN DOLIBARR-ASSISTENTEN',
        'rd_deployed' => 'htdocs bereitgestellt und conf.php vorbereitet.', 'rd_removing' => 'entferne Installer und .zip ...',
        'rd_redir' => 'fertig. leite zum nativen Assistenten weiter ...', 'rd_manual' => '(Aufräumen fehlgeschlagen: Installer manuell löschen) leite weiter ...',
        'fn_title' => 'INSTALLATION ABGESCHLOSSEN', 'fn_op' => 'dolibarr läuft', 'fn_user' => 'Benutzer:',
        'fn_sec' => 'Aus Sicherheitsgründen AUFRÄUMEN drücken, um Installer, ZIP und das install/-Verzeichnis zu löschen.',
        'fn_cleaning' => 'RÄUME AUF...', 'fn_deleting' => 'lösche install/, .zip und Installer ...',
        'fn_removed' => 'Installer gelöscht. leite weiter ...', 'fn_manual' => '(manuelles Aufräumen nötig)',
        'gi_title' => 'HINWEIS', 'gi_msg' => 'Dolibarr scheint in diesem Verzeichnis BEREITS installiert zu sein (conf/conf.php mit Daten vorhanden).',
        'gi_re' => 'Für eine Neuinstallation löschen Sie zuerst conf/conf.php und die Datei install.lock im documents-Verzeichnis.',
        'err' => 'FEHLER:', 'net' => 'Netz:', 'retrying_block' => 'wiederhole Block (Offset {off}, Versuch {try}) ...',
        'net_fail' => 'Netzwerkfehler bei Offset {off}:',
        'ss_nocontact' => '{url} nicht erreichbar ({s}).',
        'ss_single' => ' Wenn Ihr Server eine Anfrage gleichzeitig bearbeitet (php -S, 1 Worker), beenden Sie unter {url}/install/.',
        'ss_s1ok' => 'Konfiguration erstellt und Datenbankverbindung hergestellt.',
        'ss_s1fail' => 'step1 hat conf.php nicht korrekt geschrieben. ', 'ss_s2fail' => 'step2 fehlgeschlagen. ',
        'ss_s2ok' => '{n} Tabellen erstellt und Referenzdaten geladen.', 'ss_s2nodrv' => 'Tabellen erstellt (per Treiber nicht prüfbar).',
        'ss_s2no' => 'Es wurden nicht alle Kerntabellen von Dolibarr erstellt. ',
        'ss_s5ok' => 'Administrator "{s}" erstellt und Installation gesperrt.',
        'ss_s5warn' => ' (WARNUNG: install.lock nicht gefunden; /install/ manuell prüfen und löschen)',
        'ss_s5fail' => 'Erstellung des Administrators "{s}" konnte nicht bestätigt werden. ',
        'ss_blocked' => 'Der Server antwortete HTTP {code} an den nativen Installer (mögliche mod_security/WAF-Sperre oder Serverfehler). Fügen Sie eine Ausnahme für /install/ hinzu oder beenden Sie manuell unter {url}/install/.',
        'ss_emptyresp' => 'Leere Antwort vom Server.', 'ss_reported' => 'Dolibarr meldete: {s}',
        'ss_checklog' => 'Prüfen Sie das Log oder führen Sie /install/ manuell aus.',
        'e_noinstall' => 'Das install/-Verzeichnis existiert nach dem Entpacken nicht.',
        'e_cantopen' => 'ZIP konnte nicht geöffnet werden: {s}', 'e_blockfail' => 'Block konnte nicht entpackt werden (Offset {s}). Speicherplatz oder Rechte?',
        'e_notfound' => 'Entpackter Inhalt nicht gefunden in {s}', 'e_cantread' => 'Temporäres Entpackverzeichnis konnte nicht gelesen werden.',
        'e_cantmove' => '"{s}" konnte nicht ans Ziel verschoben/kopiert werden. Speicherplatz und Rechte prüfen.',
        'e_needcurl' => 'Der automatische Download erfordert die cURL-Erweiterung.', 'e_cantwrite' => 'Download-Datei nicht beschreibbar: {s}',
        'e_dlfail' => 'Download fehlgeschlagen: {s}', 'e_unexpected' => 'Unerwartete Antwort vom Download-Server (HTTP {code}).',
        'e_noversion' => 'Keine Version zum Download ausgewählt.', 'e_corrupt' => 'Das heruntergeladene ZIP ist kein gültiges Dolibarr-Paket (beschädigter Download). Erneut versuchen.',
        'e_badhash' => 'Integritätsprüfung des heruntergeladenen Pakets fehlgeschlagen (Version {s}): SHA-256 stimmt nicht überein. Möglicher MITM oder beschädigter Mirror. Erneut versuchen oder ZIP manuell hochladen.',
        'e_noconfig' => 'Keine gespeicherte Konfiguration.', 'e_unknownajax' => 'unbekannte AJAX-Aktion',
        'e_forbidden' => 'Verboten: diese Installation ist an den Browser gebunden, der sie gestartet hat. Laden Sie den Installer in diesem Browser neu, oder löschen Sie __doli_installer_tmp__, um neu zu beginnen.',
    ),
    'fr' => array(
        'topbar_sub' => 'terminal d\'installation', 'lang' => 'langue',
        'foot' => 'si vous interrompez le processus, supprimez ce fichier et le .zip manuellement',
        'st_inicio' => 'début', 'st_paquete' => 'paquet', 'st_requisitos' => 'prérequis',
        'st_config' => 'config', 'st_extraer' => 'extraire', 'st_instalar' => 'installer',
        'st_listo' => 'terminé', 'st_lanzar' => 'lancer',
        'b_back' => '< RETOUR', 'b_continue' => 'CONTINUER >', 'b_retry' => 'RÉESSAYER', 'b_finish' => 'TERMINER >',
        'b_extract' => 'EXTRAIRE >', 'b_install' => 'INSTALLER >', 'b_open' => 'OUVRIR DOLIBARR',
        'b_clean' => 'NETTOYER & ENTRER >', 'b_go' => 'ALLER À L\'ASSISTANT >',
        'tagline' => '// installateur automatique de Dolibarr — décompresse et configure tout',
        'w_pkg' => 'PAQUET', 'w_none' => 'Pas de ZIP local : à l\'étape suivante vous pourrez télécharger la version souhaitée automatiquement.',
        'w_one' => 'détecté : {s} ({mb} Mo) — ou téléchargez une autre version à l\'étape suivante.',
        'w_many' => '{n} paquets détectés — vous choisirez lequel (ou en téléchargerez un autre) à l\'étape suivante.',
        'w_dest' => 'destination : {s}', 'w_choose' => 'CHOISIR LE MODE',
        'w_auto_h' => '[ 1 ]  INSTALLATION AUTOMATIQUE',
        'w_auto_d' => 'Choisissez le paquet (local ou téléchargement) → crée base de données + tables + administrateur + verrou. Zéro clic dans l\'assistant Dolibarr. S\'autodétruit à la fin.',
        'w_simple_h' => '[ 2 ]  EXTRAIRE SEULEMENT  (mode expert)',
        'w_simple_d' => 'Choisissez le paquet (local ou téléchargement), décompresse htdocs et vous redirige vers l\'assistant natif install/ de Dolibarr pour le configurer vous-même.',
        'req_title' => 'VÉRIFICATION DU SYSTÈME', 'req_block' => 'Des prérequis obligatoires manquent. Corrigez-les (demandez à votre hébergeur) et réessayez.',
        'req_php' => 'Version de PHP ≥ {s}', 'req_ext' => 'Extension PHP : {s}', 'req_required' => '(obligatoire)', 'req_recommended' => '(recommandée)',
        'req_dbdrv' => 'Pilote de base de données (MySQL et/ou PostgreSQL)', 'req_http' => 'cURL ou allow_url_fopen (pour exécuter l\'installateur)',
        'req_writable' => 'Répertoire d\'installation accessible en écriture', 'req_parent' => 'Répertoire parent accessible en écriture (pour ../documents)',
        'req_zip' => 'Paquet ZIP de Dolibarr présent', 'req_yes' => 'oui', 'req_no' => 'non', 'req_none' => 'introuvable',
        'req_npkg' => '{n} paquets : {s}',
        'pk_title' => 'PAQUET DOLIBARR', 'pk_uselocal' => 'utiliser un ZIP déjà présent', 'pk_nonehere' => '(aucun disponible)',
        'pk_download' => 'télécharger une version automatiquement', 'pk_reqcurl' => '(nécessite cURL)',
        'pk_nozip' => 'Aucun .zip à côté de l\'installateur.', 'pk_locallabel' => 'ZIP local :', 'pk_chooselocal' => 'choisir le ZIP local ({n} détectés)',
        'pk_verlabel' => 'version à télécharger (paquet officiel de sourceforge.net)',
        'pk_vermanual' => 'ou saisissez une version exacte (x.y.z)', 'pk_optional' => '(facultatif)',
        'pk_dlhint' => '~85 Mo. Téléchargé sur le serveur par blocs, avec une vraie barre de progression.',
        'pp_title' => 'CHOISISSEZ LE PAQUET DOLIBARR',
        'pp_intro_simple' => 'mode ultra-simple : htdocs est décompressé et nous vous menons à l\'assistant natif install/',
        'pp_intro_full' => 'installation automatique : après avoir choisi le paquet, vous configurerez base de données et administrateur',
        'dest_title' => 'DESTINATION', 'dest_sub' => 'sous-dossier d\'installation (facultatif, vide = ici)', 'dest_empty' => '(vide)',
        'cf_chosen' => 'PAQUET CHOISI', 'cf_dl' => 'télécharger dolibarr-{ver}.zip', 'cf_undef' => '(non défini)',
        'cf_destarrow' => '→ destination', 'cf_change' => 'changer de paquet',
        'cf_db' => 'BASE DE DONNÉES', 'cf_dbtype' => 'type de base de données', 'cf_host' => 'serveur (hôte)', 'cf_port' => 'port',
        'cf_dbname' => 'nom de la base de données', 'cf_prefix' => 'préfixe des tables', 'cf_user' => 'utilisateur', 'cf_pass' => 'mot de passe',
        'cf_passempty' => '(peut être vide en test)',
        'cf_create' => 'créer la base de données automatiquement (nécessite l\'utilisateur admin du SGBD)',
        'cf_rootuser' => 'utilisateur admin du SGBD (root / postgres)', 'cf_rootpass' => 'mot de passe admin du SGBD',
        'cf_admin' => 'ADMINISTRATEUR DOLIBARR', 'cf_login' => 'identifiant',
        'cf_opts' => 'OPTIONS', 'cf_deflang' => 'langue par défaut', 'cf_https' => 'forcer HTTPS',
        'cf_baseurl' => 'URL de base détectée', 'cf_baseurl_h' => 'URL publique de la racine de Dolibarr ; normalement correcte.',
        'cf_review' => 'Vérifiez :',
        'v_dbname' => 'Le nom de la base de données est obligatoire.', 'v_dbuser' => 'L\'utilisateur de la base de données est obligatoire.',
        'v_prefix' => 'Le préfixe des tables doit être alphanumérique et finir par "_" (ex. llx_).',
        'v_root' => 'Pour créer la base de données, vous avez besoin de l\'utilisateur root/admin du SGBD.',
        'v_alogin' => 'L\'identifiant de l\'administrateur est obligatoire.',
        'v_apass' => 'Le mot de passe de l\'administrateur est obligatoire (Dolibarr ne l\'autorise pas vide).',
        'v_achars' => 'Le mot de passe de l\'administrateur ne peut pas contenir de guillemets doubles ("), les caractères < > \\, la séquence ../ ni d\'entités HTML (&#..., &quot) : l\'installateur Dolibarr les supprime et vous bloquerait.',
        'v_ver' => 'Sélectionnez ou saisissez une version valide (format x.y.z) à télécharger.',
        'v_nolocal' => 'Il n\'y a pas de ZIP local. Téléversez un dolibarr-*.zip ou choisissez "Télécharger une version".',
        'v_choosezip' => 'Sélectionnez lequel des {n} paquets ZIP vous voulez utiliser.',
        'v_badzip' => 'Le ZIP "{s}" ne ressemble pas à un paquet officiel Dolibarr (pas de "*/htdocs/").',
        'ex_title' => 'EXTRACTION :: {s}',
        'ex_noscript' => 'Cet assistant a besoin de JavaScript pour décompresser par blocs. Activez-le (ou désactivez l\'extension qui bloque fetch) et rechargez.',
        'ex_dest' => 'destination : {s}', 'ex_opening' => 'ouverture de {s} ...', 'ex_block' => 'bloc', 'ex_files' => 'fich.',
        'ex_processed' => '{n} entrées traitées. déplacement htdocs -> racine ...', 'ex_complete' => 'extraction TERMINÉE.',
        'ex_retryblock' => 'RÉESSAYER CE BLOC',
        'dl_title' => 'TÉLÉCHARGEMENT :: dolibarr-{ver}.zip',
        'dl_noscript' => 'Cet assistant a besoin de JavaScript pour télécharger par blocs. Activez-le et rechargez.',
        'dl_origin' => 'source : sourceforge.net', 'dl_connecting' => 'connexion à sourceforge.net ...',
        'dl_complete' => 'téléchargement TERMINÉ ({mb}). validation du ZIP ...', 'dl_ready' => 'paquet prêt.', 'dl_retry' => 'RÉESSAYER',
        'in_title' => 'EXÉCUTION DE L\'INSTALLATEUR NATIF (sans surveillance)',
        'in_noscript' => 'Cet assistant a besoin de JavaScript pour exécuter l\'installation. Activez-le et rechargez, ou terminez manuellement sur {url}/install/.',
        'in_tables' => '// l\'étape des tables peut prendre plusieurs minutes sur les hébergements lents ; ne fermez pas la fenêtre',
        'in_s1' => 'créer la configuration et la base de données', 'in_s2' => 'créer les tables et les données de référence',
        'in_s5' => 'créer l\'administrateur et verrouiller l\'installation',
        'in_starting' => 'démarrage de la séquence d\'installation', 'in_resuming' => '(reprise après {s})',
        'in_finished' => 'INSTALLATION TERMINÉE.', 'in_working' => 'en cours ({s})',
        'in_retrystep' => 'RÉESSAYER CETTE ÉTAPE', 'in_openinstall' => 'OUVRIR /install/',
        'rd_title' => 'EXTRACTION TERMINÉE — LANCEMENT DE L\'ASSISTANT DOLIBARR',
        'rd_deployed' => 'htdocs déployé et conf.php préparé.', 'rd_removing' => 'suppression de l\'installateur et du .zip ...',
        'rd_redir' => 'prêt. redirection vers l\'assistant natif ...', 'rd_manual' => '(nettoyage impossible : supprimez l\'installateur manuellement) redirection ...',
        'fn_title' => 'INSTALLATION TERMINÉE', 'fn_op' => 'dolibarr opérationnel', 'fn_user' => 'utilisateur :',
        'fn_sec' => 'Par sécurité, appuyez sur NETTOYER pour supprimer l\'installateur, le ZIP et le répertoire install/.',
        'fn_cleaning' => 'NETTOYAGE...', 'fn_deleting' => 'suppression de install/, .zip et installateur ...',
        'fn_removed' => 'installateur supprimé. redirection ...', 'fn_manual' => '(nettoyage manuel nécessaire)',
        'gi_title' => 'AVIS', 'gi_msg' => 'Dolibarr semble DÉJÀ installé dans ce répertoire (conf/conf.php avec données existe).',
        'gi_re' => 'Pour réinstaller de zéro, supprimez d\'abord conf/conf.php et le fichier install.lock du répertoire documents.',
        'err' => 'ERREUR :', 'net' => 'réseau :', 'retrying_block' => 'nouvelle tentative du bloc (offset {off}, essai {try}) ...',
        'net_fail' => 'Échec réseau à l\'offset {off} :',
        'ss_nocontact' => 'Impossible de joindre {url} ({s}).',
        'ss_single' => ' Si votre serveur traite une requête à la fois (php -S, 1 worker), terminez sur {url}/install/.',
        'ss_s1ok' => 'Configuration créée et connexion à la base de données établie.',
        'ss_s1fail' => 'step1 n\'a pas écrit conf.php correctement. ', 'ss_s2fail' => 'step2 a échoué. ',
        'ss_s2ok' => '{n} tables créées et données de référence chargées.', 'ss_s2nodrv' => 'Tables créées (non vérifiable par pilote).',
        'ss_s2no' => 'Toutes les tables de base de Dolibarr n\'ont pas été créées. ',
        'ss_s5ok' => 'Administrateur "{s}" créé et installation verrouillée.',
        'ss_s5warn' => ' (AVERTISSEMENT : install.lock introuvable ; vérifiez et supprimez /install/ manuellement)',
        'ss_s5fail' => 'Impossible de confirmer la création de l\'administrateur "{s}". ',
        'ss_blocked' => 'Le serveur a répondu HTTP {code} à l\'installateur natif (blocage mod_security/WAF possible ou erreur serveur). Ajoutez une exception pour /install/ ou terminez manuellement sur {url}/install/.',
        'ss_emptyresp' => 'Réponse vide du serveur.', 'ss_reported' => 'Dolibarr a signalé : {s}',
        'ss_checklog' => 'Consultez le log ou exécutez /install/ manuellement.',
        'e_noinstall' => 'Le répertoire install/ n\'existe pas après l\'extraction.',
        'e_cantopen' => 'Impossible d\'ouvrir le ZIP : {s}', 'e_blockfail' => 'Échec d\'extraction du bloc (offset {s}). Espace disque ou permissions ?',
        'e_notfound' => 'Contenu extrait introuvable dans {s}', 'e_cantread' => 'Impossible de lire le répertoire temporaire d\'extraction.',
        'e_cantmove' => 'Impossible de déplacer/copier "{s}" vers la destination. Vérifiez l\'espace disque et les permissions.',
        'e_needcurl' => 'Le téléchargement automatique nécessite l\'extension cURL.', 'e_cantwrite' => 'Impossible d\'écrire le fichier de téléchargement : {s}',
        'e_dlfail' => 'Échec du téléchargement : {s}', 'e_unexpected' => 'Réponse inattendue du serveur de téléchargement (HTTP {code}).',
        'e_noversion' => 'Aucune version sélectionnée à télécharger.', 'e_corrupt' => 'Le ZIP téléchargé n\'est pas un paquet Dolibarr valide (téléchargement corrompu). Réessayez.',
        'e_badhash' => 'Échec de la vérification d\'intégrité du paquet téléchargé (version {s}) : le SHA-256 ne correspond pas. MITM possible ou miroir corrompu. Réessayez ou téléversez le ZIP manuellement.',
        'e_noconfig' => 'Aucune configuration enregistrée.', 'e_unknownajax' => 'action AJAX inconnue',
        'e_forbidden' => 'Interdit : cette installation est liée au navigateur qui l\'a lancée. Rechargez l\'installateur dans ce navigateur, ou supprimez __doli_installer_tmp__ pour recommencer.',
    ),
    'it' => array(
        'topbar_sub' => 'terminale di installazione', 'lang' => 'lingua',
        'foot' => 'se interrompi il processo, elimina questo file e lo .zip manualmente',
        'st_inicio' => 'inizio', 'st_paquete' => 'pacchetto', 'st_requisitos' => 'requisiti',
        'st_config' => 'config', 'st_extraer' => 'estrai', 'st_instalar' => 'installa',
        'st_listo' => 'fatto', 'st_lanzar' => 'avvia',
        'b_back' => '< INDIETRO', 'b_continue' => 'CONTINUA >', 'b_retry' => 'RIPROVA', 'b_finish' => 'FINE >',
        'b_extract' => 'ESTRAI >', 'b_install' => 'INSTALLA >', 'b_open' => 'APRI DOLIBARR',
        'b_clean' => 'PULISCI ED ENTRA >', 'b_go' => 'VAI ALLA PROCEDURA >',
        'tagline' => '// installer automatico di Dolibarr — decomprime e configura tutto',
        'w_pkg' => 'PACCHETTO', 'w_none' => 'Nessuno ZIP locale: nel passaggio successivo potrai scaricare automaticamente la versione che vuoi.',
        'w_one' => 'rilevato: {s} ({mb} MB) — oppure scarica un\'altra versione nel passaggio successivo.',
        'w_many' => '{n} pacchetti rilevati — sceglierai quale (o ne scaricherai un altro) nel passaggio successivo.',
        'w_dest' => 'destinazione: {s}', 'w_choose' => 'SCEGLI MODALITÀ',
        'w_auto_h' => '[ 1 ]  INSTALLAZIONE AUTOMATICA',
        'w_auto_d' => 'Scegli il pacchetto (locale o download) → crea database + tabelle + amministratore + blocco. Zero clic nella procedura Dolibarr. Si autodistrugge alla fine.',
        'w_simple_h' => '[ 2 ]  SOLO ESTRAI  (modalità esperto)',
        'w_simple_d' => 'Scegli il pacchetto (locale o download), decomprime htdocs e ti reindirizza alla procedura nativa install/ di Dolibarr per configurarla tu.',
        'req_title' => 'CONTROLLO DEL SISTEMA', 'req_block' => 'Mancano requisiti obbligatori. Correggili (chiedi al tuo host) e riprova.',
        'req_php' => 'Versione di PHP ≥ {s}', 'req_ext' => 'Estensione PHP: {s}', 'req_required' => '(obbligatoria)', 'req_recommended' => '(consigliata)',
        'req_dbdrv' => 'Driver del database (MySQL e/o PostgreSQL)', 'req_http' => 'cURL o allow_url_fopen (per eseguire l\'installer)',
        'req_writable' => 'Directory di installazione scrivibile', 'req_parent' => 'Directory superiore scrivibile (per ../documents)',
        'req_zip' => 'Pacchetto ZIP di Dolibarr presente', 'req_yes' => 'sì', 'req_no' => 'no', 'req_none' => 'non trovato',
        'req_npkg' => '{n} pacchetti: {s}',
        'pk_title' => 'PACCHETTO DOLIBARR', 'pk_uselocal' => 'usa uno ZIP già presente', 'pk_nonehere' => '(nessuno disponibile)',
        'pk_download' => 'scarica una versione automaticamente', 'pk_reqcurl' => '(richiede cURL)',
        'pk_nozip' => 'Nessuno .zip accanto all\'installer.', 'pk_locallabel' => 'ZIP locale:', 'pk_chooselocal' => 'scegli ZIP locale ({n} rilevati)',
        'pk_verlabel' => 'versione da scaricare (pacchetto ufficiale da sourceforge.net)',
        'pk_vermanual' => 'oppure scrivi una versione esatta (x.y.z)', 'pk_optional' => '(facoltativo)',
        'pk_dlhint' => '~85 MB. Scaricato sul server a blocchi, con barra di avanzamento reale.',
        'pp_title' => 'SCEGLI IL PACCHETTO DOLIBARR',
        'pp_intro_simple' => 'modalità ultrasemplice: htdocs viene decompresso e ti portiamo alla procedura nativa install/',
        'pp_intro_full' => 'installazione automatica: dopo aver scelto il pacchetto configurerai database e amministratore',
        'dest_title' => 'DESTINAZIONE', 'dest_sub' => 'sottocartella di installazione (facoltativa, vuota = qui)', 'dest_empty' => '(vuota)',
        'cf_chosen' => 'PACCHETTO SCELTO', 'cf_dl' => 'scarica dolibarr-{ver}.zip', 'cf_undef' => '(non definito)',
        'cf_destarrow' => '→ destinazione', 'cf_change' => 'cambia pacchetto',
        'cf_db' => 'DATABASE', 'cf_dbtype' => 'tipo di database', 'cf_host' => 'server (host)', 'cf_port' => 'porta',
        'cf_dbname' => 'nome del database', 'cf_prefix' => 'prefisso tabelle', 'cf_user' => 'utente', 'cf_pass' => 'password',
        'cf_passempty' => '(può essere vuota in test)',
        'cf_create' => 'crea il database automaticamente (richiede l\'utente amministratore del DBMS)',
        'cf_rootuser' => 'utente admin del DBMS (root / postgres)', 'cf_rootpass' => 'password admin del DBMS',
        'cf_admin' => 'AMMINISTRATORE DOLIBARR', 'cf_login' => 'login',
        'cf_opts' => 'OPZIONI', 'cf_deflang' => 'lingua predefinita', 'cf_https' => 'forza HTTPS',
        'cf_baseurl' => 'URL base rilevato', 'cf_baseurl_h' => 'URL pubblico della radice di Dolibarr; di solito corretto.',
        'cf_review' => 'Controlla:',
        'v_dbname' => 'Il nome del database è obbligatorio.', 'v_dbuser' => 'L\'utente del database è obbligatorio.',
        'v_prefix' => 'Il prefisso delle tabelle deve essere alfanumerico e finire con "_" (es. llx_).',
        'v_root' => 'Per creare il database serve l\'utente root/admin del DBMS.',
        'v_alogin' => 'Il login dell\'amministratore è obbligatorio.',
        'v_apass' => 'La password dell\'amministratore è obbligatoria (Dolibarr non la consente vuota).',
        'v_achars' => 'La password dell\'amministratore non può contenere virgolette doppie ("), i caratteri < > \\, la sequenza ../ né entità HTML (&#..., &quot): l\'installer di Dolibarr le rimuove e ti escluderebbe.',
        'v_ver' => 'Seleziona o scrivi una versione valida (formato x.y.z) da scaricare.',
        'v_nolocal' => 'Non c\'è nessuno ZIP locale. Carica un dolibarr-*.zip o scegli "Scarica versione".',
        'v_choosezip' => 'Seleziona quale dei {n} pacchetti ZIP vuoi usare.',
        'v_badzip' => 'Lo ZIP "{s}" non sembra un pacchetto ufficiale Dolibarr (manca "*/htdocs/").',
        'ex_title' => 'DECOMPRESSIONE :: {s}',
        'ex_noscript' => 'Questa procedura ha bisogno di JavaScript per decomprimere a blocchi. Attivalo (o disattiva l\'estensione che blocca fetch) e ricarica.',
        'ex_dest' => 'destinazione: {s}', 'ex_opening' => 'apertura di {s} ...', 'ex_block' => 'blocco', 'ex_files' => 'file',
        'ex_processed' => '{n} voci elaborate. sposto htdocs -> radice ...', 'ex_complete' => 'decompressione COMPLETATA.',
        'ex_retryblock' => 'RIPROVA QUESTO BLOCCO',
        'dl_title' => 'DOWNLOAD :: dolibarr-{ver}.zip',
        'dl_noscript' => 'Questa procedura ha bisogno di JavaScript per scaricare a blocchi. Attivalo e ricarica.',
        'dl_origin' => 'origine: sourceforge.net', 'dl_connecting' => 'connessione a sourceforge.net ...',
        'dl_complete' => 'download COMPLETATO ({mb}). validazione ZIP ...', 'dl_ready' => 'pacchetto pronto.', 'dl_retry' => 'RIPROVA',
        'in_title' => 'ESECUZIONE INSTALLER NATIVO (non presidiata)',
        'in_noscript' => 'Questa procedura ha bisogno di JavaScript per eseguire l\'installazione. Attivalo e ricarica, oppure termina manualmente su {url}/install/.',
        'in_tables' => '// il passaggio delle tabelle può richiedere alcuni minuti su host lenti; non chiudere la finestra',
        'in_s1' => 'crea configurazione e database', 'in_s2' => 'crea tabelle e dati di riferimento',
        'in_s5' => 'crea amministratore e blocca installazione',
        'in_starting' => 'avvio della sequenza di installazione', 'in_resuming' => '(ripresa dopo {s})',
        'in_finished' => 'INSTALLAZIONE TERMINATA.', 'in_working' => 'in corso ({s})',
        'in_retrystep' => 'RIPROVA QUESTO PASSAGGIO', 'in_openinstall' => 'APRI /install/',
        'rd_title' => 'DECOMPRESSIONE COMPLETATA — AVVIO DELLA PROCEDURA DOLIBARR',
        'rd_deployed' => 'htdocs distribuito e conf.php preparato.', 'rd_removing' => 'rimozione dell\'installer e dello .zip ...',
        'rd_redir' => 'fatto. reindirizzamento alla procedura nativa ...', 'rd_manual' => '(impossibile pulire: elimina l\'installer a mano) reindirizzamento ...',
        'fn_title' => 'INSTALLAZIONE COMPLETATA', 'fn_op' => 'dolibarr operativo', 'fn_user' => 'utente:',
        'fn_sec' => 'Per sicurezza, premi PULISCI per eliminare l\'installer, lo ZIP e la directory install/.',
        'fn_cleaning' => 'PULIZIA...', 'fn_deleting' => 'eliminazione di install/, .zip e installer ...',
        'fn_removed' => 'installer eliminato. reindirizzamento ...', 'fn_manual' => '(pulizia manuale necessaria)',
        'gi_title' => 'AVVISO', 'gi_msg' => 'Dolibarr sembra GIÀ installato in questa directory (esiste conf/conf.php con dati).',
        'gi_re' => 'Per reinstallare da zero, elimina prima conf/conf.php e il file install.lock nella directory dei documenti.',
        'err' => 'ERRORE:', 'net' => 'rete:', 'retrying_block' => 'nuovo tentativo blocco (offset {off}, tentativo {try}) ...',
        'net_fail' => 'Errore di rete all\'offset {off}:',
        'ss_nocontact' => 'Impossibile contattare {url} ({s}).',
        'ss_single' => ' Se il tuo server gestisce una richiesta alla volta (php -S, 1 worker), termina su {url}/install/.',
        'ss_s1ok' => 'Configurazione creata e connessione al database stabilita.',
        'ss_s1fail' => 'step1 non ha scritto conf.php correttamente. ', 'ss_s2fail' => 'step2 non riuscito. ',
        'ss_s2ok' => '{n} tabelle create e dati di riferimento caricati.', 'ss_s2nodrv' => 'Tabelle create (non verificabile dal driver).',
        'ss_s2no' => 'Non sono state create tutte le tabelle di base di Dolibarr. ',
        'ss_s5ok' => 'Amministratore "{s}" creato e installazione bloccata.',
        'ss_s5warn' => ' (AVVISO: install.lock non trovato; controlla ed elimina /install/ a mano)',
        'ss_s5fail' => 'Impossibile confermare la creazione dell\'amministratore "{s}". ',
        'ss_blocked' => 'Il server ha risposto HTTP {code} all\'installer nativo (possibile blocco mod_security/WAF o errore del server). Aggiungi un\'eccezione per /install/ o termina manualmente su {url}/install/.',
        'ss_emptyresp' => 'Risposta vuota dal server.', 'ss_reported' => 'Dolibarr ha segnalato: {s}',
        'ss_checklog' => 'Controlla il log o esegui /install/ manualmente.',
        'e_noinstall' => 'La directory install/ non esiste dopo l\'estrazione.',
        'e_cantopen' => 'Impossibile aprire lo ZIP: {s}', 'e_blockfail' => 'Estrazione del blocco non riuscita (offset {s}). Spazio su disco o permessi?',
        'e_notfound' => 'Contenuto estratto non trovato in {s}', 'e_cantread' => 'Impossibile leggere la directory temporanea di estrazione.',
        'e_cantmove' => 'Impossibile spostare/copiare "{s}" nella destinazione. Controlla spazio su disco e permessi.',
        'e_needcurl' => 'Il download automatico richiede l\'estensione cURL.', 'e_cantwrite' => 'Impossibile scrivere il file di download: {s}',
        'e_dlfail' => 'Download non riuscito: {s}', 'e_unexpected' => 'Risposta inattesa dal server di download (HTTP {code}).',
        'e_noversion' => 'Nessuna versione selezionata da scaricare.', 'e_corrupt' => 'Lo ZIP scaricato non è un pacchetto Dolibarr valido (download corrotto). Riprova.',
        'e_badhash' => 'Verifica di integrità fallita per il pacchetto scaricato (versione {s}): lo SHA-256 non corrisponde. Possibile MITM o mirror corrotto. Riprova o carica lo ZIP manualmente.',
        'e_noconfig' => 'Nessuna configurazione salvata.', 'e_unknownajax' => 'azione AJAX sconosciuta',
        'e_forbidden' => 'Vietato: questa installazione è legata al browser che l\'ha avviata. Ricarica l\'installer in quel browser, o elimina __doli_installer_tmp__ per ricominciare.',
    ),
    );
    return $d;
}

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
        // TLS estricto: estas son descargas EXTERNAS (GitHub/SourceForge), no la
        // autollamada local. Verificamos certificado y limitamos redirecciones a HTTPS.
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
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
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $b = @file_get_contents($url, false, $ctx);
        return $b === false ? null : $b;
    }
    return null;
}

/**
 * SHA-256 conocidos de paquetes oficiales (verificación de integridad de la descarga).
 * Si la versión está aquí, la descarga debe coincidir o se rechaza. Versiones no
 * listadas se aceptan solo con TLS verificado + ZIP válido. Ampliable.
 */
function di_known_hashes()
{
    return array(
        '23.0.3' => '40c1c36133aeec69a6c1ca0c00edbed988b1655cc0a2a3fe34d51da1cd8f24e6',
    );
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
        return array('error' => di_t('e_needcurl'));
    }
    $url = di_download_url($cfg['download_version']);
    $file = di_download_target($cfg);
    $chunkSize = 4 * 1024 * 1024; // 4 MB por petición
    $end = $offset + $chunkSize - 1;

    $fp = @fopen($file, $offset === 0 ? 'wb' : 'ab');
    if (!$fp) {
        return array('error' => di_t('e_cantwrite', array('{s}' => $file)));
    }

    $total = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8,
        CURLOPT_RANGE => $offset . '-' . $end,
        CURLOPT_FILE => $fp,
        CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 300,
        // TLS estricto (descarga externa) + redirecciones solo HTTPS: evita que un
        // MITM/mirror sustituya el paquete (lo que sería ejecución de código en el server).
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
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
        return array('error' => di_t('e_dlfail', array('{s}' => $err)));
    }
    if ($code === 200) {
        // El mirror ignoró el Range y envió el archivo completo en esta petición.
        clearstatcache(true, $file);
        $sz = filesize($file);
        return array('next' => $sz, 'total' => $sz, 'done' => true, 'received' => $dl);
    }
    if ($code !== 206) {
        return array('error' => di_t('e_unexpected', array('{code}' => $code)));
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
    // Anti Host-header poisoning: el host acaba grabado en conf.php (dolibarr_main_url_root)
    // y guía las autollamadas. Solo aceptamos un host con forma válida; si HTTP_HOST llega
    // manipulado, caemos a SERVER_NAME y, en último término, a localhost.
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9._\-]+(:[0-9]{1,5})?$/', $host)) {
        $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9._\-]+(:[0-9]{1,5})?$/', $host)) {
            $host = 'localhost';
        }
    }
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
    // Además del null lógico, BORRAMOS físicamente los artefactos con secretos para que
    // no sobrevivan en disco más allá del TTL.
    if (!empty($data['ts']) && (time() - (int) $data['ts']) > DI_CONFIG_TTL) {
        if (!empty($data['target'])) {
            @unlink($data['target'] . '/install/install.forced.php');
        }
        di_rrmdir(DI_TMPDIR);
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
    // Token por instalación: ata las operaciones mutantes al navegador que la inició
    // (anti-CSRF y anti-secuestro de una instalación en curso por un tercero).
    if (empty($cfg['tok'])) {
        $cfg['tok'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : sha1(uniqid('', true) . mt_rand());
    }
    // Guardamos como .php con guardia: si el servidor ignora el .htaccess (Nginx,
    // LiteSpeed, AllowOverride None) y ejecuta el .php, devuelve 403; si lo sirviera
    // como texto, el JSON queda tras un die() y no es trivialmente accesible.
    $body = "<?php http_response_code(403); die('Forbidden: EasyDoliInstaller'); ?>\n"
        . DI_CONFIG_MARK . json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    @file_put_contents(DI_CONFIG, $body);
    @chmod(DI_CONFIG, 0600);
}

/** Emite la cookie con el token de la instalación (HttpOnly, SameSite=Lax). */
function di_set_token_cookie($tok)
{
    if (!$tok || headers_sent()) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    @setcookie('edi_tok', $tok, array(
        'expires' => time() + DI_CONFIG_TTL, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure,
    ));
    $_COOKIE['edi_tok'] = $tok;
}

/**
 * ¿La petición porta el token de ESTA instalación? true si no hay token aún
 * (fase de arranque) o si coincide; false si hay token y no coincide.
 */
function di_token_ok($cfg)
{
    if (!$cfg || empty($cfg['tok'])) {
        return true; // aún no hay instalación atada a un navegador
    }
    $given = $_COOKIE['edi_tok'] ?? ($_POST['tok'] ?? ($_GET['tok'] ?? ''));
    return is_string($given) && hash_equals($cfg['tok'], $given);
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
    @chmod($confFile, 0644); // el propietario (PHP) conserva escritura; sin escritura para grupo/otros

    if (!is_dir($installDir)) {
        return array(false, di_t('e_noinstall'));
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
    // Crear el usuario de BD solo si creamos la BD Y hay contraseña: Dolibarr aborta la
    // creación de usuario con contraseña vacía, y en pruebas se usa un usuario ya existente
    // (p. ej. root sin contraseña), donde basta con crear la base.
    $createUser = (!empty($db['create']) && $db['pass'] !== '');

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
        return array('error' => di_t('e_cantopen', array('{s}' => $cfg['zip'])));
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
            return array('error' => di_t('e_blockfail', array('{s}' => $offset)));
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
        return array(false, di_t('e_notfound', array('{s}' => $src)));
    }
    $target = $cfg['target'];
    if (!is_dir($target)) {
        @mkdir($target, 0755, true);
    }

    $items = scandir($src);
    if ($items === false) {
        return array(false, di_t('e_cantread'));
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
                return array(false, di_t('e_cantmove', array('{s}' => $it)));
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
        return di_t('ss_blocked', array('{code}' => $code, '{url}' => $cfg['baseurl']));
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
    // Defensa en profundidad anti Host-poisoning: no escribir un host con forma rara.
    $wh = (string) parse_url($want, PHP_URL_HOST);
    if ($wh === '' || !preg_match('/^[A-Za-z0-9._\-]+$/', $wh)) {
        return;
    }
    if (!preg_match('/(\$dolibarr_main_url_root\s*=\s*)([\'"])(.*?)\2(\s*;)/', $c, $mm)) {
        return;
    }
    $got = $mm[3];
    $gotHost = strtolower((string) parse_url($got, PHP_URL_HOST));
    $wantHost = strtolower($wh);
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
            return array('ok' => false, 'msg' => di_t('ss_nocontact', array('{url}' => $url, '{s}' => $res['error']))
                . di_t('ss_single', array('{url}' => $cfg['baseurl'])));
        }
        // Verificación: conf.php ahora contiene los datos de conexión.
        $conf = $cfg['target'] . '/conf/conf.php';
        $c = @file_get_contents($conf);
        if ($c && preg_match('/dolibarr_main_db_name\s*=\s*[\'"]' . preg_quote($cfg['db']['name'], '/') . '/', $c)) {
            di_fix_main_url($cfg, $conf, $c);
            @chmod($conf, 0640); // conf.php ya tiene credenciales: cerrar lectura a grupo/otros (efectivo en Linux)
            return array('ok' => true, 'msg' => di_t('ss_s1ok'));
        }
        return array('ok' => false, 'msg' => di_t('ss_s1fail') . di_blocked_hint($cfg, $res));
    }

    if ($sub === 'step2') {
        $url = di_install_url($cfg, 'step2.php');
        $res = di_http($url, array('action' => 'set', 'selectlang' => $lang), 600);
        di_log("step2 http=" . $res['code'] . " err=" . $res['error']);
        if ($res['code'] === 0) {
            return array('ok' => false, 'msg' => di_t('ss_nocontact', array('{url}' => $url, '{s}' => $res['error'])));
        }
        if ((int) $res['code'] >= 400) {
            return array('ok' => false, 'msg' => di_t('ss_s2fail') . di_blocked_hint($cfg, $res));
        }
        $core = di_core_tables_ok($cfg);
        if ($core === true) {
            $n = di_count_tables($cfg);
            return array('ok' => true, 'msg' => di_t('ss_s2ok', array('{n}' => $n)));
        }
        if ($core === null) {
            // Sin driver para verificar: confiamos en la señal del HTML.
            if (stripos($res['body'], 'step4') !== false || stripos($res['body'], 'CreateDatabaseObjects') !== false) {
                return array('ok' => true, 'msg' => di_t('ss_s2nodrv'));
            }
        }
        return array('ok' => false, 'msg' => di_t('ss_s2no') . di_blocked_hint($cfg, $res));
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
            return array('ok' => false, 'msg' => di_t('ss_nocontact', array('{url}' => $url, '{s}' => $res['error'])));
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

        // Borramos el forced (contiene la contraseña root del SGBD) PASE LO QUE PASE:
        // ya no se necesita tras intentar step5, ni en éxito ni en fallo.
        @unlink($cfg['target'] . '/install/install.forced.php');
        if ($adminOk || $lockFresh || (!$ok && $lock)) {
            // Éxito: retiramos cookies/log del instalador nativo (la purga de secretos
            // de config.php se hace en el handler AJAX, que es quien posee $cfg).
            @unlink(DI_COOKIES);
            @unlink(DI_LOG);
            $warn = $lock ? '' : di_t('ss_s5warn');
            return array('ok' => true, 'msg' => di_t('ss_s5ok', array('{s}' => $login)) . $warn);
        }
        return array('ok' => false, 'msg' => di_t('ss_s5fail', array('{s}' => $login)) . di_blocked_hint($cfg, $res));
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
        return di_t('ss_emptyresp');
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
            return di_t('ss_reported', array('{s}' => implode(' | ', array_slice(array_unique($msgs), 0, 3))));
        }
    }
    if (preg_match('#(Fatal error|Parse error|Uncaught)[^<\n]{0,200}#i', $html, $m)) {
        return 'PHP: ' . trim($m[0]);
    }
    return di_t('ss_checklog');
}

/* ===========================================================================
 *  ROUTER AJAX (devuelve JSON)
 * ======================================================================== */

di_ui_lang(); // fija el idioma y la cookie ANTES de cualquier salida

if (isset($_GET['ajax'])) {
    di_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    $ajax = $_GET['ajax'];
    $cfg = di_load_config();

    // Anti-CSRF / anti-secuestro: las acciones mutantes exigen el token de la instalación
    // (cookie puesta en el arranque). 'versiones' no toca estado y queda exenta.
    if (in_array($ajax, array('extraer', 'instalar', 'descargar', 'limpiar'), true) && !di_token_ok($cfg)) {
        http_response_code(403);
        echo json_encode(array('error' => di_t('e_forbidden')));
        exit;
    }

    if ($ajax === 'versiones') {
        echo json_encode(array('versions' => di_fetch_versions()));
        exit;
    }

    if ($ajax === 'descargar') {
        if (!$cfg || empty($cfg['download_version'])) {
            echo json_encode(array('error' => di_t('e_noversion')));
            exit;
        }
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        $r = di_download_chunk($cfg, $offset);
        if (isset($r['error'])) {
            echo json_encode($r);
            exit;
        }
        if ($r['done']) {
            // El ZIP ya está local: verificamos integridad (SHA-256 si la versión es conocida),
            // validamos estructura y fijamos zip + prefix en la config.
            $file = di_download_target($cfg);
            $known = di_known_hashes();
            $ver = $cfg['download_version'];
            if (isset($known[$ver])) {
                $got = @hash_file('sha256', $file);
                if (!$got || !hash_equals($known[$ver], $got)) {
                    @unlink($file);
                    echo json_encode(array('error' => di_t('e_badhash', array('{s}' => $ver))));
                    exit;
                }
            }
            $prefix = di_detect_prefix($file);
            if ($prefix === null) {
                @unlink($file);
                echo json_encode(array('error' => di_t('e_corrupt')));
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
            echo json_encode(array('error' => di_t('e_noconfig')));
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
            echo json_encode(array('ok' => false, 'msg' => di_t('e_noconfig')));
            exit;
        }
        $sub = isset($_GET['sub']) ? $_GET['sub'] : '';
        $r = di_run_substep($cfg, $sub);
        if (!empty($r['ok'])) {
            // Persistimos el progreso para poder reanudar tras un F5.
            $cfg['progress'] = $sub;
            // Tras step5 los secretos ya no hacen falta: los purgamos de config.php.
            if ($sub === 'step5') {
                unset($cfg['db']['pass'], $cfg['db']['rootpass'], $cfg['admin']['pass']);
            }
            di_save_config($cfg);
        }
        echo json_encode($r);
        exit;
    }

    if ($ajax === 'limpiar') {
        // Solo POST: una limpieza es destructiva; rechazar GET corta el CSRF por <img>/navegación.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(array('error' => 'method'));
            exit;
        }
        $cfg = di_load_config();
        $target = $cfg && !empty($cfg['target']) ? $cfg['target'] : DI_DIR;
        $base = $cfg && !empty($cfg['baseurl']) ? rtrim($cfg['baseurl'], '/') : rtrim(di_self_base_url(), '/');
        $mode = $cfg['mode'] ?? 'full';

        // Defensa en profundidad: solo borrar dentro de DI_DIR (nunca rutas externas).
        $rt = realpath($target);
        $bdir = realpath(DI_DIR);
        $inside = ($rt !== false && $bdir !== false && strncmp($rt . DIRECTORY_SEPARATOR, $bdir . DIRECTORY_SEPARATOR, strlen($bdir) + 1) === 0);

        if ($mode === 'simple') {
            // Conservamos install/ y conf.php: el usuario terminará con el asistente nativo.
            $appurl = $base . '/install/index.php';
        } else {
            // Modo automático: install/ ya no hace falta y contiene secretos (forced).
            if ($inside) {
                @di_rrmdir($target . '/install');
            }
            $appurl = $base . '/';
        }
        // En ambos casos borramos el ZIP (siempre dentro de DI_DIR por construcción),
        // los temporales y el propio instalador.
        if ($cfg && !empty($cfg['zip']) && is_file($cfg['zip']) && dirname((string) realpath($cfg['zip'])) === $bdir) {
            @unlink($cfg['zip']);
        }
        @di_rrmdir(DI_TMPDIR);
        // Degradar a inerte ANTES de borrar: si @unlink falla (owner SFTP != PHP), el
        // script ya no opera ni expone nada.
        @file_put_contents(__FILE__, "<?php http_response_code(410); die('EasyDoliInstaller removed'); ");
        @unlink(__FILE__);
        echo json_encode(array('ok' => true, 'appurl' => $appurl, 'selfdeleted' => !file_exists(__FILE__)));
        exit;
    }

    echo json_encode(array('error' => di_t('e_unknownajax')));
    exit;
}

/* ===========================================================================
 *  PROCESADO DEL FORMULARIO DE CONFIGURACIÓN
 * ======================================================================== */

$formError = null;
// ---- PASO PAQUETE: elegir ZIP local o descargar versión (común a ambos modos) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'paquete') {
    $modo = (($_POST['modo'] ?? 'full') === 'simple') ? 'simple' : 'full';
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

    $subpath = preg_replace('#[^A-Za-z0-9_\-/]#', '', trim(str_replace('\\', '/', $_POST['subpath'] ?? ''), '/'));
    $target = DI_DIR . ($subpath !== '' ? '/' . $subpath : '');
    $baseurl = di_self_base_url() . ($subpath !== '' ? '/' . $subpath : '');

    $errs = array();
    if ($pkgsource === 'download') {
        if (!$downloadVer) {
            $errs[] = di_t('v_ver');
        }
    } elseif (!$zip) {
        $errs[] = empty($allZips) ? di_t('v_nolocal') : di_t('v_choosezip', array('{n}' => count($allZips)));
    } elseif (!$prefix) {
        $errs[] = di_t('v_badzip', array('{s}' => basename($zip)));
    }

    if ($errs) {
        $formError = $errs;  // se re-renderiza la página 'paquete' (paso ya = paquete)
    } else {
        di_save_config(array(
            'mode' => $modo,
            'zip' => $zip,
            'prefix' => $prefix,
            'download_version' => $downloadVer,
            'subpath' => $subpath,
            'target' => $target,
            'baseurl' => $baseurl,
        ));
        // Arranque de la instalación: emitimos la cookie con el token recién generado.
        $saved = di_load_config();
        if ($saved) {
            di_set_token_cookie($saved['tok'] ?? '');
        }
        if ($pkgsource === 'download') {
            $next = 'descargar';
        } else {
            $next = ($modo === 'simple') ? 'extraer' : 'requisitos';
        }
        header('Location: ' . DI_SELF . '?paso=' . $next);
        exit;
    }
}

// ---- PASO CONFIG: solo base de datos + administrador (fusiona en la config del paquete) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $cfg = di_load_config();
    if ($cfg && !di_token_ok($cfg)) {
        http_response_code(403);
        di_header(di_t('gi_title'), 'config');
        echo '<div class="win"><div class="t">403</div><div class="b"><div class="msg err">' . di_h(di_t('e_forbidden')) . '</div></div></div>';
        di_footer();
        exit;
    }
    if (!$cfg) {
        header('Location: ' . DI_SELF . '?paso=bienvenida');
        exit;
    }

    $dbType = in_array($_POST['db_type'] ?? 'mysqli', array('mysqli', 'pgsql'), true) ? $_POST['db_type'] : 'mysqli';
    $dbPort = (int) ($_POST['db_port'] ?? 0);
    if ($dbPort <= 0) {
        $dbPort = ($dbType === 'pgsql') ? 5432 : 3306;
    }

    $cfg['lang'] = preg_replace('#[^a-zA-Z_]#', '', $_POST['lang'] ?? 'es_ES');
    $cfg['forcehttps'] = !empty($_POST['forcehttps']);
    if (!empty($_POST['baseurl'])) {
        $cfg['baseurl'] = di_validate_baseurl($_POST['baseurl'], $cfg['baseurl']);
    }
    $cfg['db'] = array(
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
    );
    $cfg['admin'] = array(
        'login' => trim($_POST['admin_login'] ?? 'admin'),
        'pass' => (string) ($_POST['admin_pass'] ?? ''),
    );

    $errs = array();
    if ($cfg['db']['name'] === '') {
        $errs[] = di_t('v_dbname');
    }
    if ($cfg['db']['user'] === '') {
        $errs[] = di_t('v_dbuser');
    }
    if (!preg_match('/^[a-z0-9]+_$/i', $cfg['db']['prefix'])) {
        $errs[] = di_t('v_prefix');
    }
    if ($cfg['db']['create'] && $cfg['db']['rootuser'] === '') {
        $errs[] = di_t('v_root');
    }
    // Nota: la contraseña de la BD PUEDE ir vacía (común en entornos de prueba, p. ej. root local).
    if ($cfg['admin']['login'] === '') {
        $errs[] = di_t('v_alogin');
    }
    if (trim($cfg['admin']['pass']) === '') {
        $errs[] = di_t('v_apass');
    }
    // El instalador nativo de Dolibarr lee 'pass' con GETPOST('pass','alpha'), que ELIMINA
    // "  < > \  ../  y entidades HTML. Si la contraseña los contiene, Dolibarr crearía el
    // admin con OTRA contraseña y no podrías entrar. La rechazamos aquí.
    if (preg_match('#["\\\\<>]|\.\./#', $cfg['admin']['pass'])
        || stripos($cfg['admin']['pass'], '&#') !== false
        || stripos($cfg['admin']['pass'], '&quot') !== false) {
        $errs[] = di_t('v_achars');
    }

    if ($errs) {
        $formError = $errs;
    } else {
        di_save_config($cfg);
        header('Location: ' . DI_SELF . '?paso=extraer');
        exit;
    }
}

/* ===========================================================================
 *  CHEQUEO DE REQUISITOS
 * ======================================================================== */

function di_requisitos()
{
    $yes = di_t('req_yes');
    $no = di_t('req_no');
    $r = array();
    $r[] = array(
        'ok' => version_compare(PHP_VERSION, DI_PHP_MIN, '>='),
        'label' => di_t('req_php', array('{s}' => DI_PHP_MIN)),
        'val' => PHP_VERSION,
        'crit' => true,
    );
    foreach (array('zip', 'mysqli', 'json', 'mbstring', 'xml', 'gd', 'curl') as $ext) {
        $crit = in_array($ext, array('zip', 'json'), true);
        $r[] = array(
            'ok' => extension_loaded($ext),
            'label' => di_t('req_ext', array('{s}' => $ext)) . ' ' . ($crit ? di_t('req_required') : di_t('req_recommended')),
            'val' => extension_loaded($ext) ? $yes : $no,
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
        'label' => di_t('req_dbdrv'),
        'val' => $avail ? implode(' + ', $avail) : $no,
        'crit' => true,
    );
    // curl o allow_url_fopen
    $httpok = function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    $r[] = array('ok' => $httpok, 'label' => di_t('req_http'), 'val' => function_exists('curl_init') ? 'curl' : (ini_get('allow_url_fopen') ? 'fopen' : $no), 'crit' => true);
    // Directorio destino escribible
    $r[] = array('ok' => is_writable(DI_DIR), 'label' => di_t('req_writable'), 'val' => DI_DIR, 'crit' => true);
    // Directorio padre escribible (para crear ../documents)
    $parent = dirname(DI_DIR);
    $r[] = array('ok' => is_writable($parent), 'label' => di_t('req_parent'), 'val' => $parent, 'crit' => false);
    // ZIP presente (uno o varios)
    $zips = di_find_zips();
    $r[] = array(
        'ok' => count($zips) > 0,
        'label' => di_t('req_zip'),
        'val' => count($zips) === 0 ? di_t('req_none')
            : (count($zips) === 1 ? basename($zips[0]) : di_t('req_npkg', array('{n}' => count($zips), '{s}' => implode(', ', array_map('basename', $zips))))),
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
        return array('bienvenida' => 'st_inicio', 'paquete' => 'st_paquete', 'extraer' => 'st_extraer', 'redir' => 'st_lanzar');
    }
    return array(
        'bienvenida' => 'st_inicio', 'paquete' => 'st_paquete', 'requisitos' => 'st_requisitos', 'config' => 'st_config',
        'extraer' => 'st_extraer', 'instalar' => 'st_instalar', 'finalizar' => 'st_listo',
    );
}

function di_header($title, $current = null)
{
    di_security_headers();
    if ($current === null) {
        $current = $_GET['paso'] ?? 'bienvenida';
    }
    $cfg = di_load_config();
    if (!empty($GLOBALS['di_force_mode'])) {
        $mode = $GLOBALS['di_force_mode'];
    } else {
        $mode = ($cfg && !empty($cfg['mode'])) ? $cfg['mode'] : 'full';
    }
    $steps = di_steps_for_mode($mode);
    ?>
<!DOCTYPE html>
<html lang="<?php echo di_ui_lang(); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo di_h($title); ?> :: EasyDoliInstaller</title>
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

  .bar-top{color:var(--grn-dim);text-shadow:none;font-size:12px;border-bottom:1px solid var(--line);padding-bottom:8px;margin-bottom:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;align-items:center}
  .langsw{display:flex;gap:2px}
  .langsw a{color:var(--grn-dim);text-decoration:none;padding:2px 6px;border:1px solid transparent;font-size:11px;letter-spacing:1px}
  .langsw a:hover{color:var(--grn);text-decoration:none}
  .langsw a.on{color:#02110a;background:var(--grn);border-color:var(--grn);text-shadow:none}

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
  .chk{display:flex;align-items:center;gap:9px;margin-top:14px;font-size:13px;cursor:pointer}
  .chk input{width:auto}
  .chk input[type=radio],.chk input[type=checkbox]{width:18px;height:18px;accent-color:var(--grn);cursor:pointer;flex:0 0 auto}
  /* Opciones de origen del paquete: más grandes y toda la fila clicable */
  .pkgopt{font-size:16px;padding:12px 14px;border:1px solid var(--line);background:var(--panel);margin-top:10px}
  .pkgopt:hover{border-color:var(--grn);background:rgba(67,255,125,.05)}
  .pkgopt input[type=radio]{width:22px;height:22px}
  .pkgopt:has(input:checked){border-color:var(--grn);box-shadow:inset 0 0 0 1px var(--grn)}
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
  <span>EasyDoliInstaller v<?php echo DI_VERSION; ?> :: <?php echo di_h(di_t('topbar_sub')); ?></span>
  <span class="langsw">
  <?php
    $qs = $_GET;
    unset($qs['ui']);
    $cur = di_ui_lang();
    foreach (di_langs() as $code => $name) {
        $href = '?' . http_build_query(array_merge($qs, array('ui' => $code)));
        echo '<a href="' . di_h($href) . '"' . ($code === $cur ? ' class="on"' : '') . ' title="' . di_h($name) . '">' . strtoupper($code) . '</a>';
    }
  ?>
  </span>
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
            echo '<span class="' . $cls . '">' . $tag . ' ' . di_h(di_t($steps[$k])) . '</span>';
        }
        echo '</div>';
    } ?>
<?php
}

function di_footer()
{
    ?>
<div class="foot">// EasyDoliInstaller · Easysoft Tech S.L. · GPL-3.0 · <?php echo di_h(di_t('foot')); ?></div>
</div></body></html>
<?php
}

/**
 * Selector de origen del paquete (compartido por los dos formularios):
 * usar un ZIP local o descargar una versión de Dolibarr. Emite su propio <div class="win">.
 */
function di_package_picker($prev, $zips)
{
    // Si venimos de un envío con error, repoblamos con lo enviado ($_POST), no con la config.
    $prevZip = isset($_POST['zipfile']) ? basename((string) $_POST['zipfile']) : basename((string) (($prev['zip'] ?? '')));
    $prevVer = isset($_POST['download_version_manual']) && $_POST['download_version_manual'] !== ''
        ? (string) $_POST['download_version_manual']
        : (isset($_POST['download_version']) ? (string) $_POST['download_version'] : (string) ($prev['download_version'] ?? ''));
    $hasCurl = function_exists('curl_init');
    // Origen por defecto: lo enviado, o local si hay ZIPs y no había versión; si no, descargar.
    if (isset($_POST['pkgsource'])) {
        $src = ($_POST['pkgsource'] === 'download') ? 'download' : 'local';
    } else {
        $src = (!empty($zips) && $prevVer === '') ? 'local' : 'download';
    }
    if (empty($zips) && !$hasCurl) {
        $src = 'local';
    }
    ?>
<div class="win"><div class="t"><?php echo di_h(di_t('pk_title')); ?></div><div class="b">
    <label class="chk pkgopt" for="src_local"><input type="radio" name="pkgsource" id="src_local" value="local" <?php echo $src === 'local' ? 'checked' : ''; ?>> <?php echo di_h(di_t('pk_uselocal')); ?><?php echo empty($zips) ? ' <span class="dim">' . di_h(di_t('pk_nonehere')) . '</span>' : ''; ?></label>
    <label class="chk pkgopt" for="src_dl"><input type="radio" name="pkgsource" id="src_dl" value="download" <?php echo $src === 'download' ? 'checked' : ''; ?> <?php echo $hasCurl ? '' : 'disabled'; ?>> <?php echo di_h(di_t('pk_download')); ?><?php echo $hasCurl ? '' : ' <span class="dim">' . di_h(di_t('pk_reqcurl')) . '</span>'; ?></label>

    <div id="blk_local" style="margin-top:12px">
    <?php if (empty($zips)) { ?>
        <div class="dim"><?php echo di_h(di_t('pk_nozip')); ?></div>
    <?php } elseif (count($zips) === 1) { ?>
        <input type="hidden" name="zipfile" value="<?php echo di_h(basename($zips[0])); ?>">
        <div><?php echo di_h(di_t('pk_locallabel')); ?> <span class="amber"><?php echo di_h(basename($zips[0])); ?></span> <span class="dim">(<?php echo round(filesize($zips[0]) / 1048576); ?> MB)</span></div>
    <?php } else { ?>
        <label class="f"><?php echo di_h(di_t('pk_chooselocal', array('{n}' => count($zips)))); ?></label>
        <select name="zipfile">
            <?php foreach ($zips as $z) {
                $bn = basename($z);
                echo '<option value="' . di_h($bn) . '"' . ($prevZip === $bn ? ' selected' : '') . '>' . di_h($bn) . ' — ' . round(filesize($z) / 1048576) . ' MB</option>';
            } ?>
        </select>
    <?php } ?>
    </div>

    <div id="blk_dl" style="margin-top:12px">
        <label class="f"><?php echo di_h(di_t('pk_verlabel')); ?></label>
        <select name="download_version" id="dlver">
            <?php foreach (di_fallback_versions() as $v) {
                echo '<option value="' . di_h($v) . '"' . ($prevVer === $v ? ' selected' : '') . '>' . di_h($v) . '</option>';
            } ?>
        </select>
        <label class="f"><?php echo di_h(di_t('pk_vermanual')); ?></label>
        <input type="text" name="download_version_manual" value="<?php echo di_h($prevVer); ?>" placeholder="<?php echo di_h(di_t('pk_optional')); ?>">
        <div class="hint"><?php echo di_h(di_t('pk_dlhint')); ?></div>
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
if (di_already_installed($cfgExisting) && !in_array($paso, array('finalizar', 'redir', 'instalar', 'extraer', 'descargar', 'paquete'), true)) {
    di_header(di_t('gi_title'), $paso);
    echo '<div class="win"><div class="t">' . di_h(di_t('gi_title')) . '</div><div class="b">';
    echo '<div class="msg warn">' . di_h(di_t('gi_msg')) . '</div>';
    echo '<p class="dim">' . di_h(di_t('gi_re')) . '</p>';
    echo '<div class="row"><span></span><a class="btn" href="' . di_h(di_self_base_url()) . '/">' . di_h(di_t('b_open')) . ' &gt;</a></div>';
    echo '</div></div>';
    di_footer();
    exit;
}

/* ===========================================================================
 *  PÁGINAS
 * ======================================================================== */

if ($paso === 'bienvenida') {
    di_header(di_t('st_inicio'));
    $zips = di_find_zips();
    ?>
<pre class="banner"> ____   ___  _     ___ ____    _    ____  ____
|  _ \ / _ \| |   |_ _| __ )  / \  |  _ \|  _ \
| | | | | | | |    | ||  _ \ / _ \ | |_) | |_) |
| |_| | |_| | |___ | || |_) / ___ \|  _ &lt;|  _ &lt;
|____/ \___/|_____|___|____/_/   \_\_| \_\_| \_\</pre>
<div class="tagline"><?php echo di_h(di_t('tagline')); ?></div>

<div class="win"><div class="t"><?php echo di_h(di_t('w_pkg')); ?></div><div class="b">
<?php
    if (count($zips) === 0) {
        echo '<div>' . di_h(di_t('w_none')) . '</div>';
    } elseif (count($zips) === 1) {
        echo '<div>' . di_h(di_t('w_one', array('{s}' => basename($zips[0]), '{mb}' => round(filesize($zips[0]) / 1048576)))) . '</div>';
    } else {
        echo '<div>' . di_h(di_t('w_many', array('{n}' => count($zips)))) . '</div>';
    }
    echo '<div class="dim">' . di_h(di_t('w_dest', array('{s}' => DI_DIR))) . '</div>';
    ?>
</div></div>

<div class="win"><div class="t"><?php echo di_h(di_t('w_choose')); ?></div><div class="b">
    <a class="choice" href="?paso=paquete&modo=full">
        <div class="h"><?php echo di_h(di_t('w_auto_h')); ?></div>
        <div class="d"><?php echo di_h(di_t('w_auto_d')); ?></div>
    </a>
    <a class="choice" href="?paso=paquete&modo=simple">
        <div class="h"><?php echo di_h(di_t('w_simple_h')); ?></div>
        <div class="d"><?php echo di_h(di_t('w_simple_d')); ?></div>
    </a>
</div></div>
<?php
    di_footer();
    exit;
}

if ($paso === 'requisitos') {
    di_header(di_t('st_requisitos'));
    $reqs = di_requisitos();
    $blocking = false;
    echo '<div class="win"><div class="t">' . di_h(di_t('req_title')) . '</div><div class="b">';
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
        echo '<div class="msg err">' . di_h(di_t('req_block')) . '</div>';
    }
    echo '<div class="row"><a class="btn dim" href="?paso=paquete&modo=full">' . di_h(di_t('b_back')) . '</a>';
    echo $blocking ? '<a class="btn" href="?paso=requisitos">' . di_h(di_t('b_retry')) . '</a>' : '<a class="btn" href="?paso=config">' . di_h(di_t('b_continue')) . '</a>';
    echo '</div></div></div>';
    di_footer();
    exit;
}

if ($paso === 'paquete') {
    $modo = ((($_POST['modo'] ?? $_GET['modo'] ?? 'full')) === 'simple') ? 'simple' : 'full';
    $GLOBALS['di_force_mode'] = $modo;
    di_header(di_t('st_paquete'), 'paquete');
    $zips = di_find_zips();
    $prev = di_load_config();
    ?>
<div class="win"><div class="t"><?php echo di_h(di_t('pp_title')); ?></div><div class="b">
<div class="dim">// <?php echo di_h($modo === 'simple' ? di_t('pp_intro_simple') : di_t('pp_intro_full')); ?></div>
<?php if (!empty($GLOBALS['formError'])) {
        echo '<div class="msg err" style="margin-top:10px">';
        foreach ($GLOBALS['formError'] as $e) {
            echo '· ' . di_h($e) . '<br>';
        }
        echo '</div>';
    } ?>
</div></div>
<form method="post" action="<?php echo DI_SELF; ?>?paso=paquete&modo=<?php echo $modo; ?>">
    <input type="hidden" name="accion" value="paquete">
    <input type="hidden" name="modo" value="<?php echo $modo; ?>">
    <?php di_package_picker($prev, $zips); ?>
    <div class="win"><div class="t"><?php echo di_h(di_t('dest_title')); ?></div><div class="b">
    <label class="f"><?php echo di_h(di_t('dest_sub')); ?></label>
    <input type="text" name="subpath" value="<?php echo di_h(isset($_POST['subpath']) ? $_POST['subpath'] : ($prev['subpath'] ?? '')); ?>" placeholder="<?php echo di_h(di_t('dest_empty')); ?>">
    </div></div>
    <div class="row">
        <a class="btn dim" href="?paso=bienvenida"><?php echo di_h(di_t('b_back')); ?></a>
        <button class="btn amber" type="submit"><?php echo di_h($modo === 'simple' ? di_t('b_extract') : di_t('b_continue')); ?></button>
    </div>
</form>
<?php
    di_footer();
    exit;
}

if ($paso === 'config') {
    $prev = di_load_config();
    if (!$prev) {
        header('Location: ' . DI_SELF . '?paso=bienvenida');
        exit;
    }
    di_header('Configuración');
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
    // Tras un envío con error NO redirigimos: hay que repoblar los campos con lo que
    // el usuario escribió ($_POST), no con la config guardada. $fv prioriza $_POST.
    $isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
    $fv = function ($postKey, $cfgPath, $def = '') use ($g) {
        return isset($_POST[$postKey]) ? (string) $_POST[$postKey] : $g($cfgPath, $def);
    };
    $fchk = function ($postKey, $cfgPath) use ($g, $isPost) {
        return $isPost ? isset($_POST[$postKey]) : (bool) $g($cfgPath);
    };
    ?>
<?php if (!empty($GLOBALS['formError']) && ($GLOBALS['formMode'] ?? 'full') !== 'simple') {
        echo '<div class="msg err"><b>' . di_h(di_t('cf_review')) . '</b><br>';
        foreach ($GLOBALS['formError'] as $e) {
            echo '· ' . di_h($e) . '<br>';
        }
        echo '</div>';
    } ?>
<?php
    $pkgLabel = $g('download_version', '')
        ? di_h(di_t('cf_dl', array('{ver}' => $g('download_version', ''))))
        : ($g('zip', '') ? di_h(basename($g('zip', ''))) : di_h(di_t('cf_undef')));
    ?>
<div class="win"><div class="t"><?php echo di_h(di_t('cf_chosen')); ?></div><div class="b">
    <div>· <span class="amber"><?php echo $pkgLabel; ?></span> <?php echo di_h(di_t('cf_destarrow')); ?> <span class="dim"><?php echo di_h($g('target', DI_DIR)); ?></span></div>
    <div class="hint"><a href="?paso=paquete&modo=full"><?php echo di_h(di_t('cf_change')); ?></a></div>
</div></div>

<form method="post" action="<?php echo DI_SELF; ?>?paso=config">
<input type="hidden" name="accion" value="guardar">

<div class="win"><div class="t"><?php echo di_h(di_t('cf_db')); ?></div><div class="b">
<?php $dbtype = $fv('db_type', 'db.type', 'mysqli'); ?>
<div class="grid">
    <div><label class="f"><?php echo di_h(di_t('cf_dbtype')); ?></label>
        <select name="db_type" id="db_type">
            <option value="mysqli"<?php echo $dbtype === 'mysqli' ? ' selected' : ''; ?>>MySQL / MariaDB</option>
            <option value="pgsql"<?php echo $dbtype === 'pgsql' ? ' selected' : ''; ?>>PostgreSQL</option>
        </select>
    </div>
    <div><label class="f"><?php echo di_h(di_t('cf_host')); ?></label><input type="text" name="db_host" value="<?php echo di_h($fv('db_host', 'db.host', 'localhost')); ?>"></div>
    <div><label class="f"><?php echo di_h(di_t('cf_port')); ?></label><input type="number" name="db_port" id="db_port" value="<?php echo di_h($fv('db_port', 'db.port', '3306')); ?>"></div>
    <div><label class="f"><?php echo di_h(di_t('cf_dbname')); ?></label><input type="text" name="db_name" value="<?php echo di_h($fv('db_name', 'db.name', 'dolibarr')); ?>"></div>
    <div><label class="f"><?php echo di_h(di_t('cf_prefix')); ?></label><input type="text" name="db_prefix" value="<?php echo di_h($fv('db_prefix', 'db.prefix', 'llx_')); ?>"></div>
    <div><label class="f"><?php echo di_h(di_t('cf_user')); ?></label><input type="text" name="db_user" value="<?php echo di_h($fv('db_user', 'db.user', '')); ?>"></div>
    <div><label class="f"><?php echo di_h(di_t('cf_pass')); ?> <span class="dim"><?php echo di_h(di_t('cf_passempty')); ?></span></label><input type="password" name="db_pass" value="<?php echo di_h($fv('db_pass', 'db.pass', '')); ?>"></div>
</div>
<label class="chk"><input type="checkbox" name="db_create" id="db_create" value="1" <?php echo $fchk('db_create', 'db.create') ? 'checked' : ''; ?>> <?php echo di_h(di_t('cf_create')); ?></label>
<div id="rootbox" style="display:none">
    <div class="grid">
        <div><label class="f"><?php echo di_h(di_t('cf_rootuser')); ?></label><input type="text" name="db_rootuser" value="<?php echo di_h($fv('db_rootuser', 'db.rootuser', 'root')); ?>"></div>
        <div><label class="f"><?php echo di_h(di_t('cf_rootpass')); ?></label><input type="password" name="db_rootpass" value="<?php echo di_h($fv('db_rootpass', 'db.rootpass', '')); ?>"></div>
    </div>
</div>
</div></div>

<div class="win"><div class="t"><?php echo di_h(di_t('cf_admin')); ?></div><div class="b">
<div class="grid">
    <div><label class="f"><?php echo di_h(di_t('cf_login')); ?></label><input type="text" name="admin_login" value="<?php echo di_h($fv('admin_login', 'admin.login', 'admin')); ?>"></div>
    <div><label class="f"><?php echo di_h(di_t('cf_pass')); ?></label><input type="password" name="admin_pass" value="<?php echo di_h($fv('admin_pass', 'admin.pass', '')); ?>"></div>
</div>
</div></div>

<div class="win"><div class="t"><?php echo di_h(di_t('cf_opts')); ?></div><div class="b">
<div class="grid">
    <div><label class="f"><?php echo di_h(di_t('cf_deflang')); ?></label>
        <select name="lang">
        <?php
        $langs = array('es_ES' => 'Español', 'en_US' => 'English', 'fr_FR' => 'Français', 'ca_ES' => 'Català', 'pt_PT' => 'Português', 'de_DE' => 'Deutsch', 'it_IT' => 'Italiano');
        $sel = $fv('lang', 'lang', 'es_ES');
        foreach ($langs as $k => $v) {
            echo '<option value="' . $k . '"' . ($sel === $k ? ' selected' : '') . '>' . $v . '</option>';
        }
        ?>
        </select>
    </div>
    <div class="full"><label class="chk"><input type="checkbox" name="forcehttps" value="1" <?php echo $fchk('forcehttps', 'forcehttps') ? 'checked' : ''; ?>> <?php echo di_h(di_t('cf_https')); ?></label></div>
    <div class="full"><label class="f"><?php echo di_h(di_t('cf_baseurl')); ?></label><input type="text" name="baseurl" value="<?php echo di_h($fv('baseurl', 'baseurl', di_self_base_url())); ?>"><div class="hint"><?php echo di_h(di_t('cf_baseurl_h')); ?></div></div>
</div>
</div></div>

<div class="row">
    <a class="btn dim" href="?paso=requisitos"><?php echo di_h(di_t('b_back')); ?></a>
    <button class="btn amber" type="submit"><?php echo di_h(di_t('b_install')); ?></button>
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
    di_header('Descarga', 'paquete');
    $ver = $cfg['download_version'];
    $nextStep = (($cfg['mode'] ?? 'full') === 'simple') ? 'extraer' : 'requisitos';
    ?>
<div class="win"><div class="t"><?php echo di_h(di_t('dl_title', array('{ver}' => $ver))); ?></div><div class="b">
<div class="pbar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="pbar"><i id="bar"></i><span id="pct">0%</span></div>
<pre class="log" id="log" aria-live="polite"></pre>
<noscript><div class="msg err"><?php echo di_h(di_t('dl_noscript')); ?></div></noscript>
<div class="msg err" id="err" style="display:none" role="alert"></div>
<div class="row"><span class="dim"><?php echo di_h(di_t('dl_origin')); ?></span>
<a class="btn" id="next" style="display:none" href="?paso=<?php echo $nextStep; ?>"><?php echo di_h(di_t('b_continue')); ?></a></div>
</div></div>
<script>
  var T=<?php echo json_encode(array('conn' => di_t('dl_connecting'), 'blk' => di_t('ex_block'), 'comp' => di_t('dl_complete'), 'ready' => di_t('dl_ready'), 'err' => di_t('err'), 'retry' => di_t('dl_retry'), 'net' => di_t('net'), 'rb' => di_t('retrying_block'), 'nf' => di_t('net_fail'))); ?>;
  var log=document.getElementById('log'),bar=document.getElementById('bar'),pct=document.getElementById('pct'),
      pbar=document.getElementById('pbar'),errb=document.getElementById('err'),next=document.getElementById('next'),cur=null,nb=0;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function mb(b){return (b/1048576).toFixed(1)+' MB';}
  function ascii(p){var w=22,f=Math.round(p/100*w);return '['+Array(f+1).join('#')+Array(w-f+1).join('-')+']';}
  function put(s){if(cur){cur.remove();cur=null;}log.insertAdjacentText('beforeend',s+'\n');cur=document.createElement('span');cur.className='cursor';log.appendChild(cur);log.scrollTop=log.scrollHeight;}
  function fail(m,off){errb.style.display='block';errb.innerHTML=T.err+' '+m+'<br><button class="btn" onclick="errb.style.display=\'none\';step('+off+',0)">'+T.retry+'</button>';}
  put(ts()+'  '+T.conn);
  function step(offset,tries){
    tries=tries||0;
    fetch('<?php echo DI_SELF; ?>?ajax=descargar&offset='+offset,{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.error){put('  !! '+d.error);fail(d.error,offset);return;}
        nb++;
        var p=d.total?Math.round(d.next/d.total*100):0;
        bar.style.width=p+'%';pct.textContent=p+'%';pbar.setAttribute('aria-valuenow',p);
        put(ts()+'  '+T.blk+' '+pad(nb,3)+'  '+ascii(p)+' '+p+'%  '+mb(d.next)+' / '+mb(d.total));
        if(d.done){put(ts()+'  '+T.comp.replace('{mb}',mb(d.next)));put(ts()+'  '+T.ready);next.style.display='inline-block';setTimeout(function(){location.href='?paso=<?php echo $nextStep; ?>';},700);}
        else step(d.next,0);
      })
      .catch(function(e){
        if(tries<6){put(ts()+'  '+T.rb.replace('{off}',offset).replace('{try}',tries+1));setTimeout(function(){step(offset,tries+1);},2000*(tries+1));}
        else{put('  !! '+T.net+' '+e);fail(T.nf.replace('{off}',offset)+' '+e,offset);}
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
    di_header(di_t('st_extraer'), 'extraer');
    $mode = $cfg['mode'] ?? 'full';
    $zipname = basename((string) ($cfg['zip'] ?? 'paquete.zip'));
    ?>
<div class="win"><div class="t"><?php echo di_h(di_t('ex_title', array('{s}' => $zipname))); ?></div><div class="b">
<div class="pbar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="pbar"><i id="bar"></i><span id="pct">0%</span></div>
<pre class="log" id="log" aria-live="polite"></pre>
<noscript><div class="msg err"><?php echo di_h(di_t('ex_noscript')); ?></div></noscript>
<div class="msg err" id="err" style="display:none" role="alert"></div>
<div class="row"><span class="dim"><?php echo di_h(di_t('ex_dest', array('{s}' => $cfg['target']))); ?></span>
<a class="btn" id="next" style="display:none" href="<?php echo $mode === 'simple' ? '?paso=redir' : '?paso=instalar'; ?>"><?php echo di_h(di_t('b_continue')); ?></a></div>
</div></div>
<script>
  var MODE=<?php echo json_encode($mode); ?>, ZIP=<?php echo json_encode($zipname); ?>;
  var T=<?php echo json_encode(array('open' => di_t('ex_opening'), 'blk' => di_t('ex_block'), 'files' => di_t('ex_files'), 'proc' => di_t('ex_processed'), 'comp' => di_t('ex_complete'), 'err' => di_t('err'), 'retry' => di_t('ex_retryblock'), 'net' => di_t('net'), 'rb' => di_t('retrying_block'), 'nf' => di_t('net_fail'))); ?>;
  var log=document.getElementById('log'),bar=document.getElementById('bar'),pct=document.getElementById('pct'),
      pbar=document.getElementById('pbar'),errb=document.getElementById('err'),next=document.getElementById('next'),nchunk=0,cur=null;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function ascii(p){var w=22,f=Math.round(p/100*w);return '['+Array(f+1).join('#')+Array(w-f+1).join('-')+']';}
  function put(s){ if(cur){cur.remove();cur=null;} log.insertAdjacentText('beforeend',s+'\n'); cur=document.createElement('span'); cur.className='cursor'; log.appendChild(cur); log.scrollTop=log.scrollHeight; }
  function fail(m,off){errb.style.display='block';errb.innerHTML=T.err+' '+m+'<br><button class="btn" onclick="errb.style.display=\'none\';step('+off+',0)">'+T.retry+'</button>';}
  put(ts()+'  '+T.open.replace('{s}',ZIP));
  function step(offset,tries){
    tries=tries||0;
    fetch('<?php echo DI_SELF; ?>?ajax=extraer&offset='+offset,{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.error){put('  !! '+d.error);fail(d.error,offset);return;}
        nchunk++;
        var p=d.total?Math.round(d.next/d.total*100):100;
        bar.style.width=p+'%';pct.textContent=p+'%';pbar.setAttribute('aria-valuenow',p);
        put(ts()+'  '+T.blk+' '+pad(nchunk,3)+'  '+pad(offset,5)+'-'+pad(d.next,5)+'  '+ascii(p)+' '+p+'%  ('+d.written+' '+T.files+')');
        if(d.done){
          put(ts()+'  '+T.proc.replace('{n}',d.total));
          put(ts()+'  '+T.comp);
          next.style.display='inline-block';
          setTimeout(function(){location.href = MODE==='simple' ? '?paso=redir' : '?paso=instalar';},700);
        } else { step(d.next,0); }
      })
      .catch(function(e){
        if(tries<5){ put(ts()+'  '+T.rb.replace('{off}',offset).replace('{try}',tries+1)); setTimeout(function(){step(offset,tries+1);},1500*(tries+1)); }
        else { put('  !! '+T.net+' '+e); fail(T.nf.replace('{off}',offset)+' '+e,offset); }
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
    di_header(di_t('st_instalar'), 'instalar');
    ?>
<div class="win"><div class="t"><?php echo di_h(di_t('in_title')); ?></div><div class="b">
<pre class="log" id="log" aria-live="polite"></pre>
<noscript><div class="msg err"><?php echo di_h(di_t('in_noscript', array('{url}' => $cfg['baseurl']))); ?></div></noscript>
<div class="msg err" id="err" style="display:none" role="alert"></div>
<div class="row"><span class="dim"><?php echo di_h(di_t('in_tables')); ?></span>
<a class="btn" id="next" style="display:none" href="?paso=finalizar"><?php echo di_h(di_t('b_finish')); ?></a></div>
</div></div>
<script>
  var T=<?php echo json_encode(array('s1' => di_t('in_s1'), 's2' => di_t('in_s2'), 's5' => di_t('in_s5'), 'starting' => di_t('in_starting'), 'resuming' => di_t('in_resuming'), 'finished' => di_t('in_finished'), 'working' => di_t('in_working'), 'retry' => di_t('in_retrystep'), 'openinstall' => di_t('in_openinstall'), 'err' => di_t('err'), 'net' => di_t('net'))); ?>;
  var steps=[['step1',T.s1],['step2',T.s2],['step5',T.s5]];
  var DONE=<?php echo json_encode($cfg['progress'] ?? ''); ?>;
  var log=document.getElementById('log'),errb=document.getElementById('err'),next=document.getElementById('next'),cur=null,timer=null,t0=0;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function fmt(s){var m=Math.floor(s/60),x=s%60;return (m?m+'m ':'')+x+'s';}
  function put(s){ if(cur){cur.remove();cur=null;} log.insertAdjacentText('beforeend',s+'\n'); cur=document.createElement('span'); cur.className='cursor'; log.appendChild(cur); log.scrollTop=log.scrollHeight; }
  function replaceLast(s){ var t=log.textContent; var i=t.lastIndexOf('\n', t.length-2); log.textContent=t.substring(0,i+1); put(s); }
  function stopT(){ if(timer){clearInterval(timer);timer=null;} }
  function fail(i,m){ stopT(); errb.style.display='block';
    errb.innerHTML=T.err+' '+m+'<br><button class="btn" onclick="errb.style.display=\'none\';run('+i+')">'+T.retry+'</button>'
      +' <a class="btn dim" href="<?php echo di_h($cfg['baseurl']); ?>/install/" target="_blank">'+T.openinstall+'</a>'; }
  function run(i){
    if(i>=steps.length){put(ts()+'  '+T.finished);next.style.display='inline-block';setTimeout(function(){location.href='?paso=finalizar';},900);return;}
    var s=steps[i];
    put(ts()+'  > '+s[0]+': '+s[1]+' ...');
    t0=Date.now();
    timer=setInterval(function(){ replaceLast(ts()+'  > '+s[0]+': '+s[1]+' ... '+T.working.replace('{s}',fmt(Math.round((Date.now()-t0)/1000)))); },1000);
    fetch('<?php echo DI_SELF; ?>?ajax=instalar&sub='+s[0],{cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){ stopT();
        if(d.ok){put('          [ OK ] '+d.msg);run(i+1);}
        else{put('          [FAIL] '+d.msg);fail(i,d.msg);}
      })
      .catch(function(e){ stopT(); put('  !! '+T.net+' '+e);fail(i,T.net+' '+e);});
  }
  var startIdx=0;
  for(var k=0;k<steps.length;k++){ if(steps[k][0]===DONE){ startIdx=k+1; break; } }
  put(ts()+'  '+T.starting+(startIdx>0?' '+T.resuming.replace('{s}',DONE):'')+' ...');
  run(startIdx);
</script>
<?php
    di_footer();
    exit;
}

if ($paso === 'redir') {
    $cfg = di_load_config();
    $base = $cfg && !empty($cfg['baseurl']) ? rtrim($cfg['baseurl'], '/') : rtrim(di_self_base_url(), '/');
    di_header(di_t('st_lanzar'), 'redir');
    ?>
<div class="win"><div class="t"><?php echo di_h(di_t('rd_title')); ?></div><div class="b">
<pre class="log" id="log"></pre>
<div class="row"><span></span><a class="btn amber" id="go" href="<?php echo di_h($base); ?>/install/index.php"><?php echo di_h(di_t('b_go')); ?></a></div>
</div></div>
<script>
  var T=<?php echo json_encode(array('dep' => di_t('rd_deployed'), 'rem' => di_t('rd_removing'), 'redir' => di_t('rd_redir'), 'man' => di_t('rd_manual'))); ?>;
  var log=document.getElementById('log'),cur=null,target=<?php echo json_encode($base . '/install/index.php'); ?>;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function put(s){ if(cur){cur.remove();cur=null;} log.insertAdjacentText('beforeend',s+'\n'); cur=document.createElement('span'); cur.className='cursor'; log.appendChild(cur); log.scrollTop=log.scrollHeight; }
  put(ts()+'  '+T.dep);
  put(ts()+'  '+T.rem);
  fetch('<?php echo DI_SELF; ?>?ajax=limpiar',{method:'POST',cache:'no-store'})
    .then(function(r){return r.json();})
    .then(function(d){ if(d&&d.appurl){target=d.appurl;} put(ts()+'  '+T.redir); setTimeout(function(){location.href=target;},1300); })
    .catch(function(e){ put(ts()+'  '+T.man); setTimeout(function(){location.href=target;},1500); });
</script>
<?php
    di_footer();
    exit;
}

if ($paso === 'finalizar') {
    $cfg = di_load_config();
    $base = $cfg && !empty($cfg['baseurl']) ? rtrim($cfg['baseurl'], '/') : rtrim(di_self_base_url(), '/');
    $appurl = $base . '/';
    di_header(di_t('st_listo'), 'finalizar');
    ?>
<div class="win"><div class="t"><?php echo di_h(di_t('fn_title')); ?></div><div class="b">
<pre class="banner" style="color:var(--grn)">  ___  _  __
 / _ \| |/ /     <?php echo di_h(di_t('fn_op')); ?>

| | | | ' /
| |_| | . \      <?php echo di_h($appurl); ?>

 \___/|_|\_\     <?php echo di_h(di_t('fn_user')); ?> <?php echo di_h($cfg['admin']['login'] ?? 'admin'); ?></pre>
<div class="msg ok" style="margin-top:14px"><?php echo di_h(di_t('fn_sec')); ?></div>
<pre class="log" id="log" style="height:120px"></pre>
<div class="row">
    <a class="btn dim" href="<?php echo di_h($appurl); ?>" target="_blank"><?php echo di_h(di_t('b_open')); ?></a>
    <button class="btn amber" id="clean" onclick="limpiar()"><?php echo di_h(di_t('b_clean')); ?></button>
</div>
</div></div>
<script>
  var T=<?php echo json_encode(array('cleaning' => di_t('fn_cleaning'), 'del' => di_t('fn_deleting'), 'removed' => di_t('fn_removed'), 'man' => di_t('fn_manual'))); ?>;
  var log=document.getElementById('log'),cur=null,appurl=<?php echo json_encode($appurl); ?>;
  function pad(n,w){n=''+n;while(n.length<w)n='0'+n;return n;}
  function ts(){var d=new Date();return pad(d.getHours(),2)+':'+pad(d.getMinutes(),2)+':'+pad(d.getSeconds(),2);}
  function put(s){ if(cur){cur.remove();cur=null;} log.insertAdjacentText('beforeend',s+'\n'); cur=document.createElement('span'); cur.className='cursor'; log.appendChild(cur); log.scrollTop=log.scrollHeight; }
  function limpiar(){
    var b=document.getElementById('clean');b.disabled=true;b.textContent=T.cleaning;
    put(ts()+'  '+T.del);
    fetch('<?php echo DI_SELF; ?>?ajax=limpiar',{method:'POST',cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){ if(d&&d.appurl){appurl=d.appurl;} put(ts()+'  '+T.removed); setTimeout(function(){location.href=appurl;},1300); })
      .catch(function(e){ put(ts()+'  '+T.man+' '+e); });
  }
</script>
<?php
    di_footer();
    exit;
}

// Fallback
header('Location: ' . DI_SELF . '?paso=bienvenida');
exit;
