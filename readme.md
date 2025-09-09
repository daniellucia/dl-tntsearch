# TNTSearch for WordPress

**TNTSearch for WordPress** es un plugin para WordPress que integra el motor de búsqueda [TNTSearch](https://github.com/teamtnt/tntsearch) en tu sitio, permitiendo búsquedas rápidas y relevantes sobre los contenidos de WordPress, incluyendo custom post types.

---

## Características

- Búsqueda instantánea y tolerante a errores (fuzziness).
- Indexación selectiva de custom post types.
- Filtro de resultados por score mínimo configurable.
- Resultados ordenados por relevancia (score).
- Integración transparente con la búsqueda nativa de WordPress.
- Página de ajustes en el menú de "Ajustes" de WordPress.
- Botón para indexar manualmente el contenido.
- Almacenamiento del índice en SQLite, sin necesidad de servicios externos.

---

## Instalación

1. Sube la carpeta del plugin a `/wp-content/plugins/dl-tntsearch` o instala desde un archivo ZIP.
2. Activa el plugin desde el panel de administración de WordPress.
3. Ve a **Ajustes > TNTSearch** para configurar y crear el índice.

---

## Uso

1. **Activa la búsqueda TNTSearch** desde la página de ajustes.
2. **Selecciona los Custom Post Types** que deseas indexar.
3. Haz clic en **Guardar** para guardar la configuración.
4. Haz clic en **Indexar ahora** para crear o actualizar el índice.
5. Utiliza la búsqueda estándar de WordPress: los resultados serán gestionados por TNTSearch y ordenados por relevancia.

---

## Opciones de configuración

- **Activar búsqueda TNTSearch:** Habilita o deshabilita la búsqueda avanzada.
- **Custom Post Types a indexar:** Selecciona los tipos de contenido que serán incluidos en el índice.
- **Indexar ahora:** Botón para crear o actualizar el índice manualmente.

---

## Requisitos

- PHP 7.4 o superior.
- WordPress 5.0 o superior.

---

## Notas técnicas

- El índice se almacena en la carpeta `/storage` del plugin como un archivo SQLite.
- Solo se muestran resultados con un score superior a 1.5.
- Los resultados se ordenan por score de mayor a menor.
- El plugin sobrescribe la búsqueda nativa de WordPress usando el filtro `posts_pre_query`.

---

## Créditos

- [TeamTNT/TNTSearch](https://github.com/teamtnt/tntsearch)
- Desarrollado por Daniel Lucia

---

## Licencia

Este plugin se distribuye bajo la licencia MIT. Consulta el archivo `LICENSE.md