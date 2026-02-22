## httpClient

Biblioteca HTTP ligera sobre `fetch` con:

- interceptores de request/response/error (Set-based, retorno de función unsubscribe)
- timeout por request con cleanup automático
- retries con backoff exponencial + jitter
- headers por defecto inmutables (`Object.freeze`)
- manejo de errores tipados (`HttpError`, `TimeoutError`, `NetworkError`)
- parsing automático de respuesta (`json`, `text`, `blob`, `arrayBuffer`)
- streaming de respuestas (`ReadableStream`) para archivos grandes
- protección contra Prototype Pollution en manejo de headers

## Instalación

```bash
npm install
```

## Uso rápido

```js
import { HttpClient } from './src/HttpClient.js';

const api = new HttpClient({
  baseURL: 'https://api.example.com',
  timeout: 5000,
  retries: 1,
});

const users = await api.get('/users', { page: 1 });
const created = await api.post('/users', { name: 'Ada' });

// Ejemplo con parámetros incrustados y query params
const id = 88754794;
const nitSec = '1165';
const details = await api.get(`/dispensation/${id}`);
const attachments = await api.get(`/dispensation/${id}/attachments`, { nitSec });
```

## API pública

### Constructor

```js
const api = new HttpClient(config);
```

Opciones de `config`:

| Campo                | Tipo                        | Default | Descripción                                                                          |
| -------------------- | --------------------------- | ------- | ------------------------------------------------------------------------------------ |
| `baseURL`            | `string`                    | `''`    | URL base para endpoints relativos.                                                   |
| `headers`            | `object \| Headers`         | `{}`    | Headers por defecto del cliente.                                                     |
| `timeout`            | `number`                    | `0`     | Timeout en ms (`0` = sin timeout).                                                   |
| `retries`            | `number`                    | `0`     | Cantidad de reintentos ante error de red.                                            |
| `retryDelay`         | `number`                    | `1000`  | Delay base para backoff exponencial con jitter.                                      |
| `maxRetryDelay`      | `number`                    | `30000` | Duración máxima del delay entre reintentos.                                          |
| `shouldRetry`        | `(err, attempt) => boolean` | `null`  | Función personalizada para decidir si reintentar.                                    |
| `retryUnsafeMethods` | `boolean`                   | `false` | Permite retry en métodos no idempotentes (ej: `POST`).                               |
| `httpsOnly`          | `boolean`                   | `false` | Bloquea requests que no usen HTTPS.                                                  |
| `validateRedirects`  | `boolean`                   | `false` | Valida redirecciones contra `allowedHosts` y `httpsOnly` (usa `redirect: 'manual'`). |
| `allowedHosts`       | `string[]`                  | `[]`    | Allowlist de hosts permitidos.                                                       |

### Métodos HTTP

```js
api.get(endpoint, params?, options?)
api.post(endpoint, data?, options?)
api.put(endpoint, data?, options?)
api.patch(endpoint, data?, options?)
api.delete(endpoint, data?, options?)
```

### `setDefaultHeaders(headers)`

Actualiza los headers por defecto del cliente de forma segura (re-congela con `Object.freeze`).

```js
api.setDefaultHeaders({
  Authorization: 'Bearer new-token',
  'X-Custom': 'value',
});
```

> **Nota:** `defaultHeaders` está congelado (`Object.freeze`) desde la construcción. Mutaciones directas como `api.defaultHeaders['key'] = 'val'` no tendrán efecto. Siempre usa `setDefaultHeaders()`.

### `options` por request

| Campo          | Tipo                                                                | Default          | Descripción                                 |
| -------------- | ------------------------------------------------------------------- | ---------------- | ------------------------------------------- |
| `headers`      | `object \| Headers`                                                 | `{}`             | Headers específicos del request.            |
| `params`       | `object \| URLSearchParams`                                         | `{}`             | Query string (normalmente usado por `get`). |
| `timeout`      | `number`                                                            | `config.timeout` | Timeout solo para ese request.              |
| `signal`       | `AbortSignal`                                                       | `undefined`      | Cancelación externa.                        |
| `responseType` | `'auto' \| 'json' \| 'text' \| 'blob' \| 'arrayBuffer' \| 'stream'` | `'auto'`         | Estrategia de parsing de respuesta.         |

## Contrato de respuesta

Todos los métodos resuelven:

```js
{
  (data, // body parseado según content-type o responseType
    status, // número HTTP (ej: 200, 404)
    statusText, // texto de estado (ej: "OK", "Not Found")
    headers, // instancia de Headers
    ok); // boolean (true si status está en rango 200-299)
}
```

## Parsing de respuesta

- `responseType: 'auto'` con `application/json` => `json`.
- `responseType: 'auto'` con `image/*`, `audio/*`, `video/*`, `application/octet-stream`, `application/pdf`, etc. => `blob`.
- `responseType: 'auto'` en otros casos => `text`.
- `responseType: 'stream'` => retorna `response.body` (`ReadableStream`) sin buffering.
- `204` o `content-length: 0` => `data = null`.

## Streaming

Para respuestas grandes o descargas de archivos, usa `responseType: 'stream'` para evitar cargar todo en memoria:

```js
const response = await api.get('/large-file', {}, { responseType: 'stream' });
const reader = response.data.getReader();
const decoder = new TextDecoder();

while (true) {
  const { done, value } = await reader.read();
  if (done) break;
  console.log(decoder.decode(value, { stream: true }));
}
```

> **Nota:** `response.data` es un `ReadableStream` (disponible en navegadores y Node.js 18+).

## Serialización y headers

- `GET` no envía `content-type` por defecto.
- En `POST/PUT/PATCH/DELETE`, si `data` es objeto y no hay `content-type`, se envía JSON con `content-type: application/json`.
- En `POST/PUT/PATCH/DELETE`, si `data` es `FormData`, se elimina `content-type` para que el runtime agregue el boundary.
- Cuerpos binarios (`Blob`, `ArrayBuffer`, `URLSearchParams`, typed arrays) no se serializan a JSON.
- `DELETE` comparte la misma lógica de serialización que `POST/PUT/PATCH`.

## Interceptores

```js
const removeReq = api.addRequestInterceptor(async (config) => {
  // Modificar config
  config.headers.set('X-Trace-Id', '123');
  return config;
});

const removeRes = api.addResponseInterceptor(async (response) => {
  // Loggear o modificar respuesta
  return response;
});

const removeErr = api.addErrorInterceptor(async (error) => {
  // throw error; // propagar
  // return recoveredResponse; // recuperar
  console.error('Interceptor capturó error:', error);
  throw error;
});

// Para remover un interceptor, llama a la función retornada:
removeReq();
removeRes();
removeErr();
```

Orden de ejecución:

- request interceptors: antes de `fetch` (FIFO)
- response interceptors: solo en respuestas `ok` (FIFO)
- error interceptors: en errores HTTP, timeout, red y abort/cancelación (FIFO)

## Errores

Se exportan:

- `HttpError`: respuesta HTTP no exitosa (`status >= 400`).
- `TimeoutError`: timeout interno alcanzado (tiene getter `isTimeoutError`).
- `NetworkError`: fallas de red (tiene getter `isNetworkError`).

Ejemplo:

```js
import { HttpError, TimeoutError, NetworkError } from './src/HttpClient.js';

try {
  await api.get('/resource');
} catch (error) {
  if (error instanceof TimeoutError) {
    // timeout
  } else if (error instanceof NetworkError) {
    // red (ej: DNS, conexión rechazada)
    // error.cause contiene el error original
  } else if (error instanceof HttpError) {
    // status no-ok (4xx, 5xx)
  } else if (error?.name === 'AbortError') {
    // cancelación externa
  }
}
```

## Seguridad (opt-in)

```js
const api = new HttpClient({
  baseURL: 'https://api.example.com',
  httpsOnly: true,
  allowedHosts: ['api.example.com'],
});
```

- `httpsOnly: true` bloquea URLs `http://`.
- `allowedHosts` bloquea hosts fuera de la allowlist.
- `validateRedirects: true` habilita protección en redirecciones:
  - **Validación recursiva**: Verifica cada salto de redirección contra `allowedHosts` y `httpsOnly`.
  - **Elimina headers sensibles** (`Authorization`, `Cookie`, etc.) si la redirección es a un origen diferente (cross-origin).
- Si se viola una regla, se lanza `Error` con `name = 'SecurityError'`.
- Los headers internos están protegidos contra **Prototype Pollution** (claves `__proto__`, `constructor`, `prototype` se filtran automáticamente).

## Scripts

```bash
npm run lint
npm run format:check
npm test              # suite completa
npm run test:unit     # solo tests unitarios
npm run test:node     # solo tests de integración
npm run ci:check
```

## TODO (Próximo Sprint)

### Observabilidad sin acoplar el core

Objetivo: agregar telemetría de ciclo de vida sin dependencias externas y sin usar `console.log` como estrategia de producción.

Alcance propuesto:

- Agregar `hooks` opcionales en `config`:
  - `onRequest(ctx)`
  - `onRetry(ctx)`
  - `onResponse(ctx)`
  - `onError(ctx)`
- Implementar un emisor interno seguro (`_emitHook`) que:
  - no rompa la ejecución del request si un hook falla.
  - invoque hooks solo si están definidos.
- Enviar eventos a backend de observabilidad mediante `transport` (HTTP) definido por el consumidor, no desde el core con logging local.
- Mantener payloads mínimos y estables por evento:
  - `method`, `url`, `attempt`, `status`, `durationMs`, `errorName`.
- Aplicar redacción de datos sensibles antes de emitir:
  - `authorization`, `cookie`, tokens y secretos en headers/body.

Criterios de aceptación:

- No hay `console.log` en flujo de observabilidad de producción.
- La librería sigue funcionando aunque falle el backend de logs.
- Hooks 100% opt-in y backward compatible.
- Tests unitarios para:
  - ejecución de hooks en éxito/retry/error.
  - no propagación de errores de hooks.
  - redacción de campos sensibles.
