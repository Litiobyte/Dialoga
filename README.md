# DialogaWP: base para desarrollo

Repositorio local para desarrollar mejoras sobre el plugin de WordPress DialogaWP.
Basado en el codigo original del plugin, con las mejoras locales descritas abajo.

## Origen

- Plugin: DialogaWP - Chatbot & Conversational Lead Capture Forms.
- Version base: 1.0.5.
- Autor declarado: Litiobyte SpA.
- SVN oficial: https://plugins.svn.wordpress.org/dialogawp/
- Version estable: https://plugins.svn.wordpress.org/dialogawp/tags/1.0.5/
- Descarga utilizada: https://downloads.wordpress.org/plugin/dialogawp.1.0.5.zip
- Metodo: extraccion del ZIP oficial, sin metadatos SVN.

## Licencia

El plugin declara **GNU GPL version 2 o posterior** en `dialogawp.php` y
`readme.txt`. Se conservan los avisos originales de autoria y licencia.

La GPL permite modificar y redistribuir el codigo bajo sus condiciones.
Al distribuir modificaciones, conserva los avisos, identifica los cambios
y sus fechas, incluye la licencia y proporciona el codigo fuente segun
corresponda. Consulta el texto completo:
https://www.gnu.org/licenses/gpl-2.0.html

La descarga contiene el plugin gratuito. El complemento DialogaWP Pro no esta
incluido. Las bibliotecas incluidas en `assets/js/vendor/` conservan sus
propios avisos de licencia.

## Requisitos e instalacion

- WordPress 5.0 o posterior.
- PHP 7.4 o posterior.
- Clave propia de OpenAI o Anthropic solo para las funcionalidades de IA.

Para probarlo, instala esta carpeta como `wp-content/plugins/dialogawp` en una
instancia de WordPress de desarrollo y activa el plugin desde el administrador.
No incluyas claves API ni datos de visitantes en el repositorio.

## Skills de desarrollo

Se instalaron 46 skills de desarrollo y seguridad de WordPress para OpenCode
en `.opencode/skills/`, solo para este proyecto. Las revisiones de origen,
licencias e instrucciones de uso se documentan en `.opencode/README.md`.
Cierra y reinicia OpenCode desde esta carpeta para descubrirlas.

## Estado inicial

Git inicializado en la rama `main`, sin commits ni remoto configurado.
El historial de versiones e instrucciones originales se encuentran en
`readme.txt`.

## Mejoras locales (2026-10-31)

- Importacion y exportacion JSON individual o masiva desde Flow Builder.
- Exportar el flujo abierto incluye cambios actuales del editor; exportar varios
  utiliza las versiones guardadas del resto. Limite: 100 flujos y 5 MiB por paquete.
- La importacion muestra un resumen, valida todos los flujos y crea copias nuevas
  e inactivas. No reemplaza flujos ni incluye conversaciones o estadisticas.
- Credenciales y cabeceras se excluyen; los conectores locales se desvinculan.
  Revisa URLs, destinatarios y dependencias antes de activar copias importadas.
  Los roles inexistentes y tipos de nodo no disponibles impiden importar.
- Las extensiones pueden registrar tipos soportados con `dialogawp_import_node_types`.
- El boton Full screen amplia el lienzo dentro de la pestana, oculta WordPress
  y la lista de flujos y mantiene herramientas y panel de nodos. Salida con boton
  o Escape; los dialogos abiertos tienen prioridad.
- Escape de campos dinamicos del editor para tratar el JSON importado como no confiable.
- Settings organizado en siete pestanas accesibles, con un solo formulario y
  conservacion de cambios entre pestanas, incluidas las opciones de extensiones.
- Administracion traducida al espanol mediante gettext: sigue el idioma del
  usuario de WordPress, con el idioma del sitio como respaldo. Incluye variantes
  regionales de espanol; el ingles sigue siendo el idioma original.
- No se traducen contenidos guardados ni la interfaz publica del chatbot.

Los catalogos fuente estan en `languages/*-es.json`; regeneralos con
`node tools/build-translations.cjs` para actualizar PO, POT y MO.

Pruebas: `php tests/flow-transfer-test.php` y
`node tests/flow-transfer-browser-test.cjs`. Consulta los requisitos del navegador
en `tests/flow-transfer-browser-test.md`. Las pruebas usan dobles de WordPress;
no sustituyen una prueba de integracion en una instalacion real.
Pruebas de ajustes e idiomas: `php tests/settings-test.php`,
`php tests/i18n-test.php` y `node tests/settings-browser-test.cjs`.
