# Changelog

Todos los cambios notables de EasyDoliInstaller.

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
