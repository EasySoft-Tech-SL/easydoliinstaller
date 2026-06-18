# Changelog

Todos los cambios notables de EasyDoliInstaller.

## [1.9.2] - 2026-06-18

### Corregido — falsos "ausentes" en reparar
- **`install/` se excluye del cotejo.** Dolibarr elimina (y recomienda borrar) el directorio `install/` tras instalar; compararlo con el paquete oficial marcaba ~1000+ ficheros como *ausentes* en una instalación perfectamente sana. Ahora `install/` se excluye igual que `conf/`, `custom/` y `documents/`, eliminando esos falsos positivos.
- Del apartado de *sobrantes* se excluyen también directorios de control de versiones/artefactos (`.git`, `.svn`, `_repo`).

### Añadido — informe de integridad detallado con diff por líneas
- El informe se muestra **por secciones**: Modificados / Ausentes / Sobrantes, cada una con su lista.
- Cada fichero **modificado es desplegable** y muestra un **diff línea a línea** (`?ajax=diff`): líneas en **rojo = oficial**, **verde = tu instalación**, con números de línea, contexto y agrupado por *hunks*. Guardas para binarios y ficheros muy grandes.

## [1.9.1] - 2026-06-18

### Corregido
- **Selector de versión de descarga**: el campo manual (`download_version_manual`) ya no se pre‑rellena — es un *override* opcional. Antes venía con una versión y, como el handler le da prioridad sobre el desplegable, cambiar el `<select>` no surtía efecto (se descargaba/instalaba la versión del campo manual, no la elegida). Ahora el desplegable es la fuente de verdad y el campo manual solo se usa si lo escribes. La versión preseleccionada (p. ej. la instalada, en *reparar*) se inyecta como opción del desplegable si no está en la lista y se conserva al refrescar la lista en vivo desde GitHub.

## [1.9.0] - 2026-06-17

### Añadido — reparar detecta ficheros inyectados/sobrantes (anti-manipulación)
- El informe de **reparar** ahora incluye un tercer apartado: ficheros que **sobran** en el core (presentes en la instalación pero **no** en el paquete oficial — posible webshell/manipulación o parches locales). Se excluyen `conf/`, `custom/` y `documents/`, los temporales y el propio instalador/paquete.
- Permite **borrar los sobrantes** con confirmación explícita (nunca automático), guardando antes un ZIP de copia. Convierte "reparar" en una verificación de integridad/seguridad real.
- Probado e2e: instalación 3.9.0 con 1 fichero modificado + 1 borrado + 2 inyectados (incl. un `eval($_GET)` en `core/`) → el informe detecta los 4, restaura modificados/ausentes y elimina los 2 sobrantes; `conf/conf.php` intacto.

### Añadido — backup/rollback en PostgreSQL
- El auto‑dump (punto de restauración antes de migrar), el botón de descarga de backup y el camino de copia ahora soportan **PostgreSQL vía `pg_dump`** cuando está disponible en el servidor (detección automática del binario, `proc_open` con `PGPASSWORD`). Si no hay `pg_dump`, se muestra el aviso para hacerlo a mano. (Args de `pg_dump` validados contra PostgreSQL 16.)

### Pulido
- **Aviso de versión no coincidente** en reparar: si el paquete elegido no coincide con la versión instalada (lo que inflaría el diff), se avisa en el informe.
- **Reanudación de la verificación** tras un F5 (continúa desde el último bloque comprobado en vez de reiniciar).
- Más hashes SHA‑256 conocidos para la verificación de integridad de descargas (añadido 3.9.0).
- **CI**: workflow de GitHub Actions que ejecuta `php -l` en PHP 7.4 / 8.1 / 8.3 en cada push/PR.

## [1.8.0] - 2026-06-17

### Añadido — MODO REPARAR (verificar integridad y restaurar)
- Nuevo tercer modo junto a **instalar** y **actualizar**. Sobre un Dolibarr ya instalado, la pantalla de detección ofrece ahora **Abrir / Actualizar / Reparar / Reinstalar**. Reparar:
  - Coteja la instalación **fichero a fichero** con el paquete **OFICIAL de la misma versión** (descargado o ZIP local), por bloques con barra de progreso y log en vivo. Compara por hash el contenido oficial (del ZIP) contra el fichero en disco.
  - Genera un **informe visual** de los ficheros que **difieren** (`~ modificado`) o **faltan** (`+ ausente`). Excluye `conf/`, `custom/` y `documents/` (datos/config del usuario).
  - Permite **descargar un ZIP** con los ficheros afectados (copia previa) y, con **confirmación**, los **restaura** desde el paquete oficial (sobrescribe los modificados y recrea los ausentes). Antes de sobrescribir guarda automáticamente el zip de copia.
- Probado e2e: instalación 3.9.0, manipulación del core (1 fichero modificado + 1 borrado) → el informe detecta ambos, el ZIP de afectados los contiene y la reparación los restaura al estado oficial (md5 coincidente / fichero recreado).

## [1.7.3] - 2026-06-17

### Corregido — la descarga del dump de la BD ya funciona en actualizar
- El botón **"Descargar copia de la base de datos (.sql)"** aparece y **funciona** en el paso de actualizar. Antes mostraba *"inicia la actualización para habilitar el backup"* y el botón no llegaba a aparecer (catch‑22): el token de instalación se emitía después de `di_header()`, con las cabeceras ya enviadas. Ahora el token se siembra al cargar la página —con los datos de BD leídos del `conf.php` existente— **antes** de cualquier salida, de modo que `?ajax=backup` descarga el `.sql` ahí mismo, antes de confirmar.

### Añadido — punto de restauración automático (rollback)
- En la **actualización automática**, el primer subpaso vuelca la BD a `documents/easydoliinstaller-rollback-<bd>.sql` (protegido por web y **persistente**: sobrevive a la autolimpieza del instalador) **antes** de tocar nada. Aparece en el log en vivo y una nota indica el fichero y los pasos de rollback (restaurar ese `.sql` + reponer los ficheros de la versión anterior). Best‑effort para MySQL/MariaDB; en PostgreSQL se omite con aviso (usar `pg_dump`). No se realiza restauración automática destructiva.
- Probado e2e: instalación 3.9.0 + actualización con subpaso `backup` generando el dump (753 KB) en `documents/` y `step5` correcto.

## [1.7.2] - 2026-06-17

### Añadido — requisitos según la versión elegida
- El paso **"Requisitos" ahora es consciente de la versión** seleccionada: lee los requisitos de PHP que el **propio paquete** declara en `install/check.php` (mínimo y, en versiones modernas, máximo) y los compara con el PHP del servidor:
  - **Bloquea** si el PHP del servidor es inferior al mínimo que exige esa versión de Dolibarr.
  - **Avisa** si el PHP es más nuevo que el máximo probado por esa versión.
  - **Avisa** para paquetes muy antiguos (era PHP 5, sin máximo declarado) sobre PHP 7/8 (p. ej. Dolibarr 3.x), anticipando incompatibilidades **antes** de instalar.
  - Muestra la versión detectada y su PHP requerido (p. ej. *"PHP para Dolibarr 23.0.3 (requiere ≥ 7.1.0)"*).
- **Rango soportado Dolibarr v3 – v23** para instalar y actualizar. La instalabilidad real de cada versión depende de que su código sea compatible con el PHP del servidor (p. ej. Dolibarr 3.6 no funciona en PHP 7.x); el paso de requisitos lo anticipa y, si falla, el instalador muestra el motivo exacto.

## [1.7.1] - 2026-06-17

### Corregido — instalación de versiones antiguas de Dolibarr
- **`step1` con campos completos**: el paso 1 ahora envía todos los datos de conexión por POST (`db_type/host/port/name/prefix/user/pass`, `main_dir/main_url`, root y creación de BD). Las versiones **antiguas** (p. ej. 3.9) leen la conexión **solo del POST** — su `install.forced.php` únicamente pre-rellena el formulario, no aplica valores server-side —, así que con `action=set` "a secas" fallaban con *"Field 'Database type/Server/Database name' is required"*. Las versiones modernas leen el POST y, si falta, el forced (el filtro `alpha` conserva puntos/dígitos, p. ej. `127.0.0.1`), por lo que **no hay regresión**.
- **Nombres de script de instalación antiguos**: Dolibarr renombró los pasos de `etapeN.php` (≤ 3.6) a `stepN.php`. El instalador ahora **detecta cuál existe** y usa el correcto (antes daba 404 en paquetes muy antiguos).
- **Mensaje claro de incompatibilidad PHP**: si el paquete elegido es demasiado antiguo para el PHP del servidor (p. ej. Dolibarr 3.6 en PHP 7.x → *"'break' not in the 'loop' or 'switch' context"* en su `adodb-time`), se muestra el fatal real y la pista *"esta versión de Dolibarr probablemente no es compatible con tu PHP X.Y; elige una versión más reciente"* en vez de un críptico "no escribió conf.php".

### Probado (e2e contra Dolibarr real)
- **3.9.0**: instala correctamente en PHP 7.4 (225 tablas, admin, lock).
- **3.6.0**: incompatible con PHP 7.x (fatal en su librería incluida) → ahora con mensaje claro.
- **23.0.3**: sin regresión (272 tablas).

## [1.7.0] - 2026-06-17

### Añadido
- **Modo ACTUALIZAR (upgrade)**: cuando detecta un Dolibarr ya instalado, el asistente ofrece **Abrir / Actualizar / Reinstalar**. La actualización:
  - Descarga (o usa) una versión superior — **nunca inferior**: el selector solo ofrece versiones ≥ la instalada y se valida también la versión del ZIP local (sin downgrade).
  - Sustituye los ficheros **preservando** `conf/` (conf.php), `custom/` (módulos del usuario) y `documents/` (datos).
  - **Dos submodos**: *Automático (del tirón)* — migra N versiones mayores en una sola pasada ejecutando las páginas nativas `upgrade.php` → `upgrade2.php` por cada salto y `step5` al final, con **log en vivo** y verificación de la versión en BD; o *Paso a paso* — sustituye los ficheros y te entrega al asistente nativo de Dolibarr.
  - **Backup opcional**: aviso destacado + botón de descarga de volcado `.sql` (MySQL/MariaDB; PostgreSQL indica `pg_dump`).
  - Detección de la versión instalada por BD (`MAIN_VERSION_LAST_UPGRADE`/`MAIN_VERSION_LAST_INSTALL`) y por ficheros (soporta el `version.inc.php` de v23+ y el `DOL_VERSION` literal antiguo).
  - Los errores SQL por sentencia de las migraciones se tratan como **avisos** (Dolibarr los tolera y continúa, como su asistente nativo); el éxito se decide por la versión final registrada en BD.
- Probado e2e contra Dolibarr real: instalación 23.0.3, **actualización directa v6 → v23 (17 migraciones mayores, 35 subpasos en una pasada)** y v22 → v23 con SQL real, preservando `conf`/`custom`/datos y recreando `install.lock`.

### Seguridad
- **Guardarraíl anti-machaque**: extraer/descargar rechazan (HTTP 409) sobrescribir una instalación existente salvo en modo actualizar o reinstalación **confirmada explícitamente**; la pantalla "ya instalado" obliga a elegir (Reinstalar pide confirmación).

### Correcciones
- Pie de página: licencia mostrada como **MIT** (antes decía GPL-3.0).

## [1.6.1] - 2026-06-17

### Licencia
- **Relicenciado a MIT** (antes GPL-3.0). EasyDoliInstaller es una herramienta independiente: no incorpora código de Dolibarr, por lo que no está sujeto a su copyleft. Dolibarr se sigue distribuyendo aparte bajo GPL-3.0.

### Seguridad
- **Token de instalación (anti-CSRF + anti-secuestro)**: al arrancar se genera un token aleatorio, se guarda en la config y se emite en una cookie `HttpOnly`/`SameSite=Lax`. Las acciones mutantes (`extraer`/`instalar`/`descargar`/`limpiar` y el POST de configuración) exigen ese token → un tercero que no inició la instalación recibe **403**. Cierra el último hallazgo MEDIO de la auditoría (ausencia de autenticación durante la ventana de instalación). El flujo de un solo navegador no cambia (la cookie viaja sola en las peticiones del propio asistente).

## [1.6.0] - 2026-06-17 — Endurecimiento de seguridad

Tras una auditoría de seguridad adversarial (13 hallazgos confirmados):

### Seguridad
- **Descarga verificada (era el único hallazgo ALTO — RCE por MITM)**: TLS estricto (`VERIFYPEER`/`VERIFYHOST`) y redirecciones solo HTTPS en la descarga del paquete y en la API de GitHub; **verificación SHA‑256** del ZIP descargado contra hashes empotrados (versiones conocidas).
- **Ciclo de vida de secretos**: `install.forced.php` se borra tras `step5` (éxito o fallo); las contraseñas (BD/root/admin) se **purgan de `config.php`** al completar; `cookies.txt`/`install.log` se eliminan; al caducar el TTL se **borra físicamente** el temporal. TTL reducido de 6 h a 2 h.
- **`ajax=limpiar` solo por POST** (corta CSRF por `<img>`/navegación) + validación de que las rutas a borrar están dentro del directorio del instalador.
- **Anti Host‑header poisoning**: `HTTP_HOST` se valida (allowlist de caracteres) antes de usarse como `baseurl`/`dolibarr_main_url_root`.
- **Autodestrucción robusta**: se sobrescribe el archivo con un stub inerte (HTTP 410) antes de `unlink`, por si el borrado falla (owner SFTP ≠ PHP).
- `conf.php` con permisos `0644` (antes `0666`) + re‑endurecido a `0640` tras `step1`; cookie de idioma con `HttpOnly`/`SameSite`/`Secure`.

> Nota: sigue siendo una herramienta **de un solo uso sin autenticación**; bórrala del servidor en cuanto termine (se autodestruye al finalizar).

## [1.5.0] - 2026-06-17

### Añadido
- **Internacionalización (i18n) completa y autocontenida** en el mismo archivo: **English, Español, Deutsch, Français, Italiano**. Diccionario embebido + `di_t()`; cubre interfaz, etiquetas, validaciones, mensajes de error y el log en vivo (JS).
- **Selector de idioma** en la barra superior (persiste en cookie, conserva el paso actual) y **autodetección** por `Accept-Language`.

### Compatibilidad
- Verificado en **PHP 7.4, 8.1 y 8.3** (sin sintaxis exclusiva de 8.x; gestionadas las diferencias de PDO/mysqli/pgsql de 8.1+).

## [1.4.0] - 2026-06-17

### Cambiado
- **El paquete es ahora el primer paso** (patrón Softaculous/Duplicator): tras elegir el modo, eliges/descargas el paquete; la configuración queda solo con base de datos + administrador. La descarga (si aplica) ocurre en ese paso dedicado.

### Corregido / UX
- Los campos del formulario **ya no se pierden** al fallar una validación: se repueblan con lo enviado (incluidas contraseñas), no con la config guardada.
- La contraseña del administrador **ya no exige mínimo de 8** (solo no vacía, como Dolibarr).
- La **contraseña de base de datos puede ir vacía** (habitual en entornos de prueba; el usuario de BD solo se crea si hay contraseña).
- Selector de origen del paquete **más grande**, con toda la fila clicable (`<label>`), radios con color de acento y resalte de la opción activa.

## [1.3.0] - 2026-06-17

### Añadido
- **Descarga autónoma del paquete**: en el formulario puedes elegir "descargar versión" en lugar de subir un ZIP. El instalador baja el paquete oficial desde SourceForge **por bloques HTTP Range** con barra de progreso real (página `descargar`), valida el ZIP y continúa solo. Ahora basta con subir **un único archivo**.
- Lista de versiones obtenida en vivo de la API de GitHub (releases estables), con caché de 1 h y lista de respaldo offline; opción de escribir una versión exacta a mano.
- Probado e2e: descarga de 22.0.5 (85 MB en ~28s) + instalación completa (276 tablas, admin, lock) en MySQL.

## [1.2.0] - 2026-06-17

### Añadido
- **Soporte para PostgreSQL** además de MySQL/MariaDB: selector de motor en el formulario, `$force_install_type` correcto, conexión y verificación vía `pgsql`/`pdo_pgsql`, puerto por defecto 5432 automático. Probado e2e (272 tablas, admin y `install.lock`) contra PostgreSQL 16 y MySQL 8.
- Requisitos detecta drivers de ambos motores; verificación de tablas portable (`SHOW TABLES` / `pg_tables`); escape SQL portable.

## [1.1.0] - 2026-06-17

### Añadido
- **Modo ultrasencillo**: solo descomprime `htdocs` y redirige al asistente nativo `install/` de Dolibarr.
- **Interfaz tipo terminal CRT** (verde fósforo) con **log en vivo real** de cada bloque de descompresión y cada paso de instalación.
- Selección de paquete cuando hay **varios** `dolibarr-*.zip`.
- Reanudación tras F5 (progreso persistido) y reintento de bloques/pasos.
- Detección de paquete flexible: `<dir>/htdocs/`, `htdocs/` en raíz, o ZIP que ya es `htdocs`.

### Seguridad
- `install.forced.php` con permisos `0600`, borrado inmediato tras `step5`.
- Configuración temporal como `.php` con guardia (403) + `.htaccess`/`web.config`, con caducidad de 6 h.
- Validación anti-SSRF de la URL base; cabeceras anti-clickjacking / `no-store`.
- Validación de la contraseña de administrador frente al filtro `alpha` de Dolibarr.
- Autolimpieza agresiva cuando se detecta una instalación ya completada.

### Rendimiento
- Descompresión **nativa** (`ZipArchive::extractTo`) por bloques: ~17.000 archivos en segundos.

### Correcciones
- Directorio de documentos robusto cuando el directorio padre no es escribible / `open_basedir`.
- Verificación de éxito por tablas núcleo y admin concreto (evita falsos positivos).
- Corrección de `dolibarr_main_url_root` cuando se usa el reintento por loopback.

## [1.0.0]
- Versión inicial: instalación automática completa en un solo archivo.
