/**
 * Error HTTP genérico. Se lanza cuando el servidor responde con status >= 400.
 *
 * @class HttpError
 * @extends Error
 * @property {number} status - Código de estado HTTP
 * @property {string} statusText - Texto del estado HTTP
 * @property {*} data - Cuerpo de la respuesta parseado
 * @property {Response} response - Objeto Response original de fetch
 */
class HttpError extends Error {
  /**
   * @param {string} message - Mensaje descriptivo del error
   * @param {number} status - Código de estado HTTP
   * @param {string} statusText - Texto del estado HTTP
   * @param {*} data - Cuerpo de la respuesta
   * @param {Response} response - Response original
   */
  constructor(message, status, statusText, data, response) {
    super(message);
    this.name = 'HttpError';
    this.status = status;
    this.statusText = statusText;
    this.data = data;
    this.response = response;
  }
}

/**
 * Error de timeout. Se lanza cuando una petición excede el tiempo límite.
 *
 * @note status = 0 indica que no hubo respuesta HTTP real.
 * Usar `error.name === 'TimeoutError'` o `instanceof TimeoutError`
 * para distinguir de errores HTTP estándar. También disponible
 * el getter `isTimeoutError` como alternativa ergonómica.
 *
 * @class TimeoutError
 * @extends HttpError
 */
class TimeoutError extends HttpError {
  /**
   * @param {string} message - Mensaje descriptivo
   * @param {number} timeout - Timeout en milisegundos que se excedió
   */
  constructor(message, timeout) {
    super(message, 0, 'Timeout', null, null);
    this.name = 'TimeoutError';
    this.timeout = timeout;
  }

  /** @returns {boolean} Siempre true para TimeoutError */
  get isTimeoutError() {
    return true;
  }
}

/**
 * Error de red. Se lanza cuando no hay conectividad o falla la conexión.
 *
 * @note status = 0 indica que no hubo respuesta HTTP real.
 * Usar `error.name === 'NetworkError'` o `instanceof NetworkError`
 * para distinguir de errores HTTP estándar. También disponible
 * el getter `isNetworkError` como alternativa ergonómica.
 *
 * @class NetworkError
 * @extends HttpError
 */
class NetworkError extends HttpError {
  /**
   * @param {string} message - Mensaje descriptivo
   * @param {Object} [options] - Opciones de error (causa)
   * @param {Error} [options.cause] - Error original
   */
  constructor(message, options = {}) {
    super(message, 0, 'Network Error', null, null);
    this.name = 'NetworkError';
    if (options.cause) {
      this.cause = options.cause;
    }
  }

  /** @returns {boolean} Siempre true para NetworkError */
  get isNetworkError() {
    return true;
  }
}

export { HttpError, TimeoutError, NetworkError };
