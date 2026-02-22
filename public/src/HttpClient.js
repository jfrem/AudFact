/**
 * httpClient — Biblioteca ligera de cliente HTTP sobre fetch
 *
 * @module HttpClient
 * @description Cliente HTTP para navegadores construido sobre la API nativa fetch.
 * Proporciona una interfaz simple con soporte para interceptores, timeout,
 * reintentos automáticos y manejo de errores tipados.
 */

import { HttpError, TimeoutError, NetworkError } from './internal/errors.js';
import {
  buildURL,
  mergeHeaders,
  delay,
  isBinaryContentType,
  isBinaryBody,
  normalizeHeaders,
  normalizeParams,
  ALLOWED_RESPONSE_TYPES,
  ERROR_INTERCEPTORS_PROCESSED,
  ensureRequestURLAllowed,
} from './internal/utils.js';
import {
  validateNonNegativeNumber,
  validateNonNegativeInteger,
  validateBoolean,
  validateStringArray,
} from './internal/validators.js';

/**
 * Cliente HTTP ligero construido sobre fetch.
 *
 * ## API Básica (Core)
 * - get, post, put, patch, delete
 * - config: baseURL, headers, timeout
 *
 * ## API Avanzada (Opt-in)
 * - Interceptores: addRequestInterceptor, addResponseInterceptor, addErrorInterceptor
 * - Retry: retries, retryDelay, maxRetryDelay, shouldRetry, retryUnsafeMethods
 * - Seguridad: httpsOnly, allowedHosts, validateRedirects
 * - Streaming: responseType: 'stream'
 *
 * @class HttpClient
 */
class HttpClient {
  /**
   * Crea una nueva instancia de HttpClient.
   *
   * @param {Object} [config={}] - Configuración del cliente
   * @param {string} [config.baseURL=''] - URL base para todas las peticiones
   * @param {Object} [config.headers={}] - Headers por defecto en cada petición
   * @param {number} [config.timeout=0] - Timeout en ms (0 = sin timeout)
   * @param {number} [config.retries=0] - Número de reintentos ante fallo de red
   * @param {number} [config.retryDelay=1000] - Delay base entre reintentos (ms)
   * @param {number} [config.maxRetryDelay=30000] - Tope máximo del backoff en ms
   * @param {Function} [config.shouldRetry=null] - Callback(error, attempt) que retorna boolean para controlar retry
   * @param {boolean} [config.retryUnsafeMethods=false] - Permite retries en métodos no idempotentes (POST/PATCH)
   * @param {boolean} [config.httpsOnly=false] - Restringe requests a endpoints HTTPS
   * @param {string[]} [config.allowedHosts=[]] - Lista de hosts permitidos (allowlist)
   * @param {boolean} [config.validateRedirects=false] - Valida URLs de redirección contra allowlist/httpsOnly
   */
  constructor(config = {}) {
    if (config === null || typeof config !== 'object' || Array.isArray(config)) {
      throw new TypeError('config must be an object');
    }
    if (config.baseURL !== undefined && typeof config.baseURL !== 'string') {
      throw new TypeError('config.baseURL must be a string');
    }
    validateNonNegativeNumber(config.timeout, 'config.timeout');
    validateNonNegativeInteger(config.retries, 'config.retries');
    validateNonNegativeNumber(config.retryDelay, 'config.retryDelay');
    validateNonNegativeNumber(config.maxRetryDelay, 'config.maxRetryDelay');
    if (config.shouldRetry !== undefined && typeof config.shouldRetry !== 'function') {
      throw new TypeError('config.shouldRetry must be a function');
    }
    validateBoolean(config.retryUnsafeMethods, 'config.retryUnsafeMethods');
    validateBoolean(config.httpsOnly, 'config.httpsOnly');
    validateStringArray(config.allowedHosts, 'config.allowedHosts');
    validateBoolean(config.validateRedirects, 'config.validateRedirects');

    this.baseURL = config.baseURL || '';
    this.defaultHeaders = Object.freeze({
      ...normalizeHeaders(config.headers, 'config.headers'),
    });
    this.timeout = config.timeout ?? 0;
    this.retries = config.retries ?? 0;
    this.retryDelay = config.retryDelay ?? 1000;
    this.maxRetryDelay = config.maxRetryDelay ?? 30000;
    this.shouldRetry = config.shouldRetry ?? null;
    this.retryUnsafeMethods = config.retryUnsafeMethods ?? false;
    this.httpsOnly = config.httpsOnly ?? false;
    this.allowedHosts = (config.allowedHosts ?? []).map((host) => host.toLowerCase());
    this.validateRedirects = config.validateRedirects ?? false;

    /** @private */
    this._requestInterceptors = new Set();
    /** @private */
    this._responseInterceptors = new Set();
    /** @private */
    this._errorInterceptors = new Set();
  }

  /**
   * Reemplaza los headers por defecto con nuevos headers validados.
   *
   * @param {Object} headers - Nuevos headers
   */
  setDefaultHeaders(headers) {
    this.defaultHeaders = Object.freeze({
      ...normalizeHeaders(headers, 'headers'),
    });
  }

  /**
   * Registra un interceptor de request.
   *
   * @param {Function} fn - Función interceptora (config) => config | Promise<config>
   * @returns {Function} Función para desuscribir el interceptor
   */
  addRequestInterceptor(fn) {
    if (typeof fn !== 'function') {
      throw new TypeError('Interceptor must be a function');
    }
    this._requestInterceptors.add(fn);
    return () => this._requestInterceptors.delete(fn);
  }

  /**
   * Registra un interceptor de response.
   *
   * @param {Function} fn - Función interceptora (response) => response | Promise<response>
   * @returns {Function} Función para desuscribir el interceptor
   */
  addResponseInterceptor(fn) {
    if (typeof fn !== 'function') {
      throw new TypeError('Interceptor must be a function');
    }
    this._responseInterceptors.add(fn);
    return () => this._responseInterceptors.delete(fn);
  }

  /**
   * Registra un interceptor de error.
   *
   * @param {Function} fn - Función interceptora (error) => result | Promise<result>
   * @returns {Function} Función para desuscribir el interceptor
   */
  addErrorInterceptor(fn) {
    if (typeof fn !== 'function') {
      throw new TypeError('Interceptor must be a function');
    }
    this._errorInterceptors.add(fn);
    return () => this._errorInterceptors.delete(fn);
  }

  get(endpoint, params = {}, options = {}) {
    return this._request('GET', endpoint, null, { ...options, params });
  }

  post(endpoint, data = {}, options = {}) {
    return this._request('POST', endpoint, data, options);
  }

  put(endpoint, data = {}, options = {}) {
    return this._request('PUT', endpoint, data, options);
  }

  patch(endpoint, data = {}, options = {}) {
    return this._request('PATCH', endpoint, data, options);
  }

  delete(endpoint, data = {}, options = {}) {
    return this._request('DELETE', endpoint, data, options);
  }

  async _request(method, endpoint, data = null, options = {}) {
    const config = await this._buildConfig(method, endpoint, data, options);

    const activeTimeout = options.timeout ?? this.timeout;
    const externalSignal = options.signal || null;
    const timeout = this._setupTimeout(activeTimeout, externalSignal);

    let lastError = null;
    const maxAttempts = this.retries + 1;
    const isIdempotentMethod = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'].includes(method);
    const canRetryMethod = isIdempotentMethod || this.retryUnsafeMethods;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      try {
        return await this._performFetch(config, timeout);
      } catch (error) {
        this._cleanupTimeout(timeout);

        const handled = await this._handleFetchError(error, timeout, activeTimeout);
        if (handled.throw) throw handled.throw;
        if (handled.return) return handled.return;

        lastError = new NetworkError(error.message || 'Network request failed', { cause: error });

        let userShouldRetry = null;
        if (this.shouldRetry) {
          let decision;
          try {
            decision = await this.shouldRetry(lastError, attempt);
          } catch (callbackError) {
            const retryDecisionError =
              callbackError instanceof Error
                ? callbackError
                : new Error(`shouldRetry callback failed: ${String(callbackError)}`, {
                    cause: callbackError,
                  });
            const recovered = await this._runErrorInterceptors(retryDecisionError);
            if (recovered) return recovered;
            throw retryDecisionError;
          }

          if (decision !== null && decision !== undefined && typeof decision !== 'boolean') {
            const retryDecisionTypeError = new TypeError(
              'config.shouldRetry must return a boolean, null, or undefined',
            );
            const recovered = await this._runErrorInterceptors(retryDecisionTypeError);
            if (recovered) return recovered;
            throw retryDecisionTypeError;
          }

          userShouldRetry = decision ?? null;
        }
        const shouldRetryAttempt =
          userShouldRetry !== null ? userShouldRetry : attempt < maxAttempts && canRetryMethod;

        if (shouldRetryAttempt) {
          const jitter = Math.random() * this.retryDelay * 0.5;
          const rawBackoff = this.retryDelay * 2 ** (attempt - 1) + jitter;
          const backoff = Math.min(rawBackoff, this.maxRetryDelay);
          await delay(backoff);

          this._resetTimeout(timeout, activeTimeout, externalSignal);
        } else {
          break;
        }
      }
    }

    const recovered = await this._runErrorInterceptors(lastError);
    if (recovered) return recovered;
    throw lastError;
  }

  /**
   * Construye la configuración del request: valida opciones, construye URL,
   * merge headers, serializa body y ejecuta interceptores de request.
   *
   * @private
   * @param {string} method - Método HTTP
   * @param {string} endpoint - Ruta del endpoint
   * @param {*} data - Datos del body
   * @param {Object} options - Opciones del request
   * @returns {Object} Config lista para fetch
   */
  async _buildConfig(method, endpoint, data, options) {
    if (options === null || typeof options !== 'object' || Array.isArray(options)) {
      throw new TypeError('options must be an object');
    }

    const {
      headers: rawHeaders = {},
      params: rawParams = {},
      timeout: requestTimeout,
      signal: externalSignal,
      responseType = 'auto',
    } = options;
    const customHeaders = normalizeHeaders(rawHeaders, 'options.headers');
    const params = normalizeParams(rawParams);

    validateNonNegativeNumber(requestTimeout, 'options.timeout');
    if (!ALLOWED_RESPONSE_TYPES.has(responseType)) {
      throw new TypeError(
        `options.responseType must be one of: ${Array.from(ALLOWED_RESPONSE_TYPES).join(', ')}`,
      );
    }
    if (
      externalSignal !== undefined &&
      externalSignal !== null &&
      (typeof externalSignal !== 'object' || typeof externalSignal.aborted !== 'boolean')
    ) {
      throw new TypeError('options.signal must be an AbortSignal');
    }

    let config = {
      method,
      url: buildURL(this.baseURL, endpoint, params),
      headers: mergeHeaders(this.defaultHeaders, customHeaders),
      body: null,
      responseType,
    };

    this._serializeBody(config, method, data);

    for (const interceptor of this._requestInterceptors.values()) {
      config = await interceptor(config);
    }
    ensureRequestURLAllowed(config.url, {
      httpsOnly: this.httpsOnly,
      allowedHosts: this.allowedHosts,
    });

    return config;
  }

  /**
   * Serializa el body del request según el método y tipo de datos.
   *
   * @private
   * @param {Object} config - Config mutable del request
   * @param {string} method - Método HTTP
   * @param {*} data - Datos del body
   */
  _serializeBody(config, method, data) {
    const hasBody = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);
    if (!hasBody || data === null || data === undefined) return;

    if (isBinaryBody(data)) {
      config.body = data;
      if (data instanceof FormData) {
        delete config.headers['content-type'];
      }
      return;
    }

    const contentType = config.headers['content-type'] || '';
    const shouldUseJSON =
      contentType.includes('application/json') ||
      (!contentType && typeof data === 'object' && data !== null);

    if (shouldUseJSON) {
      if (!contentType) {
        config.headers['content-type'] = 'application/json';
      }
      config.body = JSON.stringify(data);
    } else {
      config.body = data;
    }
  }

  /**
   * Configura el timeout con AbortController y vincula señal externa.
   *
   * @private
   * @param {number} activeTimeout - Timeout en ms (0 = sin timeout)
   * @param {AbortSignal|null} externalSignal - Señal de cancelación externa
   * @returns {Object} Estado del timeout: { signal, controller, timeoutId, timedOut, externalAbortHandler, externalSignal }
   */
  _setupTimeout(activeTimeout, externalSignal) {
    const state = {
      controller: null,
      timeoutId: null,
      externalAbortHandler: null,
      timedOut: false,
      signal: externalSignal || null,
      externalSignal,
    };

    if (activeTimeout <= 0) return state;

    state.controller = new AbortController();
    state.signal = state.controller.signal;

    if (externalSignal && !externalSignal.aborted) {
      state.externalAbortHandler = () => state.controller.abort();
      externalSignal.addEventListener('abort', state.externalAbortHandler, { once: true });
    } else if (externalSignal?.aborted) {
      state.controller.abort();
    }

    const { controller } = state;
    state.timeoutId = setTimeout(() => {
      state.timedOut = true;
      controller.abort();
    }, activeTimeout);

    return state;
  }

  /**
   * Limpia timeout y listeners de señal externa.
   *
   * @private
   * @param {Object} timeout - Estado del timeout
   */
  _cleanupTimeout(timeout) {
    if (timeout.timeoutId) {
      clearTimeout(timeout.timeoutId);
      timeout.timeoutId = null;
    }
    if (timeout.externalSignal && timeout.externalAbortHandler) {
      timeout.externalSignal.removeEventListener('abort', timeout.externalAbortHandler);
      timeout.externalAbortHandler = null;
    }
  }

  /**
   * Reinicia el timeout para un nuevo intento de retry.
   *
   * @private
   * @param {Object} timeout - Estado del timeout
   * @param {number} activeTimeout - Timeout en ms
   * @param {AbortSignal|null} externalSignal - Señal de cancelación externa
   */
  _resetTimeout(timeout, activeTimeout, externalSignal) {
    if (activeTimeout <= 0) return;

    timeout.controller = new AbortController();
    timeout.signal = timeout.controller.signal;
    timeout.timedOut = false;

    if (externalSignal && !externalSignal.aborted) {
      timeout.externalAbortHandler = () => timeout.controller.abort();
      externalSignal.addEventListener('abort', timeout.externalAbortHandler, { once: true });
    } else if (externalSignal?.aborted) {
      timeout.controller.abort();
    }

    const { controller } = timeout;
    timeout.timeoutId = setTimeout(() => {
      timeout.timedOut = true;
      controller.abort();
    }, activeTimeout);
  }

  /**
   * Verifica si un status code es un redirect HTTP.
   *
   * @private
   * @param {number} status - Código de estado HTTP
   * @returns {boolean}
   */
  _isRedirect(status) {
    return [301, 302, 303, 307, 308].includes(status);
  }

  /**
   * Valida y sigue una redirección manualmente, aplicando las políticas de
   * seguridad (httpsOnly, allowedHosts) sobre la URL destino.
   *
   * @private
   * @param {Response} response - Respuesta con status 3xx
   * @param {string} currentUrl - URL actual del request (puede ser la original o una de redirect)
   * @param {Object} currentHeaders - Headers actuales del request (pueden haber sido saneados)
   * @param {string} currentMethod - Método actual tras redirects previos
   * @param {*} currentBody - Body actual tras redirects previos
   * @param {Object} config - Config del request original
   * @param {Object} timeout - Estado del timeout
   * @param {number} redirectCount - Contador de redirecciones
   * @returns {Response} Respuesta final tras seguir el redirect
   */
  async _handleRedirect(
    response,
    currentUrl,
    currentHeaders,
    currentMethod,
    currentBody,
    config,
    timeout,
    redirectCount,
  ) {
    if (redirectCount >= 20) {
      throw new NetworkError('Max redirects exceeded', {
        cause: new Error('Max redirects exceeded'),
      });
    }

    const location = response.headers.get('location');
    if (!location) return response;

    // Resolver URL relativa contra la URL actual
    const redirectURL = new URL(location, currentUrl).toString();

    // Validar la URL de redirección contra las políticas de seguridad
    ensureRequestURLAllowed(redirectURL, {
      httpsOnly: this.httpsOnly,
      allowedHosts: this.allowedHosts,
    });

    // Detectar cambio de origen para proteger credenciales (step-by-step)
    const currentOrigin = new URL(currentUrl).origin;
    const redirectOrigin = new URL(redirectURL).origin;

    let headers = currentHeaders;
    if (currentOrigin !== redirectOrigin) {
      // Cross-origin redirect: eliminar headers sensibles (case-insensitive)
      headers = { ...headers };
      const sensitive = ['authorization', 'www-authenticate', 'cookie', 'cookie2'];
      Object.keys(headers).forEach((key) => {
        if (sensitive.includes(key.toLowerCase())) {
          delete headers[key];
        }
      });
    }

    const shouldRewriteToGet = [301, 302, 303].includes(response.status);
    const nextMethod = shouldRewriteToGet ? 'GET' : currentMethod;
    const nextBody = shouldRewriteToGet ? null : currentBody;

    // Seguir el redirect con la URL validada y headers saneados
    const redirectOptions = {
      method: nextMethod,
      headers,
      body: nextBody,
      redirect: 'manual',
    };

    if (timeout.signal) {
      redirectOptions.signal = timeout.signal;
    }

    const nextResponse = await fetch(redirectURL, redirectOptions);

    if (this._isRedirect(nextResponse.status)) {
      return this._handleRedirect(
        nextResponse,
        redirectURL,
        headers,
        nextMethod,
        nextBody,
        config,
        timeout,
        redirectCount + 1,
      );
    }

    return nextResponse;
  }

  /**
   * Clasifica un error de fetch y ejecuta interceptores de error.
   * Retorna { throw: error } si debe propagarse, { return: result } si fue recuperado.
   *
   * @private
   * @param {Error} error - Error capturado
   * @param {Object} timeout - Estado del timeout
   * @param {number} activeTimeout - Timeout activo
   * @returns {Object} { throw?, return? } — Solo uno de los dos estará definido, o ninguno para continuar retry
   */
  async _handleFetchError(error, timeout, activeTimeout) {
    if (error.name === 'AbortError') {
      if (!timeout.timedOut) {
        const recovered = await this._runErrorInterceptors(error);
        if (recovered) return { return: recovered };
        return { throw: error };
      }

      const timeoutError = new TimeoutError(
        `Request timeout after ${activeTimeout}ms`,
        activeTimeout,
      );

      const recovered = await this._runErrorInterceptors(timeoutError);
      if (recovered) return { return: recovered };
      return { throw: timeoutError };
    }

    if (error.name === 'SecurityError') {
      return { throw: error };
    }

    if (error instanceof HttpError) {
      if (error[ERROR_INTERCEPTORS_PROCESSED]) {
        return { throw: error };
      }
      const recovered = await this._runErrorInterceptors(error);
      if (recovered) return { return: recovered };
      return { throw: error };
    }

    return {};
  }

  async _parseResponse(response, responseType) {
    if (responseType === 'stream') {
      return response.body;
    }

    if (response.status === 204 || response.headers.get('content-length') === '0') {
      return null;
    }

    let effectiveType = responseType;
    if (responseType === 'auto') {
      const contentType = response.headers.get('content-type') || '';
      if (isBinaryContentType(contentType)) {
        effectiveType = 'blob';
      } else if (contentType.includes('application/json')) {
        effectiveType = 'json';
      } else {
        effectiveType = 'text';
      }
    }

    const fallbackResponse =
      responseType === 'json' || responseType === 'auto' ? response.clone() : null;

    try {
      switch (effectiveType) {
        case 'text':
          return await response.text();
        case 'blob':
          return await response.blob();
        case 'arrayBuffer':
          return await response.arrayBuffer();
        case 'json':
        default:
          return await response.json();
      }
    } catch {
      if (effectiveType === 'json') {
        try {
          return await fallbackResponse.text();
        } catch {
          return null;
        }
      }
      return null;
    }
  }

  async _runErrorInterceptors(error) {
    let currentError = error;
    for (const interceptor of this._errorInterceptors.values()) {
      try {
        const result = await interceptor(currentError);
        if (result) return result;
      } catch (newError) {
        currentError = newError;
      }
    }
    return null;
  }

  /**
   * Ejecuta el fetch real, maneja redirecciones y parsea la respuesta.
   *
   * @private
   * @param {Object} config - Configuración del request
   * @param {Object} timeout - Estado del timeout
   * @returns {Promise<Object>} Resultado del request { data, status, ... }
   */
  async _performFetch(config, timeout) {
    const fetchOptions = {
      method: config.method,
      headers: config.headers,
      body: config.body,
      ...(this.validateRedirects ? { redirect: 'manual' } : {}),
    };

    if (timeout.signal) {
      fetchOptions.signal = timeout.signal;
    }

    let response = await fetch(config.url, fetchOptions);
    this._cleanupTimeout(timeout);

    if (this.validateRedirects && this._isRedirect(response.status)) {
      response = await this._handleRedirect(
        response,
        config.url,
        config.headers,
        config.method,
        config.body,
        config,
        timeout,
        0,
      );
    }

    const responseData = await this._parseResponse(response, config.responseType);

    let result = {
      data: responseData,
      status: response.status,
      statusText: response.statusText,
      headers: response.headers,
      ok: response.ok,
    };

    if (!response.ok) {
      const error = new HttpError(
        `HTTP ${response.status}: ${response.statusText}`,
        response.status,
        response.statusText,
        responseData,
        response,
      );

      error[ERROR_INTERCEPTORS_PROCESSED] = true;
      result = await this._runErrorInterceptors(error);
      if (!result) throw error;
      return result;
    }

    for (const interceptor of this._responseInterceptors.values()) {
      result = await interceptor(result);
    }

    return result;
  }
}

/**
 * Crea una nueva instancia de HttpClient con la configuración dada.
 *
 * @param {Object} [config={}] - Configuración del cliente
 * @returns {HttpClient}
 */
function createHttpClient(config = {}) {
  return new HttpClient(config);
}

export { HttpClient, HttpError, TimeoutError, NetworkError, createHttpClient };

export default HttpClient;
