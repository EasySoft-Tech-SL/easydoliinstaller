# EasyDoliInstaller

**Instalador automático "todo en uno" para [Dolibarr](https://www.dolibarr.org), en un solo archivo PHP.** Al estilo de Duplicator/Migrator: subes `easydoliinstaller.php` junto al ZIP oficial de Dolibarr, abres el archivo en el navegador y el asistente descomprime y realiza **todo** el proceso de instalación por ti.

> Hecho por [Easysoft Tech S.L.](https://github.com/easySoft-Tech-SL) · Licencia GPL-3.0 (igual que Dolibarr)

![estética terminal CRT](https://img.shields.io/badge/UI-terminal%20CRT-43ff7d?style=flat-square) ![PHP ≥7.1](https://img.shields.io/badge/PHP-%E2%89%A57.1-777bb3?style=flat-square) ![Dolibarr](https://img.shields.io/badge/Dolibarr-paquete%20oficial-1d3a8a?style=flat-square)

---

## ✨ Qué hace

Dos modos en el mismo archivo:

| Modo | Qué hace |
|------|----------|
| **Automático** | Descomprime + crea la base de datos + tablas + datos de referencia + cuenta de administrador + bloquea la instalación. **Cero clics** en el asistente nativo. Al terminar se autodestruye. |
| **Ultrasencillo** | Solo descomprime `htdocs` y te redirige al asistente nativo `install/` de Dolibarr para que lo configures tú. |

- 🖥️ **Interfaz tipo terminal CRT** (verde fósforo) con **log en vivo real** de cada bloque de descompresión y cada paso de instalación (no una carga inventada).
- ⚡ **Descompresión nativa** (`ZipArchive::extractTo`) por bloques: ~17.000 archivos en segundos, como 7-Zip.
- 🔁 Reanudable: reintenta bloques/pasos y sobrevive a un F5.
- 🧩 Detecta y deja **elegir** entre varios `dolibarr-*.zip`.

## 🚀 Uso

1. Sube a la carpeta que será la **raíz web** de tu Dolibarr:
   - `easydoliinstaller.php`
   - El paquete oficial de Dolibarr, p. ej. `dolibarr-23.0.3.zip`
2. Abre en el navegador: `https://tu-dominio/easydoliinstaller.php`
3. Sigue el asistente. En modo automático, al terminar pulsa **Limpiar** y entras a tu Dolibarr ya instalado.

## ✅ Requisitos del servidor

- PHP ≥ 7.1 (recomendado 8.x). Probado en PHP 7.4 + MySQL 8 + Apache.
- Extensiones: `zip`, `json` (obligatorias); `mysqli`/`pdo_mysql`; `curl` o `allow_url_fopen`. Recomendadas: `gd`, `mbstring`, `xml`.
- Carpeta de instalación **escribible**.
- El servidor debe poder atender **más de una petición a la vez** (Apache/nginx normales; **no** sirve `php -S` monohilo, que se bloquearía en la autollamada del modo automático).

## 🔧 Cómo funciona (modo automático)

1. Detecta el ZIP y extrae **solo el contenido de `htdocs/`** como raíz web.
2. Escribe `install/install.forced.php` (instalación desatendida de Dolibarr, `noedit=2`) y un `conf/conf.php` vacío y escribible.
3. Ejecuta por HTTP los pasos nativos de Dolibarr: `step1` (conf + BD) → `step2` (tablas + datos) → `step5` (admin + `install.lock`).
4. Verifica contra la BD (tablas núcleo, usuario admin, fichero de bloqueo).
5. Limpia: borra `install/`, el ZIP, los temporales y **se autodestruye**.

## 🔒 Seguridad

EasyDoliInstaller es una herramienta de **un solo uso**:

- El `install/install.forced.php` (con contraseñas) se escribe con permisos `0600` y se **borra en cuanto termina** `step5`; `install/` completo se elimina al limpiar.
- La configuración temporal se guarda como `.php` con guardia (devuelve 403) dentro de `__doli_installer_tmp__/` (protegido con `.htaccess` + `web.config`) y **caduca** a las 6 h.
- Cabeceras anti-clickjacking/`no-store`, validación anti-SSRF de la URL base, y autolimpieza agresiva si detecta una instalación ya completada.

> ⚠️ Aun así, **no dejes el instalador en un servidor público**. Si interrumpes el proceso, borra manualmente `easydoliinstaller.php`, el `.zip` y la carpeta `__doli_installer_tmp__`.

## 🐛 Si algo falla

- Si un paso se detiene, el asistente lo indica y ofrece **reintentar** o terminar manualmente en `https://tu-dominio/install/`.
- Revisa `__doli_installer_tmp__/install.log` para ver los códigos HTTP de cada paso.
- ¿Detrás de un WAF/mod_security? Añade una excepción para `/install/`.

## 📦 Compatibilidad

- Paquete oficial de Dolibarr (`dolibarr-x.y.z/htdocs/...`), un ZIP cuyo raíz sea `htdocs/`, o un ZIP que ya **sea** el contenido de `htdocs`.
- Base de datos: **MySQL / MariaDB**.

## Licencia

GPL-3.0-or-later. Consulta [LICENSE](LICENSE).
