# Licencia y atribución — WILLAQ

## Licencia

WILLAQ se publica bajo la **Creative Commons Atribución-NoComercial-CompartirIgual
4.0 Internacional** ([CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/deed.es)).

El texto legal completo está en [`LICENSE`](LICENSE). No es un resumen ni una
adaptación: es el `legalcode` oficial de Creative Commons, sin modificar.

| Puedes | No puedes |
|---|---|
| ✅ Usar el código para estudiarlo y auditarlo | ❌ Usarlo con fines comerciales |
| ✅ Modificarlo y redistribuirlo | ❌ Distribuir una versión modificada bajo otra licencia |
| ✅ Reutilizarlo en clase, taller o exposición | ❌ Reclamar que es un producto oficial del Estado |
| ✅ Citarlo indicando la autoría y la licencia | ❌ Eliminar las atribuciones del código original |
## Qué significa en la práctica

- **Atribución.** Si reutilizas el código o sus resultados, cita a WILLAQ y
  enlaza a esta licencia y al repositorio.
- **No comercial.** Puedes usarlo y modificarlo libremente con fines docentes,
  de investigación o de gestión del riesgo. No puedes venderlo, cobrar por
  un servicio basado en él ni usarlo como base de un producto de mercado.
- **Compartir igual.** Si lo modificas, la versión modificada se publica
  bajo la misma licencia.

## Atribución recomendada

```
WILLAQ — Inteligencia territorial y apoyo a la decisión frente al Fenómeno El Niño.
Proyecto de IESTP "Argentina", authored by ismael (iamisma326-cpu).
Licencia CC BY-NC-SA 4.0 — https://creativecommons.org/licenses/by-nc-sa/4.0/
Repositorio: https://github.com/iamisma326-cpu/willaq
```

## Aviso sobre los datos

Esta licencia cubre **el código de WILLAQ**. No cubre los datos de terceros
que el proyecto consume y redistribute en su propio directorio `data/`:

| Fuente | Qué hay en el repo | Nota |
|---|---|---|
| **SENAMHI** | Catálogo y series diarias de estaciones | Se publican para trazabilidad y auditoría. **No tienen licencia de reutilización declarada** (ver README §8): quien los reutilice debe verificar las condiciones de uso en la fuente. |
| **INEI** | Proyecciones distritales de población 2018–2026 | Datos públicos oficiales, con el sha256 del XLSX de origen registrado. |
| **Open-Meteo** | ERA5, GloFAS y pronóstico | Proveniencia declarada en cada respuesta de la API y en los artefactos. |
| **OpenStreetMap** | Teselas del mapa base | AttributionRequired: © colaboradores de OSM. |

Los **datos del SENAMHI son la excepción**: son obra de un tercero con
condiciones de uso propias. La licencia CC BY-NC-SA de WILLAQ no las
transfiere ni las sustituye.
