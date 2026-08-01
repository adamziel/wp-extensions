

# WP Extensions

Pequeñas extensiones y experimentos de WordPress.

## Búsqueda de Texto Completo por Idioma

`indexer/` es el complemento activo de búsqueda de texto completo para WordPress. Language FTS construye
un índice de búsqueda local para el contenido de WordPress, añade análisis multilingüe donde los
analizadores configurados lo admiten, y proporciona a los administradores del sitio herramientas prácticas de administración,
WP-CLI y diagnóstico para comprender el comportamiento de búsqueda.

[![Try in Playground](https://github.com/WordPress/action-wp-playground-pr-preview/raw/main/assets/playground-preview-button.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/indexer/playground/blueprint.json)

La vista previa en Playground descarga el
[lanzamiento del núcleo con versión y dígito de verificación fijados](https://github.com/adamziel/wp-extensions/releases/download/language-fts-v0.1.12/language-fts-core.zip),
lo verifica antes de la activación y abre `Ajustes > Búsqueda de Texto Completo` en la
pestaña Sandbox. Es una forma rápida de inspeccionar el flujo de trabajo, no una garantía de que cada
host, base de datos, caché, proveedor, idioma o patrón de tráfico haya sido
validado. Evalúenlo en un entorno de staging, mantengan copias de seguridad y una ruta de reversión, y utilicen las
herramientas de estado con su propio contenido.

Consulte [indexer/README.md](indexer/README.md) para la configuración, arquitectura,
notas sobre idiomas/analizadores y comprobaciones de desarrollo.

## Importador Universal de WordPress

`universal-wordpress-importer/` es un complemento de WordPress para importaciones duraderas
y reanudables desde árboles de contenido: carpetas locales, carpetas arrastradas desde el navegador,
archivos zip, Markdown, HTML, texto, EPUB, WXR, PDFs, repositorios de GitHub,
URLs de sitios/REST de WordPress y feeds RSS/Atom.

[![Try in Playground](https://img.shields.io/badge/Try%20in-WordPress%20Playground-3858e9?style=for-the-badge&logo=wordpress)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Funiversal-wordpress-importer-demo.json)

El Blueprint de Playground instala el complemento empaquetado y abre
`Herramientas -> Importador Universal`. Usa esta ruta de fuente integrada para probar una importación:

```text
/wordpress/wp-content/plugins/universal-wordpress-importer/examples/playground-import
```

Consulte [universal-wordpress-importer/README.md](universal-wordpress-importer/README.md)
para características, uso, ejemplos, limitaciones y comprobaciones de desarrollo.

## Editor Markdown

`markdown-editor/` abre un directorio de archivos Markdown en el editor de bloques de WordPress
cuando se ejecuta en WordPress Playground.

Incluye:

- un mu-plugin que asigna `wp_posts` y `wp_postmeta` a tablas virtuales de SQLite
  respaldadas por Markdown
- el código fuente de la extensión PHP.wasm `sqlite_markdown` que registra esas tablas virtuales
- `php-toolkit` como submódulo para la conversión de Markdown <-> marcado de bloques

Consulte [markdown-editor/README.md](markdown-editor/README.md) para notas de uso y
desarrollo.

Los Lanzamientos de GitHub publican un paquete `wp-markdown-editor.zip` listo para ejecutar con
el mu-plugin, las dependencias de tiempo de ejecución del kit de herramientas PHP, el módulo lateral PHP.wasm
preconstruido y un pequeño árbol de páginas Markdown en `content/`.

### Demo del ZIP de Liberación

#### Playground en el Navegador

Abre la demo del Editor Markdown en el sitio web de WordPress Playground:

```text
https://playground.wordpress.net/?php=8.4&php-extension=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fmarkdown-editor%2Fsqlite-markdown-extension%2Fdist%2Fmanifest.json&blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fmarkdown-editor-browser.json
```

Esta demo en el navegador usa la extensión PHP.wasm `sqlite_markdown` publicada y
carga el árbol de Markdown de ejemplo en el sistema de archivos temporal del navegador de Playground.
Los cambios realizados en el editor permanecen dentro de esa sesión de Playground.

#### CLI de Playground Local

Descarga el paquete de lanzamiento del Editor Markdown e inicia Playground:

```bash
curl -fsSL https://github.com/adamziel/wp-extensions/releases/download/markdown-editor-latest/wp-markdown-editor.zip -o wp-markdown-editor.zip
rm -rf wp-markdown-editor
unzip -q wp-markdown-editor.zip

npx --yes @wp-playground/cli@latest server \
	--php=8.4 \
	--login \
	--php-extension=wp-markdown-editor/markdown-editor/sqlite-markdown-extension/dist/manifest.json \
	--mount=wp-markdown-editor/content:/markdown-root \
	--mount=wp-markdown-editor/markdown-editor:/wordpress/wp-content/mu-plugins
```

Luego abre:

```text
http://127.0.0.1:9400/wp-admin/edit.php?post_type=page
```

## StillPress

`static-site-generator/` es un complemento de WordPress que exporta un sitio de WordPress a
HTML estático y activos de frontend. Funciona en WordPress regular y en
WordPress Playground.

[![Try it in WordPress Playground](static-site-generator/assets/try-it-in-playground.svg)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fstatic-site-generator-browser.json)

El exportador incluye:

- una pantalla de administración en `Herramientas -> StillPress`
- una API programática `ssgwp_export_static_site()`
- un comando de WP-CLI: `wp static-site export`
- ejemplos de Blueprint de Playground para flujos de trabajo en navegador y CLI

### Playground en el Navegador

Abre este Blueprint en la aplicación web de Playground:

```text
https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fadamziel%2Fwp-extensions%2Fmain%2Fblueprints%2Fstatic-site-generator-browser.json
```

El Blueprint instala el complemento desde este repositorio, prepara un sitio de demo más rico
con páginas, categorías, publicaciones fechadas y contenido de bloques, y luego abre
`Herramientas -> StillPress`.

Usa la pantalla de administración para descargar el ZIP estático. El ZIP es el sitio estático publicado; guarda el sitio completo de Playground por separado si deseas mantener un
sitio de origen de WordPress editable.

Después de extraer el ZIP, abre `index.html` para una revisión rápida. Para la vista previa más precisa, sirve la carpeta extraída mediante HTTP:

```bash
python3 -m http.server 8080
```

Luego abre `http://localhost:8080/`.

### Playground CLI

Desde un clon de este repositorio:

```bash
mkdir -p ./static-site-output
npx @wp-playground/cli@latest run-blueprint \
	--mount=./static-site-generator:/wordpress/wp-content/plugins/static-site-generator \
	--mount=./static-site-output:/exports \
	--blueprint=./blueprints/static-site-generator-cli-export.json
```

El ZIP generado se escribe en:

```text
./static-site-output/static-site.zip
```

Extráelo y ejecuta `python3 -m http.server 8080` desde la carpeta extraída para
una vista previa HTTP local.

Si la CLI de Playground no puede escribir el ZIP en el directorio de salida montado,
asegúrate de que el directorio del host sea escribible por el entorno de ejecución:

```bash
chmod 777 ./static-site-output
```

### WordPress Regular

Copia `static-site-generator/` en `wp-content/plugins/`:

```bash
cp -R static-site-generator /path/to/wordpress/wp-content/plugins/
```

Luego activa **StillPress** en `wp-admin -> Plugins`.
Abre `Herramientas -> StillPress`, elige las opciones de exportación y descarga
el ZIP estático.

Requisitos:

- WordPress 6.5 o posterior
- PHP 7.4 o posterior
- Extensión `zip` de PHP para descargas ZIP

### WP-CLI de WordPress Regular

Desde la raíz de WordPress, activa el complemento y ejecuta:

```bash
wp plugin activate static-site-generator
wp static-site export --output=./static-site.zip --fetch-mode=auto
wp static-site export --output-dir=./static-site --fetch-mode=auto
```

Opciones útiles:

```bash
wp static-site export --output=./static-site.zip --url-mode=relative
wp static-site export --output-dir=./static-site --url-mode=relative
wp static-site export --output=./static-site.zip --fetch-mode=internal
wp static-site export --output=./static-site.zip --generate-sitemap --generate-robots
wp static-site export --output=./static-site.zip --report
```

Usa `--fetch-mode=internal` cuando las solicitudes HTTP de retorno (loopback) estén bloqueadas o
poco confiables, incluido en muchos entornos Playground.

Abrir archivos exportados directamente con `file://` es útil para comprobaciones básicas de HTML y
CSS, pero los navegadores bloquean los módulos ES de JavaScript desde orígenes `file://`.
Usa el comando de vista previa HTTP local anterior cuando pruebes código de frontend interactivo
como la API de Interactividad de WordPress.

### Comprobaciones de Desarrollo

```bash
php indexer/tests/run.php
php -n indexer/tests/run.php
find static-site-generator -name '*.php' -print0 | xargs -0 -n1 php -l
php static-site-generator/tests/path-utils-test.php
php static-site-generator/tests/url-collector-test.php
php static-site-generator/tests/url-rewriter-test.php
php static-site-generator/tests/static-exporter-test.php
php static-site-generator/tests/plugin-test.php

cd universal-wordpress-importer
composer install
composer validate --no-check-publish
composer test
composer lint
composer build:release
```
