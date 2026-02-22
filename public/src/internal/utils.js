/**
 * Verifica si una URL es absoluta (comienza con http:// o https://).
 *
 * @param {string} url - URL a verificar
 * @returns {boolean}
 */
function isAbsoluteURL(url) {
  return /^https?:\/\//i.test(url);
}

/**
 * Construye una URL completa combinando base, path y parámetros de query string.
 *
 * @param {string} base - URL base (ej: 'https://api.example.com')
 * @param {string} path - Ruta del endpoint (ej: '/users')
 * @param {Object} [params={}] - Parámetros de query string
 * @returns {string} URL completa
 */
function buildURL(base, path, params = {}) {
  let url;

  if (isAbsoluteURL(path)) {
    url = new URL(path);
  } else if (!base) {
    // Browser: usar origin actual como base implícita
    if (typeof globalThis !== 'undefined' && globalThis.location?.origin) {
      url = new URL(path, globalThis.location.origin);
    } else {
      throw new TypeError(
        `Cannot resolve relative URL "${path}" without a baseURL. ` +
          'Provide config.baseURL or use an absolute URL.',
      );
    }
  } else {
    const cleanBase = base.replace(/\/+$/, '');
    const cleanPath = path.replace(/^\/+/, '');
    url = new URL(`${cleanBase}/${cleanPath}`);
  }

  if (typeof URLSearchParams !== 'undefined' && params instanceof URLSearchParams) {
    params.forEach((value, key) => {
      url.searchParams.append(key, value);
    });
  } else {
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        url.searchParams.append(key, String(value));
      }
    });
  }

  return url.toString();
}

/**
 * Claves prohibidas para prevenir ataques de Prototype Pollution.
 * @see https://github.com/nicke3/json-prototype-pollution
 */
const FORBIDDEN_PROTO_KEYS = new Set(['__proto__', 'constructor', 'prototype']);

/**
 * Combina dos objetos de headers, dando prioridad a los custom.
 * Usa Object.create(null) y filtra claves peligrosas para prevenir
 * ataques de Prototype Pollution.
 *
 * @param {Object} defaults - Headers por defecto
 * @param {Object} [custom={}] - Headers personalizados (sobreescriben defaults)
 * @returns {Object} Headers combinados (sin prototipo)
 */
function mergeHeaders(defaults, custom = {}) {
  const merged = Object.create(null);

  Object.entries(defaults).forEach(([key, value]) => {
    if (FORBIDDEN_PROTO_KEYS.has(key)) return;
    merged[key.toLowerCase()] = value;
  });

  Object.entries(custom).forEach(([key, value]) => {
    if (FORBIDDEN_PROTO_KEYS.has(key)) return;
    merged[key.toLowerCase()] = value;
  });

  return merged;
}

/**
 * Retorna una promesa que se resuelve después de un tiempo dado.
 *
 * @param {number} ms - Milisegundos a esperar
 * @returns {Promise<void>}
 */
function delay(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

/**
 * Patrones regex para detectar content-types binarios.
 *
 * Cobertura:
 * - Categorías completas: image/*, audio/*, video/*
 * - Tipos application específicos: octet-stream, pdf, zip, gzip, x-tar, x-7z-compressed, vnd.*
 *
 * Limitaciones conocidas:
 * - application/wasm, application/protobuf y otros tipos binarios custom NO se detectan.
 * - Para estos casos, usar responseType: 'blob' o 'arrayBuffer' explícitamente.
 */
const BINARY_CONTENT_TYPE_RE = /^(image|audio|video)\//i;
const BINARY_APP_TYPES_RE =
  /^application\/(octet-stream|pdf|zip|gzip|x-tar|x-7z-compressed|vnd\.)/i;
const ALLOWED_RESPONSE_TYPES = new Set(['auto', 'json', 'text', 'blob', 'arrayBuffer', 'stream']);
const ERROR_INTERCEPTORS_PROCESSED = Symbol('errorInterceptorsProcessed');

/**
 * Verifica si un content-type es binario.
 *
 * @param {string} contentType - Valor del header Content-Type
 * @returns {boolean}
 */
function isBinaryContentType(contentType) {
  if (!contentType) return false;
  const type = contentType.split(';')[0].trim();
  return BINARY_CONTENT_TYPE_RE.test(type) || BINARY_APP_TYPES_RE.test(type);
}

/**
 * Verifica si un valor es un cuerpo binario que no debe serializarse a JSON.
 *
 * @param {*} body - Cuerpo a verificar
 * @returns {boolean}
 */
function isBinaryBody(body) {
  return (
    body instanceof FormData ||
    body instanceof Blob ||
    body instanceof ArrayBuffer ||
    body instanceof URLSearchParams ||
    (typeof ArrayBuffer !== 'undefined' && ArrayBuffer.isView(body))
  );
}

/**
 * Normaliza headers de entrada.
 *
 * @param {Object|Headers|undefined|null} headers - Headers de entrada
 * @param {string} source - Nombre del campo para mensajes de error
 * @returns {Object} Headers normalizados a objeto plano
 */
function normalizeHeaders(headers, source) {
  if (headers === undefined || headers === null) return {};
  if (typeof Headers !== 'undefined' && headers instanceof Headers) {
    return Object.fromEntries(headers.entries());
  }
  if (typeof headers === 'object' && !Array.isArray(headers)) {
    return headers;
  }
  throw new TypeError(`${source} must be an object or Headers instance`);
}

/**
 * Normaliza parámetros de query string.
 *
 * @param {Object|URLSearchParams|undefined|null} params - Parámetros de entrada
 * @returns {Object|URLSearchParams} Parámetros normalizados
 */
function normalizeParams(params) {
  if (params === undefined || params === null) return {};
  if (typeof URLSearchParams !== 'undefined' && params instanceof URLSearchParams) {
    return params;
  }
  if (typeof params === 'object' && !Array.isArray(params)) {
    return params;
  }
  throw new TypeError('options.params must be an object or URLSearchParams');
}

/**
 * Crea un error de seguridad para validaciones de endpoint.
 *
 * @param {string} message - Mensaje del error
 * @returns {Error}
 */
function createSecurityError(message) {
  const error = new Error(message);
  error.name = 'SecurityError';
  return error;
}

/**
 * Valida restricciones opcionales de seguridad sobre la URL final.
 *
 * @param {string} urlString - URL completa de request
 * @param {Object} options - Opciones de seguridad
 * @param {boolean} options.httpsOnly - Permite solo protocolo HTTPS
 * @param {string[]} options.allowedHosts - Lista de hosts permitidos
 */
function ensureRequestURLAllowed(urlString, { httpsOnly, allowedHosts }) {
  const parsedURL = new URL(urlString);

  if (httpsOnly && parsedURL.protocol !== 'https:') {
    throw createSecurityError(`Blocked non-HTTPS request: ${urlString}`);
  }

  if (
    Array.isArray(allowedHosts) &&
    allowedHosts.length > 0 &&
    !allowedHosts.includes(parsedURL.hostname.toLowerCase())
  ) {
    throw createSecurityError(`Blocked host not in allowlist: ${parsedURL.hostname}`);
  }
}

export {
  buildURL,
  mergeHeaders,
  delay,
  isBinaryContentType,
  isBinaryBody,
  normalizeHeaders,
  normalizeParams,
  ALLOWED_RESPONSE_TYPES,
  ERROR_INTERCEPTORS_PROCESSED,
  FORBIDDEN_PROTO_KEYS,
  ensureRequestURLAllowed,
};
