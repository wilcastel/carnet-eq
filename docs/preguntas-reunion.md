# Reunión de cierre para producción — Carnet Equidad

## Propósito y resultado esperado

Esta guía convierte el MVP actual en decisiones verificables para dejar el plugin listo para producción. La reunión debe cerrar responsables, evidencia y fechas; no se deben asumir datos, reglas de negocio ni requisitos legales que la API o el área responsable no hayan confirmado.

**Resultado de salida:** un acta con cada decisión prioritaria resuelta, sus evidencias adjuntas y los responsables de entregar lo pendiente. Con ello se podrá configurar, probar y aprobar el release sin exponer credenciales ni datos personales.

## Estado actual confirmado

- [x] Consulta pública de documento, selección de póliza, generación de PDF descargable y auditoría estructurada están implementadas en el MVP.
- [x] La generación vuelve a consultar la API y valida en servidor el consentimiento y la opción elegida; no guarda el PDF en disco.
- [x] Existe límite de consultas por IP, consentimiento de tratamiento de datos y exportación CSV administrativa de auditoría.
- [x] La API de desarrollo se ha usado mediante VPN y responde una lista de registros.
- [ ] CAPTCHA, conectividad de producción, contrato definitivo de datos, plantilla definitiva y aprobación de salida no están confirmados.

## Lista priorizada para la reunión

1. Resolver primero los bloqueadores de producción (sección 1).
2. Decidir los datos y el alcance final del carnet PDF (sección 2).
3. Confirmar las reglas de producto y el contrato de la API (sección 3).
4. Registrar mejoras diferidas sin convertirlas en bloqueadores (sección 4).
5. Cerrar el acta con los criterios de aceptación de release (sección 5).

---

## 1. Bloqueadores antes de producción

### 1.1 Conectividad de la API desde producción

**Pregunta.** ¿Cuál será el mecanismo aprobado para que el servidor de WordPress de producción alcance la API: VPN site-to-site, endpoint público con allowlist de IP, proxy interno u otro?

- **Por qué importa:** la URL usada en desarrollo es privada y depende de VPN; sin una ruta de red estable el plugin no puede consultar ni generar carnets.
- **Comportamiento actual / supuesto temporal:** desarrollo consume la API por VPN. No existe una ruta ni una URL de producción confirmada.
- **Decisión requerida:** mecanismo, URL/base path, DNS si aplica, puertos, allowlist, responsable de soporte y procedimiento de diagnóstico.
- **Dueño o evidencia solicitada:** Infraestructura/Seguridad de La Equidad; diagrama de red, prueba desde el host de producción y datos de contacto para incidentes.

### 1.2 Credenciales, `consumer`, `cod_pla` y ciclo de vida del token

**Pregunta.** ¿Qué credenciales y valores definitivos deben usarse por ambiente, y cuál es el TTL y la política de renovación del token?

- **Por qué importa:** evita fallos de autenticación, caché incorrecta y uso accidental de secretos de desarrollo en producción.
- **Comportamiento actual / supuesto temporal:** las credenciales se leen solo en configuración del servidor; `consumer = Linktic`, `cod_pla = 1821` y caché de token de 10 minutos son supuestos de integración. Ante `401`, se renueva una vez y se reintenta una vez.
- **Decisión requerida:** credenciales separadas por ambiente, valor permitido de `consumer`, uno o varios `cod_pla`, TTL real, límite de emisión/concurrencia de tokens y método de invalidación o renovación.
- **Dueño o evidencia solicitada:** propietario de Api Data Carnet; documento de integración actualizado y prueba controlada sin compartir secretos en el acta.

### 1.3 CAPTCHA antes de exponer la consulta

**Pregunta.** ¿Se aprobará Cloudflare Turnstile, hCaptcha u otro CAPTCHA; quién administra la cuenta y cuáles son las claves por ambiente?

- **Por qué importa:** el formulario público consulta documentos personales y requiere una barrera adicional contra automatización y enumeración.
- **Comportamiento actual / supuesto temporal:** hay rate limiting por IP; CAPTCHA está pendiente y no debe considerarse sustituido por ese límite.
- **Decisión requerida:** proveedor, política de fallo (bloquear o degradar), dominios autorizados, propietario de claves y requisito de privacidad/cookies.
- **Dueño o evidencia solicitada:** Seguridad/Producto; aprobación del proveedor y configuración de sitio para desarrollo y producción.

### 1.4 Despliegue, operación y propiedad

**Pregunta.** ¿Quién instala, configura y opera el plugin en WordPress de producción, y cómo se entregará el paquete instalable?

- **Por qué importa:** el usuario final no debe ejecutar Composer ni configurar dependencias; se requiere un ZIP de release con `vendor/` incluido y una operación repetible.
- **Comportamiento actual / supuesto temporal:** el plugin crea su tabla de auditoría y programa su limpieza al activarse. La configuración sensible debe vivir en `wp-config.php` o en variables de entorno del servidor, nunca en Git ni en el navegador.
- **Decisión requerida:** responsable de hosting/WordPress, canal seguro para secretos, procedimiento de instalación/rollback, ventana de cambio, monitoreo y soporte de primer nivel.
- **Dueño o evidencia solicitada:** Operaciones/administración WordPress; runbook firmado y acceso de prueba controlado.

### 1.5 Cumplimiento: auditoría, retención y texto legal

**Pregunta.** ¿La retención, los campos auditados y el texto de consentimiento/política cumplen la política interna y la Ley 1581 aplicable al flujo?

- **Por qué importa:** la auditoría contiene documento e IP; requiere finalidad, acceso restringido, retención aprobada y eliminación verificable.
- **Comportamiento actual / supuesto temporal:** se auditan consultas y eventos relevantes con retención automática de 180 días; el visor y CSV requieren administración. La interfaz exige consentimiento y enlaza a la política suministrada.
- **Decisión requerida:** periodo de retención, base/finalidad de tratamiento, usuarios autorizados, proceso de atención de solicitudes, URL y copy legal definitivos, e indicación de si debe cambiarse el consentimiento.
- **Dueño o evidencia solicitada:** Jurídica/Privacidad/Compliance; aprobación escrita de política, texto y retención.

---

## 2. Decisiones que completan el PDF/carnet

### 2.1 Datos no presentes en la API

**Pregunta.** ¿Cuál es la fuente autorizada para **Tomador** y **V/r asegurado por gastos médicos**?

- **Por qué importa:** son campos visibles del modelo; inventarlos en un documento oficial sería incorrecto.
- **Comportamiento actual / supuesto temporal:** ambos se imprimen como `PENDIENTE DE CONFIRMACIÓN`.
- **Decisión requerida:** campo API, catálogo, valor fijo aprobado o eliminación/rediseño del campo; indicar reglas de formato y vigencia.
- **Dueño o evidencia solicitada:** Producto/negocio y propietario de la API; ejemplo anonimizado de respuesta o definición de catálogo.

### 2.2 Alcance de la plantilla y producto asegurado

**Pregunta.** ¿La plantilla de Accidentes Estudiantiles aplica a todos los registros consultables o solo a determinados planes/pólizas?

- **Por qué importa:** evita generar un carnet visualmente o legalmente incorrecto para otro producto.
- **Comportamiento actual / supuesto temporal:** se usa el modelo entregado como fondo para el PDF; el alcance por producto no está formalmente confirmado.
- **Decisión requerida:** reglas de elegibilidad, relación con `cod_pla`, plantilla por producto y conducta cuando un registro no sea elegible.
- **Dueño o evidencia solicitada:** Producto/Negocio; matriz producto → plantilla → campos requeridos.

### 2.3 Documento, NIT y datos visibles

**Pregunta.** ¿El campo del modelo debe mostrar documento del asegurado, NIT del tomador o ambos? ¿Qué etiqueta exacta debe usarse?

- **Por qué importa:** NIT y documento no son equivalentes y una etiqueta errónea confunde al usuario o incumple el diseño aprobado.
- **Comportamiento actual / supuesto temporal:** el PDF etiqueta el dato disponible como `DOCUMENTO`, no como NIT.
- **Decisión requerida:** dato fuente, etiqueta, enmascaramiento si aplica y regla cuando el tipo documental no sea cédula.
- **Dueño o evidencia solicitada:** Jurídica/Producto/Diseño; arte final anotado y definición de datos.

### 2.4 Diseño final, formato y dimensiones

**Pregunta.** ¿Cuál archivo es la plantilla final y cuáles son el tamaño, orientación, sangrado, número de caras y formatos de entrega exigidos?

- **Por qué importa:** las coordenadas del texto dependen de dimensiones y tipografías; un cambio tardío obliga a recalibrar el PDF.
- **Comportamiento actual / supuesto temporal:** se superponen datos sobre un PDF proporcionado; se conserva el enfoque de dos caras del modelo disponible.
- **Decisión requerida:** arte final versionado, dimensiones físicas/PDF, reverso obligatorio, tipografías, paleta y requisitos de impresión o solo descarga.
- **Dueño o evidencia solicitada:** Diseño/Marca; PDF vectorial final y aprobación visual.

### 2.5 QR, foto y textos/reglas legales del carnet

**Pregunta.** ¿Debe incluirse foto, QR/código de verificación, firma, logos adicionales o textos regulatorios obligatorios?

- **Por qué importa:** estos elementos requieren datos, servicios o validación que la implementación actual no posee.
- **Comportamiento actual / supuesto temporal:** el carnet no incluye foto ni QR; solo muestra los elementos presentes en la plantilla base.
- **Decisión requerida:** inclusión/exclusión de cada elemento; para QR, contenido, URL/servicio de validación, duración y protección anti-fraude; para foto, fuente, consentimiento y retención; textos exactos aprobados.
- **Dueño o evidencia solicitada:** Diseño/Jurídica/Seguridad; arte final, especificación de QR y textos legales aprobados.

---

## 3. Aclaraciones de producto y contrato de datos

### 3.1 Formato y validación de documentos

**Pregunta.** ¿Qué tipos de documento acepta `doc_aseg`, cómo se distinguen, se preservan ceros iniciales y existe dígito de verificación?

- **Por qué importa:** normalizar eliminando caracteres o imponer longitud puede alterar un identificador válido o rechazar usuarios legítimos.
- **Comportamiento actual / supuesto temporal:** el formulario conserva solo dígitos y exige entre 6 y 11 caracteres; no distingue tipo documental.
- **Decisión requerida:** tipos permitidos, máscara por tipo, longitudes, tratamiento de puntos/guiones/espacios, ceros iniciales, dígito de verificación y mensajes de error.
- **Dueño o evidencia solicitada:** Propietario API/Negocio; contrato de entrada y conjunto de casos de prueba anonimizados.

### 3.2 Regla de selección cuando hay varios registros

**Pregunta.** ¿Cuándo devuelve la API más de un registro y cuál es la regla para escoger el carnet: selección del usuario, póliza vigente más reciente, una combinación u otra?

- **Por qué importa:** un mismo documento puede corresponder a varias pólizas, órdenes, instituciones o renovaciones; elegir mal puede emitir el carnet incorrecto.
- **Comportamiento actual / supuesto temporal:** se presentan radios con póliza, orden y vigencia y el usuario elige; el servidor valida que la elección aún exista al descargar.
- **Decisión requerida:** criterios de agrupación/deduplicación, campos de identificación visibles, orden de presentación, registros vencidos, manejo de una sola coincidencia y selección por defecto si se permite.
- **Dueño o evidencia solicitada:** Negocio/propietario API; respuestas anonimizadas con uno, varios, renovados y vencidos.

### 3.3 Semántica y calidad de la respuesta API

**Pregunta.** ¿Cuál es el contrato versionado de respuesta y qué significan `SUCURSAL`, `ORDEN`, `CERTIFICADO`, beneficiario, cobertura y fechas?

- **Por qué importa:** el PDF y la selección requieren usar campos correctos, manejar nulos y determinar vigencia sin interpretar datos de forma ad hoc.
- **Comportamiento actual / supuesto temporal:** se consumen los campos conocidos de póliza/orden/certificado/sucursal/vigencias; la vigencia se muestra con los valores que entrega la API. No hay contrato completo de nulos, zona horaria ni estado activo confirmado.
- **Decisión requerida:** esquema y tipos, campos obligatorios/opcionales, significado de valores nulos, zona horaria/formato de fechas, señal oficial de vigencia/estado y compatibilidad/versionado.
- **Dueño o evidencia solicitada:** Equipo API; OpenAPI corregido o contrato JSON, ejemplos anonimizados y política de cambios.

### 3.4 Expectativas de disponibilidad y errores

**Pregunta.** ¿Qué volumen, picos, tiempos de respuesta, disponibilidad y experiencia de error se esperan para consulta y PDF?

- **Por qué importa:** define límites, timeouts, capacidad, monitoreo y mensajes de contingencia realistas.
- **Comportamiento actual / supuesto temporal:** el plugin limita por IP y devuelve mensajes genéricos ante fallos del upstream; no hay SLO, capacidad ni canal de soporte acordados.
- **Decisión requerida:** volumen esperado y pico, SLO/SLA, timeout/reintentos permitidos, mensajes/canal de atención para no encontrado, servicio caído y mantenimiento, y métricas/alertas requeridas.
- **Dueño o evidencia solicitada:** Producto/Operaciones/API; estimación de carga y acuerdo operativo.

---

## 4. Mejoras diferidas (no bloquean el MVP salvo decisión contraria)

- [ ] Validación pública de QR o código de carnet, si se aprueba en la sección 2.5.
- [ ] Soporte para múltiples tipos documentales tras confirmar el contrato de la sección 3.1.
- [ ] Paginación, búsqueda y reportes adicionales de auditoría, conservando acceso restringido.
- [ ] Métricas y tablero operativo conforme a la sección 3.4.
- [ ] Plantillas diferenciadas por producto, si la sección 2.2 lo requiere.
- [ ] Automatización de empaquetado/release para generar el ZIP con dependencias de producción.
- [ ] Ajustar el tamaño físico del carnet al estándar de tarjeta ISO/IEC 7810 ID-1 (85.60 x 53.98mm). La plantilla entregada por el cliente mide 84.14 x 50.00mm (1.46mm más angosta, 3.98mm más corta que el estándar); se mantiene tal cual mientras no haya decisión del cliente/diseño. El PDF ya usa dos páginas (portada + reverso con datos), que es el formato natural para impresión a doble cara de un carnet de tamaño estándar — de aprobarse el ajuste, solo cambia el tamaño de página y se recalibran las coordenadas de los 8 campos, no la arquitectura.

---

## 5. Criterios de aceptación para liberar a producción

No aprobar el release hasta poder marcar todos los puntos aplicables:

- [ ] La conectividad desde producción hacia la API fue probada con el mecanismo aprobado.
- [ ] Las credenciales de producción están almacenadas solo en el servidor/canal de secretos y fueron probadas sin exponerlas.
- [ ] `consumer`, `cod_pla`, TTL y manejo de token están confirmados por el responsable de API.
- [ ] CAPTCHA está configurado y probado en el dominio de producción, incluida su política de fallo.
- [ ] Formato documental y regla de múltiples registros están aprobados y cubiertos por pruebas representativas anonimizadas.
- [ ] El PDF usa arte final aprobado y ya no contiene textos temporales, salvo aceptación escrita explícita.
- [ ] Jurídica aprobó consentimiento, URL/copy de privacidad, campos auditados, acceso y retención.
- [ ] Casos de consulta encontrada, no encontrada, varias coincidencias, error upstream, límite de peticiones y descarga PDF fueron aceptados en ambiente objetivo.
- [ ] El paquete instalable contiene dependencias de producción; la activación crea/migra auditoría y programa retención correctamente.
- [ ] Existe responsable de despliegue, rollback, monitoreo y soporte, con fecha y ventana de liberación acordadas.

## Plantilla breve de registro de decisiones

| Fecha | Tema / pregunta | Decisión tomada | Responsable | Evidencia o enlace | Fecha límite | Estado |
| --- | --- | --- | --- | --- | --- | --- |
| AAAA-MM-DD |  |  |  |  |  | Pendiente |

## Cierre de reunión

Antes de cerrar, revisar los ítems sin decisión. Cada uno debe tener un responsable, una fecha y evidencia esperada; si impide un criterio de aceptación, se mantiene como bloqueador de producción.
