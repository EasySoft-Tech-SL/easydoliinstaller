# Changelog

Todos los cambios notables de EasyDoliInstaller.

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
