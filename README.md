# Carnet Equidad

Plugin de WordPress: una página pública donde una persona escribe su número de
cédula, el plugin consulta el servicio **Api Data Carnet** de La Equidad del lado
del servidor, y la persona elige cuál de sus registros de póliza quiere.

> Alcance del incremento 1. La generación del carnet en PDF descargable a partir
> del registro elegido **no** forma parte de este incremento.

## Qué hace

- El shortcode **`[carnet_equidad_form]`** muestra un formulario público con un
  solo campo (cédula). Un script pequeño en JavaScript sin dependencias y un CSS
  mínimo se cargan **únicamente** en las páginas que contienen el shortcode.
- Ruta REST **`POST /wp-json/carnet/v1/consulta`**, cuerpo `{ "cedula": "..." }`:
  - Limpia la cédula dejando solo dígitos; exige entre 6 y 11 dígitos (`400` en
    caso contrario).
  - Llama a `ApiDataCarnetClient` y transforma el resultado:
    - **encontrado** → `200 { "status": "found", "asegurado": "<NOMBRE_ASEGURADO del primer registro>", "opciones": [ { "id", "poliza", "orden", "certificado", "sucursal", "vigencia_desde", "vigencia_hasta" } ] }`
    - **no encontrado** (`404` del servicio) → `200 { "status": "not_found" }`
    - **fallo del servicio / autenticación / configuración** → `502 { "status": "error", "message": "<mensaje genérico>" }`
  - La respuesta nunca incluye datos del beneficiario ni el campo `PRIMA`.
  - `permission_callback` es `__return_true` (público) por ahora. El cliente
    JavaScript igual envía el nonce `wp_rest`. **PENDIENTE: rate-limit + CAPTCHA
    antes de producción.**
- El JavaScript del front-end envía la petición con `fetch` y representa los
  estados `loading` / `not_found` / `error` / `found`. El estado `found` muestra
  las opciones como una lista de radios (Póliza / Orden / Vigencia). El botón
  "Continuar" por ahora solo vuelca la opción elegida en un bloque de resumen y en
  `console.log` — marcado con `// TODO: next increment -> call /pdf endpoint`.

## Arquitectura

Distribución orientada a *screaming* / hexagonal bajo `src/`:

| Ruta | Responsabilidad |
| --- | --- |
| `src/Validation/CedulaValidator.php` | Limpia y valida la cédula (puro) |
| `src/Http/HttpTransport.php` | Interfaz de transporte (`post()`) — punto de inyección |
| `src/Http/WpHttpTransport.php` | Implementación con `wp_remote_post` |
| `src/Http/HttpResponse.php` | Objeto de valor para la respuesta |
| `src/Http/TransportException.php` | Fallo a nivel de red |
| `src/Api/ApiDataCarnetClient.php` | Orquesta token + `dataAsegurado` (puro) |
| `src/Api/ClientConfig.php` | Configuración desde constantes; los getters lanzan excepción si faltan |
| `src/Api/TokenStore.php` | Interfaz de caché del token — punto de inyección |
| `src/Api/WpTransientTokenStore.php` | Implementación basada en transients |
| `src/Api/PolicyOptionMapper.php` | Registro crudo → forma recortada `opciones` (puro) |
| `src/Api/ApiClientFactory.php` | Ensambla el cliente con sus colaboradores de WP |
| `src/Api/Exception/*` | `ConfigException`, `NotFoundException`, `AuthException`, `UpstreamException` |
| `src/Rest/ConsultaController.php` | Registra y atiende la ruta REST |
| `src/Frontend/ShortcodeRenderer.php` | Shortcode + encolado condicional de assets |
| `src/Plugin.php` | Raíz de composición (hooks) |

`ApiDataCarnetClient`, `CedulaValidator` y `PolicyOptionMapper` **no dependen de
WordPress**, y eso es lo que permite probarlos de forma unitaria.

### Comportamiento del cliente del servicio

- `getToken()` → `POST {base}/api/v1/validarToken` con `{ "user", "password" }`.
  La clave del token en el JSON es literalmente `"token: "` (con espacio y dos
  puntos al final); se acepta una clave limpia `"token"` como alternativa. El
  token se cachea en el transient `carnet_equidad_api_token` con un **TTL
  conservador de 10 minutos** (la duración real no está confirmada — pregunta
  abierta #5).
- `getDataAsegurado(string $cedula): array` → `POST {base}/api/v1/dataAsegurado`
  con `Authorization: Bearer <token>` y
  `{ "consumer": "Linktic", "doc_aseg": <cedula>, "cod_pla": "1821" }`.
  - `200` → devuelve el array de registros crudos ya decodificado.
  - `404` → lanza **`NotFoundException`** (centinela elegido; no devuelve `null`).
  - `401` → borra el token cacheado, se vuelve a autenticar y reintenta **una
    vez**; un segundo `401` lanza `AuthException`.
  - cualquier otro código fuera de 2xx / fallo de transporte → lanza
    `UpstreamException`.
- `consumer = "Linktic"` y `cod_pla = "1821"` son constantes de clase, todavía
  pendientes de confirmación formal (preguntas abiertas #8 y #10).

## Constantes requeridas en `wp-config.php`

Agrégalas **antes** de la línea `/* That's all, stop editing! */`:

```php
define( 'CARNET_API_USER', 'user0017-LIN-0033' );      // requerida — credencial real
define( 'CARNET_API_PASSWORD', 'tu-clave-aqui' );       // requerida — credencial real
define( 'CARNET_API_BASE_URL', 'http://192.168.243.194:9050' ); // opcional — este es el valor por defecto
```

`CARNET_API_BASE_URL` es opcional; si se omite, el cliente usa
`http://192.168.243.194:9050` (IP privada, accesible solo a través de la VPN).
`CARNET_API_USER` / `CARNET_API_PASSWORD` son obligatorias — el cliente lanza
`ConfigException` (mapeada a un error genérico `502`) cuando falta alguna.

## Instalación

```bash
cd wp-content/plugins/carnet-equidad
composer install --no-dev   # producción
```

Luego activa "Carnet Equidad" en wp-admin y coloca `[carnet_equidad_form]` en una
página.

## Ejecución de las pruebas

```bash
cd wp-content/plugins/carnet-equidad
composer install            # incluye phpunit (dev)
vendor/bin/phpunit
```

La suite es PHP puro — **no requiere arrancar WordPress**. Cubre:

- `CedulaValidator` — limpieza y la regla de 6 a 11 dígitos.
- `ApiDataCarnetClient` con un `HttpTransport` falso + un `TokenStore` falso:
  camino feliz, cacheo y reutilización del token, la clave rara `"token: "`,
  centinela de `404`, un `401` → re-autenticación + reintento, `401` repetido →
  `AuthException`, `5xx` y fallo de transporte → `UpstreamException`.
- `PolicyOptionMapper` — forma recortada, campos de beneficiario / `PRIMA`
  descartados, normalización de fecha `"2026-02-01T00:00:00"` → `"2026-02-01"`.

## Pendientes / preguntas abiertas

De `Documents/carnet-equidad/acercamiento-proyecto.md` §9. Estas afectan a **este**
incremento:

- **#11 / #13 — formato de la cédula y ceros a la izquierda.** Por ahora quitamos
  todo lo que no sea dígito y conservamos los ceros a la izquierda tal como se
  escriben. Sin confirmar si el servicio espera el documento "tal cual" sin
  dígito de verificación, ni si los ceros a la izquierda importan.
- **#14 — el rango de longitud 6–11.** Tomado del documento de trabajo; sin
  confirmar que ningún documento real quede fuera de ese rango.
- **#15 — por qué `dataAsegurado` devuelve un array.** Exponemos cada registro
  como una opción seleccionable; los casos reales con múltiples registros (varias
  pólizas, renovaciones, varias instituciones) aún no están confirmados.
- **#5 — TTL del token.** No hay duración documentada ni endpoint de refresco, así
  que el TTL del transient es una estimación conservadora (10 min) y además lo
  refrescamos de forma reactiva ante un `401`.
- **#21 — formato de fecha / zona horaria.** `"2026-02-01T00:00:00"` se trunca a
  la parte de fecha; se desconoce la zona horaria.

### Bloquea el SIGUIENTE incremento (carnet en PDF)

- **#23 / #24 / #25** — cuál de los dos diseños del carnet es el definitivo, en
  qué formato (PDF vectorial vs imagen vs AI/PSD), dimensiones finales y si lleva
  reverso.
- **#16** — cuando hay varios registros, cuál debe usar el carnet.
- **#17** — de dónde salen los campos "Tomador" y "V/r asegurado por gastos
  médicos" (no vienen en la respuesta de la API).
- **#26 / #27 / #28** — foto, código QR / de verificación, y textos legales /
  firmas / logos obligatorios en el carnet.
- **Elección de la librería PDF** (FPDI+TCPDF vs mPDF), a la espera del formato
  final de la plantilla.
