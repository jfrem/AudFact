/**
 * Valida que un valor sea numérico y no negativo.
 *
 * @param {number|undefined} value - Valor a validar
 * @param {string} field - Nombre del campo
 */
function validateNonNegativeNumber(value, field) {
  if (value === undefined) return;
  if (typeof value !== 'number' || Number.isNaN(value) || !Number.isFinite(value) || value < 0) {
    throw new TypeError(`${field} must be a non-negative number`);
  }
}

/**
 * Valida que un valor sea entero no negativo.
 *
 * @param {number|undefined} value - Valor a validar
 * @param {string} field - Nombre del campo
 */
function validateNonNegativeInteger(value, field) {
  if (value === undefined) return;
  if (
    typeof value !== 'number' ||
    Number.isNaN(value) ||
    !Number.isFinite(value) ||
    !Number.isInteger(value) ||
    value < 0
  ) {
    throw new TypeError(`${field} must be a non-negative integer`);
  }
}

/**
 * Valida que un valor sea booleano.
 *
 * @param {boolean|undefined} value - Valor a validar
 * @param {string} field - Nombre del campo
 */
function validateBoolean(value, field) {
  if (value === undefined) return;
  if (typeof value !== 'boolean') {
    throw new TypeError(`${field} must be a boolean`);
  }
}

/**
 * Valida que un valor sea un arreglo de strings no vacíos.
 *
 * @param {string[]|undefined} value - Valor a validar
 * @param {string} field - Nombre del campo
 */
function validateStringArray(value, field) {
  if (value === undefined) return;
  if (!Array.isArray(value)) {
    throw new TypeError(`${field} must be an array of non-empty strings`);
  }
  for (const item of value) {
    if (typeof item !== 'string' || item.trim().length === 0) {
      throw new TypeError(`${field} must be an array of non-empty strings`);
    }
  }
}

export {
  validateNonNegativeNumber,
  validateNonNegativeInteger,
  validateBoolean,
  validateStringArray,
};
